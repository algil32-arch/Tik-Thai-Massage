<?php
/**
 * Entry point per il cron di sincronizzazione con Google Calendar.
 * Uso da riga di comando: php /percorso/cron-calendar-sync.php
 * Uso via URL: https://www.esempio.it/cron-calendar-sync.php?token=VALORE_DI_GOOGLE_SYNC_TOKEN
 */

date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/google-calendar-client.php';
require_once __DIR__ . '/google-calendar-sync.php';
require_once __DIR__ . '/google-calendar-push.php';

$isCommandLine = PHP_SAPI === 'cli';
if (!$isCommandLine) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

function cronSyncResponse(int $status, array $payload): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
    exit($status >= 400 && PHP_SAPI === 'cli' ? 1 : 0);
}

if (!$isCommandLine) {
    $expectedToken = trim((string)(appConfig()['GOOGLE_SYNC_TOKEN'] ?? ''));
    $providedToken = trim((string)($_GET['token'] ?? ''));

    if ($expectedToken === '') {
        cronSyncResponse(503, [
            'success' => false,
            'message' => 'Imposta GOOGLE_SYNC_TOKEN in private/config.local.php per abilitare il cron via URL.',
        ]);
    }
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        cronSyncResponse(403, ['success' => false, 'message' => 'Token di sincronizzazione non valido.']);
    }
}

try {
    $pdo = dbConnection();
} catch (Throwable $e) {
    cronSyncResponse(500, ['success' => false, 'message' => 'Database non raggiungibile.']);
}

try {
    $result = googleCalendarSync($pdo);
    $result['notifiche_google'] = googleCalendarEnsureWatch($pdo);
    if (!empty($result['notifiche_google']['renewed'])) {
        // Recupera anche le modifiche arrivate fra la prima lettura e l'attivazione del canale.
        $result = array_merge($result, ['dopo_attivazione_notifiche' => googleCalendarSync($pdo)]);
    }
} catch (GoogleCalendarException $e) {
    cronSyncResponse(502, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Google Calendar sync failed: ' . $e->getMessage());
    cronSyncResponse(500, [
        'success' => false,
        'message' => 'Sincronizzazione non riuscita: ' . $e->getMessage(),
    ]);
}

cronSyncResponse(200, array_merge(['success' => true], $result));
