<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| iLEARNING — GATED PDF STREAM
|--------------------------------------------------------------------------
| The only way a pdf_notes/pdf_activity PDF is ever readable -- no direct
| uploads/ URL exists for these (see ilearning_save_pdf_attachment() and
| uploads/ilearning_private/.htaccess). Every request re-checks that the
| caller is either the topic's teacher or a student enrolled in its class.
|
| download=1 is only ever honored for pdf_notes (and plain written topics'
| attachments, which reuse this same endpoint once they're marked private --
| not the case today, but harmless to allow). pdf_activity NEVER sets
| Content-Disposition: attachment, regardless of what's requested -- that's
| the one hard rule the rest of this feature is built around.
|
| Caveat worth being upfront about: this raises the bar (no guessable URL,
| no download button, canvas-only render with no selectable text layer for
| activities) but it is not a hard guarantee against a determined student --
| anyone who can view a page in a browser can still screenshot it. Nothing
| server-side can fully prevent that; this is the same ceiling every
| "view-only" document viewer (Google Docs included) has.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$role = $_SESSION['role'];
$teacher_id = $role === 'teacher' ? current_staff_id() : null;
$student_id = $role === 'student' ? current_student_id() : null;

$attachment_id = (int) ($_GET['attachment_id'] ?? 0);
$wantsDownload = ($_GET['download'] ?? '') === '1';

$stmt = $pdo->prepare('SELECT * FROM ilearning_attachments WHERE id = ?');
$stmt->execute([$attachment_id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Not found.');
}

$topicStmt = $pdo->prepare('SELECT * FROM ilearning_topics WHERE id = ?');
$topicStmt->execute([$attachment['topic_id']]);
$topic = $topicStmt->fetch();

if (!$topic || !ilearning_can_access_topic($pdo, $topic, $role, $school_id, $teacher_id, $student_id)) {
    http_response_code(403);
    exit('You do not have access to this file.');
}

if ($wantsDownload && $topic['content_type'] === 'pdf_activity') {
    http_response_code(403);
    exit('This activity is view-only and cannot be downloaded.');
}

// file_path is already stored as the full relative path from scholar/ --
// e.g. "uploads/ilearning_private/xxx.pdf" or "uploads/ilearning/xxx.pdf".
$fullPath = realpath(__DIR__ . '/../' . $attachment['file_path']);
$allowedRoot = realpath(__DIR__ . '/../uploads');

if ($fullPath === false || $allowedRoot === false || strpos($fullPath, $allowedRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('File missing.');
}

$isPdfActivity = $topic['content_type'] === 'pdf_activity';

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Content-Length: ' . filesize($fullPath));

if ($wantsDownload && !$isPdfActivity) {
    header('Content-Disposition: attachment; filename="' . basename($attachment['original_name']) . '"');
} else {
    // Deliberately no filename for the inline case -- a generic
    // "document.pdf" default in a browser's own Save-As dialog is a small
    // extra deterrent, though (see caveat above) not a real barrier.
    header('Content-Disposition: inline');
}

readfile($fullPath);
