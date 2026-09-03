<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ASSESSMENTS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/assessments.php so the classic page and the
| JSON endpoint (api/admin/assessments.php) enforce the same 100%
| report-weight cap identically.
|--------------------------------------------------------------------------
*/

/**
 * A term's report-counted assessments must never collectively exceed
 * 100% -- past that, getCalculatedGradeAndComment()'s weighted sum in
 * _report_card_render.php produces a bogus >100 score. Assessments
 * excluded from the report (include_in_report=0) don't count at all.
 */
function admin_assessments_weight_used(PDO $pdo, int $schoolId, string $term, int $year, ?int $excludeId = null): float
{
    $sql = 'SELECT COALESCE(SUM(weight_percentage),0) FROM assessments WHERE school_id = ? AND term = ? AND year = ? AND include_in_report = 1';
    $params = [$schoolId, $term, $year];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

/** @return array{ok:bool,message:string} */
function admin_assessments_create(PDO $pdo, int $schoolId, string $title, float $weight, string $term, int $year, string $status, bool $includeInReport): array
{
    if ($title === '' || $weight <= 0 || !in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)) {
        return ['ok' => false, 'message' => 'Title, a positive weight, and a term are required.'];
    }

    if ($includeInReport) {
        $already = admin_assessments_weight_used($pdo, $schoolId, $term, $year);
        if ($already + $weight > 100.001) {
            $remaining = max(0, round(100 - $already, 2));
            return ['ok' => false, 'message' => "{$term} {$year} already has " . round($already, 2) . "% of its report weight allocated -- only {$remaining}% is left. Lower this weight, adjust another assessment first, or uncheck \"On report\" if this one shouldn't count toward the final grade."];
        }
    }

    $status = in_array($status, ['Draft', 'Open', 'Closed'], true) ? $status : 'Draft';
    $ins = $pdo->prepare('
        INSERT INTO assessments (school_id, title, weight_percentage, term, year, status, include_in_report)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([$schoolId, $title, $weight, $term, $year, $status, $includeInReport ? 1 : 0]);
    return ['ok' => true, 'message' => 'Assessment created.'];
}

/** @return array{ok:bool,message:string} */
function admin_assessments_update(PDO $pdo, int $schoolId, int $id, float $weight, string $status, bool $includeInReport): array
{
    $existing_stmt = $pdo->prepare('SELECT term, year FROM assessments WHERE id = ? AND school_id = ?');
    $existing_stmt->execute([$id, $schoolId]);
    $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

    if ($id <= 0 || $weight <= 0 || !$existing) {
        return ['ok' => false, 'message' => 'Invalid assessment.'];
    }

    $already = $includeInReport ? admin_assessments_weight_used($pdo, $schoolId, $existing['term'], (int) $existing['year'], $id) : 0.0;
    if ($includeInReport && $already + $weight > 100.001) {
        $remaining = max(0, round(100 - $already, 2));
        return ['ok' => false, 'message' => "{$existing['term']} {$existing['year']}'s other report-counted assessments already use " . round($already, 2) . "% -- only {$remaining}% is left for this one."];
    }

    $status = in_array($status, ['Draft', 'Open', 'Closed'], true) ? $status : 'Draft';
    $upd = $pdo->prepare('
        UPDATE assessments
        SET weight_percentage = ?, status = ?, include_in_report = ?
        WHERE id = ? AND school_id = ?
    ');
    $upd->execute([$weight, $status, $includeInReport ? 1 : 0, $id, $schoolId]);
    return ['ok' => true, 'message' => 'Assessment updated.'];
}

/** @return array{assessments:array,weight_used_by_term:array<string,float>} */
function admin_assessments_fetch_list(PDO $pdo, int $schoolId): array
{
    $list = $pdo->prepare('
        SELECT id, title, weight_percentage, term, year, status, include_in_report
        FROM assessments
        WHERE school_id = ?
        ORDER BY year DESC, term DESC, title ASC
    ');
    $list->execute([$schoolId]);
    $assessments = $list->fetchAll(PDO::FETCH_ASSOC);

    $weight_used_by_term = [];
    foreach ($assessments as $a) {
        if (!$a['include_in_report']) continue;
        $key = $a['term'] . '|' . $a['year'];
        $weight_used_by_term[$key] = ($weight_used_by_term[$key] ?? 0) + (float) $a['weight_percentage'];
    }

    return ['assessments' => $assessments, 'weight_used_by_term' => $weight_used_by_term];
}
