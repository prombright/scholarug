<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR TIMETABLE GENERATE HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/timetable_generate.php page and
| scholar/api/admin/timetable_generate.php. Logic ported verbatim from the
| original page. Requires _timetable_engine.php (scholar_generate_timetable)
| to already be loaded by the caller.
*/

function admin_timetable_generate_overview(PDO $pdo, int $school_id): array
{
    $schoolStmt = $pdo->prepare("SELECT current_term, current_year FROM schools WHERE id = ?");
    $schoolStmt->execute([$school_id]);
    $school = $schoolStmt->fetch();
    $term = $school['current_term'] ?: 'Term 1';
    $year = $school['current_year'] ?: (string) date('Y');

    $periodCountStmt = $pdo->prepare("SELECT COUNT(*) FROM timetable_periods WHERE school_id = ? AND is_teaching_period = 1");
    $periodCountStmt->execute([$school_id]);
    $periodCount = (int) $periodCountStmt->fetchColumn();

    $assignmentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_assignments WHERE school_id = ?");
    $assignmentCountStmt->execute([$school_id]);
    $assignmentCount = (int) $assignmentCountStmt->fetchColumn();

    $hasExistingStmt = $pdo->prepare("SELECT COUNT(*) FROM timetable_entries WHERE school_id = ? AND term = ? AND academic_year = ?");
    $hasExistingStmt->execute([$school_id, $term, $year]);
    $hasExisting = (int) $hasExistingStmt->fetchColumn();

    return [
        'term' => $term,
        'year' => $year,
        'period_count' => $periodCount,
        'assignment_count' => $assignmentCount,
        'has_existing' => $hasExisting,
    ];
}

/** Runs the generator and attaches readable names to any unplaced assignments (the engine itself only deals in ids). */
function admin_timetable_generate_run(PDO $pdo, int $school_id, string $term, string $year): array
{
    $result = scholar_generate_timetable($pdo, $school_id, $term, $year);

    if (!empty($result['unplaced'])) {
        $teacherNames = [];
        $classNames = [];
        $subjectNames = [];
        $tStmt = $pdo->prepare("SELECT staff_id, first_name, last_name FROM staff WHERE school_id = ?");
        $tStmt->execute([$school_id]);
        foreach ($tStmt->fetchAll() as $t) {
            $teacherNames[(int) $t['staff_id']] = trim($t['first_name'] . ' ' . $t['last_name']);
        }
        $cStmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ?");
        $cStmt->execute([$school_id]);
        foreach ($cStmt->fetchAll() as $c) {
            $classNames[(int) $c['id']] = $c['class_name'] . ($c['stream_name'] ? ' ' . $c['stream_name'] : '');
        }
        $sStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ?");
        $sStmt->execute([$school_id]);
        foreach ($sStmt->fetchAll() as $s) {
            $subjectNames[(int) $s['id']] = $s['subject_name'];
        }
        foreach ($result['unplaced'] as &$u) {
            $u['teacher_name'] = $teacherNames[$u['teacher_id']] ?? 'Unknown';
            $u['class_name'] = $classNames[$u['class_id']] ?? 'Unknown';
            $u['subject_name'] = $subjectNames[$u['subject_id']] ?? 'Unknown';
        }
        unset($u);
    }

    return $result;
}
