<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: FEES (JSON)
|--------------------------------------------------------------------------
| JSON twin of student_fees.php -- same expected/paid/balance computation.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This login is not linked to a student record.']);
    exit;
}

$stu_stmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Student record not found for this school.']);
    exit;
}

$fee_stmt = $pdo->prepare('SELECT fs.day_tuition, fs.boarding_tuition, fs.entry_fee FROM fee_structures fs WHERE fs.school_id = ? AND fs.class_id = ? LIMIT 1');
$fee_stmt->execute([$school_id, $student['class_id']]);
$fee_structure = $fee_stmt->fetch(PDO::FETCH_ASSOC);
$expected = $fee_structure ? (float) $fee_structure['day_tuition'] + (float) $fee_structure['entry_fee'] : 0.0;

$paid_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE school_id = ? AND student_id = ?');
$paid_stmt->execute([$school_id, $student_id]);
$paid = (float) $paid_stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'expected' => $expected,
    'paid' => $paid,
    'balance' => $expected - $paid,
], JSON_UNESCAPED_SLASHES);
