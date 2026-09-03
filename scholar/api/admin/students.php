<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: STUDENTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/students.php, built on
| school_admin/_students_helpers.php. CSV import/export and the "Print
| Class Credentials" page stay classic links/forms -- file transfer and a
| print view aren't things JSON carries any better (same reasoning as the
| teacher-side Marks Entry/Library pilots).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_subject_helpers.php';
require_once __DIR__ . '/../../school_admin/_students_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_students_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** Same rows admin_students_fetch_all() returns, plus URLs relative to SCHOLAR_BASE (the SPA's own root) instead of school_admin/. */
function admin_students_snapshot(PDO $pdo, int $schoolId): array
{
    $data = admin_students_fetch_all($pdo, $schoolId);
    $report_term = current_term();
    $report_year = current_year();
    $sb = rtrim(SCHOLAR_BASE, '/');

    foreach ($data['students'] as &$row) {
        $row['edit_url'] = $sb . '/school_admin/edit_student.php?id=' . (int) $row['id'];
        $row['report_url'] = $sb . '/generate_report.php?student_id=' . (int) $row['id'] . '&term=' . urlencode($report_term) . '&year=' . urlencode($report_year);
        $row['subjects_url'] = $sb . '/school_admin/student_subjects.php?student_id=' . (int) $row['id'];
    }
    unset($row);

    $school_type_stmt = $pdo->prepare('SELECT school_type FROM schools WHERE id = ?');
    $school_type_stmt->execute([$schoolId]);

    return [
        'success' => true,
        'school_type' => $school_type_stmt->fetchColumn() ?: 'Secondary',
        'students' => $data['students'],
        'classes' => $data['classes'],
        'total' => $data['total'],
        'male' => $data['male'],
        'female' => $data['female'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(admin_students_snapshot($pdo, $school_id), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $result = null;

    switch ($action) {
        case 'add_student':
            $result = admin_students_add(
                $pdo, $school_id,
                trim((string) ($body['full_name'] ?? '')),
                trim((string) ($body['gender'] ?? '')),
                (int) ($body['class_id'] ?? 0),
                trim((string) ($body['level_type'] ?? 'O-Level'))
            );
            break;
        case 'create_student_login':
            $r = admin_create_student_login($pdo, (int) ($body['student_id'] ?? 0), $school_id);
            $result = $r['ok']
                ? ['ok' => true, 'message' => "Login created for {$r['full_name']} — Access Code: {$r['username']} (used as both username and password). Share this with the student now; they'll set their own password on first login."]
                : ['ok' => false, 'message' => $r['error']];
            break;
        case 'regenerate_student_password':
            $result = admin_students_regenerate_password($pdo, $school_id, (int) ($body['student_id'] ?? 0));
            break;
        default:
            admin_students_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_students_json_error($result['message']);
    }

    $snapshot = admin_students_snapshot($pdo, $school_id);
    $snapshot['message'] = $result['message'];
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_students_json_error('Method not allowed.', 405);
