<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: DASHBOARD (JSON)
|--------------------------------------------------------------------------
| JSON twin of teachers_portal.php's three counts (assignments, unread
| messages, is-any-class-teacher). The card list itself (icons, colors,
| descriptions, which href each links to) is static and lives in the Vue
| page, same as the classic PHP page hardcodes its own $cards array --
| nothing here needs sharing with a helper file, these are one-line counts.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

$class_teacher_stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?');
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$staff_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$assign_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_assignments WHERE school_id = ? AND teacher_id = ?');
$assign_count_stmt->execute([$school_id, $staff_id]);
$assignment_count = (int) $assign_count_stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'is_any_class_teacher' => $is_any_class_teacher,
    'unread_message_count' => $unread_message_count,
    'assignment_count' => $assignment_count,
], JSON_UNESCAPED_SLASHES);
