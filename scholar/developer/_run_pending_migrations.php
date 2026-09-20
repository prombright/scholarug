<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ONE-TIME MIGRATION RUNNER -- deploy helper, delete after use
|--------------------------------------------------------------------------
| Applies library_migration.sql, multi_school_login_migration.sql,
| developer_messages_migration.sql, grading_scales_level_type_migration.sql,
| attendance_late_status_migration.sql, fees_term_scoping_migration.sql,
| login_lockout_migration.sql, password_reset_otp_attempts_migration.sql,
| subject_paper_weights_migration.sql,
| grading_scales_primary_level_migration.sql and
| access_pin_widen_migration.sql against the live database via the same
| db.php connection every other page uses (no direct DB CLI/phpMyAdmin
| access needed from the deploying machine). Gated behind an active
| developer session, same as every other scholar/developer/* page. Lives
| here rather than scholar/_setup/ because that folder's .htaccess denies
| all direct HTTP access outright (see its own header comment) -- this
| needs to actually be reachable once, then deleted.
|
| DELETE THIS FILE immediately after it reports success -- it is not meant
| to stay on the server.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';

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

// $sourceFile records this migration into schema_migrations once every
// statement has run (or been safely skipped as already-applied) -- so
// visiting this page also keeps developer/migrations_status.php honest,
// instead of that page only ever being updated by hand. Best-effort: a
// database that hasn't applied _setup/schema_migrations_tracking.sql yet
// (table doesn't exist, error 1146) still gets the real migration applied
// above, it just can't be recorded yet.
function run_statements(PDO $pdo, string $label, array $statements, ?string $sourceFile = null): void
{
    echo "== {$label} ==\n";
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
            echo "OK: " . substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 90) . "...\n";
        } catch (\PDOException $e) {
            // 1050 = table already exists, 1060 = column exists, 1061 = key
            // exists, 1091 = can't drop (doesn't exist) -- all mean "already
            // applied", safe to skip so this runner is safe to re-run.
            if (in_array((int) $e->errorInfo[1], [1050, 1060, 1061, 1091], true)) {
                echo "SKIP (already applied): " . $e->getMessage() . "\n";
            } else {
                echo "FAIL: " . $e->getMessage() . "\n";
            }
        }
    }
    if ($sourceFile !== null) {
        try {
            $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute([$sourceFile]);
        } catch (\PDOException $e) {
            echo "(not recorded in schema_migrations -- run schema_migrations_tracking.sql first)\n";
        }
    }
    echo "\n";
}

run_statements($pdo, 'library_migration', [
    "CREATE TABLE IF NOT EXISTS library_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        category ENUM('notes','past_paper') NOT NULL,
        title VARCHAR(255) NOT NULL,
        term VARCHAR(20) NULL,
        year INT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        status ENUM('Draft','Published') NOT NULL DEFAULT 'Published',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_library_school_class_subject (school_id, class_id, subject_id),
        KEY idx_library_teacher (teacher_id),
        KEY idx_library_category (category),
        CONSTRAINT fk_library_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
        CONSTRAINT fk_library_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        CONSTRAINT fk_library_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        CONSTRAINT fk_library_teacher FOREIGN KEY (teacher_id) REFERENCES staff(staff_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
], 'library_migration.sql');

echo "== multi_school_login_migration: pre-check ==\n";
$emailConflicts = $pdo->query("SELECT school_id, email, COUNT(*) c FROM users WHERE email IS NOT NULL GROUP BY school_id, email HAVING c > 1")->fetchAll();
$phoneConflicts = $pdo->query("SELECT school_id, phone, COUNT(*) c FROM staff WHERE phone IS NOT NULL GROUP BY school_id, phone HAVING c > 1")->fetchAll();

if ($emailConflicts || $phoneConflicts) {
    echo "ABORTED -- existing duplicate rows would violate the new constraint. Resolve these first, then re-run:\n";
    foreach ($emailConflicts as $r) echo "  users: school_id={$r['school_id']} email={$r['email']} count={$r['c']}\n";
    foreach ($phoneConflicts as $r) echo "  staff: school_id={$r['school_id']} phone={$r['phone']} count={$r['c']}\n";
    echo "\n";
} else {
    echo "no conflicts found\n\n";
    run_statements($pdo, 'multi_school_login_migration', [
        "ALTER TABLE users DROP INDEX `email`",
        "ALTER TABLE users ADD UNIQUE KEY `uniq_school_email` (`school_id`, `email`)",
        "ALTER TABLE staff DROP INDEX `username`",
        "ALTER TABLE staff ADD UNIQUE KEY `uniq_school_phone` (`school_id`, `phone`)",
    ], 'multi_school_login_migration.sql');
}

run_statements($pdo, 'developer_messages_migration', [
    "CREATE TABLE IF NOT EXISTS developer_conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dev_conv_school (school_id),
        CONSTRAINT fk_dev_conv_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS developer_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        sender_role ENUM('school_admin','developer') NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        KEY idx_dev_msg_conversation (conversation_id),
        CONSTRAINT fk_dev_msg_conversation FOREIGN KEY (conversation_id) REFERENCES developer_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
], 'developer_messages_migration.sql');

// grading_scales_level_type_migration -- O-Level and A-Level need
// independent grading bands (see that .sql file's own header). The ALTER
// throws 1060 (duplicate column) and gets SKIPped by run_statements() if
// a prior run already added it; the UPDATE is naturally idempotent on its
// own (a second run just matches zero rows), so it's not wrapped in the
// same try/catch skip logic -- it's always safe to run again.
run_statements($pdo, 'grading_scales_level_type_migration', [
    "ALTER TABLE grading_scales ADD COLUMN level_type ENUM('O-Level','A-Level') NULL DEFAULT NULL AFTER school_id",
], 'grading_scales_level_type_migration.sql');
echo "== grading_scales_level_type_migration: backfill ==\n";
$backfilled = $pdo->exec("UPDATE grading_scales SET level_type = 'O-Level' WHERE level_type IS NULL");
echo "OK: backfilled {$backfilled} row(s) to O-Level\n\n";

// attendance_late_status_migration -- 'late' is a real enum value now (see
// that .sql file's header for the Present/Absent/Late/Excused vocabulary
// mismatch this fixes). MODIFY COLUMN has no "already applied" error code
// to catch -- re-applying the identical enum definition is just a no-op --
// so this always runs, not wrapped in run_statements()'s SKIP logic.
echo "== attendance_late_status_migration ==\n";
$pdo->exec("ALTER TABLE attendance MODIFY COLUMN status ENUM('present','absent','sick','permission','late') NULL DEFAULT 'present'");
echo "OK: attendance.status now allows 'late'\n";
try {
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute(['attendance_late_status_migration.sql']);
} catch (\PDOException $e) {
    echo "(not recorded in schema_migrations -- run schema_migrations_tracking.sql first)\n";
}
echo "\n";

// fees_term_scoping_migration -- adds nullable term/year to fee_structures
// and fee_payments, backfills existing rows to each school's own
// current_term/current_year, then widens fee_structures' unique key to
// (school_id, class_id, term, year). See that .sql file's header for why
// (raising tuition for a new term was silently recalculating balances for
// already-settled prior terms). Done via PHP-side existence checks rather
// than run_statements()'s try/catch-on-error-code pattern because the key
// widen step depends on the columns already existing.
echo "== fees_term_scoping_migration ==\n";
$hasCol = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

if (!$hasCol($pdo, 'fee_structures', 'term')) {
    $pdo->exec("ALTER TABLE fee_structures ADD COLUMN term VARCHAR(20) NULL AFTER class_id, ADD COLUMN year VARCHAR(10) NULL AFTER term");
    echo "OK: fee_structures.term/year added\n";
} else {
    echo "SKIP (already applied): fee_structures.term/year\n";
}

if (!$hasCol($pdo, 'fee_payments', 'term')) {
    $pdo->exec("ALTER TABLE fee_payments ADD COLUMN term VARCHAR(20) NULL AFTER student_id, ADD COLUMN year VARCHAR(10) NULL AFTER term");
    echo "OK: fee_payments.term/year added\n";
} else {
    echo "SKIP (already applied): fee_payments.term/year\n";
}

$backfilledStructures = $pdo->exec("UPDATE fee_structures fs JOIN schools s ON s.id = fs.school_id SET fs.term = s.current_term, fs.year = s.current_year WHERE fs.term IS NULL");
echo "OK: backfilled {$backfilledStructures} fee_structures row(s)\n";
$backfilledPayments = $pdo->exec("UPDATE fee_payments fp JOIN schools s ON s.id = fp.school_id SET fp.term = s.current_term, fp.year = s.current_year WHERE fp.term IS NULL");
echo "OK: backfilled {$backfilledPayments} fee_payments row(s)\n";

$oldKeyStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_structures' AND INDEX_NAME = 'school_class_unique'");
$oldKeyStmt->execute();
if ((int) $oldKeyStmt->fetchColumn() > 0) {
    $pdo->exec("ALTER TABLE fee_structures DROP INDEX school_class_unique, ADD UNIQUE KEY school_class_term_year_unique (school_id, class_id, term, year)");
    echo "OK: fee_structures unique key widened to (school_id, class_id, term, year)\n";
} else {
    echo "SKIP (already applied): fee_structures unique key already widened\n";
}

try {
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute(['fees_term_scoping_migration.sql']);
} catch (\PDOException $e) {
    echo "(not recorded in schema_migrations -- run schema_migrations_tracking.sql first)\n";
}
echo "\n";

// login_lockout_migration -- adds the login_attempts table used by
// auth_guard.php's login_is_locked_out()/login_record_attempt() to
// rate-limit login.php's school-code+PIN and non-student username/
// password paths. See that .sql file's header for the full reasoning.
run_statements($pdo, 'login_lockout_migration', [
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(191) NOT NULL,
        succeeded TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_attempts_identifier_time (identifier, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
], 'login_lockout_migration.sql');

// password_reset_otp_attempts_migration -- caps how many OTP guesses
// forgot_password.php accepts against one issued code (was previously
// unlimited within the 15-minute expiry window). See that .sql file's
// header for the full reasoning.
run_statements($pdo, 'password_reset_otp_attempts_migration', [
    "ALTER TABLE users ADD COLUMN password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0",
], 'password_reset_otp_attempts_migration.sql');

// subject_paper_weights_migration -- lets a two-paper subject weight
// Paper 1/Paper 2 unevenly toward the combined mark the report card
// grades (default 50/50, identical to the old flat average). See that
// .sql file's header for the full reasoning.
run_statements($pdo, 'subject_paper_weights_migration', [
    "ALTER TABLE subjects ADD COLUMN paper1_weight_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00, ADD COLUMN paper2_weight_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00",
], 'subject_paper_weights_migration.sql');

// grading_scales_primary_level_migration -- grading_scales.level_type was
// ENUM('O-Level','A-Level'); inserting 'Primary' into it didn't error, it
// silently stored an empty string, which is why a Primary school's report
// card graded every subject "N/A". MODIFY COLUMN has no "already applied"
// error code to catch -- re-applying the identical enum definition is
// just a no-op -- so this always runs, not wrapped in run_statements()'s
// SKIP logic. See that .sql file's header for the full reasoning.
echo "== grading_scales_primary_level_migration ==\n";
try {
    $pdo->exec("ALTER TABLE grading_scales MODIFY COLUMN level_type ENUM('O-Level','A-Level','Primary') NULL");
    echo "OK: grading_scales.level_type now allows 'Primary'\n";
} catch (\PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
try {
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute(['grading_scales_primary_level_migration.sql']);
} catch (\PDOException $e) {
    echo "(not recorded in schema_migrations -- run schema_migrations_tracking.sql first)\n";
}
echo "\n";

// access_pin_widen_migration -- schools.access_pin was VARCHAR(5), just
// wide enough for the plain 5-digit PIN it's always stored as and NOT
// wide enough for a bcrypt hash (~60 chars) -- the reason an earlier
// attempt to hash it broke login in production. Widening it does not
// touch any existing data; every school's current PIN keeps working
// exactly as before until login.php's self-healing rehash upgrades it on
// next successful login. MODIFY COLUMN has no "already applied" error
// code to catch, so this always runs (re-applying the identical
// definition is a no-op) rather than going through run_statements().
echo "== access_pin_widen_migration ==\n";
try {
    $pdo->exec("ALTER TABLE schools MODIFY COLUMN access_pin VARCHAR(255) NOT NULL");
    echo "OK: schools.access_pin now allows a bcrypt hash\n";
} catch (\PDOException $e) {
    // Printed instead of left to crash the whole page (500 with no detail
    // in production, since display_errors is correctly off there) -- this
    // is the one migration in this file that previously had no try/catch
    // around its ALTER, unlike every other migration here.
    echo "FAILED: " . $e->getMessage() . "\n";
}
try {
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute(['access_pin_widen_migration.sql']);
} catch (\PDOException $e) {
    echo "(not recorded in schema_migrations -- run schema_migrations_tracking.sql first)\n";
}
echo "\n";

echo "DONE. Verify the output above, then delete this file from the server.\n";
