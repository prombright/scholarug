<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ONE-TIME BACKFILL: assign a student_no to every student missing one
|--------------------------------------------------------------------------
| Neither student registration path (single Add Student, CSV import) ever
| set students.student_no -- confirmed live, 442 of 466 non-graduated
| students had none, which is also what silently broke CSV mark imports
| (they matched students by student_no). Both paths now generate one via
| scholar_generate_student_no() (school_admin/students.php) for every NEW
| student; this backfills everyone already in the system before that fix.
|
| Uses the exact same generator every new registration uses, so a
| backfilled number and a freshly-registered one are indistinguishable in
| format (STU-{year}-####, globally unique, same convention as
| staff.staff_code).
|
| Run once via: php _setup/backfill_missing_student_numbers.php
| Preview with no writes: php _setup/backfill_missing_student_numbers.php --dry-run
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../db.php';
require __DIR__ . '/../auth_guard.php'; // scholar_generate_student_no()

$dry_run = in_array('--dry-run', $argv ?? [], true);
if ($dry_run) {
    echo "*** DRY RUN -- no changes will be written ***\n\n";
}

$students = $pdo->query("
    SELECT id, full_name, school_id
    FROM students
    WHERE student_no IS NULL OR student_no = ''
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// scholar_generate_student_no() re-queries the DB for the last-used number
// each call -- correct in the live run (each UPDATE commits before the
// next call), but in dry-run nothing is ever written, so it would return
// the same "next" number for every row. Track the sequence locally here
// instead so the preview actually reflects what a live run would produce.
$dry_run_next_seq = null;
if ($dry_run) {
    $year = date('Y');
    $prefix = "STU-{$year}-";
    $seq = $pdo->prepare("SELECT student_no FROM students WHERE student_no LIKE ? ORDER BY student_no DESC LIMIT 1");
    $seq->execute([$prefix . '%']);
    $last = $seq->fetchColumn();
    $dry_run_next_seq = $last ? ((int) substr($last, -4) + 1) : 1;
}

$assigned = 0;
foreach ($students as $s) {
    if ($dry_run) {
        $student_no = "STU-{$year}-" . str_pad((string) $dry_run_next_seq, 4, '0', STR_PAD_LEFT);
        $dry_run_next_seq++;
    } else {
        $student_no = scholar_generate_student_no($pdo);
        $pdo->prepare("UPDATE students SET student_no = ? WHERE id = ?")->execute([$student_no, $s['id']]);
    }
    $assigned++;
    echo ($dry_run ? "WOULD ASSIGN: " : "ASSIGNED: ") . "student #{$s['id']} \"{$s['full_name']}\" (school #{$s['school_id']}) -> {$student_no}\n";
}

echo "\n=== SUMMARY ===\n";
echo "Total assigned: {$assigned}\n";