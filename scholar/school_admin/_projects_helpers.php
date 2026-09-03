<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR PROJECTS (ADMIN OVERSIGHT) HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/projects.php page and
| scholar/api/admin/projects.php. Logic ported verbatim from the original
| page.
*/

/** S.3-S.6 classes only. Anchored to an "S" prefix -- see admin_student_level_type() for the same P.x-misread bug/fix. */
function admin_projects_eligible_classes(PDO $pdo, int $school_id): array
{
    $classes_stmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name");
    $classes_stmt->execute([$school_id]);
    $all_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($all_classes, static function (array $c): bool {
        return preg_match('/^S\.?\s*([1-9][0-9]*)/i', $c['class_name'], $m) && (int) $m[1] >= 3;
    }));
}

function admin_projects_teachers(PDO $pdo, int $school_id): array
{
    $teachers_stmt = $pdo->prepare("SELECT staff_id, first_name, last_name FROM staff WHERE school_id = ? AND staff_category = 'Teaching' AND status = 'active' ORDER BY first_name");
    $teachers_stmt->execute([$school_id]);
    return $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** This class's project (if any) + assigned teachers + every student's stage/evidence history. */
function admin_projects_class_state(PDO $pdo, int $school_id, int $sel_class_id): array
{
    $proj_stmt = $pdo->prepare("SELECT * FROM projects WHERE school_id = ? AND class_id = ?");
    $proj_stmt->execute([$school_id, $sel_class_id]);
    $project = $proj_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $assigned_teachers = [];
    $students = [];
    $stages_by_student = [];

    if ($project) {
        $at_stmt = $pdo->prepare("
            SELECT st.staff_id, TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name
            FROM project_teacher_assignments pta
            JOIN staff st ON st.staff_id = pta.teacher_id AND st.school_id = ?
            WHERE pta.project_id = ?
        ");
        $at_stmt->execute([$school_id, $project['id']]);
        $assigned_teachers = $at_stmt->fetchAll(PDO::FETCH_ASSOC);

        $students_stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name");
        $students_stmt->execute([$school_id, $sel_class_id]);
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

        $stages_stmt = $pdo->prepare("
            SELECT ps.student_id, ps.stage_title, ps.description, ps.recorded_at,
                GROUP_CONCAT(pse.file_path SEPARATOR '|') AS photo_paths
            FROM project_stages ps
            LEFT JOIN project_stage_evidence pse ON pse.stage_id = ps.id
            WHERE ps.project_id = ?
            GROUP BY ps.id
            ORDER BY ps.recorded_at DESC
        ");
        $stages_stmt->execute([$project['id']]);
        foreach ($stages_stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $stages_by_student[(int) $s['student_id']][] = $s;
        }
    }

    return [
        'project' => $project,
        'assigned_teachers' => $assigned_teachers,
        'students' => $students,
        'stages_by_student' => $stages_by_student,
    ];
}

function admin_projects_create(PDO $pdo, int $school_id, int $sel_class_id, int $admin_user_id, string $title, string $description, int $teacher_id): array
{
    $teacher_check = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ?");
    $teacher_check->execute([$teacher_id, $school_id]);

    if ($title === '' || !$teacher_check->fetch()) {
        return ['ok' => false, 'message' => 'Please enter a title and pick a valid teacher.'];
    }

    $ins = $pdo->prepare("INSERT INTO projects (school_id, class_id, title, description, created_by) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$school_id, $sel_class_id, $title, $description, $admin_user_id]);
    $project_id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_teacher_assignments (project_id, teacher_id) VALUES (?, ?)")->execute([$project_id, $teacher_id]);

    return ['ok' => true, 'message' => 'Project created and teacher assigned.'];
}

function admin_projects_assign_teacher(PDO $pdo, int $school_id, int $sel_class_id, int $project_id, int $teacher_id): array
{
    $proj_check = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND school_id = ? AND class_id = ?");
    $proj_check->execute([$project_id, $school_id, $sel_class_id]);
    $teacher_check = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ?");
    $teacher_check->execute([$teacher_id, $school_id]);

    if (!$proj_check->fetch() || !$teacher_check->fetch()) {
        return ['ok' => false, 'message' => 'Could not assign that teacher.'];
    }

    $pdo->prepare("INSERT IGNORE INTO project_teacher_assignments (project_id, teacher_id) VALUES (?, ?)")->execute([$project_id, $teacher_id]);
    return ['ok' => true, 'message' => 'Teacher assigned.'];
}
