<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — EXPORT STAFF (CSV)
|--------------------------------------------------------------------------
| Streams every staff record in the school as a CSV. Same `SELECT * FROM
| staff WHERE school_id = ?` staff_manager.php's own list already uses.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin', 'hr']);

$school_id = current_school_id();

$stmt = $pdo->prepare("SELECT * FROM staff WHERE school_id = ? ORDER BY first_name ASC");
$stmt->execute([$school_id]);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

$school_stmt = $pdo->prepare('SELECT school_name FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_name = $school_stmt->fetchColumn() ?: 'school';
$safe_name = preg_replace('/[^a-z0-9]+/i', '_', $school_name);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $safe_name . '_staff_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Staff Code', 'First Name', 'Last Name', 'Category', 'Role', 'Email', 'Phone', 'Status']);
foreach ($staff as $s) {
    fputcsv($output, [
        $s['staff_code'] ?? '',
        $s['first_name'] ?? '',
        $s['last_name'] ?? '',
        $s['staff_category'] ?? '',
        $s['role'] ?? '',
        $s['email'] ?? '',
        $s['phone'] ?? '',
        $s['status'] ?? '',
    ]);
}
fclose($output);
exit;
