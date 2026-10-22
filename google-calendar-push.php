<?php

require_once __DIR__ . '/google-calendar-client.php';

function googleCalendarWebhookUrl(): string
{
    $url = trim((string)(appConfig()['GOOGLE_CALENDAR_WEBHOOK_URL'] ?? ''));
    if ($url !== '' && (
        filter_var($url, FILTER_VALIDATE_URL) === false
        || parse_url($url, PHP_URL_SCHEME) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null
        || parse_url($url, PHP_URL_PASS) !== null
        || parse_url($url, PHP_URL_QUERY) !== null
        || parse_url($url, PHP_URL_FRAGMENT) !== null
    )) {
        throw new GoogleCalendarException('GOOGLE_CALENDAR_WEBHOOK_URL deve essere un URL HTTPS senza credenziali, query o frammenti.');
    }
    return $url;
}

function googleCalendarEnsureWatch(PDO $pdo): array
{
    $url = googleCalendarWebhookUrl();
    if ($url === '') {
        return ['enabled' => false];
    }
    $settings = googleCalendarSettings();
    $lock = $pdo->query("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), ':google-calendar-watch'), 256), 5)");
    if ((int)$lock->fetchColumn() !== 1) {
        throw new GoogleCalendarException('Registrazione notifiche Google gia in corso.');
    }
    try {
        $cleanup = $pdo->prepare('DELETE FROM google_calendar_canali WHERE expires_at <= NOW()');
        $cleanup->execute();
        $find = $pdo->prepare(
            'SELECT expires_at FROM google_calendar_canali
             WHERE calendar_id = :calendar AND callback_url = :url AND resource_id IS NOT NULL
               AND expires_at > DATE_ADD(NOW(), INTERVAL 1 DAY)
             ORDER BY expires_at DESC LIMIT 1'
        );
        $find->execute([':calendar' => $settings['calendar_id'], ':url' => $url]);
        $existing = $find->fetch();
        if ($existing) {
            return ['enabled' => true, 'renewed' => false, 'expires_at' => $existing['expires_at']];
        }

        $id = bin2hex(random_bytes(24));
        $token = bin2hex(random_bytes(32));
        // La notifica iniziale puo arrivare prima della risposta alla richiesta watch.
        $pending = $pdo->prepare(
            'INSERT INTO google_calendar_canali (id, token_hash, calendar_id, callback_url, expires_at)
             VALUES (:id, :hash, :calendar, :url, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
        );
        $pending->execute([
            ':id' => $id, ':hash' => hash('sha256', $token),
            ':calendar' => $settings['calendar_id'], ':url' => $url,
        ]);
        try {
            $response = googleCalendarRequest(
                $pdo, 'POST', '/calendars/' . rawurlencode($settings['calendar_id']) . '/events/watch',
                ['id' => $id, 'token' => $token, 'type' => 'web_hook', 'address' => $url,
                    'params' => ['ttl' => '604800']]
            );
            if (($response['id'] ?? '') !== $id || empty($response['resourceId'])
                || !isset($response['expiration']) || !is_numeric($response['expiration'])
                || (float)$response['expiration'] / 1000 <= time()) {
                throw new GoogleCalendarException('Risposta Google non valida durante la registrazione delle notifiche.');
            }
            $expiresAt = (new DateTimeImmutable('@' . (string)(int)((float)$response['expiration'] / 1000)))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            $save = $pdo->prepare(
                'UPDATE google_calendar_canali SET resource_id = :resource, expires_at = :expires WHERE id = :id'
            );
            $save->execute([':resource' => (string)$response['resourceId'], ':expires' => $expiresAt, ':id' => $id]);
            return ['enabled' => true, 'renewed' => true, 'expires_at' => $expiresAt];
        } catch (Throwable $e) {
            $pdo->prepare('DELETE FROM google_calendar_canali WHERE id = :id')->execute([':id' => $id]);
            throw $e;
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(SHA2(CONCAT(DATABASE(), ':google-calendar-watch'), 256))");
    }
}

function googleCalendarNotificationStatus(PDO $pdo, array $headers): int
{
    $id = (string)($headers['HTTP_X_GOOG_CHANNEL_ID'] ?? '');
    $token = (string)($headers['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? '');
    $resource = (string)($headers['HTTP_X_GOOG_RESOURCE_ID'] ?? '');
    $state = (string)($headers['HTTP_X_GOOG_RESOURCE_STATE'] ?? '');
    if ($id === '' || $token === '' || $resource === '' || !in_array($state, ['sync', 'exists', 'not_exists'], true)) {
        return 403;
    }
    $find = $pdo->prepare(
        'SELECT token_hash, resource_id FROM google_calendar_canali
         WHERE id = :id AND calendar_id = :calendar AND expires_at > NOW()'
    );
    $find->execute([':id' => $id, ':calendar' => googleCalendarSettings()['calendar_id']]);
    $channel = $find->fetch();
    if (!$channel || !hash_equals((string)$channel['token_hash'], hash('sha256', $token))) {
        return 403;
    }
    if ($channel['resource_id'] === null) {
        return $state === 'sync' ? 204 : 503;
    }
    if (!hash_equals((string)$channel['resource_id'], $resource)) {
        return 403;
    }
    return $state === 'sync' ? 204 : 200;
}
