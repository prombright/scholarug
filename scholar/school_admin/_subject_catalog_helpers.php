<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SUBJECT CATALOG: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/subject_catalog.php so the classic page and
| the JSON endpoint (api/admin/subject_catalog.php) adopt subjects and
| combinations identically -- additive-only, same "all 3 subjects present
| first" combination rule.
|--------------------------------------------------------------------------
*/

const ADMIN_SUBJECT_LEVEL_CLASS_NAMES = [
    'O-Level' => ['S.1', 'S.2', 'S.3', 'S.4'],
    'A-Level' => ['S.5', 'S.6'],
];

/** @return array<int,int> catalog subject_reference_ids this school already carries */
function admin_subjects_already_adopted(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare("SELECT DISTINCT subject_reference_id FROM subjects WHERE school_id = ? AND subject_reference_id IS NOT NULL AND class_name != ''");
    $stmt->execute([$schoolId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'subject_reference_id'));
}

/** @return array{message:string,type:string} */
function admin_subjects_adopt(PDO $pdo, int $schoolId, string $levelType, array $catalogIds): array
{
    $levelType = in_array($levelType, ['O-Level', 'A-Level'], true) ? $levelType : 'O-Level';
    $classNames = ADMIN_SUBJECT_LEVEL_CLASS_NAMES[$levelType];

    $alreadyIds = admin_subjects_already_adopted($pdo, $schoolId);
    $newIds = array_diff($catalogIds, $alreadyIds);

    if (empty($newIds)) {
        return ['message' => 'Nothing new to adopt -- everything ticked was already added.', 'type' => 'error'];
    }

    $catalog_stmt = $pdo->prepare('SELECT * FROM subject_catalog WHERE id = ? AND level_type = ? AND is_active = 1');
    $ins = $pdo->prepare('
        INSERT INTO subjects (school_id, subject_reference_id, level_type, subject_name, subject_code, is_compulsory, papers_count, class_name, subject_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $prefix = $levelType === 'A-Level' ? 'A-' : 'O-';
    $adopted_count = 0;

    foreach ($newIds as $cid) {
        $catalog_stmt->execute([$cid, $levelType]);
        $cat = $catalog_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cat) continue;

        $final_code = $prefix . $cat['subject_code'];
        $subject_type = $cat['is_compulsory'] ? 'Core' : 'Elective';

        foreach ($classNames as $cn) {
            $papers = scholar_papers_count_for_class($cat['subject_code'], $cn, (int) $cat['papers_count']);
            $ins->execute([
                $schoolId, $cid, $levelType, $cat['subject_name'], $final_code,
                (int) $cat['is_compulsory'], $papers, $cn, $subject_type,
            ]);
        }
        $adopted_count++;
    }

    return ['message' => "Adopted {$adopted_count} subject(s) across " . implode(', ', $classNames) . '.', 'type' => 'success'];
}

/** @return array{message:string,type:string} */
function admin_subjects_adopt_combinations(PDO $pdo, int $schoolId, array $comboIds): array
{
    $already_stmt = $pdo->prepare('SELECT combination_catalog_id FROM combinations WHERE school_id = ? AND combination_catalog_id IS NOT NULL');
    $already_stmt->execute([$schoolId]);
    $already_ids = array_map('intval', array_column($already_stmt->fetchAll(PDO::FETCH_ASSOC), 'combination_catalog_id'));

    $new_ids = array_diff($comboIds, $already_ids);
    if (empty($new_ids)) {
        return ['message' => 'Nothing new to adopt -- everything ticked was already added.', 'type' => 'error'];
    }

    $has_subject = $pdo->prepare("
        SELECT COUNT(*) FROM subjects s
        JOIN subject_catalog sc ON sc.id = s.subject_reference_id
        WHERE s.school_id = ? AND sc.subject_code = ? AND sc.level_type = 'A-Level'
    ");
    $combo_stmt = $pdo->prepare('SELECT * FROM combination_catalog WHERE id = ? AND is_active = 1');
    $ins_combo = $pdo->prepare('
        INSERT INTO combinations (school_id, combination_catalog_id, code, name, subject_code_1, subject_code_2, subject_code_3)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $adopted_count = 0;
    $skipped_names = [];

    foreach ($new_ids as $cid) {
        $combo_stmt->execute([$cid]);
        $combo = $combo_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$combo) continue;

        $codes = [$combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3']];
        $all_present = true;
        foreach ($codes as $code) {
            $has_subject->execute([$schoolId, $code]);
            if ((int) $has_subject->fetchColumn() === 0) { $all_present = false; break; }
        }

        if (!$all_present) {
            $skipped_names[] = $combo['code'];
            continue;
        }

        $ins_combo->execute([$schoolId, $cid, $combo['code'], $combo['name'], $combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3']]);
        $adopted_count++;
    }

    $message = '';
    $type = 'error';
    if ($adopted_count > 0) {
        $message = "Adopted {$adopted_count} combination(s).";
        $type = 'success';
    }
    if (!empty($skipped_names)) {
        $message .= ($message ? ' ' : '') . 'Skipped ' . implode(', ', $skipped_names) . ' -- adopt all of its subjects (A-Level) first.';
        $type = $adopted_count > 0 ? 'success' : 'error';
    }
    return ['message' => $message, 'type' => $type];
}

/** @return array<int,array{id:int,subject_name:string,subject_code:string,papers_count:int,is_compulsory:bool,adopted:bool}> */
function admin_subjects_fetch_catalog(PDO $pdo, int $schoolId, string $levelType): array
{
    $stmt = $pdo->prepare('SELECT * FROM subject_catalog WHERE level_type = ? AND is_active = 1 ORDER BY display_order, subject_name');
    $stmt->execute([$levelType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $adopted = admin_subjects_already_adopted($pdo, $schoolId);

    return array_map(static function (array $cs) use ($adopted): array {
        return [
            'id' => (int) $cs['id'],
            'subject_name' => $cs['subject_name'],
            'subject_code' => $cs['subject_code'],
            'papers_count' => (int) $cs['papers_count'],
            'is_compulsory' => (bool) $cs['is_compulsory'],
            'adopted' => in_array((int) $cs['id'], $adopted, true),
        ];
    }, $rows);
}

/** @return array{combinations:array,adopted_subject_codes:array<int,string>} */
function admin_subjects_fetch_combinations_data(PDO $pdo, int $schoolId): array
{
    $combo_catalog = $pdo->query('SELECT * FROM combination_catalog WHERE is_active = 1 ORDER BY display_order, name')->fetchAll(PDO::FETCH_ASSOC);

    $already_stmt = $pdo->prepare('SELECT combination_catalog_id FROM combinations WHERE school_id = ? AND combination_catalog_id IS NOT NULL');
    $already_stmt->execute([$schoolId]);
    $adopted_combo_ids = array_map('intval', array_column($already_stmt->fetchAll(PDO::FETCH_ASSOC), 'combination_catalog_id'));

    $codes_stmt = $pdo->prepare("
        SELECT DISTINCT sc.subject_code
        FROM subjects s
        JOIN subject_catalog sc ON sc.id = s.subject_reference_id
        WHERE s.school_id = ? AND sc.level_type = 'A-Level'
    ");
    $codes_stmt->execute([$schoolId]);
    $adopted_subject_codes = array_column($codes_stmt->fetchAll(PDO::FETCH_ASSOC), 'subject_code');

    $combinations = array_map(static function (array $combo) use ($adopted_combo_ids, $adopted_subject_codes): array {
        $codes = [$combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3']];
        $missing = array_values(array_diff($codes, $adopted_subject_codes));
        return [
            'id' => (int) $combo['id'],
            'code' => $combo['code'],
            'name' => $combo['name'],
            'subject_codes' => $codes,
            'adopted' => in_array((int) $combo['id'], $adopted_combo_ids, true),
            'missing' => $missing,
        ];
    }, $combo_catalog);

    return ['combinations' => $combinations, 'adopted_subject_codes' => $adopted_subject_codes];
}
