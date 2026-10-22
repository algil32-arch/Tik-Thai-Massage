<?php

date_default_timezone_set('Europe/Rome');
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/google-calendar-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$checks = 0;
$config = ['GOOGLE_CALENDAR_ID' => 'test-calendar', 'GOOGLE_CALENDAR_WEBHOOK_URL' => 'https://example.test/google-calendar-webhook.php'];
$api = null;
$lists = [];
$listResponse = ['items' => [], 'nextSyncToken' => 'next-token'];
$inserted = [];
$deleted = [];
$emails = 0;

class GoogleCalendarException extends RuntimeException {}
class GoogleCalendarSyncTokenExpired extends RuntimeException {}

function appConfig(): array { return $GLOBALS['config']; }
function googleCalendarSettings(): array
{
    return ['calendar_id' => appConfig()['GOOGLE_CALENDAR_ID'], 'timezone' => 'Europe/Rome'];
}
function googleCalendarRequest(PDO $pdo, string $method, string $path, ?array $payload = null, array $query = []): array
{
    return ($GLOBALS['api'])($method, $path, $payload, $query);
}
function googleCalendarListEvents(PDO $pdo, array $query): array
{
    $GLOBALS['lists'][] = $query;
    return $GLOBALS['listResponse'];
}
function googleCalendarInsertEvent(PDO $pdo, array $payload): array
{
    $GLOBALS['inserted'][] = $payload;
    return ['id' => 'created-event'];
}
function googleCalendarDeleteEvent(PDO $pdo, string $id): void { $GLOBALS['deleted'][] = $id; }
function sendBookingConfirmationEmail(int $id, array $booking, string $email): bool
{
    $GLOBALS['emails']++;
    return true;
}
function calendarCheck(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function calendarReject(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (GoogleCalendarException $e) {
        calendarCheck(str_contains($e->getMessage(), $message), 'Unexpected error: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

class CalendarStatement extends PDOStatement
{
    public function __construct(private array $step) {}
    public function execute(?array $params = null): bool
    {
        if (isset($this->step['params'])) {
            calendarCheck($params === $this->step['params'], 'Wrong parameters for ' . $this->step['sql']);
        }
        if (isset($this->step['verify'])) ($this->step['verify'])($params);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->step['result'] ?? false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->step['result'] ?? []; }
    public function fetchColumn(int $column = 0): mixed { return $this->step['result'] ?? false; }
    public function rowCount(): int { return $this->step['count'] ?? 1; }
}
class CalendarPDO extends PDO
{
    public bool $transaction = false;
    public function __construct(public array $steps = []) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $step = array_shift($this->steps);
        calendarCheck($step !== null && str_contains($query, $step['sql']), 'Unexpected SQL: ' . $query);
        return new CalendarStatement($step);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->prepare($query);
    }
    public function inTransaction(): bool { return $this->transaction; }
}
function calendarStep(string $sql, mixed $result = false): array { return compact('sql', 'result'); }

try {
    // Copie delle librerie reali con soli confini esterni (API/email) sostituiti.
    foreach (['google-calendar-push.php', 'google-calendar-sync.php'] as $file) {
        copy($root . '/' . $file, $temporary . '/' . $file);
    }
    foreach (['google-calendar-client.php', 'booking-mailer.php'] as $file) {
        file_put_contents($temporary . '/' . $file, "<?php\n");
    }
    require $temporary . '/google-calendar-push.php';
    require $temporary . '/google-calendar-sync.php';
    require $root . '/booking-slots.php';

    calendarCheck(googleCalendarWebhookUrl() === $config['GOOGLE_CALENDAR_WEBHOOK_URL'], 'Valid HTTPS URL rejected');
    foreach (['http://example.test/hook', 'https://user:pass@example.test/hook', 'https://example.test/hook?token=secret', 'https://example.test/hook#fragment'] as $url) {
        $config['GOOGLE_CALENDAR_WEBHOOK_URL'] = $url;
        calendarReject(fn() => googleCalendarWebhookUrl(), 'URL HTTPS');
    }
    $config['GOOGLE_CALENDAR_WEBHOOK_URL'] = '';
    $pdo = new CalendarPDO();
    calendarCheck(googleCalendarEnsureWatch($pdo) === ['enabled' => false], 'Push must remain opt-in');
    $config['GOOGLE_CALENDAR_WEBHOOK_URL'] = 'https://example.test/google-calendar-webhook.php';

    $expiry = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
    $pdo = new CalendarPDO([
        calendarStep('GET_LOCK', 1), calendarStep('DELETE FROM google_calendar_canali'),
        calendarStep('SELECT expires_at', ['expires_at' => $expiry]), calendarStep('RELEASE_LOCK', 1),
    ]);
    calendarCheck(googleCalendarEnsureWatch($pdo) === ['enabled' => true, 'renewed' => false, 'expires_at' => $expiry], 'Active channel must not be recreated');
    calendarCheck($pdo->steps === [], 'Watch lock not released');

    $registered = [];
    $api = function (string $method, string $path, array $payload) use (&$registered): array {
        calendarCheck($method === 'POST' && $path === '/calendars/test-calendar/events/watch', 'Wrong watch endpoint');
        calendarCheck($payload['address'] === appConfig()['GOOGLE_CALENDAR_WEBHOOK_URL'], 'Wrong callback URL');
        calendarCheck($payload['type'] === 'web_hook' && $payload['params']['ttl'] === '604800', 'Wrong watch settings');
        calendarCheck(strlen($payload['id']) <= 64 && strlen($payload['token']) === 64, 'Invalid channel secrets');
        $registered = $payload;
        return ['id' => $payload['id'], 'resourceId' => 'resource', 'expiration' => (time() + 604800) * 1000];
    };
    $pending = calendarStep('INSERT INTO google_calendar_canali');
    $pending['verify'] = function (array $params) use (&$registered): void {
        $registered = $params;
        calendarCheck(strlen($params[':hash']) === 64, 'Only token hash should be stored');
    };
    $save = calendarStep('UPDATE google_calendar_canali');
    $save['verify'] = function (array $params) use (&$registered): void {
        calendarCheck($params[':id'] === $registered['id'] && $params[':resource'] === 'resource', 'Watch response not persisted');
        calendarCheck($params[':expires'] > date('Y-m-d H:i:s', time() + 86400), 'Expiration not persisted');
    };
    $pdo = new CalendarPDO([
        calendarStep('GET_LOCK', 1), calendarStep('DELETE FROM google_calendar_canali'),
        calendarStep('SELECT expires_at'), $pending, $save, calendarStep('RELEASE_LOCK', 1),
    ]);
    calendarCheck(googleCalendarEnsureWatch($pdo)['renewed'], 'Missing channel not registered');
    calendarCheck($pdo->steps === [], 'Registration SQL incomplete');

    $api = fn() => throw new GoogleCalendarException('watch failed');
    $pdo = new CalendarPDO([
        calendarStep('GET_LOCK', 1), calendarStep('DELETE FROM google_calendar_canali'),
        calendarStep('SELECT expires_at'), $pending, calendarStep('DELETE FROM google_calendar_canali WHERE id'),
        calendarStep('RELEASE_LOCK', 1),
    ]);
    calendarReject(fn() => googleCalendarEnsureWatch($pdo), 'watch failed');
    calendarCheck($pdo->steps === [], 'Failed registration must remove pending channel and release lock');

    $headers = ['HTTP_X_GOOG_CHANNEL_ID' => 'channel', 'HTTP_X_GOOG_CHANNEL_TOKEN' => 'secret',
        'HTTP_X_GOOG_RESOURCE_ID' => 'resource', 'HTTP_X_GOOG_RESOURCE_STATE' => 'exists'];
    foreach ([
        [$headers, ['token_hash' => hash('sha256', 'secret'), 'resource_id' => 'resource'], 200],
        [array_replace($headers, ['HTTP_X_GOOG_RESOURCE_STATE' => 'sync']), ['token_hash' => hash('sha256', 'secret'), 'resource_id' => null], 204],
        [$headers, ['token_hash' => hash('sha256', 'secret'), 'resource_id' => null], 503],
        [$headers, ['token_hash' => hash('sha256', 'wrong'), 'resource_id' => 'resource'], 403],
        [$headers, ['token_hash' => hash('sha256', 'secret'), 'resource_id' => 'wrong'], 403],
        [$headers, false, 403],
        [array_replace($headers, ['HTTP_X_GOOG_RESOURCE_STATE' => 'sync']), ['token_hash' => hash('sha256', 'secret'), 'resource_id' => 'resource'], 204],
        [array_replace($headers, ['HTTP_X_GOOG_RESOURCE_STATE' => 'not_exists']), ['token_hash' => hash('sha256', 'secret'), 'resource_id' => 'resource'], 200],
    ] as [$input, $channel, $expected]) {
        $step = calendarStep('SELECT token_hash, resource_id', $channel);
        $step['params'] = [':id' => 'channel', ':calendar' => 'test-calendar'];
        calendarCheck(googleCalendarNotificationStatus(new CalendarPDO([$step]), $input) === $expected, 'Wrong notification acceptance status');
    }
    calendarCheck(googleCalendarNotificationStatus(new CalendarPDO(), []) === 403, 'Missing notification headers accepted');

    $pdo = new CalendarPDO([calendarStep('GET_LOCK', 0)]);
    calendarReject(fn() => googleCalendarSync($pdo), 'gia in corso');
    calendarCheck($pdo->steps === [], 'Busy sync attempted to mutate data');
    $pdo = new CalendarPDO();
    $pdo->transaction = true;
    calendarReject(fn() => googleCalendarSync($pdo), 'transazione');

    $appointment = ['id' => 42, 'data_appuntamento' => date('Y-m-d'), 'ora_inizio' => '14:00:00',
        'ora_fine' => '15:00:00', 'stato' => 'in_attesa', 'metodo_pagamento' => 'studio',
        'stato_pagamento' => 'da_saldare', 'note' => '', 'studio_nome' => 'Studio',
        'servizio_nome' => 'Test', 'prezzo' => 60, 'cliente_nome' => 'Test', 'cliente_cognome' => 'Customer',
        'cliente_email' => 'customer@example.test', 'cliente_telefono' => ''];
    $link = calendarStep('SET google_event_id = :event');
    $link['params'] = [':event' => 'created-event', ':id' => 42, ':expected_status' => 'in_attesa'];
    $pdo = new CalendarPDO([
        calendarStep('GET_LOCK', 1), calendarStep('INSERT INTO google_calendar_stato'),
        calendarStep('SELECT sync_token', ['sync_token' => 'old-token']),
        calendarStep('UPDATE google_calendar_stato SET sync_token'),
        calendarStep('DELETE FROM agenda_blocchi WHERE data_blocco'),
        calendarStep('WHERE a.google_event_id IS NULL', [$appointment]), $link,
        calendarStep("WHERE stato = 'annullato'", [['id' => 43, 'google_event_id' => 'cancelled-event']]),
        calendarStep('UPDATE appuntamenti SET google_event_id = NULL'),
        calendarStep('SET last_sync_at = NOW(), last_error = NULL'), calendarStep('RELEASE_LOCK', 1),
    ]);
    $result = googleCalendarSync($pdo);
    calendarCheck($result['eventi_creati'] === 1 && $result['appuntamenti_confermati'] === 1, 'Booking not exported and confirmed');
    calendarCheck($result['eventi_rimossi'] === 1 && $deleted === ['cancelled-event'], 'Cancelled event not deleted');
    calendarCheck($emails === 1 && $result['email_conferma_inviate'] === 1, 'Confirmation email missing');
    calendarCheck($inserted[0]['extendedProperties']['private'][GOOGLE_SYNC_APPOINTMENT_PROPERTY] === '42', 'Missing event identity');
    calendarCheck($pdo->steps === [], 'Sync failed to release lock');

    $pdo = new CalendarPDO([calendarStep('GET_LOCK', 1), calendarStep('INSERT INTO google_calendar_stato')]);
    $pdo->steps[] = calendarStep('SELECT sync_token', ['sync_token' => 'old-token']);
    $pdo->steps[] = calendarStep('UPDATE google_calendar_stato SET sync_token');
    $pdo->steps[] = calendarStep('DELETE FROM agenda_blocchi WHERE data_blocco');
    $pdo->steps[] = calendarStep('WHERE a.google_event_id IS NULL', []);
    $pdo->steps[] = calendarStep("WHERE stato = 'annullato'", []);
    $pdo->steps[] = calendarStep('SET last_sync_at = NOW(), last_error = NULL');
    $pdo->steps[] = calendarStep('RELEASE_LOCK', 1);
    googleCalendarSyncAfterAdminChange($pdo);
    calendarCheck(str_contains($_SESSION['calendar_sync_notice'], 'completata'), 'Admin success not shown');
    calendarCheck($emails === 1 && count($inserted) === 1, 'Repeated notification must not reconfirm or resend');

    $failure = calendarStep('INSERT INTO google_calendar_stato');
    $failure['verify'] = fn() => throw new GoogleCalendarException('database write failed');
    $pdo = new CalendarPDO([calendarStep('GET_LOCK', 1), $failure, calendarStep('RELEASE_LOCK', 1)]);
    calendarReject(fn() => googleCalendarSync($pdo), 'database write failed');
    calendarCheck($pdo->steps === [], 'Failed sync must release lock');

    foreach (['10:00:00', '12:00:00', null] as $start) {
        $event = ['id' => 'manual-event', 'start' => ['dateTime' => '2027-06-15T' . ($start ?? '12:00:00') . '+02:00'],
            'end' => ['dateTime' => '2027-06-15T' . ($start === '10:00:00' ? '11:00:00' : '13:00:00') . '+02:00']];
        if ($start === null) $event['status'] = 'cancelled';
        $listResponse = ['items' => [$event], 'nextSyncToken' => 'updated-token'];
        $steps = [calendarStep('SELECT sync_token', ['sync_token' => 'previous-token'])];
        if ($start === null) $steps[] = calendarStep('UPDATE appuntamenti SET google_event_id = NULL');
        $remove = calendarStep('DELETE FROM agenda_blocchi WHERE google_event_id');
        $remove['params'] = [':event' => 'manual-event'];
        $steps[] = $remove;
        if ($start !== null) {
            $store = calendarStep('INSERT INTO agenda_blocchi');
            $store['params'] = [':event' => 'manual-event', ':data' => '2027-06-15',
                ':start' => $start, ':end' => $start === '10:00:00' ? '11:00:00' : '13:00:00', ':titolo' => null];
            $steps[] = $store;
        }
        $steps[] = calendarStep('UPDATE google_calendar_stato SET sync_token');
        $steps[] = calendarStep('DELETE FROM agenda_blocchi WHERE data_blocco');
        $pdo = new CalendarPDO($steps);
        googleSyncPullBlocks($pdo);
        calendarCheck($pdo->steps === [], 'Google create/move/delete not applied to local blocks');
        $blocks = $start === null ? [] : googleEventToBlocks($event, 'Europe/Rome');
        $available = bookingAvailableSlots('2027-06-15', 60,
            [['ora_inizio' => '09:00:00', 'ora_fine' => '14:00:00']], $blocks,
            new DateTimeImmutable('2027-06-14'));
        calendarCheck(in_array('10:00', $available, true) === ($start !== '10:00:00'), 'Old slot not released after Google move/delete');
        calendarCheck(in_array('12:00', $available, true) === ($start !== '12:00:00'), 'Moved Google event not blocking new slot');
    }

    $config['GOOGLE_CALENDAR_ID'] = '';
    googleCalendarSyncAfterAdminChange(new CalendarPDO());
    calendarCheck(str_contains($_SESSION['calendar_sync_notice'], 'non configurata'), 'Missing configuration hidden');
    $config['GOOGLE_CALENDAR_ID'] = 'test-calendar';
    googleCalendarSyncAfterAdminChange(new CalendarPDO([calendarStep('GET_LOCK', 0)]));
    calendarCheck(str_contains($_SESSION['calendar_sync_notice'], 'non riuscita'), 'Admin sync error hidden');

    foreach (['booking-admin.php' => 2, 'booking-admin-advanced.php' => 3] as $file => $expected) {
        calendarCheck(substr_count(file_get_contents($root . '/' . $file), 'googleCalendarSyncAfterAdminChange($pdo)') === $expected, 'Admin mutation hook missing: ' . $file);
    }

    copy($root . '/google-calendar-webhook.php', $temporary . '/google-calendar-webhook.php');
    foreach (['app-config.php', 'google-calendar-push.php', 'google-calendar-sync.php'] as $file) {
        file_put_contents($temporary . '/' . $file, "<?php\n");
    }
    $bootstrap = <<<'PHP'
$_SERVER['REQUEST_METHOD'] = $argv[1];
function dbConnection(): PDO { return new class extends PDO { public function __construct() {} }; }
function googleCalendarNotificationStatus(PDO $pdo, array $headers): int { return (int)$GLOBALS['argv'][2]; }
function googleCalendarSync(PDO $pdo): array {
    if ($GLOBALS['argv'][3] === 'fail') throw new RuntimeException('simulated sync failure');
    $GLOBALS['synced'] = true;
    return [];
}
register_shutdown_function(function () { echo (!empty($GLOBALS['synced']) ? 'synced;' : '') . 'status=' . http_response_code(); });
require $argv[4];
PHP;
    foreach ([
        ['GET', '200', 'ok', 'status=405'],
        ['POST', '403', 'ok', 'status=403'],
        ['POST', '204', 'ok', 'status=204'],
        ['POST', '503', 'ok', 'status=503'],
        ['POST', '200', 'ok', 'synced;status=204'],
        ['POST', '200', 'fail', 'status=503'],
    ] as [$method, $notificationStatus, $operation, $expected]) {
        $process = proc_open(
            [PHP_BINARY, '-r', $bootstrap, $method, $notificationStatus, $operation, $temporary . '/google-calendar-webhook.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
        );
        if (!is_resource($process)) throw new RuntimeException('Cannot run webhook test');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        calendarCheck(proc_close($process) === 0 && $output === $expected, 'Wrong webhook response: ' . $output . $errors);
    }
    echo "Google Calendar: {$checks} checks passed.\n";
} finally {
    foreach (['google-calendar-push.php', 'google-calendar-sync.php', 'google-calendar-client.php', 'booking-mailer.php', 'app-config.php', 'google-calendar-webhook.php'] as $file) {
        if (is_file($temporary . '/' . $file)) unlink($temporary . '/' . $file);
    }
    rmdir($temporary);
}
