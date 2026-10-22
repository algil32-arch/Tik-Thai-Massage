<?php

require_once __DIR__ . '/booking-mailer.php';

const GOOGLE_SYNC_APPOINTMENT_PROPERTY = 'thaitik_appointment_id';
const GOOGLE_SYNC_FUTURE_DAYS = 365;

function googleEventIsOwnAppointment(array $event): bool
{
    return isset($event['extendedProperties']['private'][GOOGLE_SYNC_APPOINTMENT_PROPERTY]);
}

function googleEventToBlocks(array $event, string $timezone): array
{
    $start = $event['start'] ?? [];
    $end = $event['end'] ?? [];
    $zone = new DateTimeZone($timezone);

    if (isset($start['date'])) {
        $startMoment = new DateTimeImmutable((string)$start['date'] . ' 00:00:00', $zone);
        $endMoment = new DateTimeImmutable((string)($end['date'] ?? $start['date']) . ' 00:00:00', $zone);
        if ($endMoment <= $startMoment) {
            $endMoment = $startMoment->modify('+1 day');
        }
    } elseif (isset($start['dateTime'])) {
        $startMoment = (new DateTimeImmutable((string)$start['dateTime']))->setTimezone($zone);
        $endMoment = (new DateTimeImmutable((string)($end['dateTime'] ?? $start['dateTime'])))->setTimezone($zone);
    } else {
        return [];
    }

    if ($endMoment <= $startMoment) {
        return [];
    }

    $blocks = [];
    $cursor = $startMoment;
    $dayGuard = 0;
    while ($cursor < $endMoment && $dayGuard < 400) {
        $dayGuard++;
        $nextMidnight = $cursor->setTime(0, 0, 0)->modify('+1 day');
        $segmentEnd = $endMoment < $nextMidnight ? $endMoment : $nextMidnight;
        $blocks[] = [
            'data' => $cursor->format('Y-m-d'),
            'ora_inizio' => $cursor->format('H:i:s'),
            'ora_fine' => $segmentEnd >= $nextMidnight ? '23:59:59' : $segmentEnd->format('H:i:s'),
        ];
        $cursor = $nextMidnight;
    }

    return $blocks;
}

function googleSyncStoreBlocks(PDO $pdo, array $event, string $timezone): void
{
    $eventId = (string)($event['id'] ?? '');
    if ($eventId === '') {
        return;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM agenda_blocchi WHERE google_event_id = :event');
    $deleteStmt->execute([':event' => $eventId]);

    $isCancelled = ($event['status'] ?? '') === 'cancelled';
    $isFreeTime = ($event['transparency'] ?? 'opaque') === 'transparent';
    if ($isCancelled || $isFreeTime) {
        return;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO agenda_blocchi (google_event_id, data_blocco, ora_inizio, ora_fine, titolo)
         VALUES (:event, :data, :start, :end, :titolo)'
    );
    foreach (googleEventToBlocks($event, $timezone) as $block) {
        $insertStmt->execute([
            ':event' => $eventId,
            ':data' => $block['data'],
            ':start' => $block['ora_inizio'],
            ':end' => $block['ora_fine'],
            ':titolo' => isset($event['summary']) ? mb_substr((string)$event['summary'], 0, 255) : null,
        ]);
    }
}

function googleSyncFetchEvents(PDO $pdo, array $query): array
{
    $events = [];
    $pageToken = null;
    $nextSyncToken = null;
    $pageGuard = 0;

    do {
        $pageGuard++;
        $pageQuery = $query;
        if ($pageToken !== null) {
            $pageQuery['pageToken'] = $pageToken;
        }

        $response = googleCalendarListEvents($pdo, $pageQuery);
        foreach ($response['items'] ?? [] as $item) {
            $events[] = $item;
        }

        $pageToken = $response['nextPageToken'] ?? null;
        $nextSyncToken = $response['nextSyncToken'] ?? $nextSyncToken;
    } while ($pageToken !== null && $pageGuard < 20);

    return ['events' => $events, 'sync_token' => $nextSyncToken];
}

function googleSyncFullQuery(): array
{
    return [
        'singleEvents' => 'true',
        'showDeleted' => 'true',
        'maxResults' => 250,
        'timeMin' => (new DateTimeImmutable('-1 day'))->format(DateTimeInterface::RFC3339),
        'timeMax' => (new DateTimeImmutable('+' . GOOGLE_SYNC_FUTURE_DAYS . ' days'))->format(DateTimeInterface::RFC3339),
    ];
}

function googleSyncPullBlocks(PDO $pdo): array
{
    $settings = googleCalendarSettings();
    $state = $pdo->query('SELECT sync_token FROM google_calendar_stato WHERE id = 1')->fetch();
    $syncToken = $state['sync_token'] ?? null;
    $fullResync = empty($syncToken);

    if ($fullResync) {
        $query = googleSyncFullQuery();
    } else {
        $query = [
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'maxResults' => 250,
            'syncToken' => $syncToken,
        ];
    }

    try {
        $result = googleSyncFetchEvents($pdo, $query);
    } catch (GoogleCalendarSyncTokenExpired $e) {
        // Google invalida i token dopo lunghe inattività: si riparte da una lettura completa.
        $fullResync = true;
        $result = googleSyncFetchEvents($pdo, googleSyncFullQuery());
    }

    if ($fullResync) {
        $pdo->prepare('DELETE FROM agenda_blocchi')->execute();
    }

    $importedBlocks = 0;
    $skippedOwnEvents = 0;
    $restoredAppointments = 0;
    foreach ($result['events'] as $event) {
        if (($event['status'] ?? '') === 'cancelled') {
            // Un appuntamento del sito cancellato a mano da Google viene ricreato al push
            // successivo: le disdette vanno fatte dall'area amministrativa.
            $restoreStmt = $pdo->prepare(
                "UPDATE appuntamenti SET google_event_id = NULL
                 WHERE google_event_id = :event AND stato IN ('in_attesa', 'confermato')"
            );
            $restoreStmt->execute([':event' => (string)($event['id'] ?? '')]);
            $restoredAppointments += $restoreStmt->rowCount();
        }

        if (googleEventIsOwnAppointment($event)) {
            $skippedOwnEvents++;
            continue;
        }
        googleSyncStoreBlocks($pdo, $event, $settings['timezone']);
        $importedBlocks++;
    }

    if (!empty($result['sync_token'])) {
        $tokenStmt = $pdo->prepare('UPDATE google_calendar_stato SET sync_token = :token WHERE id = 1');
        $tokenStmt->execute([':token' => $result['sync_token']]);
    }

    $cleanupStmt = $pdo->prepare('DELETE FROM agenda_blocchi WHERE data_blocco < :limite');
    $cleanupStmt->execute([':limite' => (new DateTimeImmutable('-7 days'))->format('Y-m-d')]);

    return [
        'full_resync' => $fullResync,
        'eventi_letti' => count($result['events']),
        'impegni_importati' => $importedBlocks,
        'eventi_del_sito_ignorati' => $skippedOwnEvents,
        'appuntamenti_da_ricreare' => $restoredAppointments,
    ];
}

function googleAppointmentEventPayload(array $appointment, string $timezone): array
{
    $date = (string)$appointment['data_appuntamento'];
    $customerName = trim((string)$appointment['cliente_nome'] . ' ' . (string)$appointment['cliente_cognome']);
    $paymentLabel = $appointment['metodo_pagamento'] === 'online' ? 'Online' : 'In studio';

    $description = "Trattamento: {$appointment['servizio_nome']}\n"
        . "Sede: {$appointment['studio_nome']}\n"
        . "Cliente: {$customerName}\n"
        . "Email: {$appointment['cliente_email']}\n"
        . "Telefono: " . ((string)$appointment['cliente_telefono'] !== '' ? $appointment['cliente_telefono'] : 'non indicato') . "\n"
        . "Pagamento: {$paymentLabel} ({$appointment['stato_pagamento']})\n"
        . "Stato: {$appointment['stato']}\n"
        . "Prenotazione #{$appointment['id']} dal sito";

    if (trim((string)$appointment['note']) !== '') {
        $description .= "\nNote: {$appointment['note']}";
    }

    return [
        'summary' => $appointment['servizio_nome'] . ' · ' . ($customerName !== '' ? $customerName : 'Cliente'),
        'description' => $description,
        'location' => (string)$appointment['studio_nome'],
        'start' => [
            'dateTime' => $date . 'T' . substr((string)$appointment['ora_inizio'], 0, 8),
            'timeZone' => $timezone,
        ],
        'end' => [
            'dateTime' => $date . 'T' . substr((string)$appointment['ora_fine'], 0, 8),
            'timeZone' => $timezone,
        ],
        'extendedProperties' => [
            'private' => [GOOGLE_SYNC_APPOINTMENT_PROPERTY => (string)$appointment['id']],
        ],
    ];
}

function googleSyncPushAppointments(PDO $pdo): array
{
    $settings = googleCalendarSettings();
    $created = 0;
    $removed = 0;
    $confirmed = 0;
    $confirmationEmailsSent = 0;
    $confirmationEmailFailures = [];

    $pendingStmt = $pdo->prepare(
        "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.ora_fine, a.stato, a.metodo_pagamento,
                a.stato_pagamento, a.note,
                st.nome AS studio_nome, sv.nome AS servizio_nome,
                sv.prezzo, c.nome AS cliente_nome, c.cognome AS cliente_cognome,
                c.email AS cliente_email, c.telefono AS cliente_telefono
         FROM appuntamenti a
         INNER JOIN studi st ON st.id = a.id_studio
         INNER JOIN servizi sv ON sv.id = a.id_servizio
         INNER JOIN clienti c ON c.id = a.id_cliente
         WHERE a.google_event_id IS NULL
           AND a.stato IN ('in_attesa', 'confermato')
           AND a.data_appuntamento >= :oggi
         ORDER BY a.data_appuntamento, a.ora_inizio
         LIMIT 50"
    );
    $pendingStmt->execute([':oggi' => (new DateTimeImmutable('today'))->format('Y-m-d')]);

    foreach ($pendingStmt->fetchAll() as $appointment) {
        $wasAwaitingConfirmation = $appointment['stato'] === 'in_attesa';
        $eventAppointment = $appointment;
        if ($wasAwaitingConfirmation) {
            $eventAppointment['stato'] = 'confermato';
        }
        $event = googleCalendarInsertEvent($pdo, googleAppointmentEventPayload($eventAppointment, $settings['timezone']));
        if (empty($event['id'])) {
            continue;
        }

        $linkStmt = $pdo->prepare(
            "UPDATE appuntamenti
             SET google_event_id = :event, google_synced_at = NOW(),
                 stato = CASE WHEN stato = 'in_attesa' THEN 'confermato' ELSE stato END
             WHERE id = :id AND google_event_id IS NULL AND stato = :expected_status"
        );
        $linkStmt->execute([
            ':event' => (string)$event['id'],
            ':id' => $appointment['id'],
            ':expected_status' => $appointment['stato'],
        ]);
        if ($linkStmt->rowCount() !== 1) {
            googleCalendarDeleteEvent($pdo, (string)$event['id']);
            continue;
        }

        $created++;
        if ($wasAwaitingConfirmation) {
            $confirmed++;
            try {
                $sent = sendBookingConfirmationEmail((int)$appointment['id'], [
                    'name' => trim((string)$appointment['cliente_nome'] . ' ' . (string)$appointment['cliente_cognome']),
                    'studio' => (string)$appointment['studio_nome'],
                    'service' => (string)$appointment['servizio_nome'],
                    'date' => (string)$appointment['data_appuntamento'],
                    'time' => substr((string)$appointment['ora_inizio'], 0, 5),
                    'price' => (float)$appointment['prezzo'],
                ], (string)$appointment['cliente_email']);
            } catch (Throwable $e) {
                $sent = false;
                error_log('Google Calendar synced booking, but confirmation email raised an error for appointment #' . $appointment['id'] . ': ' . $e->getMessage());
            }
            if ($sent) {
                $confirmationEmailsSent++;
            } else {
                $confirmationEmailFailures[] = (int)$appointment['id'];
                error_log('Google Calendar synced booking, but confirmation email failed for appointment #' . $appointment['id']);
            }
        }
    }

    $cancelledStmt = $pdo->query(
        "SELECT id, google_event_id FROM appuntamenti
         WHERE stato = 'annullato' AND google_event_id IS NOT NULL
         LIMIT 50"
    );

    foreach ($cancelledStmt->fetchAll() as $appointment) {
        googleCalendarDeleteEvent($pdo, (string)$appointment['google_event_id']);
        $clearStmt = $pdo->prepare(
            'UPDATE appuntamenti SET google_event_id = NULL, google_synced_at = NOW() WHERE id = :id'
        );
        $clearStmt->execute([':id' => $appointment['id']]);
        $removed++;
    }

    return [
        'eventi_creati' => $created,
        'eventi_rimossi' => $removed,
        'appuntamenti_confermati' => $confirmed,
        'email_conferma_inviate' => $confirmationEmailsSent,
        'email_conferma_non_inviate' => $confirmationEmailFailures,
    ];
}

function googleCalendarSync(PDO $pdo): array
{
    if ($pdo->inTransaction()) {
        throw new GoogleCalendarException('Avvia la sincronizzazione dopo il salvataggio della transazione.');
    }
    $lock = $pdo->query("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), ':google-calendar-sync'), 256), 5)");
    if ((int)$lock->fetchColumn() !== 1) {
        throw new GoogleCalendarException('Sincronizzazione gia in corso. Riprova tra poco.');
    }
    try {
        return googleCalendarSyncLocked($pdo);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(SHA2(CONCAT(DATABASE(), ':google-calendar-sync'), 256))");
    }
}

function googleCalendarSyncLocked(PDO $pdo): array
{
    $settings = googleCalendarSettings();

    $initStmt = $pdo->prepare(
        'INSERT INTO google_calendar_stato (id, calendar_id)
         VALUES (1, :calendar)
         ON DUPLICATE KEY UPDATE calendar_id = VALUES(calendar_id)'
    );
    $initStmt->execute([':calendar' => $settings['calendar_id']]);

    try {
        $pulled = googleSyncPullBlocks($pdo);
        $pushed = googleSyncPushAppointments($pdo);
    } catch (Throwable $e) {
        $errorStmt = $pdo->prepare(
            'UPDATE google_calendar_stato SET last_error = :errore, last_sync_at = NOW() WHERE id = 1'
        );
        $errorStmt->execute([':errore' => mb_substr($e->getMessage(), 0, 500)]);
        throw $e;
    }

    $doneStmt = $pdo->prepare(
        'UPDATE google_calendar_stato SET last_sync_at = NOW(), last_error = NULL WHERE id = 1'
    );
    $doneStmt->execute();

    return array_merge($pulled, $pushed, ['sincronizzato_il' => (new DateTimeImmutable())->format('Y-m-d H:i:s')]);
}

function googleCalendarSyncAfterBooking(PDO $pdo): array
{
    $config = appConfig();
    if (trim((string)($config['GOOGLE_CALENDAR_ID'] ?? '')) === '') {
        return ['attempted' => false, 'success' => false];
    }

    try {
        return [
            'attempted' => true,
            'success' => true,
            'result' => googleCalendarSync($pdo),
        ];
    } catch (Throwable $e) {
        error_log('Google Calendar sync after booking failed: ' . $e->getMessage());
        return ['attempted' => true, 'success' => false];
    }
}

function googleCalendarSyncAfterAdminChange(PDO $pdo): void
{
    $result = googleCalendarSyncAfterBooking($pdo);
    $_SESSION['calendar_sync_notice'] = !$result['attempted']
        ? 'Modifica salvata. Sincronizzazione Google non configurata.'
        : ($result['success']
            ? 'Modifica salvata e sincronizzazione Google completata.'
            : 'Modifica salvata, ma sincronizzazione Google non riuscita. Il cron riprovera; controlla il log PHP.');
}
