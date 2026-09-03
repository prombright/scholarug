<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ADMIN ATTENDANCE: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/attendance.php so the classic page and the
| JSON endpoint (api/admin/attendance.php) read/write the exact same way.
|
| Note this uses a DIFFERENT status vocabulary (Present/Absent/Late/Excused)
| than the teacher Roll Call tool (present/absent/sick/permission) -- both
| write to the same `attendance` table under school_id/subject_id IS NULL.
| That mismatch predates this refactor; preserved as-is, not something this
| pass reconciles.
|--------------------------------------------------------------------------
*/

function admin_attendance_fetch_classes(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE school_id = ? ORDER BY class_name ASC');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_attendance_fetch_students(PDO $pdo, int $schoolId, int $classId): array
{
    if ($classId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM students WHERE school_id = ? AND class_id = ? ORDER BY student_name ASC');
    $stmt->execute([$schoolId, $classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,message:string} */
function admin_attendance_save(PDO $pdo, int $schoolId, int $classId, string $date, array $statuses): array
{
    try {
        $pdo->beginTransaction();

        $check = $pdo->prepare('SELECT id FROM attendance WHERE student_id = ? AND school_id = ? AND attendance_date = ? AND subject_id IS NULL LIMIT 1');
        $update = $pdo->prepare('UPDATE attendance SET status = ? WHERE id = ?');
        $insert = $pdo->prepare('INSERT INTO attendance (school_id, student_id, class_id, attendance_date, status) VALUES (?, ?, ?, ?, ?)');

        foreach ($statuses as $studentId => $status) {
            $check->execute([$studentId, $schoolId, $date]);
            $existing = $check->fetchColumn();

            if ($existing) {
                $update->execute([$status, $existing]);
            } else {
                $insert->execute([$schoolId, $studentId, $classId, $date, $status]);
            }
        }

        $pdo->commit();
        return ['ok' => true, 'message' => 'Attendance saved successfully.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Unable to save attendance: ' . $e->getMessage()];
    }
}

/** @return array<string,int> status => count, for the given date (whole school, not just one class) */
function admin_attendance_today_summary(PDO $pdo, int $schoolId, string $date): array
{
    try {
        $stmt = $pdo->prepare('SELECT status, COUNT(*) total FROM attendance WHERE school_id = ? AND attendance_date = ? GROUP BY status');
        $stmt->execute([$schoolId, $date]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return [];
    }
}
