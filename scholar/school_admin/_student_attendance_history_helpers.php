<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR STUDENT ATTENDANCE HISTORY HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/student_attendance_history.php page
| and scholar/api/admin/student_attendance_history.php. Logic ported
| verbatim from the original page.
*/

function admin_student_attendance_history_fetch(PDO $pdo, int $school_id, int $student_id): array
{
    if ($student_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid student.'];
    }

    $stmt = $pdo->prepare("
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ?
        LIMIT 1
    ");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found in your school.'];
    }

    $rows_stmt = $pdo->prepare("
        SELECT a.attendance_date, a.status, a.subject_id, sub.subject_name
        FROM attendance a
        LEFT JOIN subjects sub ON sub.id = a.subject_id
        WHERE a.student_id = ?
          AND (a.school_id = ? OR a.subject_id IS NOT NULL)
        ORDER BY a.attendance_date DESC, sub.subject_name ASC
        LIMIT 200
    ");
    $rows_stmt->execute([$student_id, $school_id]);
    $rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['ok' => true, 'student' => $student, 'rows' => $rows];
}
