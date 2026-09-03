<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — MARKS ENTRY: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of teacher_marks_entry.php so the classic page and the JSON
| endpoint (api/teacher/marks.php) enforce identical rules -- assignment
| ownership, "assessment must be Open", the 0-100 guard, and the
| draft-vs-submit transaction are all security/data-integrity checks that
| must never drift between the two renderers.
|--------------------------------------------------------------------------
*/

/** Is this teacher actually assigned to this class/subject/paper? */
function marks_verify_assignment(PDO $pdo, int $schoolId, int $staffId, int $classId, int $subjectId, int $paperNumber): bool
{
    $stmt = $pdo->prepare("
        SELECT id FROM teacher_assignments
        WHERE school_id = ? AND teacher_id = ? AND class_id = ? AND subject_id = ? AND paper_number = ?
    ");
    $stmt->execute([$schoolId, $staffId, $classId, $subjectId, $paperNumber]);
    return (bool) $stmt->fetchColumn();
}

/** @return string|false the assessment's status, or false if it doesn't exist in this school */
function marks_assessment_status(PDO $pdo, int $schoolId, int $assessmentId)
{
    $stmt = $pdo->prepare('SELECT status FROM assessments WHERE id = ? AND school_id = ?');
    $stmt->execute([$assessmentId, $schoolId]);
    return $stmt->fetchColumn();
}

/**
 * Elective subjects only roster students actually enrolled in them (via
 * student_subjects); Core subjects keep the whole class list -- same rule
 * everywhere this roster is built (form view, CSV template, CSV import).
 */
function marks_is_elective(PDO $pdo, int $schoolId, int $subjectId): bool
{
    $stmt = $pdo->prepare('SELECT subject_type FROM subjects WHERE id = ? AND school_id = ?');
    $stmt->execute([$subjectId, $schoolId]);
    return $stmt->fetchColumn() === 'Elective';
}

/**
 * The roster for one class/subject/assessment/paper, each row carrying
 * whatever mark (and draft/submitted status) is already on file.
 *
 * @return array<int,array{student_id:int,full_name:string,student_no:?string,marks:?float,submission_status:?string}>
 */
function marks_fetch_roster(PDO $pdo, int $schoolId, int $classId, int $subjectId, int $assessmentId, int $paperNumber): array
{
    $isElective = marks_is_elective($pdo, $schoolId, $subjectId);

    $stmt = $pdo->prepare("
        SELECT st.id AS student_id, st.full_name, st.student_no, sm.marks, sm.submission_status
        FROM students st
        LEFT JOIN student_marks sm
               ON st.id = sm.student_id
              AND sm.subject_id = :subject_id
              AND sm.assessment_id = :assessment_id
              AND sm.paper_number = :paper_number
        WHERE st.school_id = :school_id AND st.class_id = :class_id
        " . ($isElective ? 'AND EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = st.id AND ss.subject_id = :elective_subject_id)' : '') . "
        ORDER BY st.full_name ASC
    ");
    $params = [
        ':school_id' => $schoolId,
        ':class_id' => $classId,
        ':subject_id' => $subjectId,
        ':assessment_id' => $assessmentId,
        ':paper_number' => $paperNumber,
    ];
    if ($isElective) {
        $params[':elective_subject_id'] = $subjectId;
    }
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Writes $marksInput (student_id => raw mark) as either 'draft' or
 * 'submitted', inside one transaction. Caller must already have verified
 * the assignment and that the assessment is Open -- this only enforces the
 * 0-100 range (a browser-side min/max is not a real guarantee against a
 * direct POST) and does the actual write.
 *
 * @param array<int|string,mixed> $marksInput
 * @return array{touched:int,out_of_range:int}
 */
function marks_save(PDO $pdo, int $schoolId, int $staffId, int $classId, int $subjectId, int $paperNumber, int $assessmentId, array $marksInput, string $action): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO student_marks (school_id, student_id, class_id, subject_id, paper_number, teacher_id, assessment_id, marks, submission_status, submitted_at)
            VALUES (:school_id, :student_id, :class_id, :subject_id, :paper_number, :teacher_id, :assessment_id, :marks, :status, :submitted_at)
            ON DUPLICATE KEY UPDATE
                marks = VALUES(marks),
                teacher_id = VALUES(teacher_id),
                submission_status = VALUES(submission_status),
                submitted_at = VALUES(submitted_at),
                updated_at = NOW()
        ");

        $submittedAt = $action === 'submitted' ? date('Y-m-d H:i:s') : null;
        $touched = 0;
        $outOfRange = 0;
        foreach ($marksInput as $studentId => $markVal) {
            if ($markVal === '' || $markVal === null) continue;
            $markVal = (float) $markVal;
            if ($markVal < 0 || $markVal > 100) {
                $outOfRange++;
                continue;
            }
            $stmt->execute([
                ':school_id' => $schoolId,
                ':student_id' => (int) $studentId,
                ':class_id' => $classId,
                ':subject_id' => $subjectId,
                ':paper_number' => $paperNumber,
                ':teacher_id' => $staffId,
                ':assessment_id' => $assessmentId,
                ':marks' => $markVal,
                ':status' => $action,
                ':submitted_at' => $submittedAt,
            ]);
            $touched++;
        }
        $pdo->commit();
        return ['touched' => $touched, 'out_of_range' => $outOfRange];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Per-assessment progress teaser: how many of $myAssignments already have
 * at least one mark on file for that assessment. One grouped query rather
 * than one per assessment.
 *
 * @return array<int,array{done:int,total:int}>
 */
function marks_progress_by_assessment(PDO $pdo, int $schoolId, int $staffId, array $activeAssessments, array $myAssignments): array
{
    if (empty($activeAssessments) || empty($myAssignments)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT assessment_id, class_id, subject_id, paper_number
        FROM student_marks
        WHERE school_id = ? AND teacher_id = ?
        GROUP BY assessment_id, class_id, subject_id, paper_number
    ");
    $stmt->execute([$schoolId, $staffId]);

    $started = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $started[$row['assessment_id'] . ':' . $row['class_id'] . ':' . $row['subject_id'] . ':' . $row['paper_number']] = true;
    }

    $progress = [];
    foreach ($activeAssessments as $a) {
        $done = 0;
        foreach ($myAssignments as $assign) {
            $key = $a['id'] . ':' . $assign['class_id'] . ':' . $assign['subject_id'] . ':' . $assign['paper_number'];
            if (isset($started[$key])) {
                $done++;
            }
        }
        $progress[$a['id']] = ['done' => $done, 'total' => count($myAssignments)];
    }
    return $progress;
}
