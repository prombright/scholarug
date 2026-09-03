<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER SUBMISSIONS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/teacher_submissions.php so the classic page
| and the JSON endpoint (api/admin/teacher_submissions.php) build the same
| oversight table.
|--------------------------------------------------------------------------
*/

function admin_teacher_submissions_fetch_filters(PDO $pdo, int $schoolId): array
{
    $assessments_stmt = $pdo->prepare('SELECT id, title, term, year FROM assessments WHERE school_id = ? ORDER BY year DESC, id DESC');
    $assessments_stmt->execute([$schoolId]);

    $classes_stmt = $pdo->prepare('SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name');
    $classes_stmt->execute([$schoolId]);

    return [
        'assessments' => $assessments_stmt->fetchAll(PDO::FETCH_ASSOC),
        'classes' => $classes_stmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Driven from teacher_assignments (not just student_marks) so a teacher with zero marks entered still shows up as "Not Started". */
function admin_teacher_submissions_fetch_rows(PDO $pdo, int $schoolId, int $assessmentId, ?int $classId): array
{
    $sql = "
        SELECT
            ta.teacher_id, ta.class_id, ta.subject_id,
            c.class_name, sub.subject_name,
            TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name,
            COUNT(CASE WHEN sm.submission_status = 'submitted' THEN 1 END) AS submitted_count,
            COUNT(CASE WHEN sm.submission_status = 'draft' THEN 1 END) AS draft_count
        FROM teacher_assignments ta
        JOIN classes c ON c.id = ta.class_id
        JOIN subjects sub ON sub.id = ta.subject_id
        JOIN staff st ON st.staff_id = ta.teacher_id AND st.school_id = ta.school_id
        LEFT JOIN student_marks sm ON sm.class_id = ta.class_id AND sm.subject_id = ta.subject_id
            AND sm.teacher_id = ta.teacher_id AND sm.assessment_id = ?
        WHERE ta.school_id = ?
    ";
    $params = [$assessmentId, $schoolId];
    if ($classId !== null) {
        $sql .= ' AND ta.class_id = ?';
        $params[] = $classId;
    }
    $sql .= ' GROUP BY ta.class_id, ta.subject_id, ta.teacher_id ORDER BY c.class_name, sub.subject_name, teacher_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
