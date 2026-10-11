<?php

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

    $pendingStmt = $pdo->prepare(
        "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.ora_fine, a.stato, a.metodo_pagamento,
                a.stato_pagamento, a.note,
                st.nome AS studio_nome, sv.nome AS servizio_nome,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome,
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
        $event = googleCalendarInsertEvent($pdo, googleAppointmentEventPayload($appointment, $settings['timezone']));
        if (empty($event['id'])) {
            continue;
        }

        $linkStmt = $pdo->prepare(
            'UPDATE appuntamenti SET google_event_id = :event, google_synced_at = NOW() WHERE id = :id'
        );
        $linkStmt->execute([':event' => (string)$event['id'], ':id' => $appointment['id']]);
        $created++;
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

    return ['eventi_creati' => $created, 'eventi_rimossi' => $removed];
}

function googleCalendarSync(PDO $pdo): array
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
