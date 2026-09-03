<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SCHOOL ADMIN: MESSAGE PARENTS
|--------------------------------------------------------------------------
| Sends a bulk SMS to parents of one class, or the whole school, through
| the ABN Bulk SMS service API (bulksms_client.php). Billed against
| Scholar's own Bulk SMS wallet — see bulksms/admin/accounts.php to top it
| up (same wallet used for every school on this Scholar instance, since
| Scholar has one Bulk SMS service account, not one per school).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../bulksms_client.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';
$success = '';

// Sourced from classes (school_id-scoped, real class_id) instead of the
// denormalized, sometimes-inconsistent students.class_name -- previously
// this filter surfaced "S.1"/"S1"/"Senior 1" as three separate options
// for what should be one class, and matched by exact string equality, so
// messaging one spelling silently dropped parents under another.
$classesStmt = $pdo->prepare(
    "SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name"
);
$classesStmt->execute([$school_id]);
$classesList = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    $target = $_POST['target'] ?? 'class';
    $classId = (int) ($_POST['class_id'] ?? 0);

    if ($message === '') {
        $error = 'Please write a message.';
    } elseif ($target === 'class' && $classId <= 0) {
        $error = 'Choose a class, or switch to "Whole school".';
    } else {
        if ($target === 'class') {
            $phoneStmt = $pdo->prepare("
                SELECT DISTINCT u.phone_number
                FROM users u
                JOIN parent_students ps ON ps.user_id = u.id
                JOIN students s ON s.id = ps.student_id
                WHERE u.school_id = ? AND u.role = 'parent' AND s.class_id = ?
                      AND u.phone_number IS NOT NULL AND u.phone_number <> ''
            ");
            $phoneStmt->execute([$school_id, $classId]);
        } else {
            $phoneStmt = $pdo->prepare("
                SELECT DISTINCT phone_number
                FROM users
                WHERE school_id = ? AND role = 'parent'
                      AND phone_number IS NOT NULL AND phone_number <> ''
            ");
            $phoneStmt->execute([$school_id]);
        }

        $phones = array_column($phoneStmt->fetchAll(), 'phone_number');

        if (empty($phones)) {
            $error = 'No parent phone numbers found for that selection.';
        } else {
            try {
                $result = BulkSmsClient::send($message, $phones);
                $success = "Sent to {$result['delivered']} parent(s)"
                    . ($result['failed'] > 0 ? ", {$result['failed']} failed" : '')
                    . ". Debited {$result['currency']} " . number_format($result['total_cost'], 2) . " from Scholar's Bulk SMS wallet.";
            } catch (Throwable $e) {
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
<title>Message Parents</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:700px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.4rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select,textarea{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
textarea{resize:vertical;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.radio-row{display:flex;gap:20px;align-items:center;margin-top:12px;}
.radio-row label{display:flex;align-items:center;gap:6px;margin:0;color:var(--text);font-size:0.9rem;}
.radio-row input{width:auto;}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<div class="container">
    <div class="header">
        <h1>Message Parents</h1>
        <a href="school_admin_dashboard.php" class="btn-link">&larr; Back</a>
    </div>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="section">
        <form method="post" id="messageParentsForm">
            <label>Send to</label>
            <div class="radio-row">
                <label><input type="radio" name="target" value="class" checked> A class</label>
                <label><input type="radio" name="target" value="school"> Whole school</label>
            </div>

            <div id="classPicker">
                <label>Class</label>
                <select name="class_id">
                    <?php foreach ($classesList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : ''), ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label>Message</label>
            <textarea name="message" rows="5" required placeholder="Type your message to parents..."></textarea>

            <button type="submit">Send SMS</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('input[name="target"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        document.getElementById('classPicker').style.display = this.value === 'class' ? 'block' : 'none';
    });
});
</script>
</body>
</html>
