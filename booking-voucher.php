<?php
require_once __DIR__ . '/voucher-model.php';

function bookingEmailValid(string $email, string $paymentMethod): bool
{
    return $email === '' ? $paymentMethod === 'voucher' : filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bookingBillingValidate(array $billing, bool $billingRequired): void
{
    if (!$billingRequired) return;
    if (!in_array($billing['customer_type'], ['privato', 'azienda'], true)
        || $billing['indirizzo'] === '' || $billing['citta'] === '' || $billing['cap'] === '') {
        throw new InvalidArgumentException('Inserisci i dati fiscali e l’indirizzo di fatturazione.');
    }
    if ($billing['customer_type'] === 'privato' && $billing['codice_fiscale'] === '') {
        throw new InvalidArgumentException('Il codice fiscale è obbligatorio per il cliente privato.');
    }
    if ($billing['customer_type'] === 'azienda' && ($billing['ragione_sociale'] === ''
        || $billing['partita_iva'] === '' || ($billing['codice_destinatario'] === '' && $billing['pec'] === ''))) {
        throw new InvalidArgumentException('Per aziende e professionisti sono necessari ragione sociale, partita IVA e codice destinatario o PEC.');
    }
}

function bookingVoucherRequiresBilling(array $voucher): bool
{
    return ($voucher['source'] ?? 'online') !== 'manual';
}

function bookingVoucherCode($value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Inserisci un codice voucher valido.');
    }
    $code = strtoupper(trim($value));
    if (!preg_match('/^TTM-[A-F0-9]{20}$/', $code)) {
        throw new InvalidArgumentException('Inserisci il codice completo riportato sul voucher.');
    }
    return $code;
}

function bookingVoucherByCode(PDO $pdo, string $code, bool $lock = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM gift_vouchers WHERE voucher_code = :code' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([':code' => $code]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        throw new InvalidArgumentException('Codice voucher non riconosciuto.');
    }
    return $voucher;
}

function bookingVoucherValidate(array $voucher, int $studioId, int $serviceId, int $durationMinutes, DateTimeImmutable $appointment, DateTimeImmutable $now): void
{
    if ((int)$voucher['id_studio'] !== $studioId || (int)$voucher['id_servizio'] !== $serviceId
        || (int)$voucher['duration_minutes'] !== $durationMinutes) {
        throw new InvalidArgumentException('Il voucher non corrisponde allo studio e al trattamento selezionati. Seleziona anche la durata indicata sul voucher.');
    }
    if (!voucherCanUse($voucher, $now)) {
        throw new InvalidArgumentException('Voucher non utilizzabile: disattivato, pagamento non confermato, scadenza superata o sedute esaurite.');
    }
    if ($appointment >= new DateTimeImmutable($voucher['valid_until'])) {
        throw new InvalidArgumentException('L’appuntamento deve essere precedente alla scadenza del voucher.');
    }
}

function bookingVoucherPrevious(PDO $pdo, string $token, string $payloadHash, int $voucherId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM gift_voucher_bookings WHERE request_token = :token');
    $stmt->execute([':token' => $token]);
    $previous = $stmt->fetch();
    if (!$previous) return null;
    if ((int)$previous['voucher_id'] !== $voucherId || !hash_equals($previous['payload_hash'], $payloadHash)) {
        throw new InvalidArgumentException('Richiesta già utilizzata per una prenotazione diversa. Ricarica la pagina prima di inviarne una nuova.');
    }
    if ($previous['restored_at'] !== null) {
        throw new InvalidArgumentException('Questa prenotazione è stata annullata. Ricarica la pagina per prenotare un nuovo appuntamento.');
    }
    return $previous;
}

function bookingVoucherDebit(PDO $pdo, int $voucherId, int $bookingId, string $token, string $payloadHash): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Voucher debit requires a booking transaction.');
    $stmt = $pdo->prepare(
        'INSERT INTO gift_voucher_bookings (voucher_id, appointment_id, request_token, payload_hash)
         VALUES (:voucher, :appointment, :token, :hash)'
    );
    $stmt->execute([':voucher' => $voucherId, ':appointment' => $bookingId, ':token' => $token, ':hash' => $payloadHash]);
    $stmt = $pdo->prepare('UPDATE gift_vouchers SET remaining_sessions = remaining_sessions - 1 WHERE id = :id AND remaining_sessions > 0');
    $stmt->execute([':id' => $voucherId]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Le sedute del voucher sono esaurite.');
}

function bookingVoucherCancellationAllowed(DateTimeImmutable $appointment, DateTimeImmutable $now): bool
{
    return $now <= $appointment->modify('-3 hours');
}

function bookingVoucherCancel(PDO $pdo, int $bookingId, ?string $customerCode = null): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id_studio FROM appuntamenti WHERE id = :id');
        $stmt->execute([':id' => $bookingId]);
        $studioId = $stmt->fetchColumn();
        if ($studioId === false) throw new InvalidArgumentException('Appuntamento non trovato.');
        $stmt = $pdo->prepare('SELECT id FROM studi WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $studioId]);
        $stmt = $pdo->prepare('SELECT * FROM appuntamenti WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch();
        if (!$booking) throw new InvalidArgumentException('Appuntamento non trovato.');
        if ($customerCode !== null) {
            if ($booking['metodo_pagamento'] !== 'voucher' || !in_array($booking['stato'], ['in_attesa', 'confermato'], true)) {
                throw new InvalidArgumentException('Prenotazione non disponibile per l’annullamento.');
            }
            $start = new DateTimeImmutable($booking['data_appuntamento'] . ' ' . $booking['ora_inizio']);
            if (!bookingVoucherCancellationAllowed($start, new DateTimeImmutable('now'))) {
                throw new InvalidArgumentException('Il termine per l’annullamento online è scaduto. Contatta lo studio.');
            }
        }
        if ($booking['stato'] !== 'annullato' && $booking['metodo_pagamento'] === 'voucher') {
            $stmt = $pdo->prepare('SELECT * FROM gift_voucher_bookings WHERE appointment_id = :id FOR UPDATE');
            $stmt->execute([':id' => $bookingId]);
            $use = $stmt->fetch();
            if (!$use) throw new RuntimeException('Collegamento voucher mancante: annullamento non completato.');
            if ($customerCode !== null) {
                $stmt = $pdo->prepare('SELECT id FROM gift_vouchers WHERE id = :id AND voucher_code = :code');
                $stmt->execute([':id' => $use['voucher_id'], ':code' => bookingVoucherCode($customerCode)]);
                if ($stmt->fetchColumn() === false || $use['restored_at'] !== null) {
                    throw new InvalidArgumentException('Prenotazione non disponibile per questo voucher.');
                }
            }
            if ($use['restored_at'] === null) {
                $stmt = $pdo->prepare('UPDATE gift_vouchers SET remaining_sessions = remaining_sessions + 1 WHERE id = :id AND remaining_sessions < total_sessions');
                $stmt->execute([':id' => $use['voucher_id']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Contatore voucher incoerente: annullamento non completato.');
                $stmt = $pdo->prepare('UPDATE gift_voucher_bookings SET restored_at = NOW() WHERE id = :id');
                $stmt->execute([':id' => $use['id']]);
            }
        }
        $stmt = $pdo->prepare("UPDATE appuntamenti SET stato = 'annullato' WHERE id = :id");
        $stmt->execute([':id' => $bookingId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
