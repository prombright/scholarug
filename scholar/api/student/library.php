<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: LIBRARY (JSON)
|--------------------------------------------------------------------------
| JSON twin of library/student_library.php -- Published documents for the
| student's own class only, split into notes/past_paper. Preview
| (view.php) stays a plain link, same reasoning as the teacher side.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

$stu_stmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$class_id = (int) ($stu_stmt->fetchColumn() ?: 0);

$docs_stmt = $pdo->prepare("
    SELECT d.*, s.subject_name
    FROM library_documents d
    JOIN subjects s ON s.id = d.subject_id
    WHERE d.school_id = ? AND d.class_id = ? AND d.status = 'Published'
    ORDER BY s.subject_name, d.created_at DESC
");
$docs_stmt->execute([$school_id, $class_id]);
$all_docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'notes' => array_values(array_filter($all_docs, static fn($d) => $d['category'] === 'notes')),
    'past_papers' => array_values(array_filter($all_docs, static fn($d) => $d['category'] === 'past_paper')),
], JSON_UNESCAPED_SLASHES);
