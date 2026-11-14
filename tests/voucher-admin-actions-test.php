<?php
require_once dirname(__DIR__) . '/voucher-admin-actions.php';
require_once dirname(__DIR__) . '/voucher-model.php';
$checks = 0;
function checkAdminVoucher(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

class VoucherAdminStatement extends PDOStatement
{
    public function __construct(private VoucherAdminPDO $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->sql, 'DELETE')) $this->db->operation = 'delete';
        if (str_starts_with($this->sql, 'UPDATE')) $this->db->operation = 'disable';
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->voucher;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->db->history; }
}
class VoucherAdminPDO extends PDO
{
    public bool $transaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public string $operation = '';
    public function __construct(public $voucher, public int $history = 0) {}
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction = false; $this->committed = true; return true; }
    public function rollBack(): bool { $this->transaction = false; $this->rolledBack = true; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new VoucherAdminStatement($this, $query);
    }
}
foreach ([
    ['manual', 0, 'delete'],
    ['manual', 1, 'disable'],
    ['online', 0, 'disable'],
    ['online', 1, 'disable'],
] as [$source, $history, $expected]) {
    $db = new VoucherAdminPDO(['source' => $source, 'status' => 'paid'], $history);
    $message = voucherRemoveOrDisable($db, 1);
    checkAdminVoucher($db->operation === $expected && $db->committed && !$db->rolledBack && $message !== '', 'Wrong removal behavior');
}
foreach ([false, ['source' => 'online', 'status' => 'pending']] as $voucher) {
    $db = new VoucherAdminPDO($voucher);
    try { voucherRemoveOrDisable($db, 1); throw new RuntimeException('Invalid removal accepted'); }
    catch (InvalidArgumentException $e) { checkAdminVoucher($db->rolledBack && !$db->committed && $db->operation === '', 'Removal did not roll back'); }
}
$voucher = ['status' => 'paid', 'remaining_sessions' => 1, 'valid_until' => '2030-01-01 00:00:00'];
checkAdminVoucher(voucherCanUse($voucher, new DateTimeImmutable('2029-01-01')), 'Active voucher rejected');
checkAdminVoucher(!voucherCanUse(array_replace($voucher, ['disabled_at' => '2028-01-01 00:00:00']), new DateTimeImmutable('2029-01-01')), 'Disabled voucher accepted');
echo "$checks voucher removal checks passed.\n";
