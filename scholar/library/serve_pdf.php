<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LIBRARY — GATED PDF STREAM
|--------------------------------------------------------------------------
| The only way a library document is ever readable -- no direct uploads/
| URL exists for these (see library_save_pdf() and
| uploads/library_private/.htaccess). Every request re-checks that the
| caller is either the document's teacher or a student enrolled in its
| class. Always served inline, never as an attachment -- unlike
| ilearning/serve_pdf.php, there is no download=1 carve-out here at all,
| matching "students can only read and no download".
|
| Caveat worth being upfront about: this raises the bar (no guessable URL,
| no download button, canvas-only render with no selectable text layer) but
| it is not a hard guarantee against a determined student -- anyone who can
| view a page in a browser can still screenshot it. Nothing server-side can
| fully prevent that; this is the same ceiling every "view-only" document
| viewer (Google Docs included) has.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);
require_once __DIR__ . '/_library_helpers.php';

$school_id = current_school_id();
$role = $_SESSION['role'];
$teacher_id = $role === 'teacher' ? current_staff_id() : null;
$student_id = $role === 'student' ? current_student_id() : null;

$doc_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM library_documents WHERE id = ?');
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Not found.');
}

if (!library_can_access_document($pdo, $doc, $role, $school_id, $teacher_id, $student_id)) {
    http_response_code(403);
    exit('You do not have access to this file.');
}

$fullPath = realpath(__DIR__ . '/../' . $doc['file_path']);
$allowedRoot = realpath(__DIR__ . '/../uploads');

if ($fullPath === false || $allowedRoot === false || strpos($fullPath, $allowedRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('File missing.');
}

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Content-Length: ' . filesize($fullPath));
// Deliberately no filename and never "attachment" -- a generic inline
// response is the one extra deterrent available here (see caveat above).
header('Content-Disposition: inline');

readfile($fullPath);
