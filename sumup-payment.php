<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/booking-mailer.php';
require_once __DIR__ . '/sumup-client.php';
require_once __DIR__ . '/sumup-intents.php';

function paymentResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    paymentResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    paymentResponse(400, ['success' => false, 'message' => 'Richiesta non valida.']);
}

$intentToken = trim((string)($input['payment_intent'] ?? ''));
$checkoutId = trim((string)($input['checkout_id'] ?? ''));
if ($intentToken === '' || $checkoutId === '') {
    paymentResponse(400, ['success' => false, 'message' => 'Intento di pagamento e checkout sono obbligatori.']);
}

try {
    $pdo = dbConnection();
    $intent = sumUpIntentByToken($pdo, $intentToken);
} catch (Throwable $e) {
    paymentResponse(500, ['success' => false, 'message' => 'Non è stato possibile verificare il pagamento.']);
}

if (!$intent || !hash_equals((string)$intent['sumup_checkout_id'], $checkoutId)) {
    paymentResponse(404, ['success' => false, 'message' => 'Checkout non trovato.']);
}

try {
    $checkout = retrieveSumUpCheckout($checkoutId);
    $settings = sumUpSettings();
} catch (SumUpApiException $e) {
    paymentResponse(502, ['success' => false, 'message' => $e->getMessage()]);
}

$checkoutReference = (string)($checkout['checkout_reference'] ?? '');
$checkoutCurrency = strtoupper((string)($checkout['currency'] ?? ''));
$checkoutAmount = (float)($checkout['amount'] ?? 0);
$expectedAmount = (float)$intent['amount'];

if (
    !hash_equals((string)$intent['sumup_checkout_reference'], $checkoutReference)
    || $checkoutCurrency !== $settings['currency']
    || abs($checkoutAmount - $expectedAmount) >= 0.005
) {
    error_log('SumUp checkout mismatch for payment intent #' . $intent['id']);
    paymentResponse(409, ['success' => false, 'message' => 'I dati del pagamento non corrispondono alla richiesta.']);
}

$checkoutStatus = strtoupper((string)($checkout['status'] ?? ''));
if ($checkoutStatus === 'PAID') {
    $transactionId = isset($checkout['transactions'][0]['id'])
        ? trim((string)$checkout['transactions'][0]['id'])
        : null;

    try {
        $result = finalizePaidSumUpIntent($pdo, (int)$intent['id'], $transactionId);
    } catch (Throwable $e) {
        error_log('Paid SumUp intent finalization failed for intent #' . $intent['id'] . ': ' . $e->getMessage());
        paymentResponse(500, [
            'success' => false,
            'message' => 'Pagamento riuscito, ma registrazione dell’appuntamento non completata. Contatta subito lo studio.',
        ]);
    }

    paymentResponse(200, [
        'success' => true,
        'paid' => true,
        'message' => 'Pagamento completato. La richiesta di prenotazione è stata registrata.',
        'booking_id' => $result['booking_id'],
        'notifications' => $result['notifications'],
        'calendar_sync' => $result['calendar_sync'],
    ]);
}

if (in_array($checkoutStatus, ['FAILED', 'EXPIRED'], true)) {
    try {
        $failedStmt = $pdo->prepare(
            "UPDATE sumup_payment_intents
             SET status = :status, customer_data = '{}', note = NULL, updated_at = NOW()
             WHERE id = :intent AND status = 'pending'"
        );
        $failedStmt->execute([
            ':status' => $checkoutStatus === 'FAILED' ? 'failed' : 'expired',
            ':intent' => $intent['id'],
        ]);
    } catch (Throwable $e) {
        paymentResponse(500, ['success' => false, 'message' => 'Non è stato possibile aggiornare lo stato del pagamento.']);
    }

    paymentResponse(200, [
        'success' => true,
        'paid' => false,
        'status' => $checkoutStatus,
        'message' => 'Il pagamento non è riuscito. Nessun appuntamento è stato registrato e lo slot è stato liberato.',
    ]);
}

if (in_array($checkoutStatus, ['PENDING', 'NEW'], true)) {
    $expiresAt = new DateTimeImmutable((string)$intent['expires_at']);
    if ($expiresAt <= new DateTimeImmutable('now')) {
        try {
            $checkoutAfterDeactivation = deactivateSumUpCheckout($checkoutId);
            $statusAfterDeactivation = strtoupper((string)($checkoutAfterDeactivation['status'] ?? ''));

            if ($statusAfterDeactivation === 'PAID') {
                $transactionId = isset($checkoutAfterDeactivation['transactions'][0]['id'])
                    ? trim((string)$checkoutAfterDeactivation['transactions'][0]['id'])
                    : null;
                $result = finalizePaidSumUpIntent($pdo, (int)$intent['id'], $transactionId);
                paymentResponse(200, [
                    'success' => true,
                    'paid' => true,
                    'message' => 'Pagamento completato. La richiesta di prenotazione è stata registrata.',
                    'booking_id' => $result['booking_id'],
                    'notifications' => $result['notifications'],
                    'calendar_sync' => $result['calendar_sync'],
                ]);
            }

            $expiredStmt = $pdo->prepare(
                "UPDATE sumup_payment_intents
                 SET status = 'expired', customer_data = '{}', note = NULL, updated_at = NOW()
                 WHERE id = :intent AND status = 'pending'"
            );
            $expiredStmt->execute([':intent' => $intent['id']]);
        } catch (SumUpApiException $e) {
            paymentResponse(502, ['success' => false, 'message' => 'Non è stato possibile chiudere il checkout scaduto. Riprova tra poco.']);
        } catch (Throwable $e) {
            paymentResponse(500, ['success' => false, 'message' => 'Non è stato possibile chiudere la richiesta scaduta.']);
        }

        paymentResponse(200, [
            'success' => true,
            'paid' => false,
            'status' => 'EXPIRED',
            'message' => 'I 15 minuti sono scaduti. Nessun appuntamento è stato registrato e lo slot è stato liberato.',
        ]);
    }

    paymentResponse(202, [
        'success' => true,
        'paid' => false,
        'status' => $checkoutStatus,
        'message' => 'Pagamento ancora in elaborazione. Attendi qualche secondo e verifica di nuovo.',
        'expires_at' => (new DateTimeImmutable((string)$intent['expires_at']))->format(DateTimeInterface::ATOM),
    ]);
}

error_log('Unexpected SumUp checkout status: ' . $checkoutStatus);
paymentResponse(502, ['success' => false, 'message' => 'SumUp ha restituito uno stato di pagamento non riconosciuto.']);
