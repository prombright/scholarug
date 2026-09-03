<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER ACCOUNT ACTIVATION
|--------------------------------------------------------------------------
| Reached via the one-time link staff_manager.php's "Send Portal Invite"
| generates. No login required to view this page (the whole point is the
| teacher doesn't have credentials yet) -- the token itself is the proof.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

if ($token === '') {
    die('Missing invite token.');
}

$tok_stmt = $pdo->prepare("
    SELECT v.*, u.username, u.role
    FROM account_verifications v
    JOIN users u ON u.id = v.user_id
    WHERE v.token = ?
");
$tok_stmt->execute([$token]);
$verification = $tok_stmt->fetch(PDO::FETCH_ASSOC);

if (!$verification) {
    $error = 'This invite link is invalid.';
} elseif ($verification['used_at'] !== null) {
    $error = 'This invite link has already been used. Please log in, or ask your admin to send a new one.';
} elseif (strtotime($verification['expires_at']) < time()) {
    $error = 'This invite link has expired. Please ask your admin to send a new one.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    // Trimmed for the same reason login.php trims the entered password
    // before verifying -- see forgot_password.php's matching comment.
    $pw1 = trim($_POST['new_password'] ?? '');
    $pw2 = trim($_POST['confirm_password'] ?? '');

    if (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE users SET password = ?, is_temp_password = 0, account_status = 'active' WHERE id = ?");
        $upd->execute([password_hash($pw1, PASSWORD_DEFAULT), $verification['user_id']]);

        $mark = $pdo->prepare("UPDATE account_verifications SET used_at = NOW() WHERE id = ?");
        $mark->execute([$verification['id']]);
        $pdo->commit();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activate Your Account — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --danger:#ef4444; --green:#10b981; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:32px;width:360px;}
h1{font-size:1.2rem;margin:0 0 6px;}
p.sub{color:var(--muted);font-size:0.85rem;margin:0 0 20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin-bottom:6px;}
input{width:100%;padding:10px 12px;margin-bottom:16px;border-radius:6px;border:1px solid var(--border);background:var(--panel);color:var(--text);}
button{width:100%;padding:10px;border:none;border-radius:6px;background:var(--cyan);color:#04121a;font-weight:700;cursor:pointer;}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);padding:10px 14px;border-radius:8px;font-size:0.85rem;}
a{color:var(--cyan);}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="card">
    <h1>Activate Your Account</h1>
    <?php if ($success): ?>
        <p class="sub">Your password is set.</p>
        <div class="alert-success">All set — <a href="login.php">log in here</a>.</div>
    <?php elseif ($error): ?>
        <p class="sub"><?= htmlspecialchars($verification['username'] ?? '', ENT_QUOTES) ?></p>
        <div class="alert-danger"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php else: ?>
        <p class="sub">Welcome, <?= htmlspecialchars($verification['username'], ENT_QUOTES) ?> — set a password to finish activating your <?= htmlspecialchars($verification['role'], ENT_QUOTES) ?> account.</p>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <label>New Password</label>
            <input type="password" name="new_password" minlength="8" required>
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" minlength="8" required>
            <button type="submit">Set Password &amp; Activate</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
