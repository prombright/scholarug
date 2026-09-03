<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: LIBRARY (JSON)
|--------------------------------------------------------------------------
| JSON twin of library/manage.php's list/delete/toggle-status actions,
| built on the same library/_library_helpers.php. Upload is deliberately
| NOT here -- it's a multipart file upload, so the Vue page posts that
| straight to the classic page instead (same reasoning as CSV import on
| the Marks Entry pilot). Preview (view.php) also stays a plain link --
| serving a PDF isn't something JSON carries any better.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/../../library/_library_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

function library_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function library_list(PDO $pdo, int $schoolId, int $staffId): array
{
    $assigned_stmt = $pdo->prepare("
        SELECT DISTINCT ta.class_id, c.class_name, ta.subject_id, s.subject_name
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.school_id = ? AND ta.teacher_id = ?
        ORDER BY c.class_name, s.subject_name
    ");
    $assigned_stmt->execute([$schoolId, $staffId]);

    $docs_stmt = $pdo->prepare("
        SELECT d.*, c.class_name, s.subject_name
        FROM library_documents d
        JOIN classes c ON c.id = d.class_id
        JOIN subjects s ON s.id = d.subject_id
        WHERE d.school_id = ? AND d.teacher_id = ?
        ORDER BY d.created_at DESC
    ");
    $docs_stmt->execute([$schoolId, $staffId]);

    return [
        'assignments' => $assigned_stmt->fetchAll(PDO::FETCH_ASSOC),
        'documents' => $docs_stmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['success' => true] + library_list($pdo, $school_id, $staff_id), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $doc_id = (int) ($body['doc_id'] ?? 0);

    if ($action === 'delete') {
        if (!library_delete_document($pdo, $doc_id, $school_id, $staff_id)) {
            library_json_error('Document not found.', 404);
        }
    } elseif ($action === 'toggle_status') {
        if (library_toggle_status($pdo, $doc_id, $school_id, $staff_id) === null) {
            library_json_error('Document not found.', 404);
        }
    } else {
        library_json_error('Unknown action.');
    }

    echo json_encode(['success' => true] + library_list($pdo, $school_id, $staff_id), JSON_UNESCAPED_SLASHES);
    exit;
}

library_json_error('Method not allowed.', 405);
