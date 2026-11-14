<?php
require_once __DIR__ . '/voucher-model.php';

function voucherManualAmount($value): string
{
    if (!is_string($value) || !preg_match('/^\d{1,6}(?:[.,]\d{1,2})?$/', trim($value))) {
        throw new InvalidArgumentException('Inserisci un importo valido, con massimo due decimali.');
    }
    $parts = explode('.', str_replace(',', '.', trim($value)));
    return (int)$parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
}

function voucherManualCreate(PDO $pdo, array $input, DateTimeImmutable $now): array
{
    $token = $input['request_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('Richiesta non valida. Ricarica la pagina e riprova.');
    }
    $names = [];
    foreach (['first_name', 'last_name'] as $field) {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 74
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Inserisci nome e cognome validi.');
        }
        $names[] = trim($value);
    }
    $title = voucherRecipientTitle($input['recipient_title'] ?? null);
    $studioId = filter_var($input['studio_id'] ?? null, FILTER_VALIDATE_INT);
    $serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT);
    $sessions = filter_var($input['sessions_per_item'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$studioId || $studioId < 1 || !$serviceId || $serviceId < 1
        || !in_array($sessions, [1, 3, 6], true) || !$quantity || $quantity < 1 || $quantity > 20) {
        throw new InvalidArgumentException('Seleziona sede, trattamento, formula e quantità validi.');
    }
    $amount = voucherManualAmount($input['amount'] ?? null);
    $recipient = implode(' ', $names);
    $hash = hash('sha256', json_encode([$studioId, $serviceId, $sessions, $quantity, $recipient, $amount, $title], JSON_THROW_ON_ERROR));
    $find = $pdo->prepare('SELECT * FROM gift_vouchers WHERE token = :token');
    $find->execute([':token' => $token]);
    $existing = $find->fetch();
    if ($existing) {
        if ($existing['source'] !== 'manual' || !hash_equals($existing['payload_hash'], $hash)) {
            throw new InvalidArgumentException('Richiesta già utilizzata con dati diversi. Ricarica la pagina.');
        }
        return $existing;
    }
    $stmt = $pdo->prepare(
        'SELECT sv.* FROM servizi sv INNER JOIN studi s ON s.id = sv.id_studio
         WHERE sv.id = :service AND s.id = :studio AND sv.attivo = 1 AND s.attivo = 1'
    );
    $stmt->execute([':service' => $serviceId, ':studio' => $studioId]);
    $service = $stmt->fetch();
    if (!$service) {
        throw new InvalidArgumentException('Seleziona un trattamento attivo della sede scelta.');
    }
    $quote = voucherQuote((string)$service['prezzo'], $sessions, $quantity);
    $code = 'TTM-' . strtoupper(bin2hex(random_bytes(10)));
    $stmt = $pdo->prepare(
        "INSERT INTO gift_vouchers (token, payload_hash, id_studio, id_servizio, service_name, duration_minutes,
         recipient_name, recipient_title, sessions_per_item, quantity, total_sessions, remaining_sessions, amount, customer_data,
         source, status, voucher_code, checkout_reference, checkout_expires_at, paid_at, valid_until)
         VALUES (:token, :hash, :studio, :service, :name, :duration, :recipient, :title, :sessions, :quantity, :total,
         :remaining, :amount, :customer, 'manual', 'paid', :code, :reference, :expires, :paid, :valid)"
    );
    try {
        $stmt->execute([
            ':token' => $token, ':hash' => $hash, ':studio' => $studioId, ':service' => $serviceId,
            ':name' => $service['nome'], ':duration' => $service['durata_minuti'], ':recipient' => $recipient, ':title' => $title,
            ':sessions' => $sessions, ':quantity' => $quantity, ':total' => $quote['total_sessions'],
            ':remaining' => $quote['total_sessions'], ':amount' => $amount, ':customer' => '{}',
            ':code' => $code, ':reference' => 'manual-' . $token, ':expires' => $now->format('Y-m-d H:i:s'),
            ':paid' => $now->format('Y-m-d H:i:s'), ':valid' => voucherValidUntil($now)->format('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
        $find->execute([':token' => $token]);
        $existing = $find->fetch();
        if (!$existing) throw $e;
        if ($existing['source'] !== 'manual' || !hash_equals($existing['payload_hash'], $hash)) {
            throw new InvalidArgumentException('Richiesta già utilizzata con dati diversi. Ricarica la pagina.');
        }
        return $existing;
    }
    return ['voucher_code' => $code];
}
