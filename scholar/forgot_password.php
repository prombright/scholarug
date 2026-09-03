<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — FORGOT PASSWORD (email OTP)
|--------------------------------------------------------------------------
| Same two-step OTP flow as every other ABNsystems app's
| forgot_password.php -- see bulksms/forgot_password.php for the fuller
| write-up. See _setup/password_reset_otp.sql and mail/Mailer.php.
|
| Scoped to the `users` table only (teacher/student/parent/dos/staff
| logins) -- school_admin's School Code + Access PIN is a completely
| different credential (schools.access_pin, no email involved) and isn't
| covered here; the page says so rather than silently doing nothing for
| that case.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';

$error = '';
$success = '';
$show_confirm = false;
$reset_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    $reset_email = trim($_POST['email'] ?? '');
    $show_confirm = true;

    if ($reset_email !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$reset_email]);
        $user = $stmt->fetch();

        if ($user) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $pdo->prepare("UPDATE users SET password_reset_otp = ?, otp_expires_at = ? WHERE id = ?")
                ->execute([$otp, $expires, $user['id']]);

            abn_send_email(
                $reset_email,
                'Your Scholar password reset code',
                '<p>Your password reset code is:</p><p style="font-size:28px;font-weight:700;letter-spacing:4px;">' . $otp . '</p><p>This code expires in 15 minutes. If you did not request this, you can ignore this email.</p>'
            );
        }
    }

    $success = 'If that email is on file, a reset code has been sent. Enter it below.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $reset_email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    // Trimmed for the same reason login.php trims the entered password
    // before verifying -- a stray leading/trailing space here (mobile
    // autocorrect, a password manager, copy-paste) would otherwise get
    // hashed as part of the password, and every future login attempt with
    // the "same" (visually trimmed) password would fail against it.
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $show_confirm = true;

    if (strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id, password_reset_otp, otp_expires_at FROM users WHERE email = ?");
        $stmt->execute([$reset_email]);
        $user = $stmt->fetch();

        if (!$user || $user['password_reset_otp'] === null || $user['password_reset_otp'] !== $otp || strtotime($user['otp_expires_at']) < time()) {
            $error = 'That code is invalid or has expired.';
        } else {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ?, password_reset_otp = NULL, otp_expires_at = NULL, is_temp_password = 0 WHERE id = ?")
                ->execute([$hash, $user['id']]);
            header('Location: login.php?reset=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Scholar | Reset Password</title>
    <style>
        :root { --login-bg: #0e1117; --login-card: #0b0d12; --login-border: #1e293b; --login-input: #12151c; --login-text: #e2e8f0; --login-muted: #64748b; --login-input-text: #fff; }
        :root[data-theme="light"] { --login-bg: #F1F5F9; --login-card: #FFFFFF; --login-border: rgba(15,23,42,.12); --login-input: #F8FAFC; --login-text: #1E293B; --login-muted: #64748B; --login-input-text: #1E293B; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--login-bg); color: var(--login-text); margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; box-sizing: border-box; }
        .login-card { background: var(--login-card); border: 1px solid var(--login-border); border-radius: 12px; padding: 40px; width: 100%; max-width: 400px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4); }
        .form-control { width: 100%; background: var(--login-input); border: 1px solid var(--login-border); padding: 12px 14px; border-radius: 6px; color: var(--login-input-text); font-size: 0.875rem; box-sizing: border-box; transition: border-color 0.15s; }
        .form-control:focus { outline: none; border-color: #00A8A8; }
        .btn-access { width: 100%; background: #00A8A8; color: #04222a; border: none; padding: 14px; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; letter-spacing: 0.5px; margin-top: 10px; }
        .hint { color: var(--login-muted); font-size: 0.7rem; text-align: center; margin-top: 18px; line-height: 1.5; }
        .login-logo { font-size: 28px; font-weight: 800; }
        .login-logo span { color: #00A8A8; }
        @media (max-width: 480px) {
            body { padding: 16px; }
            .login-card { padding: 28px 22px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>

    <div class="login-card">
        <div style="text-align: center; margin-bottom: 30px;">
            <div class="login-logo">Scholar<span>Ug</span></div>
            <div style="color: #64748b; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 5px;">Reset Password</div>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.2); border-left: 4px solid #ef4444; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #fca5a5; margin-bottom: 20px;">
                &#9888; <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background: rgba(0,168,168, 0.05); border: 1px solid rgba(0,168,168, 0.2); border-left: 4px solid #00A8A8; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #67e8f9; margin-bottom: 20px;">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!$show_confirm): ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="request_reset" value="1">
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.65rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px;">Email</label>
                    <input type="email" name="email" required class="form-control" autofocus>
                </div>
                <button type="submit" class="btn-access">Send Reset Code</button>
            </form>
        <?php else: ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="confirm_reset" value="1">
                <input type="hidden" name="email" value="<?= htmlspecialchars($reset_email, ENT_QUOTES, 'UTF-8') ?>">
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.65rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px;">Reset Code</label>
                    <input type="text" name="otp" required class="form-control" autofocus>
                </div>
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.65rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px;">New Password</label>
                    <input type="password" name="new_password" required class="form-control">
                </div>
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 0.65rem; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px;">Confirm New Password</label>
                    <input type="password" name="confirm_password" required class="form-control">
                </div>
                <button type="submit" class="btn-access">Update Password</button>
            </form>
        <?php endif; ?>

        <div class="hint">
            <a href="login.php" style="color: var(--login-muted);">&larr; Back to login</a><br><br>
            School admins logging in with a School Code + Access PIN should contact support for PIN resets instead.
        </div>
    </div>

</body>
</html>
