<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: DASHBOARD (JSON)
|--------------------------------------------------------------------------
| JSON twin of student_portal.php -- same queries (marks/fees/attendance/
| elections/messages teasers), reshaped into JSON instead of feature-card
| HTML. The attendance donut's gradient-stop math stays server-side
| (same as the PHP page) since it's just arithmetic, not markup.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../elections/_election_helpers.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This login is not linked to a student record.']);
    exit;
}

$stu_stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Student record not found for this school.']);
    exit;
}

$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

$marks_count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM student_marks sm
    JOIN assessments a ON a.id = sm.assessment_id AND a.school_id = sm.school_id
    WHERE sm.student_id = ? AND sm.school_id = ? AND a.status = 'Closed'
");
$marks_count_stmt->execute([$student_id, $school_id]);
$marks_count = (int) $marks_count_stmt->fetchColumn();

$fee_stmt = $pdo->prepare('SELECT fs.day_tuition, fs.entry_fee FROM fee_structures fs WHERE fs.school_id = ? AND fs.class_id = ? LIMIT 1');
$fee_stmt->execute([$school_id, $student['class_id']]);
$fee_structure = $fee_stmt->fetch(PDO::FETCH_ASSOC);
$expected = $fee_structure ? (float) $fee_structure['day_tuition'] + (float) $fee_structure['entry_fee'] : 0.0;
$paid_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE school_id = ? AND student_id = ?');
$paid_stmt->execute([$school_id, $student_id]);
$paid = (float) $paid_stmt->fetchColumn();
$balance = $expected - $paid;

$att_stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM attendance WHERE student_id = ? GROUP BY status');
$att_stmt->execute([$student_id]);
$attendance = ['present' => 0, 'absent' => 0, 'sick' => 0, 'permission' => 0];
foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendance[$row['status']] = (int) $row['c'];
}

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.student_id = ? AND cv.school_id = ? AND cm.sender_role = 'teacher' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$student_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$school_stmt = $pdo->prepare('SELECT school_name, school_badge FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];
$badge_url = null;
if (!empty($school_row['school_badge']) && file_exists(__DIR__ . '/../../' . $school_row['school_badge'])) {
    $badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school_row['school_badge'], '/');
}

echo json_encode([
    'success' => true,
    'school_name' => $school_row['school_name'] ?? 'Scholar',
    'badge_url' => $badge_url,
    'student' => [
        'full_name' => $student['full_name'],
        'class_name' => $student['class_name'] ?? null,
        'level_type' => $student['level_type'] ?? null,
        'student_no' => $student['student_no'] ?? null,
    ],
    'marks_count' => $marks_count,
    'fees' => ['expected' => $expected, 'paid' => $paid, 'balance' => $balance],
    'attendance' => $attendance,
    'open_positions_to_vote' => $open_positions_to_vote,
    'unread_message_count' => $unread_message_count,
], JSON_UNESCAPED_SLASHES);
