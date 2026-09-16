<?php
declare(strict_types=1);

/*
| Temporary diagnostic -- delete after use. Gated behind an active
| developer session, same pattern as _diag_opcache.php.
|
| Deliberately minimal: only cheap, single-row schema/query checks. An
| earlier version of this file also ran the full class/subject auto-seed
| and a complete report-card render, which is expensive against a live
| database with real data -- on this host that was heavy enough to crash
| the PHP worker outright (Cloudflare reported it as a 521, "origin
| unreachable", not a normal PHP error). Nothing here should take more
| than a few milliseconds.
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
set_time_limit(10);

echo "PHP version: " . phpversion() . "\n\n";

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

$school = $pdo->query("SELECT id, school_name, school_type FROM schools ORDER BY id LIMIT 1")->fetch();
if (!$school) {
    echo "\nNo rows in `schools` at all.\n";
    exit;
}
$school_id = (int) $school['id'];
echo "\nUsing school_id={$school_id} ({$school['school_name']}, {$school['school_type']})\n";

echo "\n== admin_grading_fetch_bands() (grading_scales.php's own query) ==\n";
try {
    require_once __DIR__ . '/../school_admin/_grading_scales_helpers.php';
    $bands = admin_grading_fetch_bands($pdo, $school_id, 'O-Level');
    echo "OK -- returned " . count($bands) . " row(s)\n";
} catch (Throwable $e) {
    echo "THREW: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n== scholar_fetch_grading_scales() (_report_card_render.php's own query) ==\n";
try {
    require_once __DIR__ . '/../_report_card_render.php';
    $scales = scholar_fetch_grading_scales($pdo, $school_id);
    echo "OK -- returned " . count($scales) . " row(s)\n";
} catch (Throwable $e) {
    echo "THREW: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\nDONE. Copy this whole output back to Claude, then delete this file from the server.\n";
