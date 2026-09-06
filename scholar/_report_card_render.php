<?php
/*
|--------------------------------------------------------------------------
| SCHOLAR — SHARED REPORT CARD RENDERER
|--------------------------------------------------------------------------
| Extracted from generate_report.php so single-student printing
| (generate_report.php) and whole-class bulk printing
| (school_admin/bulk_report_print.php) render from one source of truth
| instead of two copies of the same markup drifting apart.
|
| render_report_card_html() does NOT do any access-control check --
| callers are responsible for that (generate_report.php checks
| is_class_teacher_of() for a 'teacher' role; bulk_report_print.php is
| already class-scoped by its own dropdown, so no per-student check is
| needed inside its loop).
|--------------------------------------------------------------------------
*/

/**
 * Pure-PHP grade lookup against an already-fetched grading_scales array,
 * instead of a `WHERE :score BETWEEN min_mark AND max_mark` SQL round trip.
 * Same first-match semantics as the SQL version (ordered by min_mark).
 *
 * @param array<int,array{grade:string,points:mixed,remark:?string,min_mark:mixed,max_mark:mixed}> $gradingScales
 */
function scholar_grade_for_score(array $gradingScales, float $score): array
{
    foreach ($gradingScales as $rule) {
        if ($score >= (float) $rule['min_mark'] && $score <= (float) $rule['max_mark']) {
            return ['grade' => $rule['grade'], 'points' => $rule['points'], 'comment' => $rule['remark'], 'color' => $rule['color'] ?? null];
        }
    }
    return ['grade' => 'N/A', 'points' => 0, 'comment' => 'Grade scale not configured for this score.', 'color' => null];
}

/**
 * Fetches one school's grading_scales table once, ordered to match the
 * original per-call SQL's implicit first-match behavior.
 *
 * @return array<int,array{grade:string,points:mixed,remark:?string,min_mark:mixed,max_mark:mixed,color:?string}>
 */
function scholar_fetch_grading_scales(PDO $pdo, int $school_id): array
{
    $stmt = $pdo->prepare("SELECT grade, points, remark, min_mark, max_mark, color FROM grading_scales WHERE school_id = ? ORDER BY min_mark ASC");
    $stmt->execute([$school_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One school's report-display settings (student photos, download gating,
 * no-data row color) -- school_settings.school_id is UNIQUE, so a school
 * has at most one row; sensible defaults apply when it has none yet, so
 * no school has to touch report_settings.php before reports keep working.
 *
 * @return array{show_student_photos:bool,allow_student_download:bool,no_data_color:?string}
 */
function scholar_fetch_report_settings(PDO $pdo, int $school_id): array
{
    $stmt = $pdo->prepare("SELECT show_student_photos, allow_student_download, no_data_color FROM school_settings WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'show_student_photos'    => $row ? (bool) $row['show_student_photos'] : true,
        'allow_student_download' => $row ? (bool) $row['allow_student_download'] : false,
        'no_data_color'          => $row['no_data_color'] ?? null,
    ];
}

/**
 * Every submitted, report-eligible weighted score for every student/subject
 * in one class in a single query -- the batched replacement for calling
 * getCalculatedGradeAndComment() once per (student, subject), which is what
 * made bulk_report_print.php run 800-1,600 queries for one 40-student class.
 * Same weighting math as getCalculatedGradeAndComment() (AVG per assessment,
 * multiplied by that assessment's weight, summed), just grouped by student
 * in PHP instead of re-querying per student/subject.
 *
 * @param int[] $studentIds
 * @return array<int,array<int,array{final:float,breakdown:array<int,array{title:string,raw_mark:float,weight_percentage:float,contribution:float}>}>>
 *         student_id => [subject_id => ['final' => weighted total, 'breakdown' => per-assessment rows]]
 */
function scholar_fetch_class_weighted_scores(PDO $pdo, int $school_id, array $studentIds, string $term, int $year): array
{
    if (empty($studentIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("
        SELECT sm.student_id, sm.subject_id, a.id AS assessment_id, a.title, AVG(sm.marks) AS marks, a.weight_percentage
        FROM student_marks sm
        JOIN assessments a ON sm.assessment_id = a.id
        WHERE sm.school_id = ?
          AND sm.student_id IN ($placeholders)
          AND a.term = ?
          AND a.year = ?
          AND a.include_in_report = 1
          AND sm.submission_status = 'submitted'
        GROUP BY sm.student_id, sm.subject_id, a.id, a.title, a.weight_percentage
    ");
    $stmt->execute(array_merge([$school_id], $studentIds, [$term, $year]));

    $bySubjectRecords = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bySubjectRecords[(int) $row['student_id']][(int) $row['subject_id']][] = $row;
    }

    $scores = [];
    foreach ($bySubjectRecords as $sid => $subjects) {
        foreach ($subjects as $subjectId => $records) {
            $final = 0.0;
            $breakdown = [];
            foreach ($records as $row) {
                $raw = floatval($row['marks']);
                $weight = floatval($row['weight_percentage']);
                $contribution = $raw * ($weight / 100);
                $final += $contribution;
                $breakdown[] = [
                    'title'             => $row['title'],
                    'raw_mark'          => round($raw, 1),
                    'weight_percentage' => $weight,
                    'contribution'      => round($contribution, 1),
                ];
            }
            $scores[$sid][$subjectId] = ['final' => round($final, 1), 'breakdown' => $breakdown];
        }
    }
    return $scores;
}

/**
 * Companion to scholar_fetch_class_weighted_scores() -- which (student,
 * subject) pairs have draft-only marks (entered but not yet submitted),
 * for the same "in progress" vs "nothing recorded" comment distinction
 * getCalculatedGradeAndComment() makes per-call.
 *
 * @param int[] $studentIds
 * @return array<int,array<int,bool>> student_id => [subject_id => true]
 */
function scholar_fetch_class_draft_subjects(PDO $pdo, int $school_id, array $studentIds, string $term, int $year): array
{
    if (empty($studentIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT sm.student_id, sm.subject_id
        FROM student_marks sm
        JOIN assessments a ON sm.assessment_id = a.id
        WHERE sm.school_id = ?
          AND sm.student_id IN ($placeholders)
          AND a.term = ?
          AND a.year = ?
          AND a.include_in_report = 1
          AND sm.submission_status = 'draft'
    ");
    $stmt->execute(array_merge([$school_id], $studentIds, [$term, $year]));

    $drafts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $drafts[(int) $row['student_id']][(int) $row['subject_id']] = true;
    }
    return $drafts;
}

/**
 * One student's human-written remarks for a term -- both columns default
 * to null (rendered as "Not yet added" by the caller) so a school that
 * hasn't adopted remarks yet sees no layout change beyond that one line.
 *
 * @return array{class_teacher_remark:?string,head_teacher_remark:?string}
 */
function scholar_fetch_report_remark(PDO $pdo, int $school_id, int $student_id, string $term, int $year): array
{
    $stmt = $pdo->prepare(
        "SELECT class_teacher_remark, head_teacher_remark FROM report_card_remarks
         WHERE school_id = ? AND student_id = ? AND term = ? AND year = ?"
    );
    $stmt->execute([$school_id, $student_id, $term, $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'class_teacher_remark' => $row['class_teacher_remark'] ?? null,
        'head_teacher_remark'  => $row['head_teacher_remark'] ?? null,
    ];
}

/**
 * Batched companion to scholar_fetch_report_remark() for bulk_report_print.php
 * -- one query for a whole class instead of one per student, same batching
 * convention as scholar_fetch_class_weighted_scores().
 *
 * @param int[] $studentIds
 * @return array<int,array{class_teacher_remark:?string,head_teacher_remark:?string}>
 */
function scholar_fetch_class_report_remarks(PDO $pdo, int $school_id, array $studentIds, string $term, int $year): array
{
    if (empty($studentIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT student_id, class_teacher_remark, head_teacher_remark FROM report_card_remarks
         WHERE school_id = ? AND student_id IN ($placeholders) AND term = ? AND year = ?"
    );
    $stmt->execute(array_merge([$school_id], $studentIds, [$term, $year]));

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['student_id']] = [
            'class_teacher_remark' => $row['class_teacher_remark'],
            'head_teacher_remark'  => $row['head_teacher_remark'],
        ];
    }
    return $out;
}

/**
 * Calculates a student's final weighted assessment score and maps it
 * against the school's admin-configured grading scale database table.
 *
 * $classBatch, when passed by a whole-class caller (bulk_report_print.php),
 * short-circuits both queries below with pre-fetched data -- see
 * scholar_fetch_class_weighted_scores()/scholar_fetch_grading_scales().
 * Left null (every existing call site), behavior is 100% unchanged from
 * before batching existed.
 */
function getCalculatedGradeAndComment(PDO $pdo, int $school_id, int $student_id, int $subject_id, string $term, int $year, ?array $classBatch = null): array
{
    if ($classBatch !== null) {
        $entry = $classBatch['weighted_scores'][$student_id][$subject_id] ?? null;

        if ($entry === null) {
            $hasDraft = !empty($classBatch['draft_subjects'][$student_id][$subject_id]);
            return [
                'final_score' => null,
                'grade'       => '-',
                'points'      => '-',
                'comment'     => $hasDraft
                    ? 'Marks entered but not yet submitted by the teacher.'
                    : 'No assessment marks recorded.',
                'color'       => null,
                'assessments' => [],
            ];
        }

        $score = $entry['final'];
        $grade = scholar_grade_for_score($classBatch['grading_scales'], $score);
        return [
            'final_score' => $score,
            'grade'       => $grade['grade'],
            'points'      => $grade['points'],
            'comment'     => $grade['comment'],
            'color'       => $grade['color'],
            'assessments' => $entry['breakdown'],
        ];
    }

    // Fetch raw marks for assessments the admin has actually chosen to
    // count toward the report (include_in_report = 1) -- a school can have
    // many assessments (practice quizzes, mock papers, etc.) without all
    // of them affecting the official grade.
    // A subject with more than one paper can have multiple student_marks
    // rows per assessment (one per paper, each entered out of 100) --
    // AVG() combines them into one effective mark for that assessment
    // before its weight_percentage is applied. For a single-paper subject
    // this is just AVG() of one row, i.e. the row's own mark -- no change
    // in behavior from before papers existed.
    $stmt = $pdo->prepare("
        SELECT
            a.title,
            AVG(sm.marks) AS marks,
            a.weight_percentage
        FROM student_marks sm
        JOIN assessments a ON sm.assessment_id = a.id
        WHERE sm.school_id = :school_id
          AND sm.student_id = :student_id
          AND sm.subject_id = :subject_id
          AND a.term = :term
          AND a.year = :year
          AND a.include_in_report = 1
          AND sm.submission_status = 'submitted'
        GROUP BY a.id, a.title, a.weight_percentage
    ");
    $stmt->execute([
        ':school_id'  => $school_id,
        ':student_id' => $student_id,
        ':subject_id' => $subject_id,
        ':term'       => $term,
        ':year'       => $year
    ]);

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        // Distinguish "nothing entered at all" from "a teacher has marks
        // in progress but hasn't submitted them yet" -- both look empty
        // to the query above (submission_status='submitted' excludes
        // drafts), but they mean very different things to someone
        // reviewing a report before it's finalized.
        $draft_check = $pdo->prepare("
            SELECT 1 FROM student_marks sm
            JOIN assessments a ON sm.assessment_id = a.id
            WHERE sm.school_id = :school_id
              AND sm.student_id = :student_id
              AND sm.subject_id = :subject_id
              AND a.term = :term
              AND a.year = :year
              AND a.include_in_report = 1
              AND sm.submission_status = 'draft'
            LIMIT 1
        ");
        $draft_check->execute([
            ':school_id'  => $school_id,
            ':student_id' => $student_id,
            ':subject_id' => $subject_id,
            ':term'       => $term,
            ':year'       => $year
        ]);

        return [
            'final_score' => null,
            'grade'       => '-',
            'points'      => '-',
            'comment'     => $draft_check->fetchColumn()
                ? 'Marks entered but not yet submitted by the teacher.'
                : 'No assessment marks recorded.',
            'color'       => null,
            'assessments' => [],
        ];
    }

    // Compute aggregate weighted score, and keep the per-assessment inputs
    // alongside it -- shown in brackets on the report so a term doesn't
    // look like it was one exam, without changing this sum at all.
    $final_score = 0;
    $breakdown = [];
    foreach ($records as $row) {
        $raw_mark = floatval($row['marks']);
        $weight   = floatval($row['weight_percentage']);
        $contribution = $raw_mark * ($weight / 100);
        $final_score += $contribution;
        $breakdown[] = [
            'title'             => $row['title'],
            'raw_mark'          => round($raw_mark, 1),
            'weight_percentage' => $weight,
            'contribution'      => round($contribution, 1),
        ];
    }

    $final_score = round($final_score, 1);

    // Look up grade and comment from school's database grading scale.
    $grade_stmt = $pdo->prepare("
        SELECT grade, points, remark, color
        FROM grading_scales
        WHERE school_id = :school_id
          AND :score BETWEEN min_mark AND max_mark
        LIMIT 1
    ");
    $grade_stmt->execute([
        ':school_id' => $school_id,
        ':score'     => $final_score
    ]);

    $grade_rule = $grade_stmt->fetch(PDO::FETCH_ASSOC);

    if ($grade_rule) {
        return [
            'final_score' => $final_score,
            'grade'       => $grade_rule['grade'],
            'points'      => $grade_rule['points'],
            'comment'     => $grade_rule['remark'],
            'color'       => $grade_rule['color'],
            'assessments' => $breakdown,
        ];
    }

    return [
        'final_score' => $final_score,
        'grade'       => 'N/A',
        'points'      => 0,
        'comment'     => 'Grade scale not configured for this score.',
        'color'       => null,
        'assessments' => $breakdown,
    ];
}

/**
 * Resolves one subject_catalog code (e.g. 'GP', 'SUBMATH') to this
 * student's own class-scoped subjects.id row. A-Level subjects are
 * adopted per-class (S.5 and S.6 each get their own subjects row), so
 * this can't be a fixed FK -- same resolution
 * school_admin/student_subjects.php's scholar_resolve_combination_subjects()
 * uses for a combination's 3 principal codes.
 */
function scholar_resolve_catalog_subject_id(PDO $pdo, int $school_id, string $class_name, string $catalog_code): ?int
{
    $stmt = $pdo->prepare("
        SELECT s.id FROM subjects s
        JOIN subject_catalog sc ON sc.id = s.subject_reference_id
        WHERE s.school_id = ? AND sc.subject_code = ? AND sc.level_type = 'A-Level'
          AND REPLACE(UPPER(s.class_name), '.', '') = REPLACE(UPPER(?), '.', '')
        LIMIT 1
    ");
    $stmt->execute([$school_id, $catalog_code, $class_name]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * UACE A-Level points. 3 principal subjects (from the student's chosen
 * combination) each contribute their full grade points (max 6 apiece, so
 * up to 18 total). General Paper and, if offered, ONE of Subsidiary
 * Mathematics/Subsidiary ICT (a student can only offer one of the two --
 * enforced at assignment time in school_admin/student_subjects.php) each
 * contribute a flat 1 point for a pass, 0 for a fail -- never scaled by
 * grade. Maximum 20, minimum 0.
 *
 * @return array{total:int, breakdown:array<int,array{label:string,code:string,grade:?string,points:float}>}|null
 *         null if the student has no combination assigned yet -- nothing to compute.
 */
function calculate_uace_points(PDO $pdo, int $school_id, array $student, string $term, int $year, ?array $classBatch = null): ?array
{
    if (empty($student['combination_id'])) {
        return null;
    }

    $combo_stmt = $pdo->prepare("SELECT subject_code_1, subject_code_2, subject_code_3 FROM combinations WHERE id = ? AND school_id = ?");
    $combo_stmt->execute([(int) $student['combination_id'], $school_id]);
    $combo = $combo_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$combo) {
        return null;
    }

    $class_name = $student['class_name'] ?? '';
    $student_id = (int) $student['id'];
    $breakdown = [];
    $total = 0.0;

    // 3 principal subjects -- full grade points each.
    foreach ([$combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3']] as $code) {
        $subject_id = scholar_resolve_catalog_subject_id($pdo, $school_id, $class_name, $code);
        if ($subject_id === null) {
            $breakdown[] = ['label' => $code, 'code' => $code, 'grade' => '-', 'points' => 0];
            continue;
        }
        $eval = getCalculatedGradeAndComment($pdo, $school_id, $student_id, $subject_id, $term, $year, $classBatch);
        $points = is_numeric($eval['points']) ? (float) $eval['points'] : 0.0;
        $total += $points;
        $breakdown[] = ['label' => $code, 'code' => $code, 'grade' => $eval['grade'], 'points' => $points];
    }

    // General Paper -- flat 1 point for a pass (any grade but F), 0 otherwise.
    $gp_id = scholar_resolve_catalog_subject_id($pdo, $school_id, $class_name, 'GP');
    if ($gp_id !== null) {
        $eval = getCalculatedGradeAndComment($pdo, $school_id, $student_id, $gp_id, $term, $year, $classBatch);
        $gp_point = ($eval['grade'] !== '-' && $eval['grade'] !== 'F') ? 1.0 : 0.0;
        $total += $gp_point;
        $breakdown[] = ['label' => 'General Paper', 'code' => 'GP', 'grade' => $eval['grade'], 'points' => $gp_point];
    }

    // Whichever ONE of Sub Math / ICT this student is actually registered
    // for. Mutual exclusion is enforced when subjects are assigned, so at
    // most one of these should ever match -- but this loop only ever
    // credits one regardless, as a defensive second layer.
    foreach (['SUBMATH' => 'Subsidiary Mathematics', 'ICT' => 'Subsidiary ICT'] as $code => $label) {
        $subject_id = scholar_resolve_catalog_subject_id($pdo, $school_id, $class_name, $code);
        if ($subject_id === null) {
            continue;
        }
        $registered = $pdo->prepare("SELECT 1 FROM student_subjects WHERE school_id = ? AND student_id = ? AND subject_id = ?");
        $registered->execute([$school_id, $student_id, $subject_id]);
        if (!$registered->fetchColumn()) {
            continue;
        }
        $eval = getCalculatedGradeAndComment($pdo, $school_id, $student_id, $subject_id, $term, $year, $classBatch);
        $point = ($eval['grade'] !== '-' && $eval['grade'] !== 'F') ? 1.0 : 0.0;
        $total += $point;
        $breakdown[] = ['label' => $label, 'code' => $code, 'grade' => $eval['grade'], 'points' => $point];
        break;
    }

    return ['total' => (int) min(20, max(0, $total)), 'breakdown' => $breakdown];
}

/**
 * Generates teacher initials from a name string.
 */
function generateInitials(?string $name): string
{
    if (empty($name)) return 'N/A';
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) $initials .= strtoupper($part[0]);
    }
    return $initials;
}

/**
 * Renders one student's report card as an HTML fragment (just the
 * .report-card-wrapper block -- no <html>/<head>/print button, so the
 * caller controls the surrounding page chrome).
 *
 * @param array $school Row from `schools` (school_name, school_badge,
 *                       phone_contact, email_contact, address) -- fetch
 *                       once and reuse across a whole-class loop rather
 *                       than re-querying per student.
 * @return array{found: bool, html: string, error: ?string}
 */
function render_report_card_html(PDO $pdo, array $school, int $school_id, int $student_id, string $term, int $year, ?array $classBatch = null, ?array $reportSettings = null): array
{
    $reportSettings = $reportSettings ?? scholar_fetch_report_settings($pdo, $school_id);

    $school_name     = $school['school_name'] ?? 'Mbarara High School';
    // schools.school_badge (not logo_path, which is never populated) is the
    // real uploaded-logo column -- same one _admin_shell.php's sidebar
    // reads. Verify the file still exists on disk before pointing an <img>
    // at it, same guard _admin_shell.php uses, so a stale/deleted upload
    // falls back cleanly instead of a broken image icon.
    $school_logo = null;
    if (!empty($school['school_badge']) && file_exists(__DIR__ . '/' . $school['school_badge'])) {
        $school_logo = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school['school_badge'], '/');
    }
    $school_contacts = ($school['phone_contact'] ?? '+256 700 000000') . ' | ' . ($school['email_contact'] ?? 'info@school.ac.ug');
    $school_address  = $school['address'] ?? 'P.O Box 1, Mbarara, Uganda';

    $student = null;
    $subject_evaluations = [];
    $error_msg = null;

    try {
        $stud_stmt = $pdo->prepare("
            SELECT s.*, c.class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.id = ? AND s.school_id = ?
        ");
        $stud_stmt->execute([$student_id, $school_id]);
        $student = $stud_stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            // sub.class_name is free-text and not always formatted the same
            // as classes.class_name across schools (e.g. "S1" vs "S.1"), so
            // the match strips dots and case rather than comparing raw
            // strings -- a strict equality here would silently hide every
            // subject for schools using the no-dot style.
            $subjects_stmt = $pdo->prepare("
                SELECT
                    sub.id AS subject_id,
                    sub.subject_code,
                    sub.subject_name,
                    CONCAT(stf.first_name, ' ', stf.last_name) AS teacher_name
                FROM subjects sub
                LEFT JOIN teacher_assignments ta
                       ON ta.subject_id = sub.id
                      AND ta.class_id = :class_id
                      AND ta.school_id = :school_id_join
                LEFT JOIN staff stf
                       ON ta.teacher_id = stf.staff_id
                      AND stf.school_id = :school_id_join2
                LEFT JOIN student_subjects ss
                       ON ss.subject_id = sub.id
                      AND ss.student_id = :student_id_join
                WHERE sub.school_id = :school_id
                  AND REPLACE(UPPER(sub.class_name), '.', '') = REPLACE(UPPER(:class_name), '.', '')
                  AND (sub.subject_type != 'Elective' OR ss.id IS NOT NULL)
                ORDER BY sub.subject_name ASC
            ");
            $subjects_stmt->execute([
                ':class_id'        => $student['class_id'],
                ':school_id'       => $school_id,
                ':school_id_join'  => $school_id,
                ':school_id_join2' => $school_id,
                ':student_id_join' => $student_id,
                ':class_name'      => $student['class_name'] ?? '',
            ]);
            $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subjects as $sub) {
                $eval = getCalculatedGradeAndComment(
                    $pdo,
                    $school_id,
                    $student_id,
                    (int) $sub['subject_id'],
                    $term,
                    $year,
                    $classBatch
                );
                $subject_evaluations[] = array_merge($sub, $eval);
            }
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }

    if (!$student) {
        return ['found' => false, 'html' => '', 'error' => $error_msg, 'student_name' => null];
    }

    $student_name = $student['full_name'] ?? $student['student_name'] ?? 'Student';

    // Same existence-check convention as $school_logo above -- a stale or
    // deleted upload falls back to no image rather than a broken icon.
    // Also gated on the school's own show_student_photos toggle, so a
    // school with no photos on file doesn't render an empty box either.
    $student_photo = null;
    if (($reportSettings['show_student_photos'] ?? true) && !empty($student['photo_path']) && file_exists(__DIR__ . '/' . $student['photo_path'])) {
        $student_photo = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($student['photo_path'], '/');
    }

    // Same grading_scales used to grade the Final column now also grades
    // each individual assessment cell (both are 0-100 percentages), so the
    // whole table -- not just the Final column -- speaks one consistent
    // color language instead of introducing a second, hardcoded palette.
    $gradingScales = $classBatch['grading_scales'] ?? scholar_fetch_grading_scales($pdo, $school_id);

    $remark = $classBatch['remarks'][$student_id]
        ?? (isset($classBatch['remarks']) ? ['class_teacher_remark' => null, 'head_teacher_remark' => null] : scholar_fetch_report_remark($pdo, $school_id, $student_id, $term, $year));

    // One column per assessment actually used this term (AOI/MID/EOT-style,
    // whatever the school named them in Assessments), in first-seen order --
    // instead of burying the breakdown in small print under a single
    // Weighted Mark column, each component gets its own heat-mapped cell.
    $assessment_titles = [];
    foreach ($subject_evaluations as $row) {
        foreach ($row['assessments'] ?? [] as $a) {
            if (!in_array($a['title'], $assessment_titles, true)) {
                $assessment_titles[] = $a['title'];
            }
        }
    }

    $combination = null;
    if (!empty($student['combination_id'])) {
        $combo_stmt = $pdo->prepare("SELECT code, name FROM combinations WHERE id = ? AND school_id = ?");
        $combo_stmt->execute([(int) $student['combination_id'], $school_id]);
        $combination = $combo_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $uace = null;
    if (($student['level_type'] ?? '') === 'A-Level') {
        $uace = calculate_uace_points($pdo, $school_id, $student, $term, $year, $classBatch);
    }

    ob_start();
    ?>
    <div class="report-card-wrapper">

        <?php if ($school_logo): ?>
        <div class="rc-watermark" style="background-image:url('<?= htmlspecialchars($school_logo) ?>');"></div>
        <?php endif; ?>

        <?php if ($student_photo): ?>
        <img src="<?= htmlspecialchars($student_photo) ?>" alt="Student Photo" class="rc-photo-corner">
        <?php endif; ?>

        <div class="rc-header">
            <?php if ($school_logo): ?>
                <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" class="rc-logo-ring">
            <?php else: ?>
                <div class="rc-logo-ring rc-logo-fallback"><?= htmlspecialchars(strtoupper(substr($school_name, 0, 1))) ?></div>
            <?php endif; ?>
            <div class="rc-header-text">
                <h1 class="school-title"><?= htmlspecialchars($school_name) ?></h1>
                <div class="school-meta school-address"><?= htmlspecialchars($school_address) ?></div>
                <div class="school-meta"><?= htmlspecialchars($school_contacts) ?></div>
            </div>
            <div class="rc-header-spacer"></div>
        </div>

        <div class="rc-title-bar">
            <span><?= htmlspecialchars($term) ?> Report Card</span>
            <?php if (!empty($student['level_type'])): ?><span class="rc-level-badge"><?= htmlspecialchars($student['level_type']) ?></span><?php endif; ?>
        </div>

        <div class="bio-infomatrix">
            <div>
                <div class="bio-item"><span class="bio-label">Student Name:</span> <strong><?= htmlspecialchars($student['full_name'] ?? $student['student_name'] ?? 'N/A') ?></strong></div>
                <div class="bio-item"><span class="bio-label">LIN / Reg No:</span> <span style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($student['student_no'] ?? $student['reg_no'] ?? 'MHS/2026/041') ?></span></div>
                <div class="bio-item"><span class="bio-label">Class Stream:</span> <?= htmlspecialchars($student['class_name'] ?? 'Senior One') ?></div>
                <?php if ($combination): ?>
                <div class="bio-item"><span class="bio-label">Combination:</span> <strong><?= htmlspecialchars($combination['code']) ?></strong> — <?= htmlspecialchars($combination['name']) ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="bio-item"><span class="bio-label">Academic Term:</span> <strong><?= htmlspecialchars($term) ?></strong></div>
                <div class="bio-item"><span class="bio-label">Calendar Year:</span> <span style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars((string) $year) ?></span></div>
                <div class="bio-item"><span class="bio-label">Gender:</span> <?= htmlspecialchars($student['gender'] ?? 'N/A') ?></div>
            </div>
        </div>

        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <?php foreach ($assessment_titles as $t): ?>
                        <th><?= htmlspecialchars($t) ?></th>
                    <?php endforeach; ?>
                    <th>Final (100%)</th>
                    <th>Grade</th>
                    <th>TR</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_score = 0;
                $subject_count = 0;
                $total_cols = 4 + count($assessment_titles);

                if (empty($subject_evaluations)): ?>
                    <tr><td colspan="<?= $total_cols ?>" style="text-align:center; padding:30px; color:#64748b; font-family:monospace;">[ No evaluation marks uploaded for this student profile. ]</td></tr>
                <?php else:
                    foreach ($subject_evaluations as $row):
                        $score    = $row['final_score'];
                        $initials = generateInitials($row['teacher_name'] ?? '');

                        if ($score !== null) {
                            $total_score += $score;
                            $subject_count++;
                        }

                        $by_title = [];
                        foreach ($row['assessments'] ?? [] as $a) {
                            $by_title[$a['title']] = $a['raw_mark'];
                        }
                        $final_color = $score !== null ? ($row['color'] ?? null) : ($reportSettings['no_data_color'] ?? null);
                    ?>
                    <tr>
                        <td class="rc-subject-cell">
                            <span class="rc-subject-name"><?= htmlspecialchars($row['subject_name']) ?></span>
                        </td>
                        <?php foreach ($assessment_titles as $t):
                            $raw = $by_title[$t] ?? null;
                            $cell_color = $raw !== null ? scholar_grade_for_score($gradingScales, (float) $raw)['color'] : null;
                        ?>
                            <td class="rc-score-cell"<?= $cell_color ? ' style="background-color:' . htmlspecialchars($cell_color) . ';"' : '' ?>>
                                <?= $raw !== null ? $raw . '%' : '<span style="color:#cbd5e1;">&mdash;</span>' ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="rc-final-cell"<?= $final_color ? ' style="background-color:' . htmlspecialchars($final_color) . ';"' : '' ?>>
                            <?= $score !== null ? $score . '%' : '<span style="color:#cbd5e1;">-</span>' ?>
                        </td>
                        <td class="rc-grade-cell">
                            <div class="rc-grade-badge"<?= !empty($row['color']) ? ' style="background-color:' . htmlspecialchars($row['color']) . ';"' : '' ?>><?= htmlspecialchars($row['grade']) ?></div>
                        </td>
                        <td class="rc-tr-cell"><?= htmlspecialchars($initials) ?></td>
                    </tr>
                    <?php endforeach;
                endif; ?>
            </tbody>
        </table>

        <?php
        $average = $subject_count > 0 ? round($total_score / $subject_count, 1) : 0;

        if ($classBatch !== null) {
            // Matches the SQL branch's unconditional lookup below -- even
            // an average of 0 (no marks at all) still gets looked up, in
            // case a school's lowest band happens to start at 0.
            $summary_eval = scholar_grade_for_score($classBatch['grading_scales'], $average);
            $overall_band = $summary_eval['grade'] ?? 'N/A';
            $overall_comm = $summary_eval['comment'] ?? 'Consistent effort and revision required across all units.';
            $overall_color = $summary_eval['color'] ?? null;
        } else {
            $summary_stmt = $pdo->prepare("
                SELECT grade, remark, color
                FROM grading_scales
                WHERE school_id = :school_id AND :avg BETWEEN min_mark AND max_mark
                LIMIT 1
            ");
            $summary_stmt->execute([':school_id' => $school_id, ':avg' => $average]);
            $summary_eval = $summary_stmt->fetch(PDO::FETCH_ASSOC);
            $overall_band = $summary_eval['grade'] ?? 'N/A';
            $overall_comm = $summary_eval['remark'] ?? 'Consistent effort and revision required across all units.';
            $overall_color = $summary_eval['color'] ?? null;
        }
        // A readable dark text color on top of whatever pastel band color
        // is configured -- these are all light backgrounds (grading_scales'
        // color picker defaults skew pastel), so a fixed dark slate reads
        // fine across the whole palette without per-color contrast math.
        $overall_color = $overall_color ?: '#e2e8f0';
        // Verification payload -- the student's identifying details plus
        // this specific term's actual outcome (average + band), so scanning
        // confirms not just which student/term the card belongs to but
        // whether its printed result matches what the system has on record
        // -- catching a card whose marks were altered after printing, not
        // just a card with no matching student at all. School contact is
        // included so anyone verifying can follow up directly. Built fresh
        // per report (term/year/average/band all vary call to call), so a
        // Term 1 and Term 3 card for the same student never carry the same
        // code. Nothing that isn't already printed elsewhere on this same
        // card; it isn't a link and contacts no server.
        $qr_payload = "Student: {$student_name}\n"
            . "Adm/Reg No: " . ($student['student_no'] ?? $student['reg_no'] ?? 'N/A') . "\n"
            . "Class: " . ($student['class_name'] ?? 'N/A') . "\n"
            . "School: {$school_name}\n"
            . "Term: {$term} {$year}\n"
            . "Term Average: {$average}% (Grade {$overall_band})\n"
            . "School Contact: {$school_contacts}";
        ?>
        <div class="rc-result-hero" style="background-color:<?= htmlspecialchars($overall_color) ?>;">
            <div class="rc-result-topline">Term Result</div>
            <div class="rc-result-band"><?= htmlspecialchars($overall_band) ?></div>
            <div class="rc-result-average"><?= $average ?>% average this term</div>
            <p class="rc-result-comment">&ldquo;<?= htmlspecialchars($overall_comm) ?>&rdquo;</p>
        </div>

        <div class="summary-box">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 55%; vertical-align: top; padding-right:15px;">
                        <div style="font-size: 13.5px; margin-bottom: 6px;"><strong>Total Weighted Marks:</strong> <span style="font-family: monospace; font-weight: bold; background:#e2e8f0; padding:2px 6px; border-radius:4px;"><?= $total_score ?></span></div>
                        <div style="font-size: 13.5px; margin-bottom: 6px;"><strong>Class Terminal Average:</strong> <span style="font-family: monospace; font-weight: bold; color: #16a34a;"><?= $average ?>%</span></div>
                        <div style="font-size: 13.5px;"><strong>Assessed Subjects:</strong> <span style="font-family: monospace; font-weight: bold;"><?= $subject_count ?> / <?= count($subject_evaluations) ?></span></div>
                    </td>
                    <td style="width: 45%; border-left: 1px dashed #cbd5e1; padding-left: 20px; vertical-align: top; text-align: center;">
                        <div class="rc-qr-target" data-qr="<?= htmlspecialchars($qr_payload) ?>"></div>
                        <div class="rc-qr-caption">Scan to verify<br>student record</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="summary-box rc-remarks-box" style="margin-top:18px;">
            <div class="rc-remark-row">
                <div class="rc-remark-label">Class Teacher's Remark:</div>
                <div class="rc-remark-text<?= empty($remark['class_teacher_remark']) ? ' rc-remark-empty' : '' ?>"><?= htmlspecialchars($remark['class_teacher_remark'] ?: 'Not yet added.') ?></div>
            </div>
            <div class="rc-remark-row">
                <div class="rc-remark-label">Head Teacher's Remark:</div>
                <div class="rc-remark-text<?= empty($remark['head_teacher_remark']) ? ' rc-remark-empty' : '' ?>"><?= htmlspecialchars($remark['head_teacher_remark'] ?: 'Not yet added.') ?></div>
            </div>
        </div>

        <?php if ($uace !== null): ?>
        <div class="summary-box" style="margin-top:18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div style="font-weight: bold; font-size:11px; color:#64748b; text-transform:uppercase;">UACE Points (A-Level)</div>
                <div style="font-weight:900;color:#0284c7;font-size:16px;"><?= $uace['total'] ?> / 20</div>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($uace['breakdown'] as $row): ?>
                <tr>
                    <td style="padding:4px 0; font-size:12.5px; color:#334155;"><?= htmlspecialchars($row['label']) ?></td>
                    <td style="padding:4px 0; text-align:center; font-size:12.5px; color:#475569; font-family:monospace;"><?= htmlspecialchars((string) $row['grade']) ?></td>
                    <td style="padding:4px 0; text-align:right; font-weight:700; color:#0284c7; font-size:12.5px;"><?= rtrim(rtrim(number_format((float) $row['points'], 1), '0'), '.') ?> pt<?= $row['points'] == 1 ? '' : 's' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php
        // Optional supplementary section -- only rendered for schools that
        // have opted into Generic Skills (school_admin/grading_scales.php)
        // and only shows skills this particular student was actually rated
        // on, so schools that never opt in see no layout change at all.
        $skills_stmt = $pdo->prepare("
            SELECT gs.skill_name, ssr.rating_grade
            FROM student_skill_ratings ssr
            JOIN generic_skills gs ON gs.id = ssr.skill_id
            WHERE ssr.school_id = :school_id AND ssr.student_id = :student_id
              AND ssr.term = :term AND ssr.year = :year AND gs.is_active = 1
            ORDER BY gs.display_order, gs.skill_name
        ");
        $skills_stmt->execute([
            ':school_id'  => $school_id,
            ':student_id' => $student_id,
            ':term'       => $term,
            ':year'       => $year,
        ]);
        $skill_ratings = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($skill_ratings)): ?>
        <div class="summary-box" style="margin-top:18px;">
            <div style="font-weight: bold; font-size:11px; color:#64748b; text-transform:uppercase; margin-bottom:10px;">Generic Skills</div>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($skill_ratings as $sr): ?>
                <tr>
                    <td style="padding:4px 0; font-size:13px; color:#334155;"><?= htmlspecialchars($sr['skill_name']) ?></td>
                    <td style="padding:4px 0; text-align:right; font-weight:700; color:#0284c7; font-size:13px;"><?= htmlspecialchars($sr['rating_grade']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($gradingScales)): ?>
        <div class="grade-legend">
            <div class="grade-legend-title">Grading Key</div>
            <table>
                <tr><th>Grade</th><th>Range</th><th>Descriptor</th></tr>
                <?php foreach ($gradingScales as $gs): ?>
                <tr>
                    <td class="grade-legend-letter"<?= !empty($gs['color']) ? ' style="background-color:' . htmlspecialchars($gs['color']) . ';"' : '' ?>><?= htmlspecialchars($gs['grade']) ?></td>
                    <td><?= htmlspecialchars((string) $gs['min_mark']) ?>&ndash;<?= htmlspecialchars((string) $gs['max_mark']) ?></td>
                    <td><?= htmlspecialchars($gs['remark'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <div class="rc-signatures" style="margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div style="text-align: center; width: 220px;">
                <div style="border-bottom: 1px solid #64748b; height: 30px;"></div>
                <div style="font-size: 11px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-top: 6px; letter-spacing: 0.5px;">Class Teacher's Signature</div>
            </div>
            <div style="text-align: center; width: 220px;">
                <div style="border-bottom: 1px solid #64748b; height: 30px; font-family: 'Courier New', monospace; font-size: 15px; color: #0284c7; font-weight: bold; line-height:35px;">CERTIFIED RECORD</div>
                <div style="font-size: 11px; text-transform: uppercase; font-weight: bold; color: #64748b; margin-top: 6px; letter-spacing: 0.5px;">Headmaster's Seal / Stamp</div>
            </div>
        </div>

        <div class="rc-footer">&copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?> &middot; Official Academic Report &middot; Generated <?= htmlspecialchars(date('d M Y, H:i')) ?></div>

    </div>
    <?php
    $html = ob_get_clean();

    return ['found' => true, 'html' => $html, 'error' => $error_msg, 'student_name' => $student_name];
}
