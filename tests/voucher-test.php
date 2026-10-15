<?php
require_once dirname(__DIR__) . '/voucher-model.php';
require_once dirname(__DIR__) . '/voucher-pdf.php';
date_default_timezone_set('Europe/Rome');
$checks = 0;
function voucherCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
foreach ([35, 50, 60, 70, 80, 100] as $price) {
    foreach ([1, 3, 6] as $sessions) {
        foreach ([1, 2, 20] as $quantity) {
            $quote = voucherQuote(number_format($price, 2, '.', ''), $sessions, $quantity);
            $unit = $sessions === 1 ? $price : ceil($price * ($sessions === 3 ? .95 : .90));
            $package = $unit * $sessions;
            $package = $sessions === 1 ? $package : ($package == 171 ? 170 : ceil($package / 5) * 5);
            voucherCheck($quote['total_cents'] === (int)($package * 100 * $quantity), 'Incorrect package price');
            voucherCheck($quote['total_sessions'] === $sessions * $quantity, 'Incorrect session count');
        }
    }
}
voucherCheck(voucherQuote('60', 3, 1)['total_cents'] === 17000, '171 euro package must be rounded down to 170');
voucherCheck(voucherQuote('60', 6, 1)['total_cents'] === 32500, '324 euro package must be rounded up to 325');
foreach ([['0', 1, 1], ['-1', 1, 1], ['60', 5, 1], ['60', 10, 1], ['60', 3, 0], ['60', 3, 21], ['60.999', 1, 1]] as $invalid) {
    try { voucherQuote(...$invalid); throw new RuntimeException('Invalid quote accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
voucherCheck(voucherValidUntil(new DateTimeImmutable('2028-02-29 14:30:00'))->format('Y-m-d H:i:s') === '2029-02-28 14:30:00', 'Leap day validity');
$v = ['status' => 'paid', 'remaining_sessions' => 1, 'valid_until' => '2027-10-13 12:00:00'];
voucherCheck(voucherCanUse($v, new DateTimeImmutable('2027-10-13 11:59:59')), 'Last valid second');
voucherCheck(!voucherCanUse($v, new DateTimeImmutable('2027-10-13 12:00:00')), 'Expired voucher accepted');
voucherCheck(!voucherCanUse(array_replace($v, ['remaining_sessions' => 0]), new DateTimeImmutable('2027-10-12')), 'Exhausted voucher accepted');
voucherCheck(!voucherCanUse(array_replace($v, ['status' => 'pending']), new DateTimeImmutable('2027-10-12')), 'Unpaid voucher accepted');
$v = ['checkout_reference' => 'gift-test', 'amount' => '335.00'];
$checkout = ['checkout_reference' => 'gift-test', 'amount' => 335, 'currency' => 'EUR'];
voucherCheck(voucherPaymentMatches($v, $checkout, 'EUR'), 'Correct checkout rejected');
foreach ([['amount' => 334], ['currency' => 'USD'], ['checkout_reference' => 'other']] as $change) {
    voucherCheck(!voucherPaymentMatches($v, array_replace($checkout, $change), 'EUR'), 'Mismatched payment accepted');
}
$buyer = ['name' => 'Mario Rossi', 'email' => 'mario@example.com', 'customer_type' => 'privato',
    'codice_fiscale' => 'RSSMRA80A01H501U', 'indirizzo' => 'Via Roma 1', 'citta' => 'Roma', 'cap' => '00100'];
voucherCheck(voucherCustomer($buyer)['name'] === 'Mario Rossi', 'Customer validation');
foreach ([['email' => 'invalid'], ['codice_fiscale' => ''], ['name' => ['not a string']], ['customer_type' => 'azienda']] as $change) {
    try { voucherCustomer(array_replace($buyer, $change)); throw new RuntimeException('Invalid customer accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
$document = ['recipient_name' => 'Anna Rossi', 'service_name' => 'Thai tradizionale',
    'duration_minutes' => 60, 'sessions_per_item' => 5, 'quantity' => 2,
    'total_sessions' => 10, 'amount' => '570.00', 'voucher_code' => 'TTM-TEST', 'valid_until' => '2027-10-13 12:00:00'];
$preview = voucherPdf($document, true);
$paid = voucherPdf($document, false);
voucherCheck(strpos($preview, 'ANTEPRIMA - NON VALIDO / NON PAGATO') !== false, 'Preview lacks watermark');
voucherCheck(strpos($paid, 'TTM-TEST') !== false && strpos($paid, 'NON PAGATO') === false, 'Paid PDF invalid');
voucherCheck(strpos($paid, '%PDF-1.4') === 0 && substr($paid, -6) === "%%EOF\n", 'Invalid PDF structure');
echo "$checks voucher checks passed.\n";
