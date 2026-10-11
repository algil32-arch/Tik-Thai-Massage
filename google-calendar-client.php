<?php
final class GoogleCalendarException extends RuntimeException
{
}

final class GoogleCalendarSyncTokenExpired extends RuntimeException
{
}

function googleCalendarSettings(): array
{
    $config = appConfig();
    $calendarId = trim((string)($config['GOOGLE_CALENDAR_ID'] ?? ''));
    $credentialsFile = trim((string)($config['GOOGLE_SERVICE_ACCOUNT_FILE'] ?? ''));
    $timezone = trim((string)($config['GOOGLE_CALENDAR_TIMEZONE'] ?? 'Europe/Rome'));

    if ($calendarId === '' || $credentialsFile === '') {
        throw new GoogleCalendarException('Configurazione Google Calendar incompleta.');
    }
    if (!function_exists('curl_init')) {
        throw new GoogleCalendarException('Estensione PHP cURL non disponibile.');
    }
    if (!function_exists('openssl_sign')) {
        throw new GoogleCalendarException('Estensione PHP OpenSSL non disponibile.');
    }

    if (!str_starts_with($credentialsFile, '/')) {
        $credentialsFile = __DIR__ . '/' . ltrim($credentialsFile, '/');
    }
    if (!is_file($credentialsFile) || !is_readable($credentialsFile)) {
        throw new GoogleCalendarException('File delle credenziali Google non trovato.');
    }

    return [
        'calendar_id' => $calendarId,
        'credentials_file' => $credentialsFile,
        'timezone' => $timezone,
    ];
}

function googleCalendarIsConfigured(): bool
{
    try {
        googleCalendarSettings();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function googleServiceAccountCredentials(): array
{
    $settings = googleCalendarSettings();
    $raw = file_get_contents($settings['credentials_file']);
    $credentials = json_decode((string)$raw, true);

    if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
        throw new GoogleCalendarException('Credenziali Google non valide.');
    }

    return $credentials;
}

function googleBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function googleRequestAccessToken(): array
{
    $credentials = googleServiceAccountCredentials();
    $issuedAt = time();
    $header = googleBase64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = googleBase64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ], JSON_THROW_ON_ERROR));

    $signature = '';
    if (!openssl_sign($header . '.' . $claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new GoogleCalendarException('Impossibile firmare la richiesta Google.');
    }
    $assertion = $header . '.' . $claims . '.' . googleBase64UrlEncode($signature);

    $curl = curl_init('https://oauth2.googleapis.com/token');
    if ($curl === false) {
        throw new GoogleCalendarException('Impossibile contattare Google.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]),
    ]);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Google token connection error: ' . $curlError);
        throw new GoogleCalendarException('Google non è raggiungibile.');
    }

    $response = json_decode((string)$responseBody, true);
    if ($statusCode < 200 || $statusCode >= 300 || !isset($response['access_token'])) {
        error_log('Google token error. HTTP status: ' . $statusCode);
        throw new GoogleCalendarException('Google ha rifiutato le credenziali del calendario.');
    }

    return [
        'access_token' => (string)$response['access_token'],
        'expires_in' => (int)($response['expires_in'] ?? 3600),
    ];
}

function googleAccessToken(PDO $pdo): string
{
    $stateStmt = $pdo->query(
        'SELECT access_token, access_token_expires_at FROM google_calendar_stato WHERE id = 1'
    );
    $state = $stateStmt->fetch();

    if ($state && !empty($state['access_token']) && !empty($state['access_token_expires_at'])) {
        $expiresAt = new DateTimeImmutable((string)$state['access_token_expires_at']);
        if ($expiresAt > new DateTimeImmutable('+60 seconds')) {
            return (string)$state['access_token'];
        }
    }

    $token = googleRequestAccessToken();
    $expiresAt = (new DateTimeImmutable())->modify('+' . max(60, $token['expires_in'] - 60) . ' seconds');

    $settings = googleCalendarSettings();
    $saveStmt = $pdo->prepare(
        'INSERT INTO google_calendar_stato (id, calendar_id, access_token, access_token_expires_at)
         VALUES (1, :calendar, :token, :expires)
         ON DUPLICATE KEY UPDATE calendar_id = VALUES(calendar_id),
                                 access_token = VALUES(access_token),
                                 access_token_expires_at = VALUES(access_token_expires_at)'
    );
    $saveStmt->execute([
        ':calendar' => $settings['calendar_id'],
        ':token' => $token['access_token'],
        ':expires' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    return $token['access_token'];
}

function googleCalendarRequest(
    PDO $pdo,
    string $method,
    string $path,
    ?array $payload = null,
    array $query = []
): array {
    $url = 'https://www.googleapis.com/calendar/v3' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new GoogleCalendarException('Impossibile contattare Google Calendar.');
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . googleAccessToken($pdo),
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedPayload === false) {
            curl_close($curl);
            throw new GoogleCalendarException('Impossibile preparare la richiesta Google Calendar.');
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }

    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Google Calendar connection error: ' . $curlError);
        throw new GoogleCalendarException('Google Calendar non è raggiungibile.');
    }

    // Un token di sincronizzazione scaduto richiede una rilettura completa del calendario.
    if ($statusCode === 410) {
        throw new GoogleCalendarSyncTokenExpired('Token di sincronizzazione Google scaduto.');
    }
    if ($statusCode === 404 && $method === 'DELETE') {
        return [];
    }
    if ($statusCode === 204 || trim((string)$responseBody) === '') {
        if ($statusCode >= 200 && $statusCode < 300) {
            return [];
        }
    }

    $response = json_decode((string)$responseBody, true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($response)) {
        error_log('Google Calendar API error. HTTP status: ' . $statusCode);
        throw new GoogleCalendarException('Google Calendar ha rifiutato la richiesta.');
    }

    return $response;
}

function googleCalendarListEvents(PDO $pdo, array $query): array
{
    $settings = googleCalendarSettings();

    return googleCalendarRequest(
        $pdo,
        'GET',
        '/calendars/' . rawurlencode($settings['calendar_id']) . '/events',
        null,
        $query
    );
}

function googleCalendarInsertEvent(PDO $pdo, array $event): array
{
    $settings = googleCalendarSettings();

    return googleCalendarRequest(
        $pdo,
        'POST',
        '/calendars/' . rawurlencode($settings['calendar_id']) . '/events',
        $event
    );
}

function googleCalendarPatchEvent(PDO $pdo, string $eventId, array $event): array
{
    $settings = googleCalendarSettings();

    return googleCalendarRequest(
        $pdo,
        'PATCH',
        '/calendars/' . rawurlencode($settings['calendar_id']) . '/events/' . rawurlencode($eventId),
        $event
    );
}

function googleCalendarDeleteEvent(PDO $pdo, string $eventId): void
{
    $settings = googleCalendarSettings();

    googleCalendarRequest(
        $pdo,
        'DELETE',
        '/calendars/' . rawurlencode($settings['calendar_id']) . '/events/' . rawurlencode($eventId)
    );
}
