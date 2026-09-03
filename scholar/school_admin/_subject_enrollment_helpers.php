<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR SUBJECT ENROLLMENT (subject-first) HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/subject_enrollment.php page and
| scholar/api/admin/subject_enrollment.php. Logic ported verbatim from the
| original page.
*/

/** Resolves the selected class/subject (with subject_matrix.php-style deep-link support) and, if both resolved, the roster + enrolled ids. */
function admin_subject_enrollment_load(PDO $pdo, int $school_id, int $sel_class_id, int $sel_subject_id): array
{
    $classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
    $classes_stmt->execute([$school_id]);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sel_class = null;
    foreach ($classes as $c) {
        if ((int) $c['id'] === $sel_class_id) { $sel_class = $c; break; }
    }

    // Deep-link support: subject_matrix.php/subject_catalog.php link straight
    // to a subject_id without knowing its class_id (they only have the
    // subject's own, possibly differently-formatted, class_name). Resolve the
    // class from the subject itself so those "Assign Students" links work
    // without the class dropdown having been touched first.
    if (!$sel_class && $sel_class_id === 0 && $sel_subject_id > 0) {
        $deep_stmt = $pdo->prepare("SELECT class_name FROM subjects WHERE id = ? AND school_id = ? AND subject_type = 'Elective'");
        $deep_stmt->execute([$sel_subject_id, $school_id]);
        $deep_class_name = $deep_stmt->fetchColumn();
        if ($deep_class_name) {
            foreach ($classes as $c) {
                if (strtoupper(str_replace('.', '', $c['class_name'])) === strtoupper(str_replace('.', '', $deep_class_name))) {
                    $sel_class = $c;
                    $sel_class_id = (int) $c['id'];
                    break;
                }
            }
        }
    }

    $electives = [];
    if ($sel_class) {
        $elec_stmt = $pdo->prepare("
            SELECT id, subject_name, subject_code
            FROM subjects
            WHERE school_id = ? AND subject_type = 'Elective'
              AND REPLACE(UPPER(class_name), '.', '') = REPLACE(UPPER(?), '.', '')
            ORDER BY subject_name ASC
        ");
        $elec_stmt->execute([$school_id, $sel_class['class_name']]);
        $electives = $elec_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $sel_subject = null;
    foreach ($electives as $e) {
        if ((int) $e['id'] === $sel_subject_id) { $sel_subject = $e; break; }
    }

    $students = [];
    $enrolled_ids = [];
    if ($sel_class && $sel_subject) {
        $stud_stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name ASC");
        $stud_stmt->execute([$school_id, $sel_class['id']]);
        $students = $stud_stmt->fetchAll(PDO::FETCH_ASSOC);

        $enrolled_stmt = $pdo->prepare("SELECT student_id FROM student_subjects WHERE subject_id = ? AND school_id = ?");
        $enrolled_stmt->execute([$sel_subject['id'], $school_id]);
        $enrolled_ids = array_map('intval', array_column($enrolled_stmt->fetchAll(PDO::FETCH_ASSOC), 'student_id'));
    }

    return [
        'classes' => $classes,
        'sel_class' => $sel_class,
        'electives' => $electives,
        'sel_subject' => $sel_subject,
        'students' => $students,
        'enrolled_ids' => $enrolled_ids,
    ];
}

/** Full replace-set save for one elective subject's roster. Returns ['ok'=>bool,'message'=>string]. */
function admin_subject_enrollment_save(PDO $pdo, int $school_id, array $sel_class, array $sel_subject, array $ticked_ids): array
{
    $ticked_ids = array_map('intval', $ticked_ids);

    // Re-validate every ticked ID actually belongs to this class/school.
    $roster_stmt = $pdo->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ?");
    $roster_stmt->execute([$school_id, $sel_class['id']]);
    $valid_ids = array_map('intval', array_column($roster_stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $final_ids = array_values(array_intersect($ticked_ids, $valid_ids));

    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM student_subjects WHERE subject_id = ? AND school_id = ?");
        $del->execute([$sel_subject['id'], $school_id]);

        if ($final_ids) {
            $ins = $pdo->prepare("INSERT INTO student_subjects (school_id, student_id, subject_id) VALUES (?, ?, ?)");
            foreach ($final_ids as $sid) {
                $ins->execute([$school_id, $sid, $sel_subject['id']]);
            }
        }
        $pdo->commit();
        return ['ok' => true, 'message' => count($final_ids) . ' student(s) enrolled in ' . $sel_subject['subject_name'] . '.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not save: ' . $e->getMessage()];
    }
}
