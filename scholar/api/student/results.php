<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: MY RESULTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of student_results.php -- same query (Closed assessments only).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This login is not linked to a student record.']);
    exit;
}

$marks_stmt = $pdo->prepare("
    SELECT sm.marks, sm.paper_number, sub.subject_name, sub.papers_count, a.title AS assessment_title, a.term, a.year
    FROM student_marks sm
    JOIN subjects sub ON sub.id = sm.subject_id AND sub.school_id = sm.school_id
    JOIN assessments a ON a.id = sm.assessment_id AND a.school_id = sm.school_id
    WHERE sm.student_id = ? AND sm.school_id = ? AND a.status = 'Closed'
    ORDER BY a.year DESC, a.term DESC, sub.subject_name ASC, sm.paper_number ASC
");
$marks_stmt->execute([$student_id, $school_id]);

echo json_encode([
    'success' => true,
    'marks' => $marks_stmt->fetchAll(PDO::FETCH_ASSOC),
    'report_url' => rtrim(SCHOLAR_BASE, '/') . '/generate_report.php?term=' . urlencode(current_term()) . '&year=' . urlencode(current_year()),
], JSON_UNESCAPED_SLASHES);
