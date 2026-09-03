<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SSO BRIDGE (into the new scholar-app / scholar-spa portal)
|--------------------------------------------------------------------------
| Mints a one-time token for whoever's already logged into this legacy
| session, then sends the browser through scholar-app (which establishes
| a real Sanctum session there) into scholar-spa, landing on ?to= (default
| "dashboard"). Mirror image of sso_from_portal.php, which brings a
| portal session into this app the other way.
|
| Link to this file with ?to=analytics (etc.) rather than linking to the
| new portal directly -- a plain link would just bounce to the portal's
| own login page, since this app's $_SESSION and the portal's Sanctum
| session are two unrelated auth systems.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php'; // starts the session, defines SCHOLAR_BASE
require_once __DIR__ . '/db.php';         // $pdo -> scholar database

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: " . SCHOLAR_BASE . "/login.php");
    exit();
}

// Method 1 (school-code) sessions use the synthetic "school_admin_{id}"
// user_id login.php itself creates -- see LoginRequest::authenticateSchool()
// on the scholar-app side for the same convention.
$userId = (string) $_SESSION['user_id'];
if (str_starts_with($userId, 'school_admin_')) {
    $kind = 'school';
    $id = (int) substr($userId, strlen('school_admin_'));
} else {
    $kind = 'user';
    $id = (int) $userId;
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

// Deliberately minimal payload (just enough to look the row up fresh on
// the other side) -- see legacy_sso_tokens migration's comment.
$stmt = $pdo->prepare(
    "INSERT INTO legacy_sso_tokens (token_hash, payload, expires_at) VALUES (?, ?, ?)"
);
$stmt->execute([
    $tokenHash,
    json_encode(['kind' => $kind, 'id' => $id]),
    time() + 120,
]);

$to = $_GET['to'] ?? 'dashboard';
$apiUrl = rtrim(SCHOLAR_APP_API_URL, '/');

header("Location: {$apiUrl}/sso/legacy?token=" . urlencode($token) . '&to=' . urlencode($to));
exit();
