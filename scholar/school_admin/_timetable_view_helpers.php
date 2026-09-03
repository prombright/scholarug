<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR TIMETABLE VIEW HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/timetable_view.php page and
| scholar/api/admin/timetable_view.php. Logic ported verbatim from the
| original page.
*/

const SCHOLAR_TIMETABLE_DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/** Current term/year + the school's class list, for the class picker. */
function admin_timetable_view_overview(PDO $pdo, int $school_id): array
{
    $schoolStmt = $pdo->prepare("SELECT current_term, current_year FROM schools WHERE id = ?");
    $schoolStmt->execute([$school_id]);
    $school = $schoolStmt->fetch();
    $term = $school['current_term'] ?: 'Term 1';
    $year = $school['current_year'] ?: (string) date('Y');

    $classesStmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name");
    $classesStmt->execute([$school_id]);
    $classes = $classesStmt->fetchAll();

    return ['term' => $term, 'year' => $year, 'classes' => $classes];
}

/** The full day/period grid (breaks included) plus this class's assignments and current entries. */
function admin_timetable_view_grid(PDO $pdo, int $school_id, string $term, string $year, int $selectedClassId): array
{
    $classAssignments = [];
    if ($selectedClassId > 0) {
        $caStmt = $pdo->prepare("
            SELECT ta.id, ta.subject_id, ta.teacher_id, sub.subject_name, sub.papers_count, ta.paper_number, st.first_name, st.last_name
            FROM teacher_assignments ta
            JOIN subjects sub ON sub.id = ta.subject_id
            JOIN staff st ON st.staff_id = ta.teacher_id
            WHERE ta.school_id = ? AND ta.class_id = ?
            ORDER BY sub.subject_name, ta.paper_number
        ");
        $caStmt->execute([$school_id, $selectedClassId]);
        $classAssignments = $caStmt->fetchAll();
    }

    // The full grid structure comes from timetable_periods (so breaks/lunch
    // show up even though they never have an entry), one row per distinct
    // period_number/label/time combination that appears on ANY day -- schools
    // using Quick Setup have identical structure across their chosen days, so
    // this naturally produces one clean row per lesson slot.
    $gridRows = [];
    $daysPresent = [];
    $periodsStmt = $pdo->prepare("SELECT * FROM timetable_periods WHERE school_id = ? ORDER BY period_number, day_of_week");
    $periodsStmt->execute([$school_id]);
    foreach ($periodsStmt->fetchAll() as $p) {
        $daysPresent[(int) $p['day_of_week']] = true;
        $rowKey = $p['period_number'];
        if (!isset($gridRows[$rowKey])) {
            $gridRows[$rowKey] = ['label' => $p['label'], 'start' => $p['start_time'], 'end' => $p['end_time'], 'is_teaching' => (int) $p['is_teaching_period'], 'by_day' => []];
        }
        $gridRows[$rowKey]['by_day'][(int) $p['day_of_week']] = (int) $p['id'];
    }
    ksort($gridRows);
    $activeDays = array_keys($daysPresent);
    sort($activeDays);

    $entries = [];
    if ($selectedClassId > 0) {
        $eStmt = $pdo->prepare("
            SELECT te.day_of_week, te.period_id, te.subject_id, te.teacher_id, sub.subject_name, sub.papers_count, te.paper_number,
                   st.first_name, st.last_name
            FROM timetable_entries te
            JOIN subjects sub ON sub.id = te.subject_id
            JOIN staff st ON st.staff_id = te.teacher_id
            WHERE te.school_id = ? AND te.class_id = ? AND te.term = ? AND te.academic_year = ?
        ");
        $eStmt->execute([$school_id, $selectedClassId, $term, $year]);
        foreach ($eStmt->fetchAll() as $e) {
            $entries[(int) $e['day_of_week'] . ':' . (int) $e['period_id']] = $e;
        }
    }

    return ['grid_rows' => $gridRows, 'active_days' => $activeDays, 'class_assignments' => $classAssignments, 'entries' => $entries];
}

/** Manual override of one slot: reassign or clear it. Same two conflict rules the generator itself enforces. */
function admin_timetable_view_save_cell(PDO $pdo, int $school_id, string $term, string $year, int $cellClassId, int $day, int $periodId, int $assignmentId): array
{
    $newEntry = null;
    if ($assignmentId > 0) {
        $aStmt = $pdo->prepare("SELECT teacher_id, subject_id, paper_number FROM teacher_assignments WHERE id = ? AND school_id = ? AND class_id = ?");
        $aStmt->execute([$assignmentId, $school_id, $cellClassId]);
        $newEntry = $aStmt->fetch();
        if (!$newEntry) {
            return ['ok' => false, 'message' => 'That assignment no longer exists for this class.'];
        }
    }

    if ($newEntry) {
        $conflictStmt = $pdo->prepare("
            SELECT te.id FROM timetable_entries te
            WHERE te.school_id = ? AND te.teacher_id = ? AND te.day_of_week = ? AND te.period_id = ?
              AND te.term = ? AND te.academic_year = ? AND te.class_id != ?
        ");
        $conflictStmt->execute([$school_id, $newEntry['teacher_id'], $day, $periodId, $term, $year, $cellClassId]);
        if ($conflictStmt->fetchColumn()) {
            return ['ok' => false, 'message' => 'That teacher already has a lesson with another class at this exact time.'];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            DELETE FROM timetable_entries
            WHERE school_id = ? AND class_id = ? AND day_of_week = ? AND period_id = ? AND term = ? AND academic_year = ?
        ")->execute([$school_id, $cellClassId, $day, $periodId, $term, $year]);

        if ($newEntry) {
            $pdo->prepare("
                INSERT INTO timetable_entries (school_id, class_id, subject_id, teacher_id, paper_number, day_of_week, period_id, term, academic_year)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$school_id, $cellClassId, $newEntry['subject_id'], $newEntry['teacher_id'], $newEntry['paper_number'], $day, $periodId, $term, $year]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Slot updated.'];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not save that change. Please try again.'];
    }
}
