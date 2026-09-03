<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: ATTENDANCE (JSON)
|--------------------------------------------------------------------------
| JSON twin of student_attendance.php -- same per-status counts, all
| recorded terms.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This login is not linked to a student record.']);
    exit;
}

$att_stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM attendance WHERE student_id = ? GROUP BY status');
$att_stmt->execute([$student_id]);
$attendance = ['present' => 0, 'absent' => 0, 'sick' => 0, 'permission' => 0];
foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendance[$row['status']] = (int) $row['c'];
}

echo json_encode(['success' => true, 'attendance' => $attendance], JSON_UNESCAPED_SLASHES);
