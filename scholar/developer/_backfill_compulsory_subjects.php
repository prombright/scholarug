<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ONE-TIME FIX — MISLABELED COMPULSORY O-LEVEL SUBJECTS -- delete after use
|--------------------------------------------------------------------------
| _setup/compulsory_subjects_fix_migration.sql corrected subject_catalog
| so Biology/Physics/Chemistry/Geography/History are is_compulsory=1
| (previously only English/Mathematics were). Its own header comment says
| already-adopted `subjects` rows are handled by
| _setup/backfill_compulsory_subjects.php -- but that script only calls
| scholar_ensure_compulsory_subjects(), which treats a catalog ID as
| "already adopted" (and skips it) the moment ANY row references it,
| regardless of that row's subject_type. A school that adopted Biology
| etc. via Subject Catalog's "adopt electives" flow BEFORE the catalog
| fix got subject_type='Elective' at the time (correct, given the
| catalog's state then) -- and the existing backfill never revisits or
| corrects it, since the subject "exists" as far as it's concerned.
|
| Confirmed live-breaking on real local data, not assumed: two schools
| (MBARARA HIGH SCHOOL, ABN Test Academy) have Biology/Physics/
| Chemistry/Geography/History sitting in `subjects` with
| subject_type='Elective' across multiple O-Level classes. Concretely,
| this means _marks_entry_helpers.php and _report_card_render.php --
| which only include a class's WHOLE roster for subject_type != 'Elective'
| subjects, and require an explicit student_subjects enrollment row for
| anything marked 'Elective' -- silently excluded these 5 "secretly
| elective" subjects from every student who was never individually
| enrolled in them (which is everyone, since nobody manually enrolls
| students in subjects that are supposed to be automatic).
|
| This script finds every `subjects` row whose subject_reference_id
| points to a now-compulsory catalog entry but whose own
| subject_type/is_compulisory still says otherwise, and corrects them
| in place (UPDATE, not re-insert -- preserves the row's id, any marks
| already recorded against it via student_marks.subject_id, etc.). Then
| runs the existing scholar_ensure_compulsory_subjects() pass too, to
| pick up any class genuinely missing a compulsory subject outright
| (the gap the original backfill script DOES correctly handle).
|
| Gated behind an active developer session, same as every other
| scholar/developer/* page. Safe to re-run -- the UPDATE's WHERE clause
| only ever matches rows still wrong; once corrected, re-running finds 0.
|
| DELETE THIS FILE immediately after it reports success -- it is not meant
| to stay on the server.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_subject_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
) {
    http_response_code(403);
    exit('Forbidden. Log in as developer first.');
}

header('Content-Type: text/plain');

echo "== Step 1: correcting subjects mislabeled Elective that are actually compulsory ==\n\n";

$find_stmt = $pdo->query("
    SELECT subj.id, subj.school_id, sch.school_name, subj.class_name, subj.subject_name
    FROM subjects subj
    JOIN subject_catalog sc ON sc.id = subj.subject_reference_id
    JOIN schools sch ON sch.id = subj.school_id
    WHERE sc.level_type = 'O-Level' AND sc.is_compulsory = 1
      AND (subj.subject_type != 'Core' OR subj.is_compulsory != 1)
    ORDER BY subj.school_id, subj.class_name
");
$mismatched = $find_stmt->fetchAll(PDO::FETCH_ASSOC);

$fix_stmt = $pdo->prepare("UPDATE subjects SET subject_type = 'Core', is_compulsory = 1 WHERE id = ?");
foreach ($mismatched as $row) {
    $fix_stmt->execute([$row['id']]);
    echo "{$row['school_name']} (school #{$row['school_id']}) — {$row['class_name']}: {$row['subject_name']} corrected Elective -> Core\n";
}
echo "\nCorrected: " . count($mismatched) . " row(s).\n";

echo "\n== Step 2: adopting any compulsory subject still missing outright ==\n\n";

$classes_stmt = $pdo->query("
    SELECT c.school_id, s.school_name, c.class_name
    FROM classes c
    JOIN schools s ON s.id = c.school_id
    WHERE c.class_name IN ('S.1', 'S.2', 'S.3', 'S.4')
    ORDER BY c.school_id, c.class_name
");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_adopted = 0;
foreach ($classes as $row) {
    $adopted = scholar_ensure_compulsory_subjects($pdo, (int) $row['school_id'], $row['class_name']);
    if ($adopted > 0) {
        echo "{$row['school_name']} (school #{$row['school_id']}) — {$row['class_name']}: adopted {$adopted} subject(s)\n";
        $total_adopted += $adopted;
    }
}

echo "\n== DONE ==\n";
echo "Subjects corrected (Elective -> Core): " . count($mismatched) . "\n";
echo "Subjects newly adopted (were missing entirely): {$total_adopted}\n";
echo "\nVerify the output above, then delete this file from the server.\n";
