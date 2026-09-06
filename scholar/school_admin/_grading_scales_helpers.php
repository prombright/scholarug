<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — GRADING SCALE SETTINGS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/grading_scales.php so the classic page and the
| JSON endpoint (api/admin/grading_scales.php) apply identical validation.
|--------------------------------------------------------------------------
*/

function admin_grading_parse_color(array $input): ?string
{
    $color = trim($input['color'] ?? '');
    return (!empty($input['use_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : null;
}

/** @return array{ok:bool,message:string} */
function admin_grading_create_band(PDO $pdo, int $schoolId, array $input): array
{
    $grade = trim($input['grade'] ?? '');
    $min_mark = is_numeric($input['min_mark'] ?? '') ? (float) $input['min_mark'] : null;
    $max_mark = is_numeric($input['max_mark'] ?? '') ? (float) $input['max_mark'] : null;
    $remark = trim($input['remark'] ?? '');
    $points = is_numeric($input['points'] ?? '') ? (float) $input['points'] : null;
    $color = admin_grading_parse_color($input);

    if ($grade === '' || $min_mark === null || $max_mark === null || $min_mark > $max_mark) {
        return ['ok' => false, 'message' => 'A grade label and a valid min/max range are required.'];
    }

    $pdo->prepare('INSERT INTO grading_scales (school_id, grade, min_mark, max_mark, remark, points, color) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$schoolId, $grade, $min_mark, $max_mark, $remark ?: null, $points, $color]);
    return ['ok' => true, 'message' => 'Grading band added.'];
}

/** @return array{ok:bool,message:string} */
function admin_grading_update_band(PDO $pdo, int $schoolId, int $id, array $input): array
{
    $grade = trim($input['grade'] ?? '');
    $min_mark = is_numeric($input['min_mark'] ?? '') ? (float) $input['min_mark'] : null;
    $max_mark = is_numeric($input['max_mark'] ?? '') ? (float) $input['max_mark'] : null;
    $remark = trim($input['remark'] ?? '');
    $points = is_numeric($input['points'] ?? '') ? (float) $input['points'] : null;
    $color = admin_grading_parse_color($input);

    if ($id <= 0 || $grade === '' || $min_mark === null || $max_mark === null || $min_mark > $max_mark) {
        return ['ok' => false, 'message' => 'Invalid band values.'];
    }

    $pdo->prepare('UPDATE grading_scales SET grade = ?, min_mark = ?, max_mark = ?, remark = ?, points = ?, color = ? WHERE id = ? AND school_id = ?')
        ->execute([$grade, $min_mark, $max_mark, $remark ?: null, $points, $color, $id, $schoolId]);
    return ['ok' => true, 'message' => 'Grading band updated.'];
}

function admin_grading_delete_band(PDO $pdo, int $schoolId, int $id): void
{
    $pdo->prepare('DELETE FROM grading_scales WHERE id = ? AND school_id = ?')->execute([$id, $schoolId]);
}

/**
 * Uganda's new lower-secondary competency-based curriculum grading scale --
 * 5 bands, A to E, no F (confirmed by the school; unlike the traditional
 * O-Level/UCE letter scale this replaces). Cutoffs are even 20-point bands
 * since the school didn't specify exact percentage boundaries -- adjust
 * per-band in the table above if your own guidance differs.
 *
 * Deliberately unrelated to A-Level UACE scoring (calculate_uace_points()
 * in _report_card_render.php) -- that's a separate, older curriculum
 * stream that still uses its own traditional grade set including F, and
 * isn't affected by this reset.
 */
function admin_grading_seed_competency_defaults(PDO $pdo, int $schoolId): void
{
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM grading_scales WHERE school_id = ?')->execute([$schoolId]);
    $defaults = [
        ['A - Exceptional', 80, 100,   'Exceptional — consistently exceeds expectations across assessed competencies.', 5, '#dcfce7'],
        ['B - Outstanding', 60, 79.99, 'Outstanding — meets expectations for this stage with strong understanding.',   4, '#dbeafe'],
        ['C - Satisfactory', 40, 59.99, 'Satisfactory — meets expectations for this stage with adequate understanding.', 3, '#fef9c3'],
        ['D - Basic',        20, 39.99, 'Basic — beginning to develop the expected competencies.',                       2, '#ffedd5'],
        ['E - Elementary',   0,  19.99, 'Elementary — needs significant support to develop the expected competencies.', 1, '#fee2e2'],
    ];
    $ins = $pdo->prepare('INSERT INTO grading_scales (school_id, grade, min_mark, max_mark, remark, points, color) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($defaults as $d) {
        $ins->execute([$schoolId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]);
    }
    $pdo->commit();
}

/** @return array{ok:bool,message:string} */
function admin_grading_create_skill(PDO $pdo, int $schoolId, string $skillName): array
{
    if ($skillName === '') {
        return ['ok' => false, 'message' => 'Enter a skill name.'];
    }
    $ins = $pdo->prepare('INSERT INTO generic_skills (school_id, skill_name, display_order) VALUES (?, ?, (SELECT n FROM (SELECT COALESCE(MAX(display_order), 0) + 1 AS n FROM generic_skills WHERE school_id = ?) x))');
    $ins->execute([$schoolId, $skillName, $schoolId]);
    return ['ok' => true, 'message' => 'Skill added.'];
}

function admin_grading_toggle_skill(PDO $pdo, int $schoolId, int $id): void
{
    $pdo->prepare('UPDATE generic_skills SET is_active = NOT is_active WHERE id = ? AND school_id = ?')->execute([$id, $schoolId]);
}

function admin_grading_delete_skill(PDO $pdo, int $schoolId, int $id): void
{
    $pdo->prepare('DELETE FROM generic_skills WHERE id = ? AND school_id = ?')->execute([$id, $schoolId]);
}

/** Best-effort placeholder list -- see grading_scales.php's header. */
function admin_grading_seed_default_skills(PDO $pdo, int $schoolId): void
{
    $defaults = ['Critical Thinking', 'Communication', 'Cooperation', 'Creativity', 'Self-Management'];
    $exists = $pdo->prepare('SELECT id FROM generic_skills WHERE school_id = ? AND skill_name = ?');
    $ins = $pdo->prepare('INSERT INTO generic_skills (school_id, skill_name, display_order) VALUES (?, ?, ?)');
    $order = 1;
    foreach ($defaults as $name) {
        $exists->execute([$schoolId, $name]);
        if (!$exists->fetchColumn()) {
            $ins->execute([$schoolId, $name, $order]);
        }
        $order++;
    }
}

function admin_grading_fetch_bands(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM grading_scales WHERE school_id = ? ORDER BY min_mark DESC');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_grading_fetch_skills(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM generic_skills WHERE school_id = ? ORDER BY display_order, skill_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
