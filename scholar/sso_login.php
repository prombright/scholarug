<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SSO BRIDGE (from the ABN Developer Portal)
|--------------------------------------------------------------------------
| Accepts a one-time token minted by devportal/launch.php, validates it
| against the abn_platform database, then sets up a completely normal
| Scholar session -- exactly as if the developer had logged in directly at
| login.php. Every existing require_role() check downstream needs zero
| changes; to those pages, this looks like a normal login.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php'; // starts the session, defines role_destination()
require_once __DIR__ . '/db.php';         // $pdo -> scholar database

$token = $_GET['token'] ?? '';
if ($token === '') {
    header("Location: " . SCHOLAR_BASE . "/login.php");
    exit();
}

// Second connection, same MySQL server, the devportal's own database.
$ssoDsn = "mysql:host=" . DB_HOST . ";dbname=abn_platform;charset=" . DB_CHARSET;
try {
    $ssoPdo = new PDO($ssoDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    http_response_code(503);
    die('Single sign-on is temporarily unavailable. Please log in directly instead.');
}

$tokenHash = hash('sha256', $token);
$stmt = $ssoPdo->prepare(
    "SELECT id, admin_id, expires_at, used_at FROM sso_tokens WHERE token_hash = ? AND target_app = 'scholar'"
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if ($row === false || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=expired");
    exit();
}

// Single-use claim: guarded UPDATE + rowCount() check, same idempotency
// pattern as bulksms/topup_status.php's wallet-credit guard -- if two
// requests race on the same token, only one can ever win this update.
$claim = $ssoPdo->prepare("UPDATE sso_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
$claim->execute([$row['id']]);
if ($claim->rowCount() !== 1) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=expired");
    exit();
}

$adminStmt = $ssoPdo->prepare("SELECT name, email FROM platform_admins WHERE id = ?");
$adminStmt->execute([$row['admin_id']]);
$admin = $adminStmt->fetch();
if ($admin === false) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=error");
    exit();
}

// Find-or-create a local role='developer' account for this admin's email,
// same auto-provision-with-an-unusable-placeholder-password pattern
// staff_manager.php's invite flow already uses -- this account is only
// ever entered through SSO, so no one needs to know its password.
$userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$userStmt->execute([$admin['email']]);
$userId = $userStmt->fetchColumn();

if ($userId === false) {
    $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $pdo->prepare(
        "INSERT INTO users (username, email, password, role, is_temp_password, account_status)
         VALUES (?, ?, ?, 'developer', 0, 'active')"
    );
    $insert->execute([$admin['email'], $admin['email'], $placeholder]);
    $userId = (int) $pdo->lastInsertId();
}

$_SESSION['user_id'] = (int) $userId;
$_SESSION['username'] = $admin['email'];
$_SESSION['role'] = 'developer';
$_SESSION['school_id'] = null;
$_SESSION['staff_id'] = null;
$_SESSION['student_id'] = null;
$_SESSION['school_name'] = 'Scholar Portal';
$_SESSION['school_badge'] = 'assets/img/default-logo.png';
$_SESSION['school_location'] = 'Uganda';

header("Location: " . SCHOLAR_BASE . "/" . role_destination('developer'));
exit();
