<?php

require_once dirname(__DIR__) . '/booking-slots.php';
date_default_timezone_set('Europe/Rome');

$date = '2027-06-15';
$now = new DateTimeImmutable('2027-06-14 12:00');
$schedules = [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 60]];
$first = ['ora_inizio' => '10:00', 'ora_fine' => '11:00'];
$second = ['ora_inizio' => '11:15', 'ora_fine' => '12:15'];
$checks = 0;

function checkSlots(string $label, array $expected, array $actual): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $checks++;
}

function quarterHourSlots(string $start, string $end): array
{
    $slots = [];
    $slot = new DateTimeImmutable('2000-01-01 ' . $start);
    $last = new DateTimeImmutable('2000-01-01 ' . $end);
    while ($slot <= $last) {
        $slots[] = $slot->format('H:i');
        $slot = $slot->modify('+15 minutes');
    }
    return $slots;
}

checkSlots('Empty agenda offers quarter-hour starts', quarterHourSlots('10:00', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [], $now));
checkSlots('First booking resumes after the fifteen minute buffer', quarterHourSlots('11:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [$first], $now));
checkSlots('Second booking adds another break', quarterHourSlots('12:30', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [$first, $second], $now));
checkSlots('Unsorted appointments', quarterHourSlots('12:30', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [$second, $first], $now));
checkSlots('Cancelled booking removed', array_merge(['10:00'], quarterHourSlots('12:30', '14:00')),
    bookingAvailableSlots($date, 60, $schedules, [$second], $now));
checkSlots('Gap before a future booking', quarterHourSlots('12:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:00', 'ora_fine' => '12:00']], $now));
checkSlots('Existing back-to-back bookings stay unchanged', quarterHourSlots('12:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [$first, ['ora_inizio' => '11:00', 'ora_fine' => '12:00']], $now));
checkSlots('Short treatment releases quarter-hour slots and the closing slot', quarterHourSlots('10:45', '14:30'),
    bookingAvailableSlots($date, 30, $schedules, [['ora_inizio' => '10:00', 'ora_fine' => '10:30']], $now));
checkSlots('Five minute boundary rejected', quarterHourSlots('12:30', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:05', 'ora_fine' => '12:05']], $now));
checkSlots('Fifteen minute boundary before booking allowed', array_merge(['10:00'], quarterHourSlots('12:30', '14:00')),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:15', 'ora_fine' => '12:15']], $now));
checkSlots('Fourteen minute boundary before booking rejected', quarterHourSlots('12:30', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:14', 'ora_fine' => '12:14']], $now));
checkSlots('Fifteen minute boundary after booking allowed', quarterHourSlots('11:00', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '09:45', 'ora_fine' => '10:45']], $now));
checkSlots('Fourteen minute boundary after booking rejected', quarterHourSlots('11:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '09:46', 'ora_fine' => '10:46']], $now));

$blocked = bookingSlotOptions($date, 60, $schedules, [['ora_inizio' => '11:05', 'ora_fine' => '12:05']], $now);
checkSlots('Blocked slot explains the fifteen minute break', [true],
    [count(array_filter($blocked, static fn(array $option): bool =>
        !$option['available'] && strpos((string)$option['message'], '15 minuti') !== false)) > 0]);
checkSlots('Past slots omitted', quarterHourSlots('11:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [$first], new DateTimeImmutable($date . ' 11:05')));
checkSlots('Separate windows retain their starting grid', quarterHourSlots('14:00', '15:00'),
    bookingAvailableSlots($date, 60, [
        ['ora_inizio' => '10:00', 'ora_fine' => '12:00', 'intervallo_minuti' => 60],
        ['ora_inizio' => '14:00', 'ora_fine' => '16:00', 'intervallo_minuti' => 60],
    ], [$first], $now));
checkSlots('No schedules', [], bookingAvailableSlots($date, 60, [], [$first], $now));
checkSlots('End of window allowed', ['10:00'],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '11:00', 'intervallo_minuti' => 60]], [], $now));
checkSlots('Thirty minute schedule uses quarter-hour customer slots', quarterHourSlots('11:15', '14:00'),
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 30]], [$first], $now));
checkSlots('Fifteen minute schedule uses quarter-hour customer slots', quarterHourSlots('11:15', '14:00'),
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 15]], [$first], $now));
checkSlots('No room for the break at closing', [],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '12:00', 'intervallo_minuti' => 60]], [$first], $now));
checkSlots('Appointment before window blocks starts without the full buffer', quarterHourSlots('10:15', '14:00'),
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '09:00', 'ora_fine' => '10:00']], $now));

$options = bookingSlotOptions($date, 120, $schedules, [$first], $now);
checkSlots('All quarter-hour starts remain visible for long treatments', quarterHourSlots('10:00', '14:45'),
    array_column($options, 'time'));
checkSlots('Only starts fitting a two hour treatment and buffer are enabled', quarterHourSlots('11:15', '13:00'),
    array_column(array_filter($options, static fn(array $option): bool => $option['available']), 'time'));
checkSlots('Thirty minute treatment can use the final closing slot', quarterHourSlots('10:00', '14:30'),
    bookingAvailableSlots($date, 30, $schedules, [], $now));

$appointments = [$second, $first, ['ora_inizio' => '13:45', 'ora_fine' => '14:15']];
$originalAppointments = $appointments;
foreach ([15, 30, 60, 90] as $duration) {
    foreach ([15, 30, 60] as $step) {
        $slots = bookingAvailableSlots($date, $duration, [
            ['ora_inizio' => '10:00', 'ora_fine' => '17:00', 'intervallo_minuti' => $step],
        ], $appointments, $now);
        foreach ($slots as $time) {
            $start = new DateTimeImmutable($date . ' ' . $time);
            $end = $start->modify('+' . $duration . ' minutes');
            if ((int)$start->format('i') % BOOKING_DISPLAY_INTERVAL_MINUTES !== 0) {
                throw new RuntimeException('Slot is not aligned to the quarter-hour grid.');
            }
            if ($start < new DateTimeImmutable($date . ' 10:00') || $end > new DateTimeImmutable($date . ' 17:00')) {
                throw new RuntimeException('Slot outside the opening window.');
            }
            foreach ($appointments as $appointment) {
                $before = new DateTimeImmutable($date . ' ' . $appointment['ora_inizio']);
                $after = new DateTimeImmutable($date . ' ' . $appointment['ora_fine']);
                if ($start < $after->modify('+15 minutes') && $end > $before->modify('-15 minutes')) {
                    throw new RuntimeException('Slot violates the fifteen minute break.');
                }
            }
        }
        $checks++;
    }
}
if ($originalAppointments !== $appointments) {
    throw new RuntimeException('Existing appointments changed.');
}
$checks++;

echo $checks . " slot checks passed.\n";
