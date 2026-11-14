<?php
require_once dirname(__DIR__) . '/voucher-manual.php';
require_once dirname(__DIR__) . '/booking-voucher.php';
require_once dirname(__DIR__) . '/voucher-pdf.php';
date_default_timezone_set('Europe/Rome');

$checks = 0;
function manualCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

class ManualVoucherStatement extends PDOStatement
{
    private ManualVoucherPDO $db;
    private string $query;
    private $result = false;
    public function __construct(ManualVoucherPDO $db, string $query) { $this->db = $db; $this->query = $query; }
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->query, 'WHERE token = :token')) {
            $this->result = $this->db->vouchers[$params[':token']] ?? false;
        } elseif (str_contains($this->query, 'FROM servizi')) {
            $this->result = $params[':service'] === 3 && $params[':studio'] === 2
                ? ['nome' => 'Thai', 'durata_minuti' => 60, 'prezzo' => '60.00'] : false;
        } elseif (str_contains($this->query, 'INSERT INTO gift_vouchers')) {
            $this->db->insert = $params;
            $this->db->vouchers[$params[':token']] = [
                'source' => 'manual', 'payload_hash' => $params[':hash'], 'voucher_code' => $params[':code'],
            ];
        } else {
            throw new RuntimeException('Unexpected SQL: ' . $this->query);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result;
    }
}

class ManualVoucherPDO extends PDO
{
    public array $vouchers = [];
    public array $insert = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ManualVoucherStatement($this, $query);
    }
}

function manualReject(callable $fn): void
{
    try { $fn(); throw new RuntimeException('Invalid request accepted'); }
    catch (InvalidArgumentException $e) { manualCheck($e->getMessage() !== '', 'Missing error message'); }
}

$input = ['request_token' => str_repeat('a', 64), 'first_name' => 'Anna', 'last_name' => 'Rossi', 'recipient_title' => 'sigra',
    'studio_id' => '2', 'service_id' => '3', 'sessions_per_item' => '3', 'quantity' => '2', 'amount' => '155,50'];
$pdo = new ManualVoucherPDO();
$now = new DateTimeImmutable('2028-02-29 12:00:00');
$created = voucherManualCreate($pdo, $input, $now);
manualCheck((bool)preg_match('/^TTM-[A-F0-9]{20}$/', $created['voucher_code']), 'Invalid booking code');
manualCheck($pdo->insert[':recipient'] === 'Anna Rossi' && $pdo->insert[':customer'] === '{}', 'Incorrect recipient or customer data');
manualCheck($pdo->insert[':title'] === 'sigra', 'Recipient title was not saved');
manualCheck($pdo->insert[':amount'] === '155.50' && $pdo->insert[':total'] === 6 && $pdo->insert[':remaining'] === 6, 'Price or sessions incorrect');
manualCheck($pdo->insert[':valid'] === '2029-02-28 12:00:00', 'Incorrect validity');
manualCheck(voucherManualCreate($pdo, $input, $now)['voucher_code'] === $created['voucher_code'], 'Retry issued another voucher');
manualReject(fn() => voucherManualCreate($pdo, array_replace($input, ['amount' => '100']), $now));
manualReject(fn() => voucherManualCreate($pdo, array_replace($input, ['recipient_title' => 'sig']), $now));
foreach ([
    ['first_name' => ''], ['last_name' => []], ['studio_id' => '9'], ['service_id' => '9'],
    ['sessions_per_item' => '5'], ['quantity' => '21'], ['amount' => '-1'], ['amount' => '1.999'],
    ['request_token' => 'invalid'], ['recipient_title' => ''], ['recipient_title' => []],
] as $change) {
    $invalid = array_replace($input, ['request_token' => str_repeat('b', 64)], $change);
    manualReject(fn() => voucherManualCreate(new ManualVoucherPDO(), $invalid, $now));
}
manualCheck(voucherManualAmount('0') === '0.00' && voucherManualAmount('00012,5') === '12.50', 'Amount normalization');

$voucher = ['id_studio' => 2, 'id_servizio' => 3, 'duration_minutes' => 60, 'status' => 'paid',
    'remaining_sessions' => 6, 'valid_until' => $pdo->insert[':valid']];
bookingVoucherValidate($voucher, 2, 3, 60, $now->modify('+1 day'), $now);
manualCheck(true, 'Manual voucher cannot be used for a booking');
manualReject(fn() => bookingVoucherValidate($voucher, 9, 3, 60, $now->modify('+1 day'), $now));
manualReject(fn() => bookingVoucherValidate(array_replace($voucher, ['remaining_sessions' => 0]), 2, 3, 60, $now->modify('+1 day'), $now));

$document = ['source' => 'manual', 'recipient_name' => 'Anna Rossi', 'service_name' => 'Thai',
    'studio_address' => 'Via Giuseppe Gioachino Belli 1', 'studio_city' => 'Roma', 'studio_postal_code' => '00193',
    'studio_name' => 'Studio Prati', 'duration_minutes' => 60, 'sessions_per_item' => 3, 'quantity' => 2,
    'total_sessions' => 6, 'amount' => '155.50', 'voucher_code' => $created['voucher_code'], 'valid_until' => $pdo->insert[':valid']];
$manualPdf = voucherPdf($document, false);
manualCheck(str_contains($manualPdf, $created['voucher_code']) && str_contains($manualPdf, 'Anna Rossi'), 'Manual PDF missing identity');
manualCheck(!str_contains($manualPdf, 'Importo totale') && !str_contains($manualPdf, '155,50'), 'Manual PDF exposes amount');
manualCheck(str_contains($manualPdf, 'Via Giuseppe Gioachino Belli 1, 00193 Roma'), 'Manual PDF missing full address');
manualCheck(str_contains($manualPdf, 'Studio Prati - C/O Barbiere Prati lounge di estetica'), 'Manual PDF missing Prati venue name');
manualCheck(str_contains(voucherPdf(array_replace($document, ['source' => 'online']), false), 'Importo totale: 155,50 EUR'), 'Online PDF changed');
echo "$checks manual voucher checks passed.\n";
