<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ADMIN ATTENDANCE: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/attendance.php so the classic page and the
| JSON endpoint (api/admin/attendance.php) read/write the exact same way.
|
| Same status vocabulary as the teacher Roll Call tool now (both write to
| the same `attendance` table under school_id/subject_id IS NULL): present/
| absent/sick/permission/late -- the real attendance.status ENUM values
| (see _setup/attendance_late_status_migration.sql). This page's UI labels
| "Excused" for the stored 'permission' value and offers 'late' as its own
| option; earlier versions of this page sent 'Present'/'Absent'/'Late'/
| 'Excused' directly, which only 2 of the 4 ever matched a real enum member
| -- 'Late'/'Excused' either silently truncated to '' (non-strict sql_mode)
| or threw and rolled back the whole class's save (strict mode), while the
| page still reported success either way.
|--------------------------------------------------------------------------
*/

/** The only 5 values attendance.status actually accepts. */
const ADMIN_ATTENDANCE_STATUSES = ['present', 'absent', 'sick', 'permission', 'late'];

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
            // Same allowlist the teacher Roll Call save already enforces --
            // a status that isn't one of these 5 real enum values would
            // otherwise either silently corrupt to '' or throw and roll
            // back every other student's attendance in this same
            // transaction (see this file's header).
            if (!in_array($status, ADMIN_ATTENDANCE_STATUSES, true)) {
                continue;
            }
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
