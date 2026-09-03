<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TIMETABLE GENERATOR (engine)
|--------------------------------------------------------------------------
| A practical greedy scheduler, not a full constraint solver: it places
| the busiest teachers first (most total periods/week across all their
| classes -- hardest to fit, so they get first pick of the grid), and for
| each teaching assignment tries to spread its periods across different
| days before ever doubling up on one day. Two things it does NOT attempt:
| double/consecutive periods for practicals, and backtracking if an early
| placement blocks a later one. Real schools' constraints are genuinely
| open-ended (room availability, teacher unavailability on specific days,
| double-period practicals, ...) -- a heuristic first draft that reports
| exactly what it couldn't place, for a human to fix by hand, is more
| honest and more useful than a "solver" that silently produces a subtly
| wrong timetable.
|
| Every placement is checked against two things before being accepted:
| the class isn't already busy in that slot, and the teacher isn't already
| busy in that slot (across ANY class, not just this one) -- the same two
| constraints timetable_entries' UNIQUE keys enforce as a DB-level
| backstop.
|--------------------------------------------------------------------------
*/

function scholar_generate_timetable(PDO $pdo, int $schoolId, string $term, string $year): array
{
    $periodsStmt = $pdo->prepare("
        SELECT id, day_of_week, period_number FROM timetable_periods
        WHERE school_id = ? AND is_teaching_period = 1
        ORDER BY day_of_week, period_number
    ");
    $periodsStmt->execute([$schoolId]);
    $slots = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($slots)) {
        return ['error' => 'No teaching periods are set up yet. Set up the day structure first.'];
    }

    $reqStmt = $pdo->prepare("
        SELECT ta.id AS assignment_id, ta.teacher_id, ta.class_id, ta.subject_id, ta.paper_number, ta.periods_per_week
        FROM teacher_assignments ta
        WHERE ta.school_id = ?
    ");
    $reqStmt->execute([$schoolId]);
    $requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requirements)) {
        return ['error' => 'No teaching assignments found. Assign teachers to subjects and classes first.'];
    }

    // Most-constrained-first: a teacher's TOTAL weekly load across every
    // class they teach, not just this one assignment's periods_per_week --
    // a teacher spread across many classes is harder to fit than the raw
    // period count of any single assignment suggests.
    $teacherLoad = [];
    foreach ($requirements as $r) {
        $teacherLoad[$r['teacher_id']] = ($teacherLoad[$r['teacher_id']] ?? 0) + (int) $r['periods_per_week'];
    }
    usort($requirements, function ($a, $b) use ($teacherLoad) {
        return $teacherLoad[$b['teacher_id']] <=> $teacherLoad[$a['teacher_id']];
    });

    $classBusy = [];
    $teacherBusy = [];
    $placements = [];
    $unplaced = [];

    foreach ($requirements as $req) {
        $needed = (int) $req['periods_per_week'];
        $placedForThisReq = 0;
        $daysUsedForThisReq = [];

        // Pass 1: at most one placement per day, so the same subject
        // doesn't land twice on one day while slots on other days are
        // still free.
        foreach ($slots as $slot) {
            if ($placedForThisReq >= $needed) {
                break;
            }
            $day = (int) $slot['day_of_week'];
            if (in_array($day, $daysUsedForThisReq, true)) {
                continue;
            }
            $classKey = $req['class_id'] . ':' . $day . ':' . $slot['id'];
            $teacherKey = $req['teacher_id'] . ':' . $day . ':' . $slot['id'];
            if (isset($classBusy[$classKey]) || isset($teacherBusy[$teacherKey])) {
                continue;
            }
            $classBusy[$classKey] = true;
            $teacherBusy[$teacherKey] = true;
            $daysUsedForThisReq[] = $day;
            $placements[] = ['req' => $req, 'day' => $day, 'period_id' => $slot['id']];
            $placedForThisReq++;
        }

        // Pass 2: still short (more periods/week needed than teaching
        // days, or every day's free slot for this class/teacher got used
        // elsewhere) -- allow doubling up on a day now.
        if ($placedForThisReq < $needed) {
            foreach ($slots as $slot) {
                if ($placedForThisReq >= $needed) {
                    break;
                }
                $day = (int) $slot['day_of_week'];
                $classKey = $req['class_id'] . ':' . $day . ':' . $slot['id'];
                $teacherKey = $req['teacher_id'] . ':' . $day . ':' . $slot['id'];
                if (isset($classBusy[$classKey]) || isset($teacherBusy[$teacherKey])) {
                    continue;
                }
                $classBusy[$classKey] = true;
                $teacherBusy[$teacherKey] = true;
                $placements[] = ['req' => $req, 'day' => $day, 'period_id' => $slot['id']];
                $placedForThisReq++;
            }
        }

        if ($placedForThisReq < $needed) {
            $unplaced[] = [
                'assignment_id' => $req['assignment_id'],
                'teacher_id' => (int) $req['teacher_id'],
                'class_id' => (int) $req['class_id'],
                'subject_id' => (int) $req['subject_id'],
                'needed' => $needed,
                'placed' => $placedForThisReq,
            ];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM timetable_entries WHERE school_id = ? AND term = ? AND academic_year = ?")
            ->execute([$schoolId, $term, $year]);

        $ins = $pdo->prepare("
            INSERT INTO timetable_entries (school_id, class_id, subject_id, teacher_id, paper_number, day_of_week, period_id, term, academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($placements as $p) {
            $ins->execute([
                $schoolId, $p['req']['class_id'], $p['req']['subject_id'], $p['req']['teacher_id'],
                $p['req']['paper_number'], $p['day'], $p['period_id'], $term, $year,
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['error' => 'Could not save the generated timetable. Please try again.'];
    }

    return [
        'placed_count' => count($placements),
        'requested_count' => array_sum(array_map(fn ($r) => (int) $r['periods_per_week'], $requirements)),
        'unplaced' => $unplaced,
    ];
}
