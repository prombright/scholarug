<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER TIMETABLE: SHARED FETCH
|--------------------------------------------------------------------------
| Pulled out of my_timetable.php so the classic page and the JSON endpoint
| (api/teacher/timetable.php) build the exact same grid. Read-only, so the
| stakes here are lower than marks entry, but still one source of truth
| rather than two copies of the query drifting apart.
|--------------------------------------------------------------------------
*/

const SCHOLAR_TIMETABLE_DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/**
 * @return array{term:string,year:string,grid_rows:array,entries:array,active_days:array<int,int>}
 */
function teacher_fetch_timetable(PDO $pdo, int $schoolId, int $staffId): array
{
    $schoolStmt = $pdo->prepare('SELECT current_term, current_year FROM schools WHERE id = ?');
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    $term = $school['current_term'] ?: 'Term 1';
    $year = $school['current_year'] ?: (string) date('Y');

    // Same grid-building approach as school_admin/timetable_view.php, just
    // scoped to this one teacher's own lessons instead of a chosen class.
    $gridRows = [];
    $daysPresent = [];
    $periodsStmt = $pdo->prepare('SELECT * FROM timetable_periods WHERE school_id = ? ORDER BY period_number, day_of_week');
    $periodsStmt->execute([$schoolId]);
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
    $eStmt = $pdo->prepare("
        SELECT te.day_of_week, te.period_id, sub.subject_name, sub.papers_count, te.paper_number,
               c.class_name, c.stream_name
        FROM timetable_entries te
        JOIN subjects sub ON sub.id = te.subject_id
        JOIN classes c ON c.id = te.class_id
        WHERE te.school_id = ? AND te.teacher_id = ? AND te.term = ? AND te.academic_year = ?
    ");
    $eStmt->execute([$schoolId, $staffId, $term, $year]);
    foreach ($eStmt->fetchAll() as $e) {
        $entries[(int) $e['day_of_week'] . ':' . (int) $e['period_id']] = $e;
    }

    return ['term' => $term, 'year' => $year, 'grid_rows' => $gridRows, 'entries' => $entries, 'active_days' => $activeDays];
}
