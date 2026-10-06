<?php

const BOOKING_BREAK_MINUTES = 5;

function bookingAvailableSlots(
    string $date,
    int $duration,
    array $schedules,
    array $appointments,
    DateTimeImmutable $now
): array {
    if ($duration < 1) {
        throw new InvalidArgumentException('La durata del trattamento deve essere positiva.');
    }

    $occupied = [];
    foreach ($appointments as $appointment) {
        $occupied[] = [
            'start' => new DateTimeImmutable($date . ' ' . $appointment['ora_inizio']),
            'end' => new DateTimeImmutable($date . ' ' . $appointment['ora_fine']),
        ];
    }
    usort($occupied, static function (array $left, array $right): int {
        return $left['start'] <=> $right['start'];
    });

    $available = [];
    foreach ($schedules as $schedule) {
        $slot = new DateTimeImmutable($date . ' ' . $schedule['ora_inizio']);
        $end = new DateTimeImmutable($date . ' ' . $schedule['ora_fine']);
        $lastStart = $end->modify('-' . $duration . ' minutes');
        $step = max(5, (int)$schedule['intervallo_minuti']);

        while ($slot <= $lastStart) {
            foreach ($occupied as $appointment) {
                $earliestNextStart = $appointment['end']->modify('+' . BOOKING_BREAK_MINUTES . ' minutes');
                $latestPreviousEnd = $appointment['start']->modify('-' . BOOKING_BREAK_MINUTES . ' minutes');
                $slotEnd = $slot->modify('+' . $duration . ' minutes');
                if ($slot < $earliestNextStart && $slotEnd > $latestPreviousEnd) {
                    // Reanchor the remaining free slots, never move an existing appointment.
                    $slot = $earliestNextStart;
                }
            }

            if ($slot > $lastStart) {
                break;
            }
            if ($slot > $now) {
                $available[$slot->format('H:i')] = true;
            }
            $slot = $slot->modify('+' . $step . ' minutes');
        }
    }

    $slots = array_keys($available);
    sort($slots);
    return $slots;
}
