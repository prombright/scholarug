<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SSO BRIDGE (from the new scholar-app / scholar-spa portal)
|--------------------------------------------------------------------------
| Accepts a one-time token minted by scholar-app's POST /api/legacy-handoff,
| then sets up a completely normal Scholar session -- exactly as if the
| user had logged in directly at login.php. Every existing require_role()
| check downstream needs zero changes; to those pages, this looks like a
| normal login.
|
| Same idea as sso_login.php (the existing devportal -> Scholar bridge),
| but that one opens a second connection to the abn_platform database
| because devportal is a genuinely separate application. This bridge's
| tokens live in portal_sso_tokens in this same `scholar` database, since
| scholar-app already reads/writes it directly.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php'; // starts the session, defines role_destination()
require_once __DIR__ . '/db.php';         // $pdo -> scholar database

$token = $_GET['token'] ?? '';
if ($token === '') {
    header("Location: " . SCHOLAR_BASE . "/login.php");
    exit();
}

$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare(
    "SELECT id, payload, expires_at, used_at FROM portal_sso_tokens WHERE token_hash = ?"
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

// expires_at is a plain Unix timestamp, not a DATETIME string -- see the
// migration's comment in scholar-app for why (this PHP process, XAMPP's
// own PHP, doesn't share a timezone with either Laravel/PHP83 or the
// MySQL server's SYSTEM timezone, so strtotime() on a naive datetime
// string here would silently misinterpret it).
if ($row === false || $row['used_at'] !== null || (int) $row['expires_at'] < time()) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=expired");
    exit();
}

// Single-use claim: guarded UPDATE + rowCount() check, same idempotency
// pattern sso_login.php uses -- if two requests race on the same token,
// only one can ever win this update.
$claim = $pdo->prepare("UPDATE portal_sso_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
$claim->execute([$row['id']]);
if ($claim->rowCount() !== 1) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=expired");
    exit();
}

$payload = json_decode($row['payload'], true);
if (!is_array($payload) || empty($payload['role'])) {
    header("Location: " . SCHOLAR_BASE . "/login.php?sso=error");
    exit();
}

// Same session-fixation fix login.php itself uses.
session_regenerate_id(true);

$_SESSION['user_id']         = $payload['id'] ?? null;
$_SESSION['username']        = $payload['username'] ?? '';
$_SESSION['role']            = $payload['role'];
$_SESSION['school_id']       = $payload['school_id'] ?? null;
$_SESSION['staff_id']        = $payload['staff_id'] ?? null;
$_SESSION['student_id']      = $payload['student_id'] ?? null;
$_SESSION['school_name']     = $payload['school_name'] ?? 'Scholar Portal';
$_SESSION['school_badge']    = $payload['school_badge'] ?? 'assets/img/default-logo.png';
$_SESSION['school_location'] = $payload['school_location'] ?? 'Uganda';
$_SESSION['current_term']    = $payload['current_term'] ?? 'Term 1';
$_SESSION['current_year']    = $payload['current_year'] ?? (string) date('Y');

if (!empty($payload['is_temp_password'])) {
    header("Location: " . SCHOLAR_BASE . "/force_password_reset.php");
    exit();
}

header("Location: " . SCHOLAR_BASE . "/" . role_destination($_SESSION['role']));
exit();
