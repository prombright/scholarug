<?php
declare(strict_types=1);

// db.php - Safe Database Connection Object
//
// Previously hardcoded host/db/user/pass inline here. Now reads them from
// config.php (env-var driven, with local XAMPP/WAMP fallbacks), so a real
// deployment only needs environment variables set -- not this file edited.
require_once __DIR__ . '/config.php';

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (\PDOException $e) {
    // In production, never echo the raw PDO exception -- it can leak the
    // DSN, DB name, or hint at valid usernames. Log it server-side instead.
    if (defined('SCHOLAR_ENV') && SCHOLAR_ENV === 'production') {
        error_log('Scholar DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        die('The system is temporarily unavailable. Please try again shortly.');
    }
    die("System Core Connection Failure: " . $e->getMessage());
}

// Shared HTML-escaping helper. Previously only defined inside
// school_admin_dashboard.php, but called from students.php and others
// that never included that file — causing "Call to undefined function
// safe_text()" fatal errors. Defined here once, in the file every page
// already requires, guarded so re-requiring db.php never redeclares it.
if (!function_exists('safe_text')) {
    function safe_text($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
