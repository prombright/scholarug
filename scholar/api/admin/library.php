<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: LIBRARY OVERVIEW (JSON)
|--------------------------------------------------------------------------
| JSON twin of library/admin_overview.php -- single read-only query, no
| actions, so no shared helper file (nothing here can drift).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin', 'headteacher', 'dos']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$docs_stmt = $pdo->prepare("
    SELECT d.title, d.category, d.status, d.term, d.year, d.created_at,
           c.class_name, s.subject_name,
           CONCAT(st.first_name, ' ', st.last_name) AS teacher_name
    FROM library_documents d
    JOIN classes c ON c.id = d.class_id
    JOIN subjects s ON s.id = d.subject_id
    JOIN staff st ON st.staff_id = d.teacher_id
    WHERE d.school_id = ?
    ORDER BY d.created_at DESC
    LIMIT 200
");
$docs_stmt->execute([$school_id]);

echo json_encode(['success' => true, 'documents' => $docs_stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_SLASHES);
