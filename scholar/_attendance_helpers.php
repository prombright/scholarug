<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER ROLL CALL: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of teacher_attendance.php so the classic page and the JSON
| endpoint (api/teacher/attendance.php) read/write the exact same way --
| in particular the check-then-write upsert (see the comment on
| attendance_save() below for why it can't just be ON DUPLICATE KEY UPDATE).
|--------------------------------------------------------------------------
*/

/** @return array<int,array{id:int,full_name:string,student_no:?string,status:?string,created_at:?string}> */
function attendance_fetch_roster(PDO $pdo, int $schoolId, int $classId, string $date): array
{
    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.student_no, a.status, a.created_at
        FROM students s
        LEFT JOIN attendance a
               ON a.student_id = s.id AND a.school_id = ? AND a.attendance_date = ? AND a.subject_id IS NULL
        WHERE s.school_id = ? AND s.class_id = ?
        ORDER BY s.full_name
    ");
    $stmt->execute([$schoolId, $date, $schoolId, $classId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{taken_at:?string,counts:array{present:int,absent:int,sick:int,permission:int}} */
function attendance_summarize(array $roster): array
{
    $takenAt = null;
    foreach ($roster as $r) {
        if (!empty($r['created_at']) && ($takenAt === null || $r['created_at'] < $takenAt)) {
            $takenAt = $r['created_at'];
        }
    }

    $counts = ['present' => 0, 'absent' => 0, 'sick' => 0, 'permission' => 0];
    if ($takenAt) {
        foreach ($roster as $r) {
            $st = $r['status'] ?? 'present';
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }
    }

    return ['taken_at' => $takenAt, 'counts' => $counts];
}

/**
 * Whole-class, no subject (subject_id left NULL) -- shares the one
 * attendance table with the per-subject history already on file. Can't
 * rely on a uniq_attendance_entry(student_id, subject_id, date) key / ON
 * DUPLICATE KEY UPDATE here the way a per-subject save could -- MySQL
 * treats NULL subject_id as never equal to itself, so two saves on the
 * same day would just insert a second row instead of updating the first.
 * Explicit check-then-write instead.
 *
 * @param array<int|string,string> $statuses student_id => status
 * @return int how many rows were touched
 */
function attendance_save(PDO $pdo, int $schoolId, int $classId, int $staffId, string $date, array $statuses): int
{
    $check_stmt = $pdo->prepare('SELECT id FROM attendance WHERE student_id = ? AND school_id = ? AND attendance_date = ? AND subject_id IS NULL LIMIT 1');
    $update_stmt = $pdo->prepare('UPDATE attendance SET status = ?, teacher_id = ? WHERE id = ?');
    $insert_stmt = $pdo->prepare('INSERT INTO attendance (school_id, student_id, class_id, teacher_id, attendance_date, status) VALUES (?, ?, ?, ?, ?, ?)');

    $touched = 0;
    foreach ($statuses as $studentId => $status) {
        if (!in_array($status, ['present', 'absent', 'sick', 'permission'], true)) continue;

        $check_stmt->execute([(int) $studentId, $schoolId, $date]);
        $existingId = $check_stmt->fetchColumn();

        if ($existingId) {
            $update_stmt->execute([$status, $staffId, $existingId]);
        } else {
            $insert_stmt->execute([$schoolId, (int) $studentId, $classId, $staffId, $date, $status]);
        }
        $touched++;
    }
    return $touched;
}
