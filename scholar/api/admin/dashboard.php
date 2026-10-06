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
require_once __DIR__ . '/../../school_admin/_fees_helpers.php';
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

/*
|--------------------------------------------------------------------------
| SETUP CHECKLIST
|--------------------------------------------------------------------------
| Base classes (S.1-S.6 / P.1-P.7) and O-Level/Primary compulsory subjects
| are auto-seeded the moment classes.php first loads (see
| admin_classes_ensure_base_classes() + scholar_ensure_compulsory_subjects()
| in school_admin/_classes_helpers.php + _subject_helpers.php) -- so their
| mere existence proves nothing about whether the admin has actually done
| anything. Each check below looks for a signal only a real admin action
| produces, not the auto-seeded baseline.
|--------------------------------------------------------------------------
*/
$streams_stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ? AND stream_name IS NOT NULL');
$streams_stmt->execute([$school_id]);
$has_streams = (int) $streams_stmt->fetchColumn() > 0;

$grading_stmt = $pdo->prepare('SELECT COUNT(*) FROM grading_scales WHERE school_id = ?');
$grading_stmt->execute([$school_id]);
$has_grading_scale = (int) $grading_stmt->fetchColumn() > 0;

admin_fees_ensure_schema($pdo);
$current_term_val = current_term();
$current_year_val = current_year();
$fees_stmt = $pdo->prepare('SELECT COUNT(*) FROM fee_structures WHERE school_id = ? AND term = ? AND year = ?');
$fees_stmt->execute([$school_id, $current_term_val, $current_year_val]);
$has_current_fees = (int) $fees_stmt->fetchColumn() > 0;

// A-Level subjects are never auto-seeded (unlike O-Level/Primary), but only
// worth flagging once the school actually has an S.5/S.6 student -- a
// Secondary school with nobody in A-Level yet doesn't need this nudge.
$alevel_students_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM students st
    JOIN classes c ON c.id = st.class_id
    WHERE st.school_id = ? AND c.class_name IN ('S.5', 'S.6')
");
$alevel_students_stmt->execute([$school_id]);
$needs_alevel_subjects = !$is_primary && (int) $alevel_students_stmt->fetchColumn() > 0;
$has_alevel_subjects = true;
if ($needs_alevel_subjects) {
    $alevel_subjects_stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE school_id = ? AND level_type = 'A-Level'");
    $alevel_subjects_stmt->execute([$school_id]);
    $has_alevel_subjects = (int) $alevel_subjects_stmt->fetchColumn() > 0;
}

$setup_checklist = [
    ['key' => 'staff', 'label' => 'Add your teaching and non-teaching staff', 'done' => $staff_count > 0, 'route' => null, 'href' => 'staff_manager.php'],
    ['key' => 'students', 'label' => 'Enroll your students', 'done' => $student_count > 0, 'route' => '/students', 'href' => null],
    ['key' => 'streams', 'label' => 'Add streams/sections to your classes (if you have more than one per class)', 'done' => $has_streams, 'route' => '/classes', 'href' => null],
    ['key' => 'grading', 'label' => 'Set up your grading scale', 'done' => $has_grading_scale, 'route' => '/grading', 'href' => null],
    ['key' => 'fees', 'label' => "Set fee structures for {$current_term_val} {$current_year_val}", 'done' => $has_current_fees, 'route' => '/fees', 'href' => null],
];
if ($needs_alevel_subjects) {
    $setup_checklist[] = ['key' => 'alevel_subjects', 'label' => 'Adopt A-Level subjects for S.5/S.6', 'done' => $has_alevel_subjects, 'route' => '/subjects', 'href' => null];
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
    'setup_checklist' => $setup_checklist,
], JSON_UNESCAPED_SLASHES);
