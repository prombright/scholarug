<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PERFORMANCE ANALYTICS: SHARED COMPUTATION
|--------------------------------------------------------------------------
| Pulled out of performance_analytics.php so the JSON endpoint
| (api/teacher/analytics.php) and the classic HTML page compute results
| the exact same way -- one place decides what "average"/"most consistent"/
| "trend" mean, whichever renderer is asking.
|--------------------------------------------------------------------------
*/

/**
 * One combined (summed across papers) score per student per assessment,
 * for every submitted mark in $classId matching $subjectId (or every
 * subject the class takes, if $subjectId is null -- the class-teacher
 * view), scoped to the school's current term/year.
 *
 * @return array<int,array{student_id:int,full_name:string,sex:string,subject_id:int,subject_name:string,assessment_id:int,assessment_title:string,assessment_order:string,score:float}>
 */
function analytics_fetch_scores(PDO $pdo, int $schoolId, int $classId, ?int $subjectId, string $term, int $year): array
{
    $sql = "
        SELECT st.id AS student_id, st.full_name, st.sex,
               sm.subject_id, sub.subject_name,
               sm.assessment_id, a.title AS assessment_title, a.created_at AS assessment_order,
               SUM(sm.marks) AS score
        FROM student_marks sm
        JOIN students st ON st.id = sm.student_id AND st.school_id = sm.school_id
        JOIN subjects sub ON sub.id = sm.subject_id
        JOIN assessments a ON a.id = sm.assessment_id
        WHERE sm.school_id = ? AND sm.class_id = ? AND sm.submission_status = 'submitted'
          AND a.term = ? AND a.year = ?
    ";
    $params = [$schoolId, $classId, $term, $year];
    if ($subjectId !== null) {
        $sql .= ' AND sm.subject_id = ?';
        $params[] = $subjectId;
    }
    $sql .= ' GROUP BY st.id, sm.subject_id, sm.assessment_id ORDER BY a.created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Turns analytics_fetch_scores()' flat rows into one summary row per
 * student: average/highest/lowest/consistency (population standard
 * deviation of their scores -- lower means more consistent) and a simple
 * first-vs-last trend. $rows must already be in chronological order.
 *
 * @return array<int,array{student_id:int,full_name:string,sex:string,scores:array<int,float>,average:float,highest:float,lowest:float,stddev:?float,trend:string}>
 */
function analytics_summarize_students(array $rows): array
{
    $byStudent = [];
    foreach ($rows as $r) {
        $sid = (int) $r['student_id'];
        if (!isset($byStudent[$sid])) {
            $byStudent[$sid] = ['student_id' => $sid, 'full_name' => $r['full_name'], 'sex' => $r['sex'], 'scores' => []];
        }
        $byStudent[$sid]['scores'][] = (float) $r['score'];
    }

    $summaries = [];
    foreach ($byStudent as $sid => $s) {
        $scores = $s['scores'];
        $n = count($scores);
        $avg = array_sum($scores) / $n;
        $variance = 0.0;
        foreach ($scores as $sc) {
            $variance += ($sc - $avg) ** 2;
        }
        $stddev = $n >= 2 ? sqrt($variance / $n) : null;

        $s['average'] = round($avg, 1);
        $s['highest'] = max($scores);
        $s['lowest'] = min($scores);
        $s['stddev'] = $stddev !== null ? round($stddev, 1) : null;
        $s['count'] = $n;
        if ($n >= 2) {
            $delta = end($scores) - reset($scores);
            $s['trend'] = $delta > 2 ? 'up' : ($delta < -2 ? 'down' : 'flat');
        } else {
            $s['trend'] = 'flat';
        }
        $summaries[] = $s;
    }
    return $summaries;
}

/** @return array{male_avg:?float,female_avg:?float,male_count:int,female_count:int} */
function analytics_gender_split(array $studentSummaries): array
{
    $male = $female = [];
    foreach ($studentSummaries as $s) {
        if ($s['sex'] === 'Male') $male[] = $s['average'];
        elseif ($s['sex'] === 'Female') $female[] = $s['average'];
    }
    return [
        'male_avg' => $male ? round(array_sum($male) / count($male), 1) : null,
        'female_avg' => $female ? round(array_sum($female) / count($female), 1) : null,
        'male_count' => count($male),
        'female_count' => count($female),
    ];
}

/**
 * Builds the full $subject_analytics / $class_analytics pair for one
 * teacher's view of one class+subject -- the same shape performance_
 * analytics.php has always rendered as HTML, now returned as plain arrays
 * so both the HTML page and the JSON endpoint can build on it identically.
 *
 * @return array{subject_analytics:?array,class_analytics:?array,is_class_teacher_here:bool}
 */
function analytics_build_view(PDO $pdo, int $schoolId, int $staffId, int $classId, int $subjectId, string $term, int $year): array
{
    $rows = analytics_fetch_scores($pdo, $schoolId, $classId, $subjectId, $term, $year);
    $students = analytics_summarize_students($rows);
    usort($students, static fn($a, $b) => $b['average'] <=> $a['average']);

    $mostConsistent = null;
    foreach ($students as $s) {
        if ($s['stddev'] === null) continue;
        if ($mostConsistent === null || $s['stddev'] < $mostConsistent['stddev']) {
            $mostConsistent = $s;
        }
    }

    $classAverages = array_column($students, 'average');
    $subject_analytics = [
        'students' => $students,
        'winner' => $students[0] ?? null,
        'most_consistent' => $mostConsistent,
        'gender' => analytics_gender_split($students),
        'class_average' => $classAverages ? round(array_sum($classAverages) / count($classAverages), 1) : null,
        'class_highest' => $classAverages ? max($classAverages) : null,
        'class_lowest' => $classAverages ? min($classAverages) : null,
        'assessment_count' => count(array_unique(array_column($rows, 'assessment_id'))),
    ];

    $ct_stmt = $pdo->prepare('SELECT class_teacher_id FROM classes WHERE id = ? AND school_id = ?');
    $ct_stmt->execute([$classId, $schoolId]);
    $is_class_teacher_here = (int) $ct_stmt->fetchColumn() === $staffId;

    $class_analytics = null;
    if ($is_class_teacher_here) {
        $allRows = analytics_fetch_scores($pdo, $schoolId, $classId, null, $term, $year);

        $bySubject = [];
        foreach ($allRows as $r) {
            $bySubject[(int) $r['subject_id']]['name'] = $r['subject_name'];
            $bySubject[(int) $r['subject_id']]['rows'][] = $r;
        }
        $subjectAverages = [];
        foreach ($bySubject as $sid => $data) {
            $subStudents = analytics_summarize_students($data['rows']);
            $avgs = array_column($subStudents, 'average');
            if ($avgs) {
                $subjectAverages[] = ['subject_name' => $data['name'], 'average' => round(array_sum($avgs) / count($avgs), 1)];
            }
        }
        usort($subjectAverages, static fn($a, $b) => $b['average'] <=> $a['average']);

        $byStudentSubjectAvg = [];
        foreach ($bySubject as $data) {
            foreach (analytics_summarize_students($data['rows']) as $s) {
                $byStudentSubjectAvg[$s['student_id']]['full_name'] = $s['full_name'];
                $byStudentSubjectAvg[$s['student_id']]['sex'] = $s['sex'];
                $byStudentSubjectAvg[$s['student_id']]['subject_avgs'][] = $s['average'];
                $byStudentSubjectAvg[$s['student_id']]['all_scores'] = array_merge($byStudentSubjectAvg[$s['student_id']]['all_scores'] ?? [], $s['scores']);
            }
        }
        $overallStudents = [];
        foreach ($byStudentSubjectAvg as $sid => $d) {
            $overallAvg = round(array_sum($d['subject_avgs']) / count($d['subject_avgs']), 1);
            $scores = $d['all_scores'];
            $n = count($scores);
            $mean = array_sum($scores) / $n;
            $variance = 0.0;
            foreach ($scores as $sc) $variance += ($sc - $mean) ** 2;
            $overallStudents[] = [
                'student_id' => $sid,
                'full_name' => $d['full_name'],
                'sex' => $d['sex'],
                'average' => $overallAvg,
                'stddev' => $n >= 2 ? round(sqrt($variance / $n), 1) : null,
                'subjects_count' => count($d['subject_avgs']),
            ];
        }
        usort($overallStudents, static fn($a, $b) => $b['average'] <=> $a['average']);

        $overallMostConsistent = null;
        foreach ($overallStudents as $s) {
            if ($s['stddev'] === null) continue;
            if ($overallMostConsistent === null || $s['stddev'] < $overallMostConsistent['stddev']) {
                $overallMostConsistent = $s;
            }
        }

        $class_analytics = [
            'students' => $overallStudents,
            'winner' => $overallStudents[0] ?? null,
            'most_consistent' => $overallMostConsistent,
            'gender' => analytics_gender_split($overallStudents),
            'subjects_ranked' => $subjectAverages,
        ];
    }

    return [
        'subject_analytics' => $subject_analytics,
        'class_analytics' => $class_analytics,
        'is_class_teacher_here' => $is_class_teacher_here,
    ];
}
