<?php
require_once dirname(__DIR__) . '/booking-mailer.php';
$checks = 0;
function mailCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$voucher = ['payment_method' => 'voucher', 'voucher_code' => 'TTM-AAAAAAAAAAAAAAAAAAAA',
    'remaining_sessions' => 2, 'price' => 60];
$line = bookingPaymentEmailLine($voucher);
mailCheck(str_contains($line, $voucher['voucher_code']), 'Voucher code missing');
mailCheck(str_contains($line, 'Sedute residue: 2'), 'Remaining sessions missing');
mailCheck(!str_contains($line, '60,00') && !str_contains($line, 'Prezzo:'), 'Voucher email contains service price');
mailCheck(str_contains(bookingPaymentEmailLine(array_replace($voucher, ['remaining_sessions' => 0])), 'Sedute residue: 0'), 'Zero remaining sessions missing');
foreach ([['voucher_code' => null], ['remaining_sessions' => null], ['remaining_sessions' => -1]] as $change) {
    try { bookingPaymentEmailLine(array_replace($voucher, $change)); throw new RuntimeException('Missing voucher data accepted'); }
    catch (RuntimeException $e) { mailCheck(str_contains($e->getMessage(), 'Dati voucher mancanti'), 'Wrong error'); }
}
mailCheck(str_contains(bookingPaymentEmailLine(['payment_method' => 'studio', 'price' => 60]), 'Prezzo: 60,00 EUR'), 'Normal service price missing');
mailCheck(str_contains(bookingPaymentEmailLine(['payment_method' => 'studio', 'price' => 60], true), 'Prezzo indicativo: 60,00 EUR'), 'Indicative price missing');
$notice = bookingVoucherCancellationNotice($voucher);
mailCheck(str_contains($notice, '3 ore') && str_contains($notice, 'https://www.thaitikmassage.it/annulla-voucher.php'), 'Cancellation notice missing');
[$multipart, $headers] = bookingCustomerEmailContent($notice, ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'], $voucher);
mailCheck(str_contains(implode("\n", $headers), 'multipart/alternative') && str_contains($multipart, 'Annulla appuntamento'), 'Cancellation button missing');
mailCheck(!str_contains($multipart, $voucher['voucher_code']), 'Voucher code exposed in cancellation link');
mailCheck(bookingVoucherCancellationNotice(['payment_method' => 'studio']) === '', 'Cancellation notice shown for non-voucher');
echo "$checks booking email checks passed.\n";
