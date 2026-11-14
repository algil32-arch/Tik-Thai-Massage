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
$document = ['recipient_name' => 'Anna Rossi', 'recipient_title' => 'sigra', 'service_name' => 'Thai tradizionale',
    'studio_address' => 'Via Valle Borbera 51', 'studio_city' => 'Roma', 'studio_postal_code' => '00141',
    'studio_name' => 'APS Studio Montesacro', 'duration_minutes' => 60, 'sessions_per_item' => 5, 'quantity' => 2,
    'total_sessions' => 10, 'amount' => '570.00', 'voucher_code' => 'TTM-TEST', 'valid_until' => '2027-10-13 12:00:00'];
$preview = voucherPdf($document, true);
$paid = voucherPdf($document, false);
voucherCheck(strpos($preview, 'ANTEPRIMA - NON VALIDO / NON PAGATO') !== false, 'Preview lacks watermark');
voucherCheck(strpos($paid, 'TTM-TEST') !== false && strpos($paid, 'NON PAGATO') === false, 'Paid PDF invalid');
voucherCheck(strpos($paid, 'Sig.ra Anna Rossi') !== false && strpos($paid, 'Thai tradizionale') !== false, 'Personalized gift message missing');
voucherCheck(strpos(voucherPdf(array_replace($document, ['recipient_title' => 'sig']), false), 'Sig. Anna Rossi') !== false, 'Male title missing');
$oldPdf = voucherPdf(array_replace($document, ['recipient_title' => null]), false);
voucherCheck(strpos($oldPdf, 'Per Anna Rossi') !== false && strpos($oldPdf, 'Sig.') === false, 'Existing voucher must use neutral greeting');
voucherCheck(voucherStudioName('Studio Prati') === 'Studio Prati - C/O Barbiere Prati lounge di estetica', 'Studio Prati location missing');
foreach (['', 'other', 1, null] as $invalidTitle) {
    try { voucherRecipientTitle($invalidTitle); throw new RuntimeException('Invalid title accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
voucherCheck(strpos($paid, '"Thai tradizionale"') !== false, 'Massage name must be quoted in the PDF');
voucherCheck(strpos($paid, 'massaggiatrice certificata Thailandese.') !== false, 'Thailandese must be capitalized in the PDF');
voucherCheck(strpos($paid, 'Siamo certi che per lei') !== false, 'Gift message conclusion missing');
voucherCheck(strpos($paid, 'APS Studio Monte Sacro') !== false, 'PDF omits selected studio or uses the old spelling');
voucherCheck(strpos($paid, 'Indirizzo: Via Valle Borbera 51, 00141 Roma') !== false, 'Full studio address missing');
voucherCheck(strpos(voucherPdf(array_replace($document, ['studio_postal_code' => '']), false), 'dati incompleti') !== false, 'Missing postal code not disclosed');
voucherCheck(strpos($preview, 'APS Studio Monte Sacro') !== false, 'Preview uses the old studio spelling');
voucherCheck(strpos($paid, 'Montesacro') === false, 'PDF contains the old studio spelling');
voucherCheck(strpos($paid, 'Chi non e gia socio') !== false, 'PDF omits APS membership information');
voucherCheck(strpos($paid, '/Subtype /Image') !== false && strpos($paid, '/Logo Do') !== false, 'PDF logo missing');
voucherCheck(strpos($paid, 'COME PRENOTARE') !== false && strpos($paid, 'Email facoltativa') !== false, 'Booking or email instructions missing');
voucherCheck(strpos($paid, 'annulla-voucher.php') !== false && strpos($paid, '3 ore') !== false, 'Cancellation instructions missing');
voucherCheck(strpos($paid, '%PDF-1.4') === 0 && substr($paid, -6) === "%%EOF\n", 'Invalid PDF structure');
voucherCheck(abs(voucherTimesItalicWidth('iiii', 12) - 13.344) < 0.001, 'Narrow italic characters have incorrect width');
voucherCheck(abs(voucherTimesItalicWidth('WWWW', 12) - 39.984) < 0.001, 'Wide italic characters have incorrect width');
voucherCheck((bool)preg_match('/\/F4 12 Tf ([0-9.]+) [0-9]+ Td \((Sig\.ra Anna Rossi[^()]*)\)/', $paid, $greetingPosition), 'Greeting position not found');
voucherCheck(abs((float)$greetingPosition[1] + voucherTimesItalicWidth($greetingPosition[2], 12) / 2 - 297.5) < 0.001, 'Greeting is not centered using Times-Italic widths');
voucherCheck((bool)preg_match('/\/F1 12 Tf 55 ([0-9]+) Td \(Valido fino al:/', $paid, $validityPosition), 'Validity position not found');
voucherCheck((bool)preg_match('/\/F3 14 Tf 55 ([0-9]+) Td \(COME PRENOTARE\)/', $paid, $bookingPosition), 'Booking heading position not found');
voucherCheck((int)$validityPosition[1] - (int)$bookingPosition[1] >= 38, 'Booking heading is not sufficiently separated');
$instruction = '3. Scegli "Prenota con voucher regalo", inserisci il codice e seleziona data e orario.';
$wrappedInstruction = voucherWrapInstructions($instruction, 11, 480);
voucherCheck(count($wrappedInstruction) === 1 && $wrappedInstruction[0] === $instruction, 'Booking instructions wrap before using the available width');
voucherCheck(voucherHelveticaWidth($instruction, 11) > 350 && voucherHelveticaWidth($instruction, 11) <= 480, 'Booking instruction does not fit the intended width');
voucherCheck(strpos($paid, $instruction) !== false, 'PDF does not use the full width for booking instructions');
$longWord = str_repeat('W', 100);
$wrappedWord = voucherWrapInstructions($longWord, 11, 480);
voucherCheck(implode('', $wrappedWord) === $longWord && count($wrappedWord) > 1, 'Long unbroken words are not preserved and wrapped');
foreach ($wrappedWord as $line) voucherCheck(voucherHelveticaWidth($line, 11) <= 480, 'Wrapped line exceeds the PDF margin');
$longDocument = array_replace($document, ['recipient_name' => str_repeat('Nome ', 30),
    'service_name' => str_repeat('Massaggio ', 15)]);
voucherCheck(strpos(voucherPdf($longDocument, false), 'annulla-voucher.php') !== false, 'Long voucher loses instructions');
voucherCheck(strpos(voucherPdf($longDocument, true), 'ANTEPRIMA - NON VALIDO / NON PAGATO') !== false, 'Long preview fails');
echo "$checks voucher checks passed.\n";
