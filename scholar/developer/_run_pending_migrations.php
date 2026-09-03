<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ONE-TIME MIGRATION RUNNER -- deploy helper, delete after use
|--------------------------------------------------------------------------
| Applies library_migration.sql, multi_school_login_migration.sql and
| developer_messages_migration.sql against the live database via the same
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
    session_start();
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

function run_statements(PDO $pdo, string $label, array $statements): void
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
]);

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
    ]);
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
]);

echo "DONE. Verify the output above, then delete this file from the server.\n";
