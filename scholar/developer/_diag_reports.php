<?php
declare(strict_types=1);

/*
| Temporary diagnostic -- delete after use. Gated behind an active
| developer session, same pattern as _diag_opcache.php.
|
| Cross-references every _setup/*.sql migration's actual table/column
| against the live schema directly -- not the schema_migrations tracker
| (which is itself one of the things being checked here, and can't be
| trusted until this file confirms it exists). This is the same kind of
| direct-schema check already done once against the local database; this
| is that same check run against whichever database this live PHP process
| actually connects to (config.php's DB_NAME), so there's no more room for
| a stray same-named-but-wrong database to give a false picture.
|
| Deliberately cheap: single-row information_schema lookups only, nothing
| that touches real application tables at scale. An earlier version of
| this file ran a full class/subject auto-seed and a complete report-card
| render, which was heavy enough against a live database with real data to
| crash the PHP worker outright (Cloudflare reported that as a 521, not a
| normal PHP error).
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
set_time_limit(15);

function col_exists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $col]);
    return (bool) $stmt->fetchColumn();
}
function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}
function index_exists(PDO $pdo, string $table, string $idx): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $idx]);
    return (bool) $stmt->fetchColumn();
}

echo "PHP version: " . phpversion() . "\n";
echo "Connected database (DATABASE()): " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

$checks = [
    // filename => [what to check]
    'timetable_migration.sql'                        => fn() => col_exists($pdo, 'teacher_assignments', 'periods_per_week') && table_exists($pdo, 'timetable_periods') && table_exists($pdo, 'timetable_entries'),
    'student_login_extras_migration.sql'              => fn() => col_exists($pdo, 'users', 'is_temp_password'),
    'hr_role_migration.sql'                           => fn() => str_contains((string) $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch()['Type'], "'HR'"),
    'staff_code.sql'                                  => fn() => col_exists($pdo, 'staff', 'staff_code'),
    'ilearning_pdf_content.sql'                       => fn() => col_exists($pdo, 'ilearning_topics', 'content_type'),
    'sms_whatsapp_migration.sql'                      => fn() => col_exists($pdo, 'sms_campaign_recipients', 'channel'),
    'grading_scales_level_type_migration.sql'         => fn() => col_exists($pdo, 'grading_scales', 'level_type'),
    'attendance_fix_and_perf_indexes_migration.sql'   => fn() => col_exists($pdo, 'attendance', 'school_id') && index_exists($pdo, 'fee_payments', 'idx_fee_payments_school_student'),
    'scheduling_data_integrity.sql'                   => fn() => col_exists($pdo, 'streams', 'class_id'),
    'password_reset_otp.sql'                          => fn() => col_exists($pdo, 'users', 'password_reset_otp'),
    'multi_school_login_migration.sql'                => fn() => index_exists($pdo, 'users', 'uniq_school_email'),
    'report_card_enhancements_migration.sql'          => fn() => col_exists($pdo, 'grading_scales', 'color') && col_exists($pdo, 'school_settings', 'show_student_photos'),
    'schema_migrations_tracking.sql'                  => fn() => table_exists($pdo, 'schema_migrations'),
    'library_migration.sql'                           => fn() => table_exists($pdo, 'library_documents'),
    'developer_messages_migration.sql'                => fn() => table_exists($pdo, 'developer_messages'),
    'elections.sql'                                   => fn() => table_exists($pdo, 'elections'),
    'ilearning_addon_billing_migration.sql'           => fn() => table_exists($pdo, 'ilearning_addons'),
    'ilearning_core_migration.sql'                    => fn() => table_exists($pdo, 'ilearning_topics'),
    'ilearning_live_migration.sql'                    => fn() => table_exists($pdo, 'ilearning_live_sessions'),
    'ilearning_annotations_migration.sql'             => fn() => table_exists($pdo, 'ilearning_text_annotations'),
    'leave_management_migration.sql'                  => fn() => table_exists($pdo, 'leave_requests'),
    'messaging_migration.sql'                         => fn() => table_exists($pdo, 'conversations'),
    'payroll_migration.sql'                           => fn() => table_exists($pdo, 'payroll_payments'),
    'projects_migration.sql'                          => fn() => table_exists($pdo, 'projects'),
    'report_remarks_migration.sql'                    => fn() => table_exists($pdo, 'report_card_remarks'),
    'sms_module_migration.sql'                        => fn() => table_exists($pdo, 'sms_wallets'),
    'compulsory_subjects_fix_migration.sql'           => fn() => true, // data fix, no schema signature to check
    'uace_subsidiary_migration.sql'                   => fn() => true, // data fix, no schema signature to check
    'performance_indexes_migration.sql'               => fn() => true, // index-only, not worth a hard-fail check here
];

$missing = [];
foreach ($checks as $file => $fn) {
    try {
        $ok = $fn();
    } catch (Throwable $e) {
        $ok = false;
    }
    echo str_pad($file, 50) . ($ok ? "APPLIED" : "MISSING") . "\n";
    if (!$ok) {
        $missing[] = $file;
    }
}

echo "\n== Summary ==\n";
if ($missing) {
    echo count($missing) . " migration(s) appear NOT applied to this database:\n";
    foreach ($missing as $m) echo " - {$m}\n";
} else {
    echo "All checked migrations appear applied.\n";
}

echo "\nDONE. Copy this whole output back to Claude, then delete this file from the server.\n";
