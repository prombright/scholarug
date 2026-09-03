<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — FORCE PASSWORD RESET
|--------------------------------------------------------------------------
| Anyone who logged in with a one-time code (users.is_temp_password = 1)
| lands here before they can reach any dashboard. login.php checks this
| flag right after authenticating and redirects here instead of the
| normal role dashboard — see the "if ($user['is_temp_password'])" check
| added to both login methods.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php'; // for the shared role_destination()

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trimmed for the same reason login.php trims the entered password
    // before verifying -- see forgot_password.php's matching comment.
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (strlen($new_password) < 6) {
        $error = 'Your new password must be at least 6 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);

        $upd = $pdo->prepare("UPDATE users SET password = ?, is_temp_password = 0, temp_password_plain = NULL WHERE id = ?");
        $upd->execute([$hash, $_SESSION['user_id']]);

        $_SESSION['is_temp_password'] = false;

        // Send them on to the dashboard their role actually belongs to —
        // role_destination() in auth_guard.php is the single place this
        // mapping is defined now, so this list and login.php's can't
        // drift apart again the way they did before (this copy was
        // missing 'student', among others).
        header('Location: ' . role_destination($_SESSION['role']));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Your Password</title>
<style>
    :root { --login-bg: #0e1117; --login-card: #0b0d12; --login-border: #1e293b; --login-input: #12151c; --login-text: #e2e8f0; --login-muted: #64748b; --login-input-text: #fff; }
    :root[data-theme="light"] { --login-bg: #F1F5F9; --login-card: #FFFFFF; --login-border: rgba(15,23,42,.12); --login-input: #F8FAFC; --login-text: #1E293B; --login-muted: #64748B; --login-input-text: #1E293B; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--login-bg); color: var(--login-text); margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; box-sizing: border-box; padding: 16px; }
    .card { background: var(--login-card); border: 1px solid var(--login-border); border-radius: 12px; padding: 40px; width: 100%; max-width: 400px; box-sizing: border-box; }
    .form-control { width: 100%; background: var(--login-input); border: 1px solid var(--login-border); padding: 12px 14px; border-radius: 6px; color: var(--login-input-text); font-size: 0.875rem; box-sizing: border-box; margin-bottom: 14px; }
    .btn { width: 100%; background: #00A8A8; color: #04222a; border: none; padding: 14px; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; }
    .alert { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 12px; border-radius: 6px; font-size: 0.8rem; margin-bottom: 16px; }
    label { display: block; font-size: 0.65rem; text-transform: uppercase; color: var(--login-muted); font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px; }
    @media (max-width: 480px) {
        .card { padding: 28px 22px; }
    }
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<style>.scholar-theme-toggle{display:none !important;}</style>
<div class="card">
    <div style="text-align:center;margin-bottom:24px;">
        <div style="font-weight:900;font-size:1.2rem;">Set Your Password</div>
        <div style="color:var(--login-muted);font-size:0.75rem;margin-top:4px;">You logged in with a one-time code — choose a permanent password to continue.</div>
    </div>

    <?php if ($error): ?><div class="alert">&#9888; <?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

    <form method="post">
        <label>New Password</label>
        <input type="password" name="new_password" class="form-control" required minlength="6">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required minlength="6">
        <button type="submit" class="btn">Set Password &amp; Continue</button>
    </form>
</div>
</body>
</html>
