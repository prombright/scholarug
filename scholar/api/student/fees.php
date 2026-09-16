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
require_once __DIR__ . '/../../school_admin/_fees_helpers.php';
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

// Same ledger computation the bursar's office actually uses
// (admin_fees_annotate_ledger()) -- see the comment on
// admin_fees_fetch_ledger_for_student() for why the old flat
// day_tuition + entry_fee formula here didn't match the real ledger for
// boarders, bursary recipients, or returning (non-new) students.
$fee_row = admin_fees_fetch_ledger_for_student($pdo, $school_id, $student_id);
$expected = (float) ($fee_row['net_due'] ?? 0.0);
$paid = (float) ($fee_row['total_paid'] ?? 0.0);

echo json_encode([
    'success' => true,
    'expected' => $expected,
    'paid' => $paid,
    // Signed, not the ledger's own clamped 'balance' field -- the student
    // Fees page shows "Overpaid / Credit" for a negative difference, which
    // a max(0, ...) balance would hide behind a flat 0.
    'balance' => $expected - $paid,
], JSON_UNESCAPED_SLASHES);
