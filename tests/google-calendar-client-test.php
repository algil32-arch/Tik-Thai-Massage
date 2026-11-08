<?php

require_once __DIR__ . '/../google-calendar-client.php';

$checks = [
    [googleCalendarDeleteAlreadyGone(404, 'DELETE'), true, 'Deleted event 404 should be idempotent'],
    [googleCalendarDeleteAlreadyGone(410, 'DELETE'), true, 'Deleted event 410 should be idempotent'],
    [googleCalendarDeleteAlreadyGone(410, 'GET'), false, 'Expired sync token must trigger a full resync'],
    [googleCalendarDeleteAlreadyGone(404, 'GET'), false, 'Missing GET resource must remain an error'],
];

foreach ($checks as [$actual, $expected, $message]) {
    if ($actual !== $expected) {
        throw new RuntimeException($message);
    }
}

echo count($checks) . " Google Calendar client checks passed.\n";
