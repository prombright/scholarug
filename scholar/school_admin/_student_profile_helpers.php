<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR STUDENT PROFILE HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/student_profile.php page and
| scholar/api/admin/student_profile.php. Logic ported verbatim from the
| original student_profile.php.
*/

function admin_student_profile_fetch(PDO $pdo, int $school_id, int $student_id): array
{
    if ($student_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid student profile.'];
    }

    $stmt = $pdo->prepare(
        "
        SELECT
            s.*,
            c.class_name,
            g.guardian_name AS parent_name,
            g.phone AS parent_phone
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN guardians g ON g.student_id = s.id
        WHERE s.id = ? AND s.school_id = ?
        LIMIT 1
        "
    );
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found or access denied.'];
    }

    try {
        $attendance_stmt = $pdo->prepare(
            "
            SELECT
                COUNT(*) AS total_days,
                SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS present_days
            FROM attendance
            WHERE student_id=? AND school_id=?
            "
        );
        $attendance_stmt->execute([$student_id, $school_id]);
        $attendance = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $attendance = ['total_days' => 0, 'present_days' => 0];
    }

    try {
        $fees_stmt = $pdo->prepare(
            "
            SELECT SUM(amount_paid) AS paid, SUM(amount_due) AS due
            FROM fees
            WHERE student_id=? AND school_id=?
            "
        );
        $fees_stmt->execute([$student_id, $school_id]);
        $fees = $fees_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $fees = ['paid' => 0, 'due' => 0];
    }

    try {
        $results_stmt = $pdo->prepare(
            "
            SELECT *
            FROM results
            WHERE student_id=? AND school_id=?
            ORDER BY id DESC
            LIMIT 5
            "
        );
        $results_stmt->execute([$student_id, $school_id]);
        $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $results = [];
    }

    return [
        'ok' => true,
        'student' => $student,
        'attendance' => $attendance,
        'fees' => $fees,
        'results' => $results,
    ];
}
