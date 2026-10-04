<?php
/**
 * Booking backend for Aruba hosting
 * Handles booking requests with a MySQL database and supports a future admin dashboard.
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/booking-mailer.php';

function apiResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = dbConnection();
} catch (Throwable $e) {
    apiResponse(500, [
        'success' => false,
        'message' => 'Database connection failed.',
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $mode = $_GET['mode'] ?? 'status';

    if ($mode === 'catalog') {
        $studios = $pdo->query('SELECT id, nome FROM studi WHERE attivo = 1 ORDER BY nome')->fetchAll();
        $services = $pdo->query(
            'SELECT id, id_studio, nome, durata_minuti, prezzo
             FROM servizi WHERE attivo = 1 ORDER BY id_studio, nome'
        )->fetchAll();
        apiResponse(200, ['success' => true, 'studios' => $studios, 'services' => $services]);
    }

    if ($mode === 'hours') {
        $studios = $pdo->query('SELECT id, nome FROM studi WHERE attivo = 1 ORDER BY nome')->fetchAll();
        $hoursRows = $pdo->query(
            'SELECT id_studio, giorno_settimana, ora_inizio, ora_fine
             FROM orari_studio WHERE attivo = 1
             ORDER BY id_studio, giorno_settimana, ora_inizio'
        )->fetchAll();

        $hoursByStudio = [];
        foreach ($hoursRows as $hoursRow) {
            $hoursByStudio[(int)$hoursRow['id_studio']][] = [
                'weekday' => (int)$hoursRow['giorno_settimana'],
                'start' => substr((string)$hoursRow['ora_inizio'], 0, 5),
                'end' => substr((string)$hoursRow['ora_fine'], 0, 5),
            ];
        }

        foreach ($studios as $studioIndex => $studio) {
            $studios[$studioIndex]['hours'] = $hoursByStudio[(int)$studio['id']] ?? [];
        }

        apiResponse(200, ['success' => true, 'studios' => $studios]);
    }

    if ($mode === 'slots') {
        $studioId = (int)($_GET['studio_id'] ?? 0);
        $serviceId = (int)($_GET['service_id'] ?? 0);
        $date = (string)($_GET['date'] ?? '');
        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($studioId < 1 || $serviceId < 1 || !$dateObject || $dateObject->format('Y-m-d') !== $date) {
            apiResponse(400, ['success' => false, 'message' => 'Studio, servizio e data validi sono obbligatori.']);
        }

        $serviceStmt = $pdo->prepare(
            'SELECT durata_minuti FROM servizi WHERE id = :service AND id_studio = :studio AND attivo = 1 LIMIT 1'
        );
        $serviceStmt->execute([':service' => $serviceId, ':studio' => $studioId]);
        $service = $serviceStmt->fetch();
        if (!$service) {
            apiResponse(404, ['success' => false, 'message' => 'Servizio non trovato per lo studio selezionato.']);
        }

        $weekday = (int)$dateObject->format('N');
        $scheduleStmt = $pdo->prepare(
            'SELECT ora_inizio, ora_fine, intervallo_minuti
             FROM orari_studio
             WHERE id_studio = :studio AND giorno_settimana = :weekday AND attivo = 1
             ORDER BY ora_inizio'
           );
        $scheduleStmt->execute([':studio' => $studioId, ':weekday' => $weekday]);
        $scheduleRows = $scheduleStmt->fetchAll();

        $appointmentsStmt = $pdo->prepare(
            "SELECT ora_inizio, ora_fine FROM appuntamenti
             WHERE id_studio = :studio AND data_appuntamento = :date
               AND stato IN ('in_attesa', 'confermato')"
        );
        $appointmentsStmt->execute([':studio' => $studioId, ':date' => $date]);
        $appointments = $appointmentsStmt->fetchAll();

        $duration = (int)$service['durata_minuti'];
        $now = new DateTimeImmutable('now');
        $available = [];
        foreach ($scheduleRows as $schedule) {
            $start = new DateTimeImmutable($date . ' ' . $schedule['ora_inizio']);
            $end = new DateTimeImmutable($date . ' ' . $schedule['ora_fine']);
            $lastStart = $end->modify('-' . $duration . ' minutes');
            $step = max(5, (int)$schedule['intervallo_minuti']);

            for ($slot = $start; $slot <= $lastStart; $slot = $slot->modify('+' . $step . ' minutes')) {
                if ($slot <= $now) {
                    continue;
                }

                $slotEnd = $slot->modify('+' . $duration . ' minutes');
                $overlaps = false;
                foreach ($appointments as $appointment) {
                    $appointmentStart = new DateTimeImmutable($date . ' ' . $appointment['ora_inizio']);
                    $appointmentEnd = new DateTimeImmutable($date . ' ' . $appointment['ora_fine']);
                    if ($slot < $appointmentEnd && $slotEnd > $appointmentStart) {
                        $overlaps = true;
                        break;
                    }
                }

                if (!$overlaps) {
                    $available[$slot->format('H:i')] = true;
                }
            }
        }

        $availableSlots = array_keys($available);
        sort($availableSlots);

        apiResponse(200, [
            'success' => true,
            'studio_id' => $studioId,
            'service_id' => $serviceId,
            'date' => $date,
            'available_slots' => $availableSlots,
        ]);
    }

    apiResponse(200, [
        'success' => true,
        'message' => 'Booking backend ready.',
        'endpoints' => [
            'POST' => 'Create a booking request',
            'GET?mode=slots' => 'Check occupied time slots',
        ],
    ]);
}

if ($method !== 'POST') {
    apiResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$required = ['studio_id', 'service_id', 'date', 'time', 'name', 'email'];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
        apiResponse(400, [
            'success' => false,
            'message' => "Missing required field: {$field}",
        ]);
    }
}

$studioId = filter_var($input['studio_id'], FILTER_VALIDATE_INT);
$serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
$date = trim((string)$input['date']);
$time = trim((string)$input['time']);
$name = trim((string)$input['name']);
$email = trim((string)$input['email']);
$notes = trim((string)($input['notes'] ?? ''));

$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$studioId || !$serviceId || !$dateObject || $dateObject->format('Y-m-d') !== $date || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiResponse(400, ['success' => false, 'message' => 'Controlla studio, servizio, data, orario ed email.']);
}

$studioStmt = $pdo->prepare('SELECT id, nome, id_professionista FROM studi WHERE id = :studio AND attivo = 1 LIMIT 1');
$studioStmt->execute([':studio' => $studioId]);
$studioRecord = $studioStmt->fetch();

if (!$studioRecord) {
    apiResponse(404, [
        'success' => false,
        'message' => 'Selected studio not found.',
    ]);
}

$serviceStmt = $pdo->prepare('SELECT id, nome, durata_minuti, prezzo FROM servizi WHERE id_studio = :studio AND id = :service AND attivo = 1 LIMIT 1');
$serviceStmt->execute([
    ':studio' => $studioRecord['id'],
    ':service' => $serviceId,
]);
$serviceRecord = $serviceStmt->fetch();

if (!$serviceRecord) {
    apiResponse(404, [
        'success' => false,
        'message' => 'Selected service not found for this studio.',
    ]);
}

$startDateTime = new DateTimeImmutable($date . ' ' . $time);
$endDateTime = $startDateTime->modify('+' . (int)$serviceRecord['durata_minuti'] . ' minutes');
if ($startDateTime <= new DateTimeImmutable('now')) {
    apiResponse(409, ['success' => false, 'message' => 'Non è possibile prenotare un orario passato.']);
}

$scheduleStmt = $pdo->prepare(
    'SELECT ora_inizio, ora_fine, intervallo_minuti FROM orari_studio
     WHERE id_studio = :studio AND giorno_settimana = :weekday AND attivo = 1'
);
$scheduleStmt->execute([
    ':studio' => $studioRecord['id'],
    ':weekday' => (int)$startDateTime->format('N'),
]);
$scheduleRows = $scheduleStmt->fetchAll();
$startMinute = ((int)$startDateTime->format('H') * 60) + (int)$startDateTime->format('i');
$endMinute = ((int)$endDateTime->format('H') * 60) + (int)$endDateTime->format('i');
$fitsSchedule = false;
foreach ($scheduleRows as $schedule) {
    $windowStart = new DateTimeImmutable($date . ' ' . $schedule['ora_inizio']);
    $windowEnd = new DateTimeImmutable($date . ' ' . $schedule['ora_fine']);
    $windowStartMinute = ((int)$windowStart->format('H') * 60) + (int)$windowStart->format('i');
    $windowEndMinute = ((int)$windowEnd->format('H') * 60) + (int)$windowEnd->format('i');
    $step = max(5, (int)$schedule['intervallo_minuti']);

    if ($startDateTime >= $windowStart && $endDateTime <= $windowEnd && ($startMinute - $windowStartMinute) % $step === 0) {
        $fitsSchedule = true;
        break;
    }
}
if (!$fitsSchedule) {
    apiResponse(409, ['success' => false, 'message' => 'L’orario selezionato non è più disponibile.']);
}

$nameParts = preg_split('/\s+/', $name, 2);
$firstName = $nameParts[0] ?? '';
$lastName = $nameParts[1] ?? '';
$studioName = (string)$studioRecord['nome'];
$serviceName = (string)$serviceRecord['nome'];

try {
    $pdo->beginTransaction();
    $lockStudio = $pdo->prepare('SELECT id FROM studi WHERE id = :studio AND attivo = 1 FOR UPDATE');
    $lockStudio->execute([':studio' => $studioRecord['id']]);

    $overlapStmt = $pdo->prepare(
        "SELECT id FROM appuntamenti
         WHERE id_studio = :studio AND data_appuntamento = :date
           AND stato IN ('in_attesa', 'confermato')
           AND ora_inizio < :end_time AND ora_fine > :start_time
         LIMIT 1"
    );
    $overlapStmt->execute([
        ':studio' => $studioRecord['id'],
        ':date' => $date,
        ':end_time' => $endDateTime->format('H:i:s'),
        ':start_time' => $startDateTime->format('H:i:s'),
    ]);
    if ($overlapStmt->fetch()) {
        $pdo->rollBack();
        apiResponse(409, ['success' => false, 'message' => 'L’orario selezionato è appena stato prenotato. Scegline un altro.']);
    }

    $customerStmt = $pdo->prepare(
        'INSERT INTO clienti (nome, cognome, email, telefono, tipo_cliente, created_at)
         VALUES (:nome, :cognome, :email, :telefono, :tipo, NOW())'
    );
    $customerStmt->execute([
        ':nome' => $firstName,
        ':cognome' => $lastName,
        ':email' => $email,
        ':telefono' => '',
        ':tipo' => 'privato',
    ]);
    $clienteId = (int)$pdo->lastInsertId();

    $bookingStmt = $pdo->prepare(
        'INSERT INTO appuntamenti (id_studio, id_professionista, id_cliente, id_servizio, data_appuntamento, ora_inizio, ora_fine, stato, note, created_at)
         VALUES (:studio, :professionista, :cliente, :servizio, :date, :time, :time_end, :status, :note, NOW())'
    );
    $bookingStmt->execute([
        ':studio' => $studioRecord['id'],
        ':professionista' => $studioRecord['id_professionista'],
        ':cliente' => $clienteId,
        ':servizio' => $serviceRecord['id'],
        ':date' => $date,
        ':time' => $startDateTime->format('H:i:s'),
        ':time_end' => $endDateTime->format('H:i:s'),
        ':status' => 'in_attesa',
        ':note' => $notes,
    ]);
    $bookingId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiResponse(500, ['success' => false, 'message' => 'Non è stato possibile salvare la richiesta.']);
}

$notificationStatus = sendBookingNotifications($bookingId, [
    'name' => $name,
    'studio' => $studioName,
    'service' => $serviceName,
    'date' => $date,
    'time' => $time,
    'price' => $serviceRecord['prezzo'],
    'notes' => $notes,
], $email);

apiResponse(201, [
    'success' => true,
    'message' => 'Prenotazione ricevuta con successo.',
    'booking_id' => $bookingId,
    'data' => [
        'studio' => $studioName,
        'service' => $serviceName,
        'date' => $date,
        'time' => $time,
        'name' => $name,
        'email' => $email,
        'price' => (float)$serviceRecord['prezzo'],
    ],
    'notifications' => $notificationStatus,
]);
