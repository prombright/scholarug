<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER ASSIGNMENTS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/assign_teacher.php so the classic page and the
| JSON endpoint (api/admin/assign_teacher.php) enforce identical rules --
| in particular the paper-number uniqueness check and the role whitelist
| (a school admin can only promote into teacher/dos/headteacher/bursar,
| never developer/school_admin itself).
|--------------------------------------------------------------------------
*/

function admin_assign_fetch_teachers(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare("
        SELECT staff.staff_id, staff.first_name, staff.last_name, u.role AS acct_role
        FROM staff
        LEFT JOIN users u ON u.staff_id = staff.staff_id
        WHERE staff.school_id = ? AND staff.staff_category = 'Teaching'
        ORDER BY staff.first_name
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** GROUP BY department_name (not just DISTINCT) so stale duplicate rows still show each name once. */
function admin_assign_fetch_departments(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT MIN(id) AS id, department_name FROM departments WHERE school_id = ? GROUP BY department_name ORDER BY department_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_assign_fetch_subjects(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT id, subject_name, class_name, level_type, papers_count FROM subjects WHERE school_id = ? ORDER BY subject_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Groups subjects by name so a picker shows "Biology" once instead of once
 * per class -- {subject_name: {class_name: {id, papers_count}}}.
 */
function admin_assign_subjects_by_name(array $subjects): array
{
    $byName = [];
    foreach ($subjects as $s) {
        $byName[$s['subject_name']][$s['class_name']] = [
            'id' => (int) $s['id'],
            'papers_count' => (int) $s['papers_count'],
        ];
    }
    return $byName;
}

function admin_assign_fetch_classes(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{teacher:?array,departments:array<int,int>,assignments:array,current_role:?string} */
function admin_assign_fetch_teacher_detail(PDO $pdo, int $schoolId, array $teachers, int $staffId): array
{
    $teacher = null;
    foreach ($teachers as $t) {
        if ((int) $t['staff_id'] === $staffId) { $teacher = $t; break; }
    }
    if (!$teacher) {
        return ['teacher' => null, 'departments' => [], 'assignments' => [], 'current_role' => null];
    }

    $dstmt = $pdo->prepare('SELECT department_id FROM staff_departments WHERE staff_id = ? AND school_id = ?');
    $dstmt->execute([$staffId, $schoolId]);
    $departments = array_map('intval', array_column($dstmt->fetchAll(PDO::FETCH_ASSOC), 'department_id'));

    $astmt = $pdo->prepare('
        SELECT ta.id, ta.paper_number, ta.periods_per_week, sub.subject_name, sub.papers_count, c.class_name, c.stream_name
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.id = ta.subject_id
        JOIN classes c ON c.id = ta.class_id
        WHERE ta.teacher_id = ? AND ta.school_id = ?
        ORDER BY c.class_name, sub.subject_name, ta.paper_number
    ');
    $astmt->execute([$staffId, $schoolId]);

    return [
        'teacher' => $teacher,
        'departments' => $departments,
        'assignments' => $astmt->fetchAll(PDO::FETCH_ASSOC),
        'current_role' => $teacher['acct_role'],
    ];
}

function admin_assign_save_departments(PDO $pdo, int $schoolId, int $staffId, array $deptIds): void
{
    $pdo->prepare('DELETE FROM staff_departments WHERE staff_id = ? AND school_id = ?')->execute([$staffId, $schoolId]);
    if ($deptIds) {
        $ins = $pdo->prepare('INSERT INTO staff_departments (school_id, staff_id, department_id) VALUES (?, ?, ?)');
        foreach ($deptIds as $did) {
            $ins->execute([$schoolId, $staffId, $did]);
        }
    }
}

/** @return array{ok:bool,message:string} */
function admin_assign_add_assignment(PDO $pdo, int $schoolId, int $staffId, int $subjectId, int $classId, int $periodsPerWeek, int $requestedPaper): array
{
    if ($subjectId <= 0 || $classId <= 0) {
        return ['ok' => false, 'message' => 'Pick both a subject and a class.'];
    }

    $periodsPerWeek = max(1, min(15, $periodsPerWeek));

    $papers_stmt = $pdo->prepare('SELECT papers_count FROM subjects WHERE id = ? AND school_id = ?');
    $papers_stmt->execute([$subjectId, $schoolId]);
    $papers_count = max(1, (int) $papers_stmt->fetchColumn());
    $paper_number = $papers_count > 1 ? max(1, min($papers_count, $requestedPaper)) : 1;

    $exists = $pdo->prepare('SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND paper_number = ? AND school_id = ?');
    $exists->execute([$staffId, $classId, $subjectId, $paper_number, $schoolId]);
    if ($exists->fetchColumn()) {
        return ['ok' => false, 'message' => 'That assignment already exists.'];
    }

    try {
        $pdo->prepare('INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, paper_number, periods_per_week, school_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$staffId, $classId, $subjectId, $paper_number, $periodsPerWeek, $schoolId]);
        return ['ok' => true, 'message' => 'Assignment added.'];
    } catch (\PDOException $e) {
        return ['ok' => false, 'message' => 'Could not save this assignment. Please try again or contact support.'];
    }
}

function admin_assign_update_periods(PDO $pdo, int $schoolId, int $staffId, int $assignmentId, int $periodsPerWeek): void
{
    $periodsPerWeek = max(1, min(15, $periodsPerWeek));
    $pdo->prepare('UPDATE teacher_assignments SET periods_per_week = ? WHERE id = ? AND school_id = ? AND teacher_id = ?')
        ->execute([$periodsPerWeek, $assignmentId, $schoolId, $staffId]);
}

function admin_assign_remove_assignment(PDO $pdo, int $schoolId, int $staffId, int $assignmentId): void
{
    $pdo->prepare('DELETE FROM teacher_assignments WHERE id = ? AND school_id = ? AND teacher_id = ?')
        ->execute([$assignmentId, $schoolId, $staffId]);
}

const ADMIN_ASSIGN_ALLOWED_ROLES = ['teacher', 'dos', 'headteacher', 'bursar'];

/** @return array{ok:bool,message:string} */
function admin_assign_change_role(PDO $pdo, int $schoolId, int $staffId, string $newRole): array
{
    if (!in_array($newRole, ADMIN_ASSIGN_ALLOWED_ROLES, true)) {
        return ['ok' => false, 'message' => 'Invalid role.'];
    }
    $pdo->prepare('UPDATE users SET role = ? WHERE staff_id = ? AND school_id = ?')->execute([$newRole, $staffId, $schoolId]);
    return ['ok' => true, 'message' => 'Role updated. They will see the new dashboard next time they log in.'];
}
