<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SCHOOL ADMIN: MANAGE PARENT ACCOUNTS
|--------------------------------------------------------------------------
| Creates a login (role='parent') and links it to one or more students via
| parent_students. Without this page, the parent role has no way to exist.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_manage_parents_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_parent'])) {
    $result = admin_parents_create(
        $pdo, $school_id,
        trim($_POST['full_name'] ?? ''),
        trim($_POST['username'] ?? ''),
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? '') ?: null,
        array_map('intval', $_POST['student_ids'] ?? [])
    );
    if ($result['ok']) { $success = $result['message']; } else { $error = $result['message']; }
}

$students = admin_parents_fetch_students($pdo, $school_id);
$parents = admin_parents_fetch_list($pdo, $school_id);
$ACTIVE_NAV = 'parents';
require_once __DIR__ . '/../_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
<style>
:root{ --panel:#131b28; --border:#2a3a52; --muted:#64748b; --green:#10b981; --danger:#ef4444; }
.container{max-width:900px;margin:auto;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;}
.picker-head{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.picker-head .count{font-size:0.75rem;color:var(--cyan);white-space:nowrap;}
.student-picker{margin-top:8px;max-height:280px;overflow-y:auto;background:var(--panel);border:1px solid var(--border);border-radius:6px;}
.student-picker .class-group-label{position:sticky;top:0;background:#0b0f16;color:var(--muted);font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;padding:6px 12px;border-bottom:1px solid var(--border);}
.student-row{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;font-size:0.85rem;border-bottom:1px solid rgba(42,58,82,0.4);}
.student-row:hover{background:rgba(0,168,168,0.06);}
.student-row input{width:auto;accent-color:var(--cyan);}
.student-row .cls{margin-left:auto;color:var(--muted);font-size:0.75rem;}
.student-row.hidden,.class-group-label.hidden{display:none;}
.student-picker .no-match{padding:16px;text-align:center;color:var(--muted);font-size:0.82rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
</style>
<div class="container">
    <h1 style="font-size:1.4rem;">Manage Parent Accounts</h1>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="section">
        <h2 style="font-size:1rem;margin:0;">Create Parent Account</h2>
        <form method="post">
            <label>Parent Full Name</label>
            <input type="text" name="full_name" required>
            <label>Username (parent will log in with this)</label>
            <input type="text" name="username" required>
            <label>Phone</label>
            <input type="text" name="phone">
            <label>Email (optional)</label>
            <input type="email" name="email">
            <div class="picker-head">
                <label style="margin:12px 0 0;">Link to Child(ren)</label>
                <span class="count" id="pickerCount">0 selected</span>
            </div>
            <input type="text" id="studentSearch" placeholder="Search by student name or class...">
            <div class="student-picker" id="studentPicker">
                <?php
                $current_class = null;
                foreach ($students as $s):
                    $class_label = $s['class_name'] ?: 'No Class';
                    if ($class_label !== $current_class):
                        $current_class = $class_label;
                ?>
                    <div class="class-group-label"><?= htmlspecialchars($class_label, ENT_QUOTES) ?></div>
                <?php endif; ?>
                    <label class="student-row" data-search="<?= htmlspecialchars(strtolower($s['full_name'] . ' ' . $class_label), ENT_QUOTES) ?>">
                        <input type="checkbox" name="student_ids[]" value="<?= (int)$s['id'] ?>">
                        <span><?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?></span>
                        <span class="cls"><?= htmlspecialchars($class_label, ENT_QUOTES) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                    <div class="no-match">No students enrolled yet.</div>
                <?php endif; ?>
            </div>
            <button type="submit" name="create_parent" value="1">Create Account</button>
            <script>
            (function () {
                var search = document.getElementById('studentSearch');
                var picker = document.getElementById('studentPicker');
                var rows = picker.querySelectorAll('.student-row');
                var groups = picker.querySelectorAll('.class-group-label');
                var count = document.getElementById('pickerCount');

                function updateCount() {
                    var n = picker.querySelectorAll('input[type="checkbox"]:checked').length;
                    count.textContent = n + ' selected';
                }
                picker.addEventListener('change', updateCount);

                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    rows.forEach(function (row) {
                        row.classList.toggle('hidden', q !== '' && row.dataset.search.indexOf(q) === -1);
                    });
                    groups.forEach(function (g) {
                        var next = g.nextElementSibling;
                        var anyVisible = false;
                        while (next && next.classList && next.classList.contains('student-row')) {
                            if (!next.classList.contains('hidden')) { anyVisible = true; }
                            next = next.nextElementSibling;
                        }
                        g.classList.toggle('hidden', !anyVisible);
                    });
                });
            })();
            </script>
        </form>
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 14px;">Existing Parent Accounts</h2>
        <table>
            <tr><th>Username</th><th>Phone</th><th>Linked Children</th></tr>
            <?php foreach ($parents as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['username'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($p['phone_number'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($p['children'] ?? '—', ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>
