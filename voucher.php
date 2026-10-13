<?php
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/voucher-store.php';
require_once __DIR__ . '/voucher-pdf.php';
require_once __DIR__ . '/booking-voucher.php';
date_default_timezone_set('Europe/Rome');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function voucherResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function voucherSendPdf(array $voucher, bool $preview): void
{
    $pdf = voucherPdf($voucher, $preview);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . ($preview ? 'anteprima-voucher' : $voucher['voucher_code']) . '.pdf"');
    echo $pdf;
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = [];
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Richiesta non valida.');
        }
    }
    $action = $method === 'GET' ? ($_GET['action'] ?? '') : ($input['action'] ?? '');
    if (!is_string($action)) {
        throw new InvalidArgumentException('Operazione non valida.');
    }
    if ($method === 'POST' && $action === 'preview') {
        foreach (['recipient_name', 'service_name', 'price'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === ''
                || strlen($input[$key]) > 150 || preg_match('/[\x00-\x1f]/', $input[$key])) {
                throw new InvalidArgumentException('Inserisci il destinatario e seleziona un trattamento.');
            }
        }
        $sessions = filter_var($input['sessions_per_item'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessions || !$quantity || !$duration || $duration > 240 || $duration < 1) {
            throw new InvalidArgumentException('Formula, quantità o durata non valida.');
        }
        $quote = voucherQuote($input['price'], $sessions, $quantity);
        voucherSendPdf([
            'recipient_name' => trim($input['recipient_name']), 'service_name' => trim($input['service_name']),
            'duration_minutes' => $duration, 'sessions_per_item' => $sessions, 'quantity' => $quantity,
            'total_sessions' => $quote['total_sessions'], 'amount' => $quote['amount'],
        ], true);
    }
    if ($method === 'POST' && $action === 'check_booking') {
        $code = bookingVoucherCode($input['voucher_code'] ?? '');
        $studioId = filter_var($input['studio_id'] ?? null, FILTER_VALIDATE_INT);
        $serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT);
        $date = $input['date'] ?? '';
        $time = $input['time'] ?? '';
        $appointment = is_string($date) && is_string($time)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time) : false;
        if (!$studioId || !$serviceId || !$appointment || $appointment->format('Y-m-d H:i') !== $date . ' ' . $time
            || $appointment <= new DateTimeImmutable('now')) {
            throw new InvalidArgumentException('Seleziona studio, trattamento, data e orario prima di verificare il voucher.');
        }
        $pdo = dbConnection();
        $stmt = $pdo->prepare(
            "SELECT sv.durata_minuti FROM servizi sv INNER JOIN studi s ON s.id = sv.id_studio
             WHERE sv.id = :service AND s.id = :studio AND sv.attivo = 1 AND s.attivo = 1 AND s.nome = 'Studio Prati'"
        );
        $stmt->execute([':service' => $serviceId, ':studio' => $studioId]);
        $duration = $stmt->fetchColumn();
        if ($duration === false) throw new InvalidArgumentException('Seleziona un trattamento attivo di Studio Prati.');
        $voucher = bookingVoucherByCode($pdo, $code);
        bookingVoucherValidate($voucher, $studioId, $serviceId, (int)$duration, $appointment, new DateTimeImmutable('now'));
        voucherResponse(200, ['success' => true, 'remaining_sessions' => (int)$voucher['remaining_sessions'],
            'valid_until' => $voucher['valid_until'],
            'message' => 'Voucher riconosciuto. La seduta verrà scalata solo quando la richiesta di prenotazione sarà registrata.']);
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        voucherResponse(405, ['success' => false, 'message' => 'Metodo non consentito.']);
    }
    if (($method === 'GET' && $action !== 'pdf')
        || ($method === 'POST' && !in_array($action, ['create', 'verify'], true))) {
        throw new InvalidArgumentException('Operazione non valida.');
    }
    $token = $method === 'GET' ? ($_GET['token'] ?? '') : ($input['token'] ?? '');
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('Codice di accesso al voucher non valido.');
    }
    $pdo = dbConnection();
    $voucher = voucherByToken($pdo, $token);
    if ($action === 'pdf') {
        if (!$voucher || $voucher['status'] !== 'paid') {
            voucherResponse(409, ['success' => false, 'message' => 'Voucher non disponibile: pagamento non confermato.']);
        }
        voucherSendPdf($voucher, false);
    }
    if ($action === 'verify') {
        if (!$voucher || !$voucher['checkout_id']) {
            voucherResponse(404, ['success' => false, 'message' => 'Voucher non trovato.']);
        }
        $result = voucherVerify($pdo, $voucher);
        voucherResponse(200, ['success' => true, 'voucher' => voucherPublic($result['voucher']),
            'checkout_id' => $result['voucher']['checkout_id'],
            'checkout_expires_at' => $result['voucher']['checkout_expires_at'],
            'notifications' => $result['notifications']]);
    }
    $serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT);
    $sessions = filter_var($input['sessions_per_item'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    $recipient = $input['recipient_name'] ?? '';
    if (!$serviceId || !$sessions || !$quantity || !is_string($recipient)
        || trim($recipient) === '' || strlen($recipient) > 150 || preg_match('/[\x00-\x1f]/', $recipient)) {
        throw new InvalidArgumentException('Controlla servizio, destinatario e quantità.');
    }
    $customer = voucherCustomer($input);
    $payloadHash = hash('sha256', json_encode([$serviceId, $sessions, $quantity, trim($recipient), $customer], JSON_THROW_ON_ERROR));
    if ($voucher) {
        if (!hash_equals($voucher['payload_hash'], $payloadHash)) {
            voucherResponse(409, ['success' => false, 'message' => 'Questo acquisto ha già un checkout. Verificalo prima di modificare il voucher.']);
        }
        voucherResponse(200, ['success' => true, 'token' => $token, 'checkout_id' => $voucher['checkout_id'],
            'checkout_expires_at' => $voucher['checkout_expires_at'], 'voucher' => voucherPublic($voucher)]);
    }
    $stmt = $pdo->prepare(
        "SELECT sv.*, s.nome AS studio_name FROM servizi sv INNER JOIN studi s ON s.id = sv.id_studio
         WHERE sv.id = :service AND sv.attivo = 1 AND s.attivo = 1 AND s.nome = 'Studio Prati'"
    );
    $stmt->execute([':service' => $serviceId]);
    $service = $stmt->fetch();
    if (!$service) {
        throw new InvalidArgumentException('Seleziona un trattamento attivo di Studio Prati. APS Jaqueline non è inclusa.');
    }
    $quote = voucherQuote((string)$service['prezzo'], $sessions, $quantity);
    if (sumUpSettings()['currency'] !== 'EUR') {
        throw new RuntimeException('I voucher richiedono la configurazione SumUp in EUR.');
    }
    $reference = 'gift-' . $token;
    $checkoutId = null;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO gift_vouchers (token, payload_hash, id_studio, id_servizio, service_name, duration_minutes,
             recipient_name, sessions_per_item, quantity, total_sessions, amount, customer_data,
             checkout_reference, checkout_expires_at)
             VALUES (:token, :hash, :studio, :service, :name, :duration, :recipient, :sessions, :quantity,
             :total, :amount, :customer, :reference, :expires)'
        );
        $stmt->execute([
            ':token' => $token, ':hash' => $payloadHash, ':studio' => $service['id_studio'],
            ':service' => $serviceId, ':name' => $service['nome'], ':duration' => $service['durata_minuti'],
            ':recipient' => trim($recipient), ':sessions' => $sessions, ':quantity' => $quantity,
            ':total' => $quote['total_sessions'], ':amount' => $quote['amount'],
            ':customer' => json_encode($customer, JSON_THROW_ON_ERROR), ':reference' => $reference,
            ':expires' => (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'),
        ]);
        $checkout = createSumUpCheckout((float)$quote['amount'], $reference, 'Voucher regalo Thai Tik Massage - ' . $service['nome']);
        $checkoutId = $checkout['id'];
        $stmt = $pdo->prepare('UPDATE gift_vouchers SET checkout_id = :checkout WHERE token = :token');
        $stmt->execute([':checkout' => $checkoutId, ':token' => $token]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($checkoutId !== null) {
            try {
                deactivateSumUpCheckout($checkoutId);
            } catch (Throwable $cleanup) {
                error_log('Voucher checkout cleanup failed: ' . $cleanup->getMessage());
            }
        }
        throw $e;
    }
    $voucher = voucherByToken($pdo, $token);
    voucherResponse(201, ['success' => true, 'token' => $token, 'checkout_id' => $voucher['checkout_id'],
        'checkout_expires_at' => $voucher['checkout_expires_at'], 'voucher' => voucherPublic($voucher)]);
} catch (InvalidArgumentException | JsonException $e) {
    voucherResponse(400, ['success' => false, 'code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]);
} catch (SumUpApiException $e) {
    error_log('Voucher SumUp error: ' . $e->getMessage());
    voucherResponse(502, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Voucher operation failed: ' . $e->getMessage());
    voucherResponse(500, ['success' => false, 'message' => 'Operazione non completata. Verifica il pagamento prima di riprovare o contatta lo studio; non effettuare un secondo acquisto.']);
}
