<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ONE-TIME BACKFILL: reconcile students.class_name to real classes
|--------------------------------------------------------------------------
| For every non-graduated student whose class_name doesn't match any real
| classes row for their school, finds the best match and rewrites
| class_id + class_name to that class's canonical spelling. A student that
| still can't be matched is printed for the admin to handle manually
| (create the missing class, or reassign that student) -- never guessed,
| never deleted.
|
| Two-pass match:
|   1. scholar_normalize_class_name() (dot/case-insensitive -- "S1"/"S.1")
|   2. "Senior N"/"Primary N" -> "S.N"/"P.N" -- a distinct, common spelling
|      (confirmed live: one real school has 431 students recorded this way)
|      that a dot/case-only normalizer can never reconcile on its own.
|
| Run once via: php _setup/backfill_student_class_ids.php
| Preview with no writes: php _setup/backfill_student_class_ids.php --dry-run
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../db.php';
require __DIR__ . '/../auth_guard.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);
if ($dry_run) {
    echo "*** DRY RUN -- no changes will be written ***\n\n";
}

function scholar_backfill_alt_class_name(string $name): ?string
{
    if (preg_match('/^senior\s*(\d+)$/i', trim($name), $m)) {
        return 'S.' . $m[1];
    }
    if (preg_match('/^primary\s*(\d+)$/i', trim($name), $m)) {
        return 'P.' . $m[1];
    }
    return null;
}

$schools = $pdo->query("SELECT id, school_name FROM schools")->fetchAll(PDO::FETCH_ASSOC);

$resolved = 0;
$already_ok = 0;
$unresolved = [];

foreach ($schools as $school) {
    $school_id = (int) $school['id'];

    $classesStmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ?");
    $classesStmt->execute([$school_id]);
    $lookup = [];
    foreach ($classesStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $lookup[scholar_normalize_class_name($c['class_name'])] = $c;
    }

    $studentsStmt = $pdo->prepare("SELECT id, full_name, class_name, class_id FROM students WHERE school_id = ? AND graduated_year IS NULL");
    $studentsStmt->execute([$school_id]);

    foreach ($studentsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $raw = (string) ($s['class_name'] ?? '');
        $match = $lookup[scholar_normalize_class_name($raw)] ?? null;

        if (!$match) {
            $alt = scholar_backfill_alt_class_name($raw);
            if ($alt !== null) {
                $match = $lookup[scholar_normalize_class_name($alt)] ?? null;
            }
        }

        if ($match) {
            if ((int) $s['class_id'] !== (int) $match['id'] || $s['class_name'] !== $match['class_name']) {
                if (!$dry_run) {
                    $upd = $pdo->prepare("UPDATE students SET class_id = ?, class_name = ? WHERE id = ?");
                    $upd->execute([$match['id'], $match['class_name'], $s['id']]);
                }
                $resolved++;
                echo ($dry_run ? "WOULD FIX: " : "FIXED: ") . "school #{$school_id} \"{$school['school_name']}\" -- student #{$s['id']} \"{$s['full_name']}\": \"{$raw}\" -> \"{$match['class_name']}\" (class_id={$match['id']})\n";
            } else {
                $already_ok++;
            }
        } else {
            $unresolved[] = "school #{$school_id} \"{$school['school_name']}\" -- student #{$s['id']} \"{$s['full_name']}\": class_name=\"{$raw}\" has no matching class -- create it via Classes, or reassign this student manually.";
        }
    }
}

echo "\n=== SUMMARY ===\n";
echo "Fixed: {$resolved}\n";
echo "Already correct: {$already_ok}\n";
echo "Unresolved: " . count($unresolved) . "\n";
if (!empty($unresolved)) {
    echo "\n--- UNRESOLVED (needs manual attention) ---\n";
    echo implode("\n", $unresolved) . "\n";
}