<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: PERFORMANCE ANALYTICS (JSON)
|--------------------------------------------------------------------------
| JSON twin of performance_analytics.php, for the Vue pilot
| (scholar/app_teacher.php). Same auth (session cookie via auth_guard.php),
| same tenant scoping, same analytics_build_view() the HTML page uses --
| this file only decides what to fetch from the query string and how to
| shape the response, never how the numbers are computed.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_analytics_helpers.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

$school_stmt = $pdo->prepare('SELECT current_term, current_year, school_name, school_badge FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];
$current_term = $school_row['current_term'] ?? 'Term 1';
$current_year = (int) ($school_row['current_year'] ?? date('Y'));

$badge_url = null;
if (!empty($school_row['school_badge']) && file_exists(__DIR__ . '/../../' . $school_row['school_badge'])) {
    $badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school_row['school_badge'], '/');
}

$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT ta.class_id, c.class_name, ta.subject_id, s.subject_name, s.subject_code
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = ? AND ta.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
");
$assigned_stmt->execute([$school_id, $staff_id]);
$my_assignments = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

$sel_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$sel_subject = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;

$sel_assignment = null;
foreach ($my_assignments as $a) {
    if ((int) $a['class_id'] === $sel_class && (int) $a['subject_id'] === $sel_subject) {
        $sel_assignment = $a;
        break;
    }
}

$response = [
    'success' => true,
    'term' => $current_term,
    'year' => $current_year,
    'school_name' => $school_row['school_name'] ?? 'Scholar',
    'badge_url' => $badge_url,
    'assignments' => $my_assignments,
    'selected' => $sel_assignment,
    'subject_analytics' => null,
    'class_analytics' => null,
    'is_class_teacher_here' => false,
];

if ($sel_assignment) {
    $view = analytics_build_view($pdo, $school_id, $staff_id, $sel_class, $sel_subject, $current_term, $current_year);
    $response['subject_analytics'] = $view['subject_analytics'];
    $response['class_analytics'] = $view['class_analytics'];
    $response['is_class_teacher_here'] = $view['is_class_teacher_here'];
} elseif ($sel_class && $sel_subject) {
    // Asked for an assignment this teacher doesn't actually have.
    http_response_code(403);
    $response = ['success' => false, 'message' => 'Not your assignment.'];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
