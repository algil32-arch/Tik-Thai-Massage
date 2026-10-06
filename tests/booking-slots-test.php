<?php

require_once dirname(__DIR__) . '/booking-slots.php';
date_default_timezone_set('Europe/Rome');

$date = '2027-06-15';
$now = new DateTimeImmutable('2027-06-14 12:00');
$schedules = [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 60]];
$first = ['ora_inizio' => '10:00', 'ora_fine' => '11:00'];
$second = ['ora_inizio' => '11:05', 'ora_fine' => '12:05'];
$checks = 0;

function checkSlots(string $label, array $expected, array $actual): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    $checks++;
}

checkSlots('Empty agenda', ['10:00', '11:00', '12:00', '13:00', '14:00'],
    bookingAvailableSlots($date, 60, $schedules, [], $now));
checkSlots('First booking shifts remaining slots', ['11:05', '12:05', '13:05'],
    bookingAvailableSlots($date, 60, $schedules, [$first], $now));
checkSlots('Second booking adds another break', ['12:10', '13:10'],
    bookingAvailableSlots($date, 60, $schedules, [$first, $second], $now));
checkSlots('Unsorted appointments', ['12:10', '13:10'],
    bookingAvailableSlots($date, 60, $schedules, [$second, $first], $now));
checkSlots('Cancelled booking removed', ['10:00', '12:10', '13:10'],
    bookingAvailableSlots($date, 60, $schedules, [$second], $now));
checkSlots('Gap before a future booking', ['12:05', '13:05'],
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:00', 'ora_fine' => '12:00']], $now));
checkSlots('Existing back-to-back bookings stay unchanged', ['12:05', '13:05'],
    bookingAvailableSlots($date, 60, $schedules, [$first, ['ora_inizio' => '11:00', 'ora_fine' => '12:00']], $now));
checkSlots('Short treatment releases next slot after 5 minutes', ['10:35', '11:35', '12:35', '13:35'],
    bookingAvailableSlots($date, 30, $schedules, [['ora_inizio' => '10:00', 'ora_fine' => '10:30']], $now));
checkSlots('Five minute boundary allowed', ['10:00', '12:10', '13:10'],
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:05', 'ora_fine' => '12:05']], $now));
checkSlots('Four minute boundary rejected', ['12:09', '13:09'],
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '11:04', 'ora_fine' => '12:04']], $now));
checkSlots('Past slots omitted', ['12:05', '13:05'],
    bookingAvailableSlots($date, 60, $schedules, [$first], new DateTimeImmutable($date . ' 11:05')));
checkSlots('Separate windows retain their starting grid', ['14:00', '15:00'],
    bookingAvailableSlots($date, 60, [
        ['ora_inizio' => '10:00', 'ora_fine' => '12:00', 'intervallo_minuti' => 60],
        ['ora_inizio' => '14:00', 'ora_fine' => '16:00', 'intervallo_minuti' => 60],
    ], [$first], $now));
checkSlots('No schedules', [], bookingAvailableSlots($date, 60, [], [$first], $now));
checkSlots('End of window allowed', ['10:00'],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '11:00', 'intervallo_minuti' => 60]], [], $now));
checkSlots('Thirty minute step retained', ['11:05', '11:35', '12:05', '12:35', '13:05', '13:35'],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 30]], [$first], $now));
checkSlots('Fifteen minute step retained', ['11:05', '11:20', '11:35', '11:50', '12:05', '12:20', '12:35', '12:50', '13:05', '13:20', '13:35', '13:50'],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '15:00', 'intervallo_minuti' => 15]], [$first], $now));
checkSlots('No room for the break at closing', [],
    bookingAvailableSlots($date, 60, [['ora_inizio' => '10:00', 'ora_fine' => '12:00', 'intervallo_minuti' => 60]], [$first], $now));
checkSlots('Appointment before window delays first slot', ['10:05', '11:05', '12:05', '13:05'],
    bookingAvailableSlots($date, 60, $schedules, [['ora_inizio' => '09:00', 'ora_fine' => '10:00']], $now));

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
            if ($start < new DateTimeImmutable($date . ' 10:00') || $end > new DateTimeImmutable($date . ' 17:00')) {
                throw new RuntimeException('Slot outside the opening window.');
            }
            foreach ($appointments as $appointment) {
                $before = new DateTimeImmutable($date . ' ' . $appointment['ora_inizio']);
                $after = new DateTimeImmutable($date . ' ' . $appointment['ora_fine']);
                if ($start < $after->modify('+5 minutes') && $end > $before->modify('-5 minutes')) {
                    throw new RuntimeException('Slot violates the five minute break.');
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
