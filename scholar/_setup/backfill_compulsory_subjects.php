<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BACKFILL: COMPULSORY O-LEVEL SUBJECTS FOR EXISTING SCHOOLS
|--------------------------------------------------------------------------
| One-time run, needed because scholar_ensure_compulsory_subjects()
| (_subject_helpers.php) only fires going forward -- on a class being
| created or a student being registered from now on. Every O-Level class
| that already existed before this feature shipped needs the same 7
| compulsory subjects (English/Mathematics/Biology/Physics/Chemistry/
| Geography/History) adopted retroactively, with the correct S.1/S.2=1,
| S.3/S.4=2 (Bio/Phy/Che only) paper counts.
|
| Safe to re-run: scholar_ensure_compulsory_subjects() is itself
| additive-only (skips subjects already adopted for a class), so running
| this twice just reports 0 newly-adopted the second time.
|
| Run once via browser as a developer login:
|   /ABNsystems/scholar/_setup/backfill_compulsory_subjects.php
| ============================================================
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_subject_helpers.php';

// Platform-wide (iterates every school), so developer-only -- same bar
// as developer_dashboard.php.
require_role(['developer']);

$classes_stmt = $pdo->query("
    SELECT c.id, c.school_id, c.class_name, s.school_name
    FROM classes c
    JOIN schools s ON s.id = c.school_id
    WHERE c.class_name IN ('S.1', 'S.2', 'S.3', 'S.4')
    ORDER BY s.school_name, c.class_name
");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_adopted = 0;
$rows_touched = 0;
$results = [];

foreach ($classes as $c) {
    $adopted = scholar_ensure_compulsory_subjects($pdo, (int) $c['school_id'], $c['class_name']);
    if ($adopted > 0) {
        $rows_touched++;
        $total_adopted += $adopted;
        $results[] = "{$c['school_name']} — {$c['class_name']}: {$adopted} subject(s) adopted";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backfill: Compulsory O-Level Subjects</title>
<style>
body{font-family:monospace;background:#0b0d12;color:#e2e8f0;padding:30px;}
h1{font-size:1.1rem;}
.line{padding:2px 0;}
.summary{margin-top:20px;padding:14px;background:#131b28;border-radius:8px;}
</style>
</head>
<body>
<h1>Compulsory O-Level Subjects — Backfill</h1>
<div class="summary">
    Checked <?= count($classes) ?> O-Level class(es) across every school.<br>
    <?= $rows_touched ?> class(es) needed subjects adopted, <?= $total_adopted ?> subject-adoption(s) total.
</div>
<?php if (empty($results)): ?>
    <p>Nothing to do -- every O-Level class already had its compulsory subjects.</p>
<?php else: ?>
    <?php foreach ($results as $line): ?>
        <div class="line"><?= htmlspecialchars($line) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>