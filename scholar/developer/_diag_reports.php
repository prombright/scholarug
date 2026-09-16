<?php
declare(strict_types=1);

/*
| Temporary diagnostic -- delete after use. Gated behind an active
| developer session, same pattern as _diag_opcache.php.
|
| Runs the exact same grading-scale/report-card code paths the school_admin
| Reports pages use, against a REAL school+student pulled from the live
| database (not a guessed id), and prints the real PHP error/exception in
| full (message + file + line), instead of the generic browser 500 page
| which hides it. Also dumps the live schema/migration-tracking state so a
| mismatch there is visible too.
*/

require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'developer' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden. Log in as developer first.');
}

header('Content-Type: text/plain');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "PHP version: " . phpversion() . "\n";
echo "display_errors: " . ini_get('display_errors') . "\n";
echo "log_errors: " . ini_get('log_errors') . "\n";
echo "error_log path: " . (ini_get('error_log') ?: '(default -- error_log file next to the script that errored)') . "\n\n";

echo "== grading_scales schema ==\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM grading_scales")->fetchAll();
    foreach ($cols as $c) echo " - {$c['Field']} ({$c['Type']})\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "\n== schema_migrations table ==\n";
try {
    $rows = $pdo->query("SELECT filename, applied_at FROM schema_migrations ORDER BY applied_at")->fetchAll();
    if (!$rows) {
        echo "(table exists but has zero rows)\n";
    }
    foreach ($rows as $r) echo " - {$r['filename']} @ {$r['applied_at']}\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Pull a REAL school + student rather than guessing an id -- otherwise
// every test below would just harmlessly return zero rows without
// actually exercising the failure.
$school = $pdo->query("SELECT id, school_name, school_type FROM schools ORDER BY id LIMIT 1")->fetch();
if (!$school) {
    echo "\nNo rows in `schools` at all -- nothing further to test against.\n";
    exit;
}
$school_id = (int) $school['id'];
echo "\nUsing school_id={$school_id} ({$school['school_name']}, {$school['school_type']})\n";

$student = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? ORDER BY id LIMIT 1");
$student->execute([$school_id]);
$student = $student->fetch();

echo "\n== Live query admin_grading_fetch_bands() runs (grading_scales.php) ==\n";
try {
    require_once __DIR__ . '/../school_admin/_grading_scales_helpers.php';
    $bands = admin_grading_fetch_bands($pdo, $school_id, 'O-Level');
    echo "OK -- returned " . count($bands) . " row(s)\n";
} catch (Throwable $e) {
    echo "THREW: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n== Live query scholar_fetch_grading_scales() runs (_report_card_render.php) ==\n";
try {
    require_once __DIR__ . '/../_report_card_render.php';
    $scales = scholar_fetch_grading_scales($pdo, $school_id);
    echo "OK -- returned " . count($scales) . " row(s)\n";
} catch (Throwable $e) {
    echo "THREW: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n== auth_guard.php's class-ladder auto-seed (runs on every protected page) ==\n";
try {
    require_once __DIR__ . '/../_subject_helpers.php';
    scholar_provision_school_type_defaults($pdo, $school_id, $school['school_type'] ?: 'Secondary');
    echo "OK\n";
} catch (Throwable $e) {
    echo "THREW: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

if ($student) {
    echo "\n== Full render_report_card_html() for a real student (id={$student['id']}, {$student['full_name']}) ==\n";
    try {
        $school_row = $pdo->prepare("SELECT school_name, school_badge, phone_contact, email_contact, address FROM schools WHERE id = ?");
        $school_row->execute([$school_id]);
        $school_row = $school_row->fetch() ?: [];
        $result = render_report_card_html($pdo, $school_row, $school_id, (int) $student['id'], current_term_fallback(), (int) date('Y'));
        echo "OK -- found=" . ($result['found'] ? 'true' : 'false') . ($result['error'] ? ", error msg: {$result['error']}" : '') . "\n";
    } catch (Throwable $e) {
        echo "THREW: " . get_class($e) . "\n";
        echo "Message: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
} else {
    echo "\nNo students found for school_id={$school_id} -- skipping full report render test.\n";
}

function current_term_fallback(): string
{
    return 'Term 1';
}

echo "\nDONE. Copy this whole output back to Claude, then delete this file from the server.\n";
