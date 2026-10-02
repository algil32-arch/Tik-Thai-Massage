<?php
/**
 * Booking backend for Aruba hosting
 * Handles booking requests with a MySQL database and supports a future admin dashboard.
 */

header('Content-Type: application/json; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'tikthai_booking';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

function apiResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    apiResponse(500, [
        'success' => false,
        'message' => 'Database connection failed.',
        'error' => $e->getMessage(),
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $mode = $_GET['mode'] ?? 'status';

    if ($mode === 'slots') {
        $studio = $_GET['studio'] ?? 'Studio Roma';
        $date = $_GET['date'] ?? date('Y-m-d');

        $studioRow = $pdo->prepare("SELECT id FROM studi WHERE nome = :name LIMIT 1");
        $studioRow->execute([':name' => $studio]);
        $studioRecord = $studioRow->fetch();

        if (!$studioRecord) {
            apiResponse(404, ['success' => false, 'message' => 'Studio not found.']);
        }

        $stmt = $pdo->prepare(
            "SELECT ora_inizio FROM appuntamenti WHERE id_studio = :studio AND data_appuntamento = :date AND stato IN ('in_attesa','confermato')"
        );
        $stmt->execute([
            ':studio' => $studioRecord['id'],
            ':date' => $date,
        ]);

        $taken = array_map(static fn($row) => $row['ora_inizio'], $stmt->fetchAll());

        apiResponse(200, [
            'success' => true,
            'studio' => $studio,
            'date' => $date,
            'taken_slots' => $taken,
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

$required = ['studio', 'service', 'date', 'time', 'name', 'email'];
foreach ($required as $field) {
    if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
        apiResponse(400, [
            'success' => false,
            'message' => "Missing required field: {$field}",
        ]);
    }
}

$studioName = trim((string)$input['studio']);
$serviceName = trim((string)$input['service']);
$date = trim((string)$input['date']);
$time = trim((string)$input['time']);
$name = trim((string)$input['name']);
$email = trim((string)$input['email']);
$notes = trim((string)($input['notes'] ?? ''));

$studioStmt = $pdo->prepare('SELECT id FROM studi WHERE nome = :studio LIMIT 1');
$studioStmt->execute([':studio' => $studioName]);
$studioRecord = $studioStmt->fetch();

if (!$studioRecord) {
    apiResponse(404, [
        'success' => false,
        'message' => 'Selected studio not found.',
    ]);
}

$serviceStmt = $pdo->prepare('SELECT id, durata_minuti, prezzo FROM servizi WHERE id_studio = :studio AND nome = :service LIMIT 1');
$serviceStmt->execute([
    ':studio' => $studioRecord['id'],
    ':service' => $serviceName,
]);
$serviceRecord = $serviceStmt->fetch();

if (!$serviceRecord) {
    apiResponse(404, [
        'success' => false,
        'message' => 'Selected service not found for this studio.',
    ]);
}

$nameParts = preg_split('/\s+/', $name, 2);
$firstName = $nameParts[0] ?? '';
$lastName = $nameParts[1] ?? '';

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

$startDateTime = new DateTimeImmutable($date . ' ' . $time);
$endDateTime = $startDateTime->modify('+' . (int)$serviceRecord['durata_minuti'] . ' minutes');

$bookingStmt = $pdo->prepare(
    'INSERT INTO appuntamenti (id_studio, id_professionista, id_cliente, id_servizio, data_appuntamento, ora_inizio, ora_fine, stato, note, created_at)
     VALUES (:studio, :professionista, :cliente, :servizio, :date, :time, :time_end, :status, :note, NOW())'
);
$bookingStmt->execute([
    ':studio' => $studioRecord['id'],
    ':professionista' => 1,
    ':cliente' => $clienteId,
    ':servizio' => $serviceRecord['id'],
    ':date' => $date,
    ':time' => $time,
    ':time_end' => $endDateTime->format('H:i:s'),
    ':status' => 'in_attesa',
    ':note' => $notes,
]);

$bookingId = (int)$pdo->lastInsertId();

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
]);
