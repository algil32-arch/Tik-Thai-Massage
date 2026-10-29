<?php
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
    $price = number_format((float)$booking['price'], 2, ',', '.') . ' EUR';
    $notes = trim((string)$booking['notes']);
    $noteLine = $notes !== '' ? "\nNote: {$notes}\n" : '';
    if (!empty($booking['voucher_code'])) {
        $noteLine .= "\nPagamento: voucher {$booking['voucher_code']}. Una seduta scalata; nessun importo da saldare.\n";
    }

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
        . "Prezzo: {$price}\n"
        . "Cliente: {$customerName}\n"
        . "Email cliente: {$customerEmail}\n"
        . $noteLine;

    $customerBody = "Ciao {$customerName},\n\n"
        . "abbiamo ricevuto la tua richiesta di appuntamento #{$bookingId}.\n"
        . "La richiesta è in attesa: diventerà confermata dopo l'inserimento riuscito in Google Calendar. "
        . "La sincronizzazione avviene di norma entro 10 minuti; ti invieremo un'altra email di conferma.\n\n"
        . "Studio: {$booking['studio']}\n"
        . "Trattamento: {$booking['service']}\n"
        . "Data e ora richieste: {$appointment}\n"
        . "Prezzo indicativo: {$price}\n"
        . $noteLine
        . "Per informazioni: {$notificationEmail}\n"
        . "Thai Tik Massage";

    try {
        $studioHeaders = $headers;
        $studioHeaders[] = 'Reply-To: ' . $customerEmail;
        $result['studio_sent'] = @mail(
            $notificationEmail,
            'Nuova richiesta appuntamento #' . $bookingId,
            $studioBody,
            implode("\r\n", $studioHeaders)
        );
    } catch (Throwable $e) {
        $result['studio_sent'] = false;
    }

    try {
        $customerHeaders = $headers;
        $customerHeaders[] = 'Reply-To: ' . $notificationEmail;
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
        . "Prezzo: " . number_format((float)$booking['price'], 2, ',', '.') . " EUR\n\n"
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

    return @mail(
        $customerEmail,
        'Appuntamento confermato #' . $bookingId,
        $body,
        implode("\r\n", $headers)
    );
}
