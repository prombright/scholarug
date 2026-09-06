<?php
declare(strict_types=1);

session_start();

require_once '../db.php';

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

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {

        $error = "Please enter your username and password.";

    } else {

        try {

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

            if (
                $developer &&
                (
                    password_verify($password, $developer['password']) ||
                    $password === $developer['password']
                )
            ) {

                session_regenerate_id(true);

                $_SESSION['user_id']  = $developer['id'];
                $_SESSION['username'] = $developer['username'];
                $_SESSION['role']     = 'developer';

                header("Location: developer_dashboard.php");
                exit;

            } else {

                $error = "Invalid developer credentials.";

            }

        } catch (Throwable $e) {

            $error = $e->getMessage();

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

<input
type="password"
name="password"
required>

<button type="submit">

Login

</button>

</form>

<div class="footer">

ScholarUg © <?=date('Y')?>

</div>

</div>

</body>

</html>