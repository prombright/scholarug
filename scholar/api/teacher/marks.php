<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: MARKS ENTRY (JSON)
|--------------------------------------------------------------------------
| JSON twin of teacher_marks_entry.php's picker + roster + save/submit flow,
| built on the same _marks_entry_helpers.php the classic page now uses --
| identical ownership/status/range checks, just JSON in and out instead of
| a redirect-and-rerender.
|
| CSV import/export is deliberately NOT part of this endpoint -- those are
| file transfers, not state the SPA needs to hold, so the Vue page links
| straight back to the classic page's existing
| ?download_marks_template=csv / ?import_marks_csv endpoints instead of
| reimplementing file I/O over JSON for no benefit.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_marks_entry_helpers.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT
        ta.class_id, c.class_name,
        ta.subject_id, s.subject_name, s.subject_code,
        ta.paper_number, s.papers_count
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = :school_id AND ta.teacher_id = :staff_id
");
$assigned_stmt->execute([':school_id' => $school_id, ':staff_id' => $staff_id]);
$my_assignments = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

$assessments_stmt = $pdo->prepare("
    SELECT id, title, weight_percentage, term, year
    FROM assessments
    WHERE school_id = ? AND status = 'Open'
    ORDER BY id DESC
");
$assessments_stmt->execute([$school_id]);
$active_assessments = $assessments_stmt->fetchAll(PDO::FETCH_ASSOC);

function marks_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sel_assessment = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
    $sel_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
    $sel_subject = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;
    $sel_paper = isset($_GET['paper_number']) ? (int) $_GET['paper_number'] : 1;

    $response = [
        'success' => true,
        'assignments' => $my_assignments,
        'assessments' => $active_assessments,
        'progress' => $sel_assessment ? null : marks_progress_by_assessment($pdo, $school_id, $staff_id, $active_assessments, $my_assignments),
        'roster' => null,
    ];

    if ($sel_assessment && $sel_class && $sel_subject) {
        $ownsAssignment = false;
        foreach ($my_assignments as $a) {
            if ((int) $a['class_id'] === $sel_class && (int) $a['subject_id'] === $sel_subject && (int) $a['paper_number'] === $sel_paper) {
                $ownsAssignment = true;
                break;
            }
        }
        if (!$ownsAssignment) {
            marks_json_error('You are not assigned to this class/subject/paper.', 403);
        }
        $response['roster'] = marks_fetch_roster($pdo, $school_id, $sel_class, $sel_subject, $sel_assessment, $sel_paper);
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $action = $body['action'] ?? null; // 'draft' or 'submitted'
    if (!in_array($action, ['draft', 'submitted'], true)) {
        marks_json_error('Invalid action.');
    }

    $class_id = (int) ($body['class_id'] ?? 0);
    $subject_id = (int) ($body['subject_id'] ?? 0);
    $assessment_id = (int) ($body['assessment_id'] ?? 0);
    $paper_number = (int) ($body['paper_number'] ?? 1);
    $marks_input = is_array($body['marks'] ?? null) ? $body['marks'] : [];

    $assessment_status = marks_assessment_status($pdo, $school_id, $assessment_id);
    if ($assessment_status !== 'Open') {
        marks_json_error('This assessment is closed and no longer accepting marks.');
    }
    if (!marks_verify_assignment($pdo, $school_id, $staff_id, $class_id, $subject_id, $paper_number)) {
        marks_json_error('You are not assigned to this class/subject/paper.', 403);
    }

    try {
        $result = marks_save($pdo, $school_id, $staff_id, $class_id, $subject_id, $paper_number, $assessment_id, $marks_input, $action);
    } catch (Exception $e) {
        marks_json_error('Error saving marks: ' . $e->getMessage(), 500);
    }

    echo json_encode([
        'success' => true,
        'touched' => $result['touched'],
        'out_of_range' => $result['out_of_range'],
        'roster' => marks_fetch_roster($pdo, $school_id, $class_id, $subject_id, $assessment_id, $paper_number),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

marks_json_error('Method not allowed.', 405);
