<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

require_once '../db.php';
require_once __DIR__ . '/../auth_guard.php'; // for csrf_token()/require_csrf()/login_is_locked_out()/login_record_attempt()
require_once __DIR__ . '/../_password_toggle.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$error = '';

/*
|--------------------------------------------------------------------------
| IF ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION['role']) &&
    $_SESSION['role'] === 'developer'
) {
    header("Location: developer_dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {

        $error = "Please enter your username and password.";

    } else {

        try {

            // Never a student, and there's no self-service reset flow to
            // worry about here -- unlike login.php's own lockout, this one
            // has no exception.
            if (login_is_locked_out($pdo, $username)) {

                $error = "Too many failed attempts. Please try again in 15 minutes.";

            } else {

            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE
                    (username = ? OR email = ?)
                AND
                    role='developer'
                LIMIT 1
            ");

            $stmt->execute([
                $username,
                $username
            ]);

            $developer = $stmt->fetch(PDO::FETCH_ASSOC);

            $password_ok = $developer && password_verify($password, $developer['password']);
            $password_ok_legacy_plaintext = $developer && !$password_ok && $password === $developer['password'];

            if ($developer && ($password_ok || $password_ok_legacy_plaintext)) {

                // Self-heals the same way the main app's login.php does --
                // a plaintext match immediately rehashes to bcrypt.
                if ($password_ok_legacy_plaintext) {
                    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_BCRYPT), $developer['id']]);
                }
                login_record_attempt($pdo, $username, true);

                session_regenerate_id(true);

                $_SESSION['user_id']  = $developer['id'];
                $_SESSION['username'] = $developer['username'];
                $_SESSION['role']     = 'developer';

                header("Location: developer_dashboard.php");
                exit;

            } else {

                login_record_attempt($pdo, $username, false);
                $error = "Invalid developer credentials.";

            }

            }

        } catch (Throwable $e) {

            // Same rule as the main app's login.php/db.php -- never echo a
            // raw exception to a pre-auth visitor.
            if (defined('SCHOLAR_ENV') && SCHOLAR_ENV === 'production') {
                error_log('Scholar developer login error: ' . $e->getMessage());
                $error = "System error. Please try again shortly.";
            } else {
                $error = $e->getMessage();
            }

        }

    }

}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0">

<title>
Developer Portal | ScholarUg
</title>

<style>


:root {
    --bg: #080b11;
    --panel: #0d1118;
    --border: #1e293b;
    --text: #e2e8f0;
    --muted: #64748b;
}

*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{

background:var(--bg);

font-family:
Inter,
Segoe UI,
sans-serif;

display:flex;

justify-content:center;

align-items:center;

height:100vh;

color:var(--text);

}

.card{

width:420px;

background:var(--panel);

border:1px solid var(--border);

border-radius:16px;

padding:40px;

box-shadow:
0 25px 50px rgba(0,0,0,.45);

}

.logo{

text-align:center;

font-size:1.05rem;

font-weight:700;

letter-spacing:1.5px;

text-transform:uppercase;

color:var(--text);

margin-bottom:6px;

}

.logo span{

color:#06b6d4;

}

h1{

text-align:center;

margin-bottom:8px;

font-size:1.5rem;

}

.subtitle{

text-align:center;

color:var(--muted);

margin-bottom:30px;

font-size:.85rem;

}

label{

display:block;

margin-bottom:8px;

font-size:.75rem;

color:var(--muted);

text-transform:uppercase;

font-weight:bold;

}

input{

width:100%;

padding:14px;

margin-bottom:20px;

background:var(--bg);

border:1px solid var(--border);

border-radius:8px;

color:var(--text);

font-size:.9rem;

}

input:focus{

outline:none;

border-color:#06b6d4;

}

.pw-wrap{ position:relative; }
.pw-wrap input{ padding-right:42px; margin-bottom:0; }
.pw-toggle-btn{ position:absolute; top:0; bottom:0; right:6px; margin:auto; height:18px; background:none; border:none; cursor:pointer; padding:6px; display:flex; align-items:center; color:var(--muted); }
.pw-toggle-btn:hover{ color:#06b6d4; }
.pw-toggle-btn svg{ width:18px; height:18px; }
.pw-wrap-margin{ margin-bottom:20px; }

button{

width:100%;

padding:15px;

border:none;

border-radius:8px;

background:#06b6d4;

color:white;

font-size:.9rem;

font-weight:700;

cursor:pointer;

transition:.3s;

}

button:hover{

background:#0891b2;

}

.error{

background:
rgba(239,68,68,.1);

border:
1px solid rgba(239,68,68,.35);

color:#f87171;

padding:12px;

border-radius:8px;

margin-bottom:20px;

font-size:.85rem;

}

.footer{

margin-top:25px;

text-align:center;

color:var(--muted);

font-size:.75rem;

}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="card">

<div class="logo">
Scholar<span>Ug</span>
</div>

<h1>
Developer Portal
</h1>

<div class="subtitle">

Platform Administration

</div>

<?php if($error): ?>

<div class="error">

<?=htmlspecialchars($error)?>

</div>

<?php endif; ?>

<form method="POST">

<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

<label>

Username / Email

</label>

<input
type="text"
name="username"
required>

<label>

Password

</label>

<div class="pw-wrap pw-wrap-margin">
<input
type="password"
name="password"
id="devLoginPasswordField"
required>
<button type="button" class="pw-toggle-btn" onclick="scholarTogglePassword('devLoginPasswordField', this)" aria-label="Show password"><?= SCHOLAR_EYE_SVG ?></button>
</div>

<button type="submit">

Login

</button>

</form>

<div class="footer">

ScholarUg © <?=date('Y')?>

</div>

</div>

<script><?= SCHOLAR_PASSWORD_TOGGLE_JS ?></script>
</body>

</html>