<?php
require_once dirname(__DIR__) . '/booking-voucher.php';
date_default_timezone_set('Europe/Rome');
$checks = 0;

function bookingVoucherCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function bookingVoucherReject(callable $operation): void
{
    try {
        $operation();
        throw new RuntimeException('Invalid voucher request accepted');
    } catch (InvalidArgumentException $e) {
        bookingVoucherCheck($e->getMessage() !== '', 'Missing validation message');
    }
}

class VoucherTestStatement extends PDOStatement
{
    private array $step;
    public function __construct(array $step) { $this->step = $step; }
    public function execute(?array $params = null): bool
    {
        bookingVoucherCheck($params === $this->step['params'], 'Unexpected SQL parameters');
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->step['result'] ?? false;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->step['result'] ?? false; }
    public function rowCount(): int { return $this->step['count'] ?? 1; }
}

class VoucherTestPDO extends PDO
{
    public array $steps;
    public bool $transaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public function __construct(array $steps) { $this->steps = $steps; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $step = array_shift($this->steps);
        bookingVoucherCheck($step !== null && str_contains($query, $step['sql']), 'Unexpected SQL: ' . $query);
        return new VoucherTestStatement($step);
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction = false; $this->commits++; return true; }
    public function rollBack(): bool { $this->transaction = false; $this->rollbacks++; return true; }
}

function voucherSql(string $sql, array $params, $result = false, int $count = 1): array
{
    return compact('sql', 'params', 'result', 'count');
}

$code = 'TTM-' . str_repeat('A', 20);
bookingVoucherCheck(bookingEmailValid('', 'voucher'), 'Voucher booking must allow no email');
bookingVoucherCheck(!bookingEmailValid('', 'studio') && !bookingEmailValid('', 'online'), 'Non-voucher booking must require email');
bookingVoucherCheck(!bookingEmailValid('invalid', 'voucher'), 'Invalid optional email accepted');
bookingVoucherCheck(bookingEmailValid('mario@example.com', 'voucher'), 'Valid email rejected');
bookingVoucherCheck(bookingVoucherCode('  ' . strtolower($code) . ' ') === $code, 'Code normalization');
foreach (['', 'TTM-TEST', 'TTM-' . str_repeat('G', 20), [], null] as $invalid) {
    bookingVoucherReject(fn() => bookingVoucherCode($invalid));
}
$now = new DateTimeImmutable('2026-10-14 12:00:00');
$appointment = new DateTimeImmutable('2026-10-15 14:00:00');
$voucher = ['id' => 1, 'voucher_code' => $code, 'id_studio' => 2, 'id_servizio' => 3,
    'duration_minutes' => 60, 'status' => 'paid', 'remaining_sessions' => 1,
    'valid_until' => '2027-10-14 12:00:00'];
$emptyBilling = ['customer_type' => 'privato', 'ragione_sociale' => '', 'codice_fiscale' => '',
    'partita_iva' => '', 'indirizzo' => '', 'citta' => '', 'cap' => '', 'codice_destinatario' => '', 'pec' => ''];
bookingVoucherCheck(!bookingVoucherRequiresBilling(['source' => 'manual']), 'Manual voucher requires billing');
bookingVoucherCheck(bookingVoucherRequiresBilling(['source' => 'online']), 'Online voucher bypasses billing');
bookingVoucherCheck(bookingVoucherRequiresBilling([]), 'Missing source must require billing');
bookingBillingValidate($emptyBilling, bookingVoucherRequiresBilling(['source' => 'manual']));
bookingVoucherCheck(true, 'Manual voucher rejected without billing data');
bookingVoucherReject(fn() => bookingBillingValidate($emptyBilling, bookingVoucherRequiresBilling(['source' => 'online'])));
bookingVoucherReject(fn() => bookingBillingValidate($emptyBilling, true));
$privateBilling = array_replace($emptyBilling, ['indirizzo' => 'Via Roma', 'citta' => 'Roma', 'cap' => '00100', 'codice_fiscale' => 'RSSMRA80A01H501U']);
bookingBillingValidate($privateBilling, true);
bookingVoucherCheck(true, 'Valid private billing rejected');
bookingVoucherReject(fn() => bookingBillingValidate(array_replace($privateBilling, ['codice_fiscale' => '']), true));
$companyBilling = array_replace($privateBilling, ['customer_type' => 'azienda', 'ragione_sociale' => 'Impresa', 'partita_iva' => '12345678901', 'pec' => 'test@example.com']);
bookingBillingValidate($companyBilling, true);
bookingVoucherCheck(true, 'Valid company billing rejected');
bookingVoucherReject(fn() => bookingBillingValidate(array_replace($companyBilling, ['pec' => '']), true));
bookingVoucherValidate($voucher, 2, 3, 60, $appointment, $now);
bookingVoucherCheck(true, 'Valid last session rejected');
foreach ([['status' => 'pending'], ['status' => 'failed'], ['status' => 'expired'],
    ['remaining_sessions' => 0], ['valid_until' => null], ['valid_until' => '2026-10-14 12:00:00'],
    ['valid_until' => '2026-10-15 14:00:00'], ['id_studio' => 9], ['id_servizio' => 9],
    ['duration_minutes' => 90]] as $change) {
    bookingVoucherReject(fn() => bookingVoucherValidate(array_replace($voucher, $change), 2, 3, 60, $appointment, $now));
}
bookingVoucherReject(fn() => bookingVoucherValidate(array_replace($voucher, ['disabled_at' => '2026-10-14 12:00:00']), 2, 3, 60, $appointment, $now));
$pdo = new VoucherTestPDO([voucherSql('voucher_code = :code FOR UPDATE', [':code' => $code], $voucher)]);
bookingVoucherCheck(bookingVoucherByCode($pdo, $code, true)['id'] === 1, 'Locked voucher lookup');
$pdo = new VoucherTestPDO([voucherSql('voucher_code = :code', [':code' => $code])]);
bookingVoucherReject(fn() => bookingVoucherByCode($pdo, $code));

$token = str_repeat('b', 64);
$hash = str_repeat('c', 64);
$previous = ['id' => 8, 'voucher_id' => 1, 'appointment_id' => 42, 'payload_hash' => $hash, 'restored_at' => null];
$pdo = new VoucherTestPDO([voucherSql('request_token = :token', [':token' => $token], $previous)]);
bookingVoucherCheck(bookingVoucherPrevious($pdo, $token, $hash, 1)['appointment_id'] === 42, 'Duplicate booking not recovered');
bookingVoucherCheck($pdo->steps === [], 'Duplicate booking unexpectedly debited');
foreach ([['voucher_id' => 2], ['payload_hash' => 'other'], ['restored_at' => '2026-10-14 13:00:00']] as $change) {
    $pdo = new VoucherTestPDO([voucherSql('request_token = :token', [':token' => $token], array_replace($previous, $change))]);
    bookingVoucherReject(fn() => bookingVoucherPrevious($pdo, $token, $hash, 1));
}
$pdo = new VoucherTestPDO([voucherSql('request_token = :token', [':token' => $token])]);
bookingVoucherCheck(bookingVoucherPrevious($pdo, $token, $hash, 1) === null, 'New request treated as duplicate');

$debitSteps = [
    voucherSql('INSERT INTO gift_voucher_bookings', [':voucher' => 1, ':appointment' => 42, ':token' => $token, ':hash' => $hash]),
    voucherSql('remaining_sessions - 1 WHERE id = :id AND remaining_sessions > 0', [':id' => 1]),
];
$pdo = new VoucherTestPDO($debitSteps);
$pdo->beginTransaction();
bookingVoucherDebit($pdo, 1, 42, $token, $hash);
bookingVoucherCheck($pdo->steps === [] && $pdo->inTransaction(), 'Debit must belong to appointment transaction');
$debitSteps[1]['count'] = 0;
$pdo = new VoucherTestPDO($debitSteps);
$pdo->beginTransaction();
bookingVoucherReject(fn() => bookingVoucherDebit($pdo, 1, 42, $token, $hash));
$pdo = new VoucherTestPDO([]);
try {
    bookingVoucherDebit($pdo, 1, 42, $token, $hash);
    throw new RuntimeException('Debit outside transaction accepted');
} catch (LogicException $e) {
    bookingVoucherCheck(true, 'Transaction guard');
}

$booking = ['id' => 42, 'id_studio' => 2, 'metodo_pagamento' => 'voucher', 'stato' => 'in_attesa'];
$use = ['id' => 8, 'voucher_id' => 1, 'restored_at' => null];
$cancelStart = [
    voucherSql('SELECT id_studio FROM appuntamenti', [':id' => 42], 2),
    voucherSql('SELECT id FROM studi WHERE id = :id FOR UPDATE', [':id' => 2], 2),
    voucherSql('SELECT * FROM appuntamenti WHERE id = :id FOR UPDATE', [':id' => 42], $booking),
];
$cancelEnd = voucherSql("SET stato = 'annullato'", [':id' => 42]);
$pdo = new VoucherTestPDO(array_merge($cancelStart, [
    voucherSql('SELECT * FROM gift_voucher_bookings WHERE appointment_id = :id FOR UPDATE', [':id' => 42], $use),
    voucherSql('remaining_sessions + 1 WHERE id = :id AND remaining_sessions < total_sessions', [':id' => 1]),
    voucherSql('SET restored_at = NOW()', [':id' => 8]), $cancelEnd,
]));
bookingVoucherCancel($pdo, 42);
bookingVoucherCheck($pdo->steps === [] && $pdo->commits === 1 && $pdo->rollbacks === 0, 'Voucher cancellation not atomic');

$cancelStart[2]['result']['stato'] = 'annullato';
$pdo = new VoucherTestPDO(array_merge($cancelStart, [$cancelEnd]));
bookingVoucherCancel($pdo, 42);
bookingVoucherCheck($pdo->steps === [] && $pdo->commits === 1, 'Repeated cancellation restored twice');
$cancelStart[2]['result'] = array_replace($booking, ['metodo_pagamento' => 'studio']);
$pdo = new VoucherTestPDO(array_merge($cancelStart, [$cancelEnd]));
bookingVoucherCancel($pdo, 42);
bookingVoucherCheck($pdo->steps === [] && $pdo->commits === 1, 'Ordinary cancellation accessed voucher tables');
$cancelStart[2]['result'] = array_replace($booking, ['metodo_pagamento' => 'online']);
$pdo = new VoucherTestPDO(array_merge($cancelStart, [$cancelEnd]));
bookingVoucherCancel($pdo, 42);
bookingVoucherCheck($pdo->steps === [] && $pdo->commits === 1, 'Online cancellation accessed voucher tables');
$cancelStart[2]['result'] = $booking;
$future = new DateTimeImmutable('+4 hours');
$customerBooking = array_replace($booking, ['data_appuntamento' => $future->format('Y-m-d'), 'ora_inizio' => $future->format('H:i:s')]);
$customerStart = $cancelStart;
$customerStart[2]['result'] = $customerBooking;
$customerSteps = [
    voucherSql('SELECT * FROM gift_voucher_bookings WHERE appointment_id = :id FOR UPDATE', [':id' => 42], $use),
    voucherSql('WHERE id = :id AND voucher_code = :code', [':id' => 1, ':code' => $code], 1),
    voucherSql('remaining_sessions + 1 WHERE id = :id AND remaining_sessions < total_sessions', [':id' => 1]),
    voucherSql('SET restored_at = NOW()', [':id' => 8]), $cancelEnd,
];
$pdo = new VoucherTestPDO(array_merge($customerStart, $customerSteps));
bookingVoucherCancel($pdo, 42, $code);
bookingVoucherCheck($pdo->commits === 1 && $pdo->steps === [], 'Customer cancellation failed');
$customerSteps[1]['result'] = false;
$pdo = new VoucherTestPDO(array_merge($customerStart, array_slice($customerSteps, 0, 2)));
bookingVoucherReject(fn() => bookingVoucherCancel($pdo, 42, $code));
bookingVoucherCheck($pdo->rollbacks === 1 && $pdo->commits === 0, 'Wrong voucher canceled appointment');
$customerStart[2]['result'] = array_replace($customerBooking, ['stato' => 'annullato']);
$pdo = new VoucherTestPDO($customerStart);
bookingVoucherReject(fn() => bookingVoucherCancel($pdo, 42, $code));
bookingVoucherCheck($pdo->rollbacks === 1, 'Canceled booking restored again');
$late = new DateTimeImmutable('+2 hours');
$customerStart[2]['result'] = array_replace($customerBooking, ['data_appuntamento' => $late->format('Y-m-d'), 'ora_inizio' => $late->format('H:i:s')]);
$pdo = new VoucherTestPDO($customerStart);
bookingVoucherReject(fn() => bookingVoucherCancel($pdo, 42, $code));
bookingVoucherCheck($pdo->rollbacks === 1, 'Late cancellation was not rolled back');
$start = new DateTimeImmutable('2027-01-15 12:00:00');
bookingVoucherCheck(bookingVoucherCancellationAllowed($start, new DateTimeImmutable('2027-01-15 09:00:00')), 'Three-hour boundary rejected');
bookingVoucherCheck(!bookingVoucherCancellationAllowed($start, new DateTimeImmutable('2027-01-15 09:00:01')), 'Less than three hours accepted');
bookingVoucherCheck(bookingVoucherCancellationAllowed($start, new DateTimeImmutable('2027-01-15 08:59:59')), 'More than three hours rejected');
$pdo = new VoucherTestPDO(array_merge($cancelStart, [
    voucherSql('SELECT * FROM gift_voucher_bookings', [':id' => 42], array_replace($use, ['restored_at' => '2026-10-14 13:00:00'])),
    $cancelEnd,
]));
bookingVoucherCancel($pdo, 42);
bookingVoucherCheck($pdo->steps === [] && $pdo->commits === 1, 'Restored use credited a second time');
$pdo = new VoucherTestPDO(array_merge($cancelStart, [voucherSql('SELECT * FROM gift_voucher_bookings', [':id' => 42])]));
try {
    bookingVoucherCancel($pdo, 42);
    throw new RuntimeException('Missing voucher link accepted');
} catch (RuntimeException $e) {
    bookingVoucherCheck($pdo->rollbacks === 1 && $pdo->commits === 0, 'Missing voucher link did not roll back');
}
$pdo = new VoucherTestPDO(array_merge($cancelStart, [
    voucherSql('SELECT * FROM gift_voucher_bookings', [':id' => 42], $use),
    voucherSql('remaining_sessions + 1', [':id' => 1], false, 0),
]));
try {
    bookingVoucherCancel($pdo, 42);
    throw new RuntimeException('Inconsistent counter accepted');
} catch (RuntimeException $e) {
    bookingVoucherCheck($pdo->rollbacks === 1 && $pdo->commits === 0 && !$pdo->inTransaction(), 'Failed restore did not roll back');
}
echo "$checks booking voucher checks passed (scripted PDO, not live MySQL).\n";
