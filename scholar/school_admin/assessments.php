<?php
// assessments.php — school_admin: create & manage assessments
// "The Admin sets the assessments that appear on the report, and can
// choose which ones (of possibly many) count toward it."

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Flexible Path Resolution for Config & Database Connections (matches the
// other school_admin/*.php files in this folder)
$base_dir = __DIR__;

if (file_exists($base_dir . '/config.php')) {
    require_once $base_dir . '/config.php';
} elseif (file_exists($base_dir . '/../config.php')) {
    require_once $base_dir . '/../config.php';
}

if (file_exists($base_dir . '/db.php')) {
    require_once $base_dir . '/db.php';
} elseif (file_exists($base_dir . '/../db.php')) {
    require_once $base_dir . '/../db.php';
}

if (file_exists($base_dir . '/auth_guard.php')) {
    require_once $base_dir . '/auth_guard.php';
} elseif (file_exists($base_dir . '/../auth_guard.php')) {
    require_once $base_dir . '/../auth_guard.php';
}

// DOS can create assessments too (previously school_admin-only) -- a
// bare $_SESSION['role'] check bypassed the shared require_role() helper
// every other page in this folder uses.
require_role(['school_admin', 'dos']);

$ACTIVE_NAV = 'assessments';

if (file_exists($base_dir . '/_admin_shell.php')) {
    require_once $base_dir . '/_admin_shell.php';
} elseif (file_exists($base_dir . '/../_admin_shell.php')) {
    require_once $base_dir . '/../_admin_shell.php';
}

if (!function_exists('safe_text')) {
    function safe_text($value): string {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

require_once $base_dir . '/_assessments_helpers.php';

$school_id = current_school_id();

$message = '';
$message_type = '';

// ---------------------------------------------------------------------
// Create a new assessment
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_assessment') {
    $result = admin_assessments_create(
        $pdo, $school_id,
        trim($_POST['title'] ?? ''),
        (float) ($_POST['weight_percentage'] ?? 0),
        $_POST['term'] ?? '',
        (int) ($_POST['year'] ?? date('Y')),
        $_POST['status'] ?? 'Draft',
        isset($_POST['include_in_report'])
    );
    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'danger';
}

// ---------------------------------------------------------------------
// Update an existing assessment's status / report-inclusion / weight
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_assessment') {
    $result = admin_assessments_update(
        $pdo, $school_id,
        (int) ($_POST['id'] ?? 0),
        (float) ($_POST['weight_percentage'] ?? 0),
        $_POST['status'] ?? 'Draft',
        isset($_POST['include_in_report'])
    );
    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'danger';
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$__data = admin_assessments_fetch_list($pdo, $school_id);
$assessments = $__data['assessments'];
$weight_used_by_term = $__data['weight_used_by_term'];

$current_year = (int) date('Y');
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.danger{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.back-link{color:var(--muted);text-decoration:none;font-size:0.85rem;border:1px solid var(--border);padding:8px 14px;border-radius:6px;}
.back-link:hover{color:var(--cyan);border-color:var(--cyan);}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;}
input:focus,select:focus{outline:none;border-color:var(--cyan);}
.new-form-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1.4fr auto;gap:14px;align-items:end;}
@media (max-width:900px){.new-form-row{grid-template-columns:1fr 1fr;}}
.check-row{display:flex;align-items:center;gap:8px;padding-bottom:9px;}
.check-row input{width:auto;}
.check-row label{margin:0;text-transform:none;font-size:0.82rem;color:var(--text);letter-spacing:0;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;white-space:nowrap;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
td select,td input[type=number]{padding:7px 8px;font-size:0.82rem;}
td.paper-cell{max-width:110px;}
.status-cell{max-width:150px;}
.pill{display:inline-block;font-size:0.68rem;padding:3px 9px;border-radius:20px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;}
.pill.Draft{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.Open{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.Closed{background:rgba(239,68,68,0.1);color:var(--danger);}
.weight-note{color:var(--muted);font-size:0.78rem;margin-top:14px;line-height:1.6;}
.weight-note strong{color:var(--text);}
.weight-warn{color:var(--amber, #f59e0b);}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
.save-btn{background:transparent;color:var(--cyan);border:1px solid rgba(0,168,168,0.4);padding:7px 14px;font-size:0.78rem;}
</style>
<main class="main-content">
<div class="page-inner">

    <div class="page-head">
        <h1 style="font-size:1.4rem;">Assessments</h1>
        <a href="<?= $_SESSION['role'] === 'dos' ? '../dos_dashboard.php' : 'school_admin_dashboard.php' ?>" class="back-link">&larr; Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= safe_text($message_type === 'danger' ? 'danger' : 'success') ?>"><?= safe_text($message) ?></div>
    <?php endif; ?>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 16px;">New Assessment</h2>
        <form method="POST" action="assessments.php">
            <input type="hidden" name="action" value="create_assessment">
            <div class="new-form-row">
                <div>
                    <label>Title</label>
                    <input type="text" name="title" placeholder="e.g. Midterm Test" required>
                </div>
                <div>
                    <label>Weight %</label>
                    <input type="number" step="0.01" min="0.01" max="100" name="weight_percentage" id="new_weight" required oninput="scholarUpdateWeightHint()">
                </div>
                <div>
                    <label>Term</label>
                    <select name="term" id="new_term" required onchange="scholarUpdateWeightHint()">
                        <option value="Term 1">Term 1</option>
                        <option value="Term 2">Term 2</option>
                        <option value="Term 3">Term 3</option>
                    </select>
                </div>
                <div>
                    <label>Year</label>
                    <input type="number" name="year" id="new_year" value="<?= $current_year ?>" required oninput="scholarUpdateWeightHint()">
                </div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="Draft">Draft</option>
                        <option value="Open" selected>Open (teachers can enter marks)</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                <div class="check-row">
                    <input type="checkbox" name="include_in_report" id="include_new" checked onchange="scholarUpdateWeightHint()">
                    <label for="include_new">On report</label>
                </div>
            </div>
            <div id="weight_hint" class="weight-note" style="margin-top:10px;"></div>
            <div style="margin-top:6px;">
                <button type="submit">Create Assessment</button>
            </div>
        </form>
    </div>

    <script>
    var scholarWeightUsedByTerm = <?= json_encode($weight_used_by_term, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    function scholarUpdateWeightHint() {
        var hint = document.getElementById('weight_hint');
        var included = document.getElementById('include_new').checked;
        var term = document.getElementById('new_term').value.trim();
        var year = document.getElementById('new_year').value.trim();
        var weight = parseFloat(document.getElementById('new_weight').value) || 0;
        if (!included) {
            hint.textContent = 'Not counted on the report, so it has no effect on the 100% cap.';
            hint.className = 'weight-note';
            return;
        }
        if (!term || !year) { hint.textContent = ''; return; }
        var used = scholarWeightUsedByTerm[term + '|' + year] || 0;
        var total = used + weight;
        var remaining = Math.max(0, Math.round((100 - used) * 100) / 100);
        if (total > 100.001) {
            hint.innerHTML = term + ' ' + year + ' already has ' + used + '% allocated -- this would push it to ' + Math.round(total * 100) / 100 + '%, over the 100% cap. Only <strong>' + remaining + '%</strong> is available.';
            hint.className = 'weight-note weight-warn';
        } else {
            hint.innerHTML = term + ' ' + year + ': ' + used + '% already allocated, ' + remaining + '% remaining before adding this one.';
            hint.className = 'weight-note';
        }
    }
    scholarUpdateWeightHint();
    </script>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 16px;">Existing Assessments</h2>
        <table>
            <thead>
                <tr>
                    <th>Title</th><th>Term / Year</th><th>Weight %</th><th>Status</th><th>On Report?</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($assessments)): ?>
                <tr><td colspan="6" class="empty">No assessments yet — create one above.</td></tr>
            <?php else: foreach ($assessments as $a): ?>
                <?php $form_id = 'assess_form_' . (int) $a['id']; ?>
                <tr>
                    <form id="<?= $form_id ?>" method="POST" action="assessments.php">
                        <input type="hidden" name="action" value="update_assessment">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    </form>
                    <td><strong><?= safe_text($a['title']) ?></strong></td>
                    <td><?= safe_text($a['term']) ?> / <?= safe_text($a['year']) ?></td>
                    <td class="paper-cell">
                        <input type="number" step="0.01" min="0.01" max="100" name="weight_percentage" value="<?= safe_text($a['weight_percentage']) ?>" form="<?= $form_id ?>">
                    </td>
                    <td class="status-cell">
                        <span class="pill <?= safe_text($a['status']) ?>" style="margin-bottom:6px;display:block;width:fit-content;"><?= safe_text($a['status']) ?></span>
                        <select name="status" form="<?= $form_id ?>">
                            <?php foreach (['Draft', 'Open', 'Closed'] as $st): ?>
                                <option value="<?= $st ?>" <?= $a['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="include_in_report" form="<?= $form_id ?>" <?= $a['include_in_report'] ? 'checked' : '' ?> style="width:auto;">
                    </td>
                    <td><button type="submit" form="<?= $form_id ?>" class="save-btn">Save</button></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <p class="weight-note" style="margin-top:10px;">
            "On Report" controls whether this assessment's marks count toward
            the weighted grade on the report card. Unchecking it keeps the
            marks recorded but excludes them from the report — useful for
            practice tests or ungraded quizzes. Split the 100% however your
            school actually grades a term — e.g. AOI 10%, Mid-Term 10%,
            Final Exam 80% — across as many report-counted assessments as
            you need; a term's report-counted weights just can't add up to
            more than 100%.
        </p>
    </div>

</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
