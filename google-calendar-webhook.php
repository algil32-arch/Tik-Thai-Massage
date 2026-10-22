<?php

date_default_timezone_set('Europe/Rome');
header('Cache-Control: no-store');
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/google-calendar-push.php';
require_once __DIR__ . '/google-calendar-sync.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

try {
    $pdo = dbConnection();
    $status = googleCalendarNotificationStatus($pdo, $_SERVER);
    if ($status !== 200) {
        http_response_code($status);
        exit;
    }
    googleCalendarSync($pdo);
    http_response_code(204);
} catch (Throwable $e) {
    error_log('Google Calendar webhook failed: ' . $e->getMessage());
    http_response_code(503);
}
