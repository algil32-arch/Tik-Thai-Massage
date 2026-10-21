<?php
require_once __DIR__ . '/voucher-model.php';
require_once __DIR__ . '/sumup-client.php';

function voucherByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('Codice di accesso al voucher non valido.');
    }
    $stmt = $pdo->prepare(
        'SELECT gv.*, s.nome AS studio_name FROM gift_vouchers gv
         INNER JOIN studi s ON s.id = gv.id_studio WHERE gv.token = :token'
    );
    $stmt->execute([':token' => $token]);
    return $stmt->fetch() ?: null;
}

function voucherNotify(array $voucher): array
{
    $config = appConfig();
    $customer = json_decode($voucher['customer_data'], true, 512, JSON_THROW_ON_ERROR);
    $from = (string)$config['MAIL_FROM_EMAIL'];
    $studio = (string)$config['BOOKING_NOTIFICATION_EMAIL'];
    $result = ['studio_sent' => false, 'customer_sent' => false];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($studio, FILTER_VALIDATE_EMAIL)) {
        error_log('Gift voucher email configuration invalid for voucher #' . $voucher['id']);
        return $result;
    }
    $studioName = str_replace('Montesacro', 'Monte Sacro', $voucher['studio_name']);
    $body = "Voucher regalo pagato: {$voucher['voucher_code']}\n"
        . "Destinatario: {$voucher['recipient_name']}\n"
        . "Servizio: {$voucher['service_name']} ({$voucher['duration_minutes']} minuti)\n"
        . "Sedute: {$voucher['total_sessions']}\nImporto: {$voucher['amount']} EUR\n"
        . "Sede: {$studioName}\nValido fino al: {$voucher['valid_until']}\n"
        . ($voucher['studio_name'] === 'APS Studio Montesacro'
            ? "Chi non e' gia' socio dovra' iscriversi all'APS al momento del primo appuntamento. Iscrizione ed eventuale quota sono gestite direttamente dall'associazione.\n"
            : '')
        . "Nessun appuntamento prenotato. Prenota sul sito scegliendo Prenota con voucher regalo e inserisci il codice, oppure contatta lo studio.\n"
        . "Download PDF: https://www.thaitikmassage.it/voucher.php?action=pdf&token={$voucher['token']}\n";
    $headers = "From: Thai Tik Massage <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    foreach (['studio_sent' => $studio, 'customer_sent' => $customer['email']] as $key => $email) {
        try {
            $result[$key] = mail($email, 'Voucher regalo ' . $voucher['voucher_code'], $body, $headers);
        } catch (Throwable $e) {
            error_log('Voucher notification error for #' . $voucher['id'] . ': ' . $e->getMessage());
        }
        if (!$result[$key]) {
            error_log('Voucher notification not delivered for #' . $voucher['id'] . ', ' . $key);
        }
    }
    return $result;
}

function voucherVerify(PDO $pdo, array $voucher): array
{
    if ($voucher['status'] === 'paid') {
        return ['voucher' => $voucher, 'notifications' => null];
    }
    $checkout = retrieveSumUpCheckout((string)$voucher['checkout_id']);
    if (!voucherPaymentMatches($voucher, $checkout, sumUpSettings()['currency'])) {
        error_log('Gift voucher checkout mismatch for #' . $voucher['id']);
        throw new RuntimeException('Il pagamento non corrisponde al voucher. Contatta lo studio.');
    }
    $status = strtoupper((string)($checkout['status'] ?? ''));
    if (in_array($status, ['NEW', 'PENDING'], true)
        && new DateTimeImmutable($voucher['checkout_expires_at']) <= new DateTimeImmutable('now')) {
        $closed = deactivateSumUpCheckout($voucher['checkout_id']);
        $status = $closed === [] ? 'EXPIRED' : strtoupper((string)($closed['status'] ?? ''));
        if ($status === 'PAID') {
            $checkout = retrieveSumUpCheckout($voucher['checkout_id']);
            if (!voucherPaymentMatches($voucher, $checkout, sumUpSettings()['currency'])) {
                throw new RuntimeException('Dati del pagamento non coerenti dopo la chiusura del checkout.');
            }
            $status = strtoupper((string)($checkout['status'] ?? ''));
        }
        if (in_array($status, ['NEW', 'PENDING'], true)) {
            throw new RuntimeException('Checkout in chiusura. Verifica nuovamente tra qualche secondo.');
        }
    }
    if (!in_array($status, ['PAID', 'NEW', 'PENDING', 'FAILED', 'EXPIRED'], true)) {
        throw new RuntimeException('SumUp ha restituito uno stato non riconosciuto.');
    }
    $notifications = null;
    $newlyPaid = false;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM gift_vouchers WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $voucher['id']]);
        $locked = $stmt->fetch();
        if (!$locked) {
            throw new RuntimeException('Voucher non trovato.');
        }
        if ($status === 'PAID' && $locked['status'] !== 'paid') {
            $now = new DateTimeImmutable('now');
            $update = $pdo->prepare(
                "UPDATE gift_vouchers SET status = 'paid', voucher_code = :code,
                 remaining_sessions = total_sessions, paid_at = :paid, valid_until = :valid,
                 transaction_id = :transaction WHERE id = :id"
            );
            $update->execute([
                ':code' => 'TTM-' . strtoupper(bin2hex(random_bytes(10))),
                ':paid' => $now->format('Y-m-d H:i:s'),
                ':valid' => voucherValidUntil($now)->format('Y-m-d H:i:s'),
                ':transaction' => $checkout['transactions'][0]['id'] ?? null,
                ':id' => $voucher['id'],
            ]);
            $newlyPaid = true;
        } elseif (in_array($status, ['FAILED', 'EXPIRED'], true) && $locked['status'] === 'pending') {
            $update = $pdo->prepare('UPDATE gift_vouchers SET status = :status WHERE id = :id');
            $update->execute([':status' => strtolower($status), ':id' => $voucher['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $updated = voucherByToken($pdo, $voucher['token']);
    if ($newlyPaid) {
        $notifications = voucherNotify($updated);
    }
    return ['voucher' => $updated, 'notifications' => $notifications];
}

function voucherPublic(array $voucher): array
{
    return array_intersect_key($voucher, array_flip([
        'status', 'recipient_name', 'service_name', 'duration_minutes', 'sessions_per_item',
        'quantity', 'total_sessions', 'remaining_sessions', 'amount', 'voucher_code', 'valid_until', 'studio_name',
    ]));
}
