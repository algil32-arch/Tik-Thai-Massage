<?php
function bookingVoucherCancellationNotice(array $booking): string
{
    if (($booking['payment_method'] ?? '') !== 'voucher') return '';
    return "\nPer annullare, inserisci il codice voucher e scegli l'appuntamento nella pagina:\n"
        . "https://www.thaitikmassage.it/annulla-voucher.php\n"
        . "L'annullamento online è possibile fino a 3 ore prima dell'appuntamento. "
        . "Dopo questo termine contatta lo studio.\n";
}

function bookingCustomerEmailContent(string $body, array $headers, array $booking): array
{
    if (($booking['payment_method'] ?? '') !== 'voucher') return [$body, $headers];
    $boundary = 'ttm-' . bin2hex(random_bytes(16));
    $headers = array_values(array_filter($headers, static fn($header) => !str_starts_with($header, 'Content-Type:') && !str_starts_with($header, 'Content-Transfer-Encoding:')));
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $html = '<!doctype html><html lang="it"><body><p>'
        . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        . '</p><p><a href="https://www.thaitikmassage.it/annulla-voucher.php" '
        . 'style="display:inline-block;padding:12px 20px;background:#2a201d;color:#fff;text-decoration:none">'
        . 'Annulla appuntamento</a></p></body></html>';
    $content = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $body . "\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n--$boundary--\r\n";
    return [$content, $headers];
}

function sendVoucherCancellationNotification(int $bookingId, string $voucherCode): bool
{
    $config = appConfig();
    $to = (string)($config['BOOKING_NOTIFICATION_EMAIL'] ?? '');
    $from = (string)($config['MAIL_FROM_EMAIL'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('Voucher cancellation notification configuration invalid for appointment #' . $bookingId);
        return false;
    }
    try {
        $sent = mail($to, 'Appuntamento annullato #' . $bookingId,
            "Il cliente ha annullato l'appuntamento #{$bookingId} con voucher {$voucherCode}.\nLa seduta è stata restituita.\n",
            "From: Thai Tik Massage <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");
    } catch (Throwable $e) {
        error_log('Voucher cancellation notification failed for appointment #' . $bookingId . ': ' . $e->getMessage());
        return false;
    }
    if (!$sent) error_log('Voucher cancellation notification not delivered for appointment #' . $bookingId);
    return $sent;
}

function bookingPaymentEmailLine(array $booking, bool $indicativePrice = false): string
{
    if (($booking['payment_method'] ?? '') === 'voucher') {
        $code = $booking['voucher_code'] ?? null;
        $remaining = $booking['remaining_sessions'] ?? null;
        if (!is_string($code) || $code === '' || !is_numeric($remaining) || (int)$remaining < 0) {
            throw new RuntimeException('Dati voucher mancanti per la notifica della prenotazione.');
        }
        return "Pagamento: voucher {$code}\nSedute residue: " . (int)$remaining
            . "\nQuesta seduta è coperta dal voucher; nessun importo da saldare.\n";
    }
    return ($indicativePrice ? 'Prezzo indicativo: ' : 'Prezzo: ')
        . number_format((float)$booking['price'], 2, ',', '.') . " EUR\n";
}

function sendBookingNotifications(int $bookingId, array $booking, string $customerEmail): array
{
    $config = appConfig();
    $notificationEmail = (string)($config['BOOKING_NOTIFICATION_EMAIL'] ?? '');
    $fromEmail = (string)($config['MAIL_FROM_EMAIL'] ?? '');
    $result = ['studio_sent' => false, 'customer_sent' => false];

    if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return $result;
    }

    $customerName = trim(preg_replace('/[\r\n]+/', ' ', (string)$booking['name']));
    $appointment = $booking['date'] . ' alle ' . $booking['time'];
    $paymentLine = bookingPaymentEmailLine($booking);
    $customerPaymentLine = bookingPaymentEmailLine($booking, true);
    $notes = trim((string)$booking['notes']);
    $noteLine = $notes !== '' ? "\nNote: {$notes}\n" : '';

    $headers = [
        'From: Thai Tik Massage <' . $fromEmail . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $studioBody = "Nuova richiesta di appuntamento #{$bookingId}\n\n"
        . "Stato: in attesa di conferma\n"
        . "Studio: {$booking['studio']}\n"
        . "Trattamento: {$booking['service']}\n"
        . "Data e ora: {$appointment}\n"
        . $paymentLine
        . "Cliente: {$customerName}\n"
        . "Email cliente: " . ($customerEmail !== '' ? $customerEmail : 'non fornita') . "\n"
        . $noteLine;

    $customerBody = "Ciao {$customerName},\n\n"
        . "abbiamo ricevuto la tua richiesta di appuntamento #{$bookingId}.\n"
        . "La richiesta è in attesa: diventerà confermata dopo l'inserimento riuscito in Google Calendar. "
        . "La sincronizzazione avviene di norma entro 10 minuti; ti invieremo un'altra email di conferma.\n\n"
        . "Studio: {$booking['studio']}\n"
        . "Trattamento: {$booking['service']}\n"
        . "Data e ora richieste: {$appointment}\n"
        . $customerPaymentLine
        . $noteLine
        . bookingVoucherCancellationNotice($booking)
        . "Per informazioni: {$notificationEmail}\n"
        . "Thai Tik Massage";

    try {
        $studioHeaders = $headers;
        if ($customerEmail !== '') $studioHeaders[] = 'Reply-To: ' . $customerEmail;
        $result['studio_sent'] = @mail(
            $notificationEmail,
            'Nuova richiesta appuntamento #' . $bookingId,
            $studioBody,
            implode("\r\n", $studioHeaders)
        );
    } catch (Throwable $e) {
        $result['studio_sent'] = false;
    }

    if ($customerEmail === '') return $result;
    try {
        $customerHeaders = $headers;
        $customerHeaders[] = 'Reply-To: ' . $notificationEmail;
        [$customerBody, $customerHeaders] = bookingCustomerEmailContent($customerBody, $customerHeaders, $booking);
        $result['customer_sent'] = @mail(
            $customerEmail,
            'Richiesta appuntamento ricevuta #' . $bookingId,
            $customerBody,
            implode("\r\n", $customerHeaders)
        );
    } catch (Throwable $e) {
        $result['customer_sent'] = false;
    }

    return $result;
}

function sendBookingConfirmationEmail(int $bookingId, array $booking, string $customerEmail): bool
{
    $config = appConfig();
    $notificationEmail = (string)($config['BOOKING_NOTIFICATION_EMAIL'] ?? '');
    $fromEmail = (string)($config['MAIL_FROM_EMAIL'] ?? '');

    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $customerName = trim(preg_replace('/[\r\n]+/', ' ', (string)$booking['name']));
    $appointment = (string)$booking['date'] . ' alle ' . (string)$booking['time'];
    $body = "Ciao {$customerName},\n\n"
        . "il tuo appuntamento #{$bookingId} è confermato.\n\n"
        . "Studio: {$booking['studio']}\n"
        . "Trattamento: {$booking['service']}\n"
        . "Data e ora: {$appointment}\n"
        . bookingPaymentEmailLine($booking) . "\n"
        . bookingVoucherCancellationNotice($booking)
        . "Per informazioni: {$notificationEmail}\n"
        . "Thai Tik Massage";
    $headers = [
        'From: Thai Tik Massage <' . $fromEmail . '>',
        'Reply-To: ' . $notificationEmail,
        'Cc: ' . $notificationEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    [$body, $headers] = bookingCustomerEmailContent($body, $headers, $booking);
    return @mail(
        $customerEmail,
        'Appuntamento confermato #' . $bookingId,
        $body,
        implode("\r\n", $headers)
    );
}
