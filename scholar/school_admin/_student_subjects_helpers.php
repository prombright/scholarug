<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR STUDENT SUBJECTS (ELECTIVES) HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/student_subjects.php page and
| scholar/api/admin/student_subjects.php. Logic ported verbatim from the
| original page.
*/

// Resolve a school's adopted combination's 3 subject codes to this
// student's own class-scoped subjects.id rows (A-Level subjects are
// adopted per-class, S.5 and S.6 each getting their own subjects row, so
// this can't be a fixed FK -- see _setup/combinations.sql). Returns null
// if any of the 3 codes hasn't been adopted for this student's class.
function scholar_resolve_combination_subjects(PDO $pdo, int $schoolId, string $className, array $codes): ?array
{
    $ids = [];
    $stmt = $pdo->prepare("
        SELECT s.id FROM subjects s
        JOIN subject_catalog sc ON sc.id = s.subject_reference_id
        WHERE s.school_id = ? AND sc.subject_code = ? AND sc.level_type = 'A-Level'
          AND REPLACE(UPPER(s.class_name), '.', '') = REPLACE(UPPER(?), '.', '')
        LIMIT 1
    ");
    foreach ($codes as $code) {
        $stmt->execute([$schoolId, $code, $className]);
        $id = $stmt->fetchColumn();
        if (!$id) return null;
        $ids[] = (int) $id;
    }
    return $ids;
}

/** Student header info + electives/enrollment/combinations for the assign-subjects screen. */
function admin_student_subjects_load(PDO $pdo, int $school_id, int $student_id): array
{
    $stud_stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.level_type, s.combination_id, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ?
    ");
    $stud_stmt->execute([$student_id, $school_id]);
    $student = $stud_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    $is_a_level = ($student['level_type'] ?? 'O-Level') === 'A-Level';
    $class_name = $student['class_name'] ?? '';

    $electives = [];
    if ($class_name !== '') {
        $elec_stmt = $pdo->prepare("
            SELECT id, subject_name, subject_code
            FROM subjects
            WHERE school_id = ? AND subject_type = 'Elective'
              AND REPLACE(UPPER(class_name), '.', '') = REPLACE(UPPER(?), '.', '')
            ORDER BY subject_name ASC
        ");
        $elec_stmt->execute([$school_id, $class_name]);
        $electives = $elec_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $enrolled_stmt = $pdo->prepare("SELECT subject_id FROM student_subjects WHERE student_id = ? AND school_id = ?");
    $enrolled_stmt->execute([$student_id, $school_id]);
    $enrolled_ids = array_map('intval', array_column($enrolled_stmt->fetchAll(PDO::FETCH_ASSOC), 'subject_id'));

    $combinations = [];
    $combo_subject_map = [];
    if ($is_a_level && $class_name !== '') {
        $combo_list_stmt = $pdo->prepare("SELECT * FROM combinations WHERE school_id = ? AND is_active = 1 ORDER BY code");
        $combo_list_stmt->execute([$school_id]);
        foreach ($combo_list_stmt->fetchAll(PDO::FETCH_ASSOC) as $combo) {
            $resolved = scholar_resolve_combination_subjects($pdo, $school_id, $class_name, [
                $combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3'],
            ]);
            if ($resolved) {
                $combinations[] = $combo;
                $combo_subject_map[(int) $combo['id']] = $resolved;
            }
        }
    }

    return [
        'ok' => true,
        'student' => $student,
        'is_a_level' => $is_a_level,
        'class_name' => $class_name,
        'electives' => $electives,
        'enrolled_ids' => $enrolled_ids,
        'combinations' => $combinations,
        'combo_subject_map' => $combo_subject_map,
    ];
}

/** Full replace-set save for one student's electives (+ A-Level combination). Same validation/rules as the original page. */
function admin_student_subjects_save(PDO $pdo, int $school_id, int $student_id, array $ticked_ids, int $posted_combo_id): array
{
    $stud_stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.level_type, s.combination_id, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ?
    ");
    $stud_stmt->execute([$student_id, $school_id]);
    $student = $stud_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }

    $is_a_level = ($student['level_type'] ?? 'O-Level') === 'A-Level';
    $class_name = $student['class_name'] ?? '';

    if ($class_name === '') {
        return ['ok' => false, 'message' => "This student isn't assigned to a class yet -- set that first on their profile."];
    }

    $ticked_ids = array_map('intval', $ticked_ids);

    // Re-validate every ticked ID is actually an Elective subject in this
    // student's own class/school -- never trust a posted ID at face value.
    $elective_stmt = $pdo->prepare("
        SELECT id FROM subjects
        WHERE school_id = ? AND subject_type = 'Elective'
          AND REPLACE(UPPER(class_name), '.', '') = REPLACE(UPPER(?), '.', '')
    ");
    $elective_stmt->execute([$school_id, $class_name]);
    $valid_ids = array_map('intval', array_column($elective_stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $final_ids = array_intersect($ticked_ids, $valid_ids);

    // UACE rule: a student can offer Subsidiary Mathematics or Subsidiary
    // ICT, never both (General Paper has no such restriction -- every
    // A-Level student can take it).
    if ($is_a_level) {
        $sub_math_id = scholar_resolve_combination_subjects($pdo, $school_id, $class_name, ['SUBMATH']);
        $ict_id = scholar_resolve_combination_subjects($pdo, $school_id, $class_name, ['ICT']);
        $offers_sub_math = $sub_math_id && in_array($sub_math_id[0], $final_ids, true);
        $offers_ict = $ict_id && in_array($ict_id[0], $final_ids, true);
        if ($offers_sub_math && $offers_ict) {
            return ['ok' => false, 'message' => 'A student can offer Subsidiary Mathematics or Subsidiary ICT, not both. Untick one and save again.'];
        }
    }

    $new_combination_id = null;
    if ($is_a_level && $posted_combo_id > 0) {
        $combo_stmt = $pdo->prepare("SELECT * FROM combinations WHERE id = ? AND school_id = ? AND is_active = 1");
        $combo_stmt->execute([$posted_combo_id, $school_id]);
        $combo = $combo_stmt->fetch(PDO::FETCH_ASSOC);
        if ($combo) {
            $resolved = scholar_resolve_combination_subjects($pdo, $school_id, $class_name, [
                $combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3'],
            ]);
            if ($resolved) {
                $new_combination_id = $posted_combo_id;
                // Union in regardless of tick state -- a combination's
                // subjects are always assigned once picked, JS-disabled
                // clients included.
                $final_ids = array_unique(array_merge($final_ids, $resolved));
            }
        }
    }
    $final_ids = array_values($final_ids);

    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM student_subjects WHERE student_id = ? AND school_id = ?");
        $del->execute([$student_id, $school_id]);

        if ($final_ids) {
            $ins = $pdo->prepare("INSERT INTO student_subjects (school_id, student_id, subject_id) VALUES (?, ?, ?)");
            foreach ($final_ids as $sid) {
                $ins->execute([$school_id, $student_id, $sid]);
            }
        }

        if ($is_a_level) {
            $pdo->prepare("UPDATE students SET combination_id = ? WHERE id = ? AND school_id = ?")
                ->execute([$new_combination_id, $student_id, $school_id]);
        }

        $pdo->commit();
        return ['ok' => true, 'message' => count($final_ids) . ' subject(s) saved for ' . $student['full_name'] . '.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not save: ' . $e->getMessage()];
    }
}
