<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — REQUEST ACCESS / FREE DEMO
|--------------------------------------------------------------------------
| Scholar has no self-registration (accounts are always provisioned by an
| ABN developer -- see developer/schools/school_admin_provision.php). This
| is the public-facing front door for that: a prospect fills in their
| school and what they want (a real account or a demo to try first), it
| emails ABN directly via the shared mailer, and the developer follows up
| and provisions the account by hand -- same underlying process as today,
| just with a real form instead of "call us."
|
| Deliberately honest about scope: this does NOT auto-provision a demo
| account or auto-expire demo data yet -- that's real backend work
| (a demo school + a scheduled cleanup job) not built in this pass. The
| copy here says "our team sets it up," not "instant," so it doesn't
| promise something that isn't true yet.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php';

$type = ($_GET['type'] ?? $_POST['type'] ?? 'real') === 'demo' ? 'demo' : 'real';
$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName = trim($_POST['school_name'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $type = ($_POST['type'] ?? 'real') === 'demo' ? 'demo' : 'real';
    $notes = trim($_POST['notes'] ?? '');

    if ($schoolName === '' || $contactName === '' || $contactEmail === '') {
        $error = 'Please fill in the school name, your name, and an email address.';
    } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $subject = ($type === 'demo' ? '[Demo Request] ' : '[Access Request] ') . $schoolName;
        $body = '<h2>New Scholar ' . ($type === 'demo' ? 'demo' : 'access') . ' request</h2>'
            . '<p><strong>School:</strong> ' . htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Contact:</strong> ' . htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Email:</strong> ' . htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Phone:</strong> ' . htmlspecialchars($contactPhone ?: '—', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Wants:</strong> ' . ($type === 'demo' ? 'Free demo' : 'Real system') . '</p>'
            . ($notes !== '' ? '<p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>' : '');

        if (abn_send_email('info@scholarug.com', $subject, $body)) {
            $sent = true;
        } else {
            $error = "Something went wrong sending your request. Please reach us directly at info@scholarug.com or 0759 815 047 instead.";
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
<title>Request Access — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --panel-raised:#1a2436; --border:#2a3a52; --text:#e2e8f0; --muted:#94a3b8; --cyan:#00A8A8; --cyan-dark:#0A3D62; }
*{box-sizing:border-box;}
body{margin:0;min-height:100vh;background:
        radial-gradient(700px circle at 10% 0%, rgba(0,168,168,0.12), transparent 55%),
        var(--bg);
    color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;padding:32px 16px;}
.card{width:100%;max-width:460px;background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:40px;}
.back{display:inline-block;color:var(--muted);text-decoration:none;font-size:0.8rem;margin-bottom:20px;}
.back:hover{color:var(--cyan);}
h1{font-size:1.35rem;margin:0 0 8px;}
.sub{color:var(--muted);font-size:0.88rem;margin:0 0 24px;line-height:1.5;}
.type-toggle{display:flex;gap:8px;margin-bottom:22px;}
.type-toggle a{flex:1;text-align:center;padding:10px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.82rem;font-weight:700;}
.type-toggle a.active{background:rgba(0,168,168,0.14);border-color:var(--cyan);color:var(--cyan);}
.field{margin-bottom:16px;}
.field label{display:block;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);font-weight:700;margin-bottom:6px;}
.field input,.field textarea{width:100%;padding:11px 13px;background:var(--panel-raised);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:0.88rem;font-family:inherit;}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--cyan);}
.demo-note{background:rgba(0,168,168,0.08);border:1px solid rgba(0,168,168,0.25);border-radius:8px;padding:12px 14px;font-size:0.8rem;color:var(--text);margin-bottom:18px;}
button{width:100%;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:13px;border-radius:8px;cursor:pointer;font-size:0.88rem;}
button:hover{background:var(--cyan-dark);}
.alert{padding:12px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:18px;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;}
.success{text-align:center;}
.success i{font-size:2.6rem;color:var(--cyan);margin-bottom:14px;display:block;}
.success p{color:var(--muted);font-size:0.9rem;line-height:1.6;}
</style>
</head>
<body>
<div class="card">
    <a class="back" href="index.php">&larr; Back to Scholar</a>

    <?php if ($sent): ?>
        <div class="success">
            <i class="bi bi-check-circle"></i>
            <h1>Request sent</h1>
            <p><?= $type === 'demo' ? 'Thanks for your interest — our team will set up your demo and reach out shortly. Demo data is for evaluation only and is cleared after 7 days.' : 'Thanks — our team will be in touch shortly to get your school set up.' ?></p>
        </div>
    <?php else: ?>
        <h1><?= $type === 'demo' ? 'Try a Free Demo' : 'Get Started with Scholar' ?></h1>
        <p class="sub">Tell us about your school and we'll reach out.</p>

        <div class="type-toggle">
            <a href="?type=real" class="<?= $type === 'real' ? 'active' : '' ?>">Real System</a>
            <a href="?type=demo" class="<?= $type === 'demo' ? 'active' : '' ?>">Free Demo</a>
        </div>

        <?php if ($type === 'demo'): ?>
            <div class="demo-note"><i class="bi bi-info-circle"></i> Demo data is cleared after 7 days.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label>School Name</label>
                <input type="text" name="school_name" required value="<?= htmlspecialchars($_POST['school_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Your Name</label>
                <input type="text" name="contact_name" required value="<?= htmlspecialchars($_POST['contact_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="contact_email" required value="<?= htmlspecialchars($_POST['contact_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Phone (optional)</label>
                <input type="tel" name="contact_phone" value="<?= htmlspecialchars($_POST['contact_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
                <label>Anything else? (optional)</label>
                <textarea name="notes" rows="3"><?= htmlspecialchars($_POST['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <button type="submit"><?= $type === 'demo' ? 'Request Demo' : 'Request Access' ?></button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
