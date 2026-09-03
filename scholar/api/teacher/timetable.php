<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: MY TIMETABLE (JSON)
|--------------------------------------------------------------------------
| JSON twin of my_timetable.php, built on the same teacher_fetch_timetable()
| the classic page uses. This file's only job is reshaping that PHP-array
| grid (keyed by internal period ids) into something a template doesn't
| need to know the day/period bookkeeping to render.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_timetable_helpers.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

$tt = teacher_fetch_timetable($pdo, $school_id, $staff_id);

$days = array_map(
    static fn(int $d) => ['number' => $d, 'name' => SCHOLAR_TIMETABLE_DAY_NAMES[$d]],
    $tt['active_days']
);

$rows = [];
foreach ($tt['grid_rows'] as $row) {
    $cells = [];
    foreach ($tt['active_days'] as $d) {
        $periodId = $row['by_day'][$d] ?? null;
        if (!$periodId) {
            $cells[$d] = null; // no period at all for this day (e.g. a half day)
            continue;
        }
        $entry = $tt['entries'][$d . ':' . $periodId] ?? null;
        $cells[$d] = $entry ? [
            'subject_name' => $entry['subject_name'],
            'paper_label' => (int) $entry['papers_count'] > 1 ? 'P' . (int) $entry['paper_number'] : null,
            'class_name' => trim($entry['class_name'] . ' ' . ($entry['stream_name'] ?? '')),
        ] : 'free';
    }
    $rows[] = [
        'label' => $row['label'],
        'start' => substr($row['start'], 0, 5),
        'end' => substr($row['end'], 0, 5),
        'is_teaching' => (bool) $row['is_teaching'],
        'cells' => $cells,
    ];
}

echo json_encode([
    'success' => true,
    'term' => $tt['term'],
    'year' => $tt['year'],
    'days' => $days,
    'rows' => $rows,
], JSON_UNESCAPED_SLASHES);
