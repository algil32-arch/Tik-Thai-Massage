<?php

const BOOKING_BREAK_MINUTES = 15;
const BOOKING_DISPLAY_INTERVAL_MINUTES = 15;
const BOOKING_MIN_TREATMENT_MINUTES = 30;

function bookingSlotOptions(
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

    $displayTimes = [];
    foreach ($schedules as $schedule) {
        $slot = new DateTimeImmutable($date . ' ' . $schedule['ora_inizio']);
        $end = new DateTimeImmutable($date . ' ' . $schedule['ora_fine']);
        while ($slot < $end) {
            if ($slot > $now) {
                $displayTimes[$slot->format('H:i')] = $slot;
            }
            $slot = $slot->modify('+' . BOOKING_DISPLAY_INTERVAL_MINUTES . ' minutes');
        }

    }

    ksort($displayTimes);
    $options = [];
    foreach ($displayTimes as $time => $slot) {
        $slotEnd = $slot->modify('+' . $duration . ' minutes');
        $fitsSchedule = false;
        foreach ($schedules as $schedule) {
            $scheduleStart = new DateTimeImmutable($date . ' ' . $schedule['ora_inizio']);
            $scheduleEnd = new DateTimeImmutable($date . ' ' . $schedule['ora_fine']);
            if ($slot >= $scheduleStart && $slotEnd <= $scheduleEnd) {
                $fitsSchedule = true;
                break;
            }
        }

        $message = null;
        if (!$fitsSchedule) {
            $message = 'Il trattamento selezionato non termina entro l’orario di apertura.';
        } else {
            foreach ($occupied as $appointment) {
                $earliestStartAfter = $appointment['end']->modify('+' . BOOKING_BREAK_MINUTES . ' minutes');
                $latestEndBefore = $appointment['start']->modify('-' . BOOKING_BREAK_MINUTES . ' minutes');
                if ($slot < $earliestStartAfter && $slotEnd > $latestEndBefore) {
                    $message = 'Il trattamento selezionato si sovrappone a un impegno o non lascia i ' . BOOKING_BREAK_MINUTES . ' minuti necessari tra gli appuntamenti.';
                    break;
                }
            }
        }

        $options[] = [
            'time' => $time,
            'available' => $message === null,
            'message' => $message,
        ];
    }

    return $options;
}

function bookingAvailableSlots(
    string $date,
    int $duration,
    array $schedules,
    array $appointments,
    DateTimeImmutable $now
): array {
    $available = [];
    foreach (bookingSlotOptions($date, $duration, $schedules, $appointments, $now) as $option) {
        if ($option['available']) {
            $available[] = $option['time'];
        }
    }
    return $available;
}

/**
 * Impegni importati da Google Calendar: valgono per tutte le sedi perche
 * la professionista e una sola. Se la tabella non esiste ancora (migrazione
 * non importata) la disponibilita resta quella calcolata dal solo database.
 */
function agendaBlocksForDate(PDO $pdo, string $date): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT ora_inizio, ora_fine FROM agenda_blocchi WHERE data_blocco = :date'
        );
        $stmt->execute([':date' => $date]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
