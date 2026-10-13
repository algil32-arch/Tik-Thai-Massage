<?php
/**
 * Booking backend for Aruba hosting
 * Handles booking requests with a MySQL database and supports a future admin dashboard.
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/booking-mailer.php';
require_once __DIR__ . '/booking-slots.php';
require_once __DIR__ . '/sumup-client.php';
require_once __DIR__ . '/sumup-intents.php';

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
             FROM servizi WHERE attivo = 1 ORDER BY id_studio, nome, durata_minuti'
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

        if ($studioId < 1 || $serviceId < 0 || !$dateObject || $dateObject->format('Y-m-d') !== $date) {
            apiResponse(400, ['success' => false, 'message' => 'Studio e data validi sono obbligatori.']);
        }

        $durationMinutes = BOOKING_MIN_TREATMENT_MINUTES;
        if ($serviceId > 0) {
            $serviceStmt = $pdo->prepare(
                'SELECT durata_minuti FROM servizi WHERE id = :service AND id_studio = :studio AND attivo = 1 LIMIT 1'
            );
            $serviceStmt->execute([':service' => $serviceId, ':studio' => $studioId]);
            $service = $serviceStmt->fetch();
            if (!$service) {
                apiResponse(404, ['success' => false, 'message' => 'Servizio non trovato per lo studio selezionato.']);
            }
            $durationMinutes = (int)$service['durata_minuti'];
        }

        expireSumUpPaymentIntents($pdo, $studioId, $date);

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
        $intentsStmt = $pdo->prepare(
            "SELECT ora_inizio, ora_fine FROM sumup_payment_intents
             WHERE id_studio = :studio AND data_appuntamento = :date AND status = 'pending'"
        );
        $intentsStmt->execute([':studio' => $studioId, ':date' => $date]);
        $occupiedSlots = array_merge(
            $appointments,
            $intentsStmt->fetchAll(),
            agendaBlocksForDate($pdo, $date)
        );

        $slotOptions = bookingSlotOptions(
            $date,
            $durationMinutes,
            $scheduleRows,
            $occupiedSlots,
            new DateTimeImmutable('now')
        );
        $availableSlots = array_values(array_map(
            static fn(array $option): string => $option['time'],
            array_filter($slotOptions, static fn(array $option): bool => $option['available'])
        ));

        apiResponse(200, [
            'success' => true,
            'studio_id' => $studioId,
            'service_id' => $serviceId > 0 ? $serviceId : null,
            'duration_minutes' => $durationMinutes,
            'date' => $date,
            'available_slots' => $availableSlots,
            'slot_options' => $slotOptions,
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
$phone = trim((string)($input['phone'] ?? ''));
$paymentMethod = trim((string)($input['payment_method'] ?? 'studio'));
$customerType = trim((string)($input['customer_type'] ?? 'privato'));
$companyName = trim((string)($input['ragione_sociale'] ?? ''));
$taxCode = strtoupper(trim((string)($input['codice_fiscale'] ?? '')));
$vatNumber = trim((string)($input['partita_iva'] ?? ''));
$billingAddress = trim((string)($input['indirizzo'] ?? ''));
$billingCity = trim((string)($input['citta'] ?? ''));
$billingPostalCode = trim((string)($input['cap'] ?? ''));
$recipientCode = strtoupper(trim((string)($input['codice_destinatario'] ?? '')));
$pec = trim((string)($input['pec'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));

if (!in_array($paymentMethod, ['studio', 'online'], true)) {
    apiResponse(400, ['success' => false, 'message' => 'Modalità di pagamento non valida.']);
}
if (!in_array($customerType, ['privato', 'azienda'], true) || $billingAddress === '' || $billingCity === '' || $billingPostalCode === '') {
    apiResponse(400, ['success' => false, 'message' => 'Inserisci i dati fiscali e l’indirizzo di fatturazione.']);
}
if ($customerType === 'privato' && $taxCode === '') {
    apiResponse(400, ['success' => false, 'message' => 'Il codice fiscale è obbligatorio per il cliente privato.']);
}
if ($customerType === 'azienda' && ($companyName === '' || $vatNumber === '' || ($recipientCode === '' && $pec === ''))) {
    apiResponse(400, ['success' => false, 'message' => 'Per aziende e professionisti sono necessari ragione sociale, partita IVA e codice destinatario o PEC.']);
}

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

if ($paymentMethod === 'online' && $studioRecord['nome'] !== 'Studio Prati') {
    apiResponse(400, [
        'success' => false,
        'message' => 'Non è possibile effettuare pagamenti on line per le prestazioni presso l\'APS Jaqueline. La prenotazione serve solo a riservare il giorno e l\'orario; il pagamento avviene esclusivamente in loco presso la sede dell\'Associazione.',
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
$nameParts = preg_split('/\s+/', $name, 2);
$firstName = $nameParts[0] ?? '';
$lastName = $nameParts[1] ?? '';
$studioName = (string)$studioRecord['nome'];
$serviceName = (string)$serviceRecord['nome'];
$bookingId = null;
$checkoutId = null;
$paymentIntentToken = null;
$expiresAt = null;

expireSumUpPaymentIntents($pdo, (int)$studioRecord['id'], $date);

try {
    $pdo->beginTransaction();
    $lockStudio = $pdo->prepare('SELECT id FROM studi WHERE id = :studio AND attivo = 1 FOR UPDATE');
    $lockStudio->execute([':studio' => $studioRecord['id']]);

    $appointmentsStmt = $pdo->prepare(
        "SELECT ora_inizio, ora_fine FROM appuntamenti
         WHERE id_studio = :studio AND data_appuntamento = :date
           AND stato IN ('in_attesa', 'confermato')
         FOR UPDATE"
    );
    $appointmentsStmt->execute([
        ':studio' => $studioRecord['id'],
        ':date' => $date,
    ]);
    $occupiedSlots = $appointmentsStmt->fetchAll();
    $intentsStmt = $pdo->prepare(
        "SELECT ora_inizio, ora_fine FROM sumup_payment_intents
         WHERE id_studio = :studio AND data_appuntamento = :date AND status = 'pending'
         FOR UPDATE"
    );
    $intentsStmt->execute([
        ':studio' => $studioRecord['id'],
        ':date' => $date,
    ]);
    $occupiedSlots = array_merge(
        $occupiedSlots,
        $intentsStmt->fetchAll(),
        agendaBlocksForDate($pdo, $date)
    );
    $slotOptions = bookingSlotOptions(
        $date,
        (int)$serviceRecord['durata_minuti'],
        $scheduleRows,
        $occupiedSlots,
        new DateTimeImmutable('now')
    );
    $selectedSlot = null;
    foreach ($slotOptions as $option) {
        if ($option['time'] === $time) {
            $selectedSlot = $option;
            break;
        }
    }
    if ($selectedSlot === null || !$selectedSlot['available']) {
        $pdo->rollBack();
        apiResponse(409, [
            'success' => false,
            'message' => $selectedSlot['message'] ?? 'L’orario scelto non è disponibile. Seleziona uno degli orari proposti.',
        ]);
    }

    if ($paymentMethod === 'online') {
        $paymentIntentToken = bin2hex(random_bytes(32));
        $checkoutReference = 'intent-' . substr($paymentIntentToken, 0, 32);
        $checkout = createSumUpCheckout(
            (float)$serviceRecord['prezzo'],
            $checkoutReference,
            'Prenotazione online - ' . $serviceName
        );
        $checkoutId = (string)$checkout['id'];
        $expiresAt = new DateTimeImmutable('+' . SUMUP_INTENT_TTL_MINUTES . ' minutes');
        $customerData = json_encode([
            'nome' => $firstName,
            'cognome' => $lastName,
            'ragione_sociale' => $companyName !== '' ? $companyName : null,
            'email' => $email,
            'telefono' => $phone,
            'codice_fiscale' => $taxCode !== '' ? $taxCode : null,
            'partita_iva' => $vatNumber !== '' ? $vatNumber : null,
            'codice_destinatario' => $recipientCode !== '' ? $recipientCode : null,
            'pec' => $pec !== '' ? $pec : null,
            'indirizzo' => $billingAddress,
            'citta' => $billingCity,
            'cap' => $billingPostalCode,
            'tipo_cliente' => $customerType,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $intentStmt = $pdo->prepare(
            "INSERT INTO sumup_payment_intents
                (token, id_studio, id_professionista, id_servizio, data_appuntamento, ora_inizio, ora_fine,
                 amount, customer_data, note, status, sumup_checkout_id, sumup_checkout_reference, expires_at)
             VALUES
                (:token, :studio, :professionista, :servizio, :date, :time, :time_end,
                 :amount, :customer_data, :note, 'pending', :checkout, :reference, :expires_at)"
        );
        $intentStmt->execute([
            ':token' => $paymentIntentToken,
            ':studio' => $studioRecord['id'],
            ':professionista' => $studioRecord['id_professionista'],
            ':servizio' => $serviceRecord['id'],
            ':date' => $date,
            ':time' => $startDateTime->format('H:i:s'),
            ':time_end' => $endDateTime->format('H:i:s'),
            ':amount' => $serviceRecord['prezzo'],
            ':customer_data' => $customerData,
            ':note' => $notes !== '' ? $notes : null,
            ':checkout' => $checkoutId,
            ':reference' => $checkoutReference,
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    } else {
        $customerStmt = $pdo->prepare(
            'INSERT INTO clienti (nome, cognome, ragione_sociale, email, telefono, codice_fiscale, partita_iva, codice_destinatario, pec, indirizzo, citta, cap, tipo_cliente, created_at)
             VALUES (:nome, :cognome, :ragione_sociale, :email, :telefono, :codice_fiscale, :partita_iva, :codice_destinatario, :pec, :indirizzo, :citta, :cap, :tipo, NOW())'
        );
        $customerStmt->execute([
            ':nome' => $firstName,
            ':cognome' => $lastName,
            ':ragione_sociale' => $companyName !== '' ? $companyName : null,
            ':email' => $email,
            ':telefono' => $phone,
            ':codice_fiscale' => $taxCode !== '' ? $taxCode : null,
            ':partita_iva' => $vatNumber !== '' ? $vatNumber : null,
            ':codice_destinatario' => $recipientCode !== '' ? $recipientCode : null,
            ':pec' => $pec !== '' ? $pec : null,
            ':indirizzo' => $billingAddress,
            ':citta' => $billingCity,
            ':cap' => $billingPostalCode,
            ':tipo' => $customerType,
        ]);
        $customerId = (int)$pdo->lastInsertId();

        $bookingStmt = $pdo->prepare(
            "INSERT INTO appuntamenti
                (id_studio, id_professionista, id_cliente, id_servizio, data_appuntamento, ora_inizio, ora_fine,
                 stato, metodo_pagamento, stato_pagamento, note, created_at)
             VALUES
                (:studio, :professionista, :cliente, :servizio, :date, :time, :time_end,
                 'in_attesa', 'studio', 'da_saldare', :note, NOW())"
        );
        $bookingStmt->execute([
            ':studio' => $studioRecord['id'],
            ':professionista' => $studioRecord['id_professionista'],
            ':cliente' => $customerId,
            ':servizio' => $serviceRecord['id'],
            ':date' => $date,
            ':time' => $startDateTime->format('H:i:s'),
            ':time_end' => $endDateTime->format('H:i:s'),
            ':note' => $notes,
        ]);
        $bookingId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
} catch (SumUpApiException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiResponse(502, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($checkoutId !== null) {
        try {
            deactivateSumUpCheckout($checkoutId);
        } catch (Throwable $cleanupError) {
            error_log('Unable to deactivate orphan SumUp checkout: ' . $cleanupError->getMessage());
        }
    }
    apiResponse(500, ['success' => false, 'message' => 'Non è stato possibile salvare la richiesta.']);
}

$notificationStatus = ['studio_sent' => false, 'customer_sent' => false];
if ($paymentMethod === 'studio') {
    $notificationStatus = sendBookingNotifications($bookingId, [
        'name' => $name,
        'studio' => $studioName,
        'service' => $serviceName,
        'date' => $date,
        'time' => $time,
        'price' => $serviceRecord['prezzo'],
        'notes' => $notes,
    ], $email);
}

apiResponse(201, [
    'success' => true,
    'message' => $paymentMethod === 'online'
        ? 'Checkout creato. Lo slot è riservato per 15 minuti; l’appuntamento sarà registrato solo dopo il pagamento.'
        : 'Prenotazione ricevuta con successo.',
    'booking_id' => $bookingId,
    'checkout_id' => $checkoutId ?? null,
    'payment_intent' => $paymentIntentToken,
    'expires_at' => $expiresAt?->format(DateTimeInterface::ATOM),
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
