<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: DASHBOARD (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/school_admin_dashboard.php -- same counts and
| chart data (class distribution, gender split), reshaped into JSON. The
| module-grid itself (icons/links) is static and lives in the Vue page,
| same as the teacher/student dashboards.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$school_stmt = $pdo->prepare('SELECT school_name, school_badge, school_type FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];
$is_primary = ($school_row['school_type'] ?? 'Secondary') === 'Primary';

$badge_url = null;
if (!empty($school_row['school_badge']) && file_exists(__DIR__ . '/../../' . $school_row['school_badge'])) {
    $badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school_row['school_badge'], '/');
}

$student_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id = ?');
$student_count_stmt->execute([$school_id]);
$student_count = (int) $student_count_stmt->fetchColumn();

$staff_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE school_id = ?');
$staff_count_stmt->execute([$school_id]);
$staff_count = (int) $staff_count_stmt->fetchColumn();

$class_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ?');
$class_count_stmt->execute([$school_id]);
$class_count = (int) $class_count_stmt->fetchColumn();

$pending_feedback_stmt = $pdo->prepare("SELECT COUNT(*) FROM parent_feedback WHERE school_id = ? AND status = 'new'");
$pending_feedback_stmt->execute([$school_id]);
$pending_feedback = (int) $pending_feedback_stmt->fetchColumn();

$class_dist_stmt = $pdo->prepare(
    'SELECT c.class_name, c.stream_name, COUNT(s.id) AS cnt
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id
     WHERE c.school_id = ?
     GROUP BY c.id, c.class_name, c.stream_name
     ORDER BY c.class_name, c.stream_name'
);
$class_dist_stmt->execute([$school_id]);
$class_distribution = array_map(static fn($r) => [
    'label' => $r['class_name'] . ($r['stream_name'] ? ' - ' . $r['stream_name'] : ''),
    'count' => (int) $r['cnt'],
], $class_dist_stmt->fetchAll(PDO::FETCH_ASSOC));

$gender_stmt = $pdo->prepare('SELECT sex, COUNT(*) AS cnt FROM students WHERE school_id = ? GROUP BY sex');
$gender_stmt->execute([$school_id]);
$gender_male = 0;
$gender_female = 0;
foreach ($gender_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['sex'] === 'Male') $gender_male = (int) $row['cnt'];
    elseif ($row['sex'] === 'Female') $gender_female = (int) $row['cnt'];
}

echo json_encode([
    'success' => true,
    'school_name' => $school_row['school_name'] ?? 'Scholar',
    'badge_url' => $badge_url,
    'is_primary' => $is_primary,
    'student_count' => $student_count,
    'staff_count' => $staff_count,
    'class_count' => $class_count,
    'pending_feedback' => $pending_feedback,
    'class_distribution' => $class_distribution,
    'gender' => ['male' => $gender_male, 'female' => $gender_female],
], JSON_UNESCAPED_SLASHES);
