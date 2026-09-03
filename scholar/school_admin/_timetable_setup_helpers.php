<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TIMETABLE DAY STRUCTURE: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/timetable_setup.php so the classic page and
| the JSON endpoint (api/admin/timetable_setup.php) build the period grid
| identically.
|--------------------------------------------------------------------------
*/

const ADMIN_TIMETABLE_DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/** @return array{ok:bool,message:string} */
function admin_timetable_quick_setup(PDO $pdo, int $schoolId, array $days, string $dayStart, int $lessonMinutes, int $periodsPerDay, int $breakAfter, int $breakMinutes, int $lunchAfter, int $lunchMinutes): array
{
    if (empty($days)) {
        return ['ok' => false, 'message' => 'Pick at least one day.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $dayStart)) {
        return ['ok' => false, 'message' => 'Day start time looks wrong.'];
    }

    $lessonMinutes = max(20, min(120, $lessonMinutes));
    $periodsPerDay = max(1, min(14, $periodsPerDay));
    $breakMinutes = max(0, min(60, $breakMinutes));
    $lunchMinutes = max(0, min(90, $lunchMinutes));

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM timetable_periods WHERE school_id = ? AND day_of_week = ?');
        $ins = $pdo->prepare('
            INSERT INTO timetable_periods (school_id, day_of_week, period_number, label, start_time, end_time, is_teaching_period)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                continue;
            }
            $del->execute([$schoolId, $day]);

            $cursor = DateTime::createFromFormat('H:i', $dayStart);
            $periodNumber = 0;

            for ($lesson = 1; $lesson <= $periodsPerDay; $lesson++) {
                $periodNumber++;
                $start = clone $cursor;
                $cursor->modify("+{$lessonMinutes} minutes");
                $ins->execute([$schoolId, $day, $periodNumber, "Period {$lesson}", $start->format('H:i:s'), $cursor->format('H:i:s'), 1]);

                if ($breakAfter > 0 && $lesson === $breakAfter && $breakMinutes > 0) {
                    $periodNumber++;
                    $start = clone $cursor;
                    $cursor->modify("+{$breakMinutes} minutes");
                    $ins->execute([$schoolId, $day, $periodNumber, 'Break', $start->format('H:i:s'), $cursor->format('H:i:s'), 0]);
                }
                if ($lunchAfter > 0 && $lesson === $lunchAfter && $lunchMinutes > 0) {
                    $periodNumber++;
                    $start = clone $cursor;
                    $cursor->modify("+{$lunchMinutes} minutes");
                    $ins->execute([$schoolId, $day, $periodNumber, 'Lunch', $start->format('H:i:s'), $cursor->format('H:i:s'), 0]);
                }
            }
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Day structure saved. Any previously generated timetable for the affected days was cleared -- regenerate when ready.'];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not save the day structure. Please try again.'];
    }
}

/** @return array{ok:bool,message:string} */
function admin_timetable_update_period(PDO $pdo, int $schoolId, int $periodId, string $label, string $startTime, string $endTime, bool $isTeaching): array
{
    if ($label === '' || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        return ['ok' => false, 'message' => 'Please fill in a label and valid start/end times.'];
    }
    $pdo->prepare('
        UPDATE timetable_periods SET label = ?, start_time = ?, end_time = ?, is_teaching_period = ?
        WHERE id = ? AND school_id = ?
    ')->execute([$label, $startTime, $endTime, $isTeaching ? 1 : 0, $periodId, $schoolId]);
    return ['ok' => true, 'message' => 'Period updated.'];
}

function admin_timetable_delete_period(PDO $pdo, int $schoolId, int $periodId): void
{
    $pdo->prepare('DELETE FROM timetable_periods WHERE id = ? AND school_id = ?')->execute([$periodId, $schoolId]);
}

/** @return array<int,array> periods grouped by day_of_week */
function admin_timetable_fetch_by_day(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM timetable_periods WHERE school_id = ? ORDER BY day_of_week, period_number');
    $stmt->execute([$schoolId]);

    $byDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $byDay[(int) $p['day_of_week']][] = $p;
    }
    return $byDay;
}
