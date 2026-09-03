<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LIBRARY — SHARED HELPERS
|--------------------------------------------------------------------------
| Included by every library/*.php page after auth_guard.php + db.php.
|--------------------------------------------------------------------------
*/

/** Is $studentId (per session) actually enrolled in $classId at $schoolId? */
function library_student_in_class(PDO $pdo, int $schoolId, int $studentId, int $classId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM students WHERE id = ? AND school_id = ? AND class_id = ?');
    $stmt->execute([$studentId, $schoolId, $classId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * True if the current session may view $doc: either the teacher who
 * uploaded it, or a student enrolled in its class (only once Published --
 * a teacher must still be able to preview their own Draft document).
 */
function library_can_access_document(PDO $pdo, array $doc, string $role, int $schoolId, ?int $teacherId, ?int $studentId): bool
{
    if ((int) $doc['school_id'] !== $schoolId) {
        return false;
    }
    if ($role === 'teacher' && $teacherId !== null) {
        return (int) $doc['teacher_id'] === $teacherId;
    }
    if ($role === 'student' && $studentId !== null) {
        return $doc['status'] === 'Published'
            && library_student_in_class($pdo, $schoolId, $studentId, (int) $doc['class_id']);
    }
    return false;
}

/**
 * Saves an uploaded PDF into uploads/library_private/ -- that directory
 * denies all direct HTTP access (see its .htaccess), so the only way to
 * ever read a document back is through serve_pdf.php's auth gate, which
 * never honors a download request for anything in this table.
 *
 * @return array{path:string,original_name:string}|null null on any
 *         validation failure (caller decides how to surface the error)
 */
function library_save_pdf(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return null;
    }

    // Extension allow-listing alone trusts the client-supplied filename --
    // a light magic-byte check (every real PDF starts with "%PDF-") costs
    // nothing and catches a renamed non-PDF before it ever reaches disk.
    $handle = fopen($file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 5) : '';
    if ($handle) fclose($handle);
    if ($header !== '%PDF-') {
        return null;
    }

    $dir = __DIR__ . '/../uploads/library_private';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'doc_' . time() . '_' . rand(1000, 9999) . '.pdf';
    $destPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return [
        'path' => 'uploads/library_private/' . $filename,
        'original_name' => $file['name'],
    ];
}

/**
 * Deletes $docId if (and only if) it belongs to $teacherId at $schoolId --
 * both the DB row and the file on disk, with the same containment check
 * as everywhere else in library/ that touches the filesystem (never trust
 * a stored path alone; confirm it resolves inside uploads/ before unlink).
 *
 * @return bool true if a document was actually deleted
 */
function library_delete_document(PDO $pdo, int $docId, int $schoolId, int $teacherId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM library_documents WHERE id = ? AND school_id = ? AND teacher_id = ?');
    $stmt->execute([$docId, $schoolId, $teacherId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return false;
    }

    $pdo->prepare('DELETE FROM library_documents WHERE id = ?')->execute([$docId]);

    $fullPath = realpath(__DIR__ . '/../' . $doc['file_path']);
    $allowedRoot = realpath(__DIR__ . '/../uploads');
    if ($fullPath !== false && $allowedRoot !== false && strpos($fullPath, $allowedRoot) === 0 && is_file($fullPath)) {
        @unlink($fullPath);
    }
    return true;
}

/**
 * Flips $docId between Draft/Published if it belongs to $teacherId at
 * $schoolId.
 *
 * @return string|null the new status, or null if no matching document
 */
function library_toggle_status(PDO $pdo, int $docId, int $schoolId, int $teacherId): ?string
{
    $stmt = $pdo->prepare('SELECT status FROM library_documents WHERE id = ? AND school_id = ? AND teacher_id = ?');
    $stmt->execute([$docId, $schoolId, $teacherId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        return null;
    }

    $next = $current === 'Published' ? 'Draft' : 'Published';
    $pdo->prepare('UPDATE library_documents SET status = ? WHERE id = ?')->execute([$next, $docId]);
    return $next;
}
