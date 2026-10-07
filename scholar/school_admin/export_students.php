<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — EXPORT STUDENTS (CSV)
|--------------------------------------------------------------------------
| Streams every student in the school as a CSV. Reuses
| admin_students_fetch_all() rather than a fresh query, so this always
| matches exactly what the Students list itself shows.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_students_helpers.php';
require_role(['school_admin']);

$school_id = current_school_id();
$data = admin_students_fetch_all($pdo, $school_id);

$school_stmt = $pdo->prepare('SELECT school_name FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_name = $school_stmt->fetchColumn() ?: 'school';
$safe_name = preg_replace('/[^a-z0-9]+/i', '_', $school_name);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $safe_name . '_students_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Student No', 'Full Name', 'Sex', 'Class', 'Level Type', 'Portal Username']);
foreach ($data['students'] as $s) {
    fputcsv($output, [
        $s['student_no'] ?? '',
        $s['full_name'] ?? '',
        $s['sex'] ?? '',
        $s['class_name'] ?? 'Unassigned',
        $s['level_type'] ?? '',
        $s['login_username'] ?? '',
    ]);
}
fclose($output);
exit;
