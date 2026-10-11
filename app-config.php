<?php
function appConfig(): array
{
    static $config;
    if (isset($config)) {
        return $config;
    }

    $config = [
        'DB_HOST' => getenv('DB_HOST') ?: '',
        'DB_PORT' => getenv('DB_PORT') ?: '3306',
        'DB_NAME' => getenv('DB_NAME') ?: '',
        'DB_USER' => getenv('DB_USER') ?: '',
        'DB_PASS' => getenv('DB_PASS') ?: '',
        'ADMIN_PASSWORD' => getenv('ADMIN_PASSWORD') ?: '',
        'BOOKING_NOTIFICATION_EMAIL' => getenv('BOOKING_NOTIFICATION_EMAIL') ?: 'info@thaitikmassage.it',
        'MAIL_FROM_EMAIL' => getenv('MAIL_FROM_EMAIL') ?: 'info@thaitikmassage.it',
        'SUMUP_API_KEY' => getenv('SUMUP_API_KEY') ?: '',
        'SUMUP_MERCHANT_CODE' => getenv('SUMUP_MERCHANT_CODE') ?: '',
        'SUMUP_CURRENCY' => getenv('SUMUP_CURRENCY') ?: 'EUR',
        'GOOGLE_CALENDAR_ID' => getenv('GOOGLE_CALENDAR_ID') ?: '',
        'GOOGLE_SERVICE_ACCOUNT_FILE' => getenv('GOOGLE_SERVICE_ACCOUNT_FILE') ?: __DIR__ . '/private/google-service-account.json',
        'GOOGLE_CALENDAR_TIMEZONE' => getenv('GOOGLE_CALENDAR_TIMEZONE') ?: 'Europe/Rome',
        'GOOGLE_SYNC_TOKEN' => getenv('GOOGLE_SYNC_TOKEN') ?: '',
    ];

    $privateConfigPath = __DIR__ . '/private/config.local.php';
    if (is_file($privateConfigPath)) {
        $privateConfig = require $privateConfigPath;
        if (!is_array($privateConfig)) {
            throw new RuntimeException('private/config.local.php must return an array.');
        }
        $config = array_replace($config, $privateConfig);
    }

    return $config;
}

function dbConnection(): PDO
{
    $config = appConfig();
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }

    $port = filter_var($config['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($port === false) {
        throw new RuntimeException('Database port is invalid.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'],
        $port,
        $config['DB_NAME']
    );

    return new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
