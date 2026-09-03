<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — GRADING SCALE SETTINGS
|--------------------------------------------------------------------------
| grading_scales has driven every report card's grade lookup since it was
| introduced, but there was never an admin UI over it -- the bands
| existing today were seeded once by an undocumented one-off script, with
| no way to view or edit them from inside Scholar. This page is that
| missing CRUD screen, plus an optional "Generic Skills" section (a
| competency-based supplement to the percentage-driven grade, per the
| 2026-08 report system overhaul).
|
| IMPORTANT: the "reset to competency-based defaults" band labels/cutoffs
| and the default skills list below are best-effort placeholders, not a
| verified official Uganda NCDC/UNEB standard -- review and adjust them
| to match current guidance before relying on them. Nothing on this page
| changes automatically; every action here is an explicit admin click.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();
$message = '';
$message_type = '';

// ---- Grading bands: create ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_band'])) {
    $grade = trim($_POST['grade'] ?? '');
    $min_mark = is_numeric($_POST['min_mark'] ?? '') ? (float) $_POST['min_mark'] : null;
    $max_mark = is_numeric($_POST['max_mark'] ?? '') ? (float) $_POST['max_mark'] : null;
    $remark = trim($_POST['remark'] ?? '');
    $points = is_numeric($_POST['points'] ?? '') ? (float) $_POST['points'] : null;
    // <input type="color"> always carries a value (browsers default it to
    // black) -- gated behind this checkbox so "no color configured" stays
    // a real, explicit choice instead of silently becoming black.
    $color = trim($_POST['color'] ?? '');
    $color = (!empty($_POST['use_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : null;

    if ($grade === '' || $min_mark === null || $max_mark === null || $min_mark > $max_mark) {
        $message = 'A grade label and a valid min/max range are required.';
        $message_type = 'error';
    } else {
        $ins = $pdo->prepare("INSERT INTO grading_scales (school_id, grade, min_mark, max_mark, remark, points, color) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$school_id, $grade, $min_mark, $max_mark, $remark ?: null, $points, $color]);
        $message = 'Grading band added.';
        $message_type = 'success';
    }
}

// ---- Grading bands: update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_band'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $grade = trim($_POST['grade'] ?? '');
    $min_mark = is_numeric($_POST['min_mark'] ?? '') ? (float) $_POST['min_mark'] : null;
    $max_mark = is_numeric($_POST['max_mark'] ?? '') ? (float) $_POST['max_mark'] : null;
    $remark = trim($_POST['remark'] ?? '');
    $points = is_numeric($_POST['points'] ?? '') ? (float) $_POST['points'] : null;
    $color = trim($_POST['color'] ?? '');
    $color = (!empty($_POST['use_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : null;

    if ($id > 0 && $grade !== '' && $min_mark !== null && $max_mark !== null && $min_mark <= $max_mark) {
        $upd = $pdo->prepare("UPDATE grading_scales SET grade = ?, min_mark = ?, max_mark = ?, remark = ?, points = ?, color = ? WHERE id = ? AND school_id = ?");
        $upd->execute([$grade, $min_mark, $max_mark, $remark ?: null, $points, $color, $id, $school_id]);
        $message = 'Grading band updated.';
        $message_type = 'success';
    } else {
        $message = 'Invalid band values.';
        $message_type = 'error';
    }
}

// ---- Grading bands: delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_band'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM grading_scales WHERE id = ? AND school_id = ?");
    $del->execute([$id, $school_id]);
    $message = 'Grading band deleted.';
    $message_type = 'success';
}

// ---- Grading bands: explicit opt-in reset to competency-based defaults ----
// Best-effort placeholder bands -- see file header. Hard delete+insert,
// same convention as classes.php's class delete; never runs without this
// exact POST + the confirm() dialog on the button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_competency_defaults'])) {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM grading_scales WHERE school_id = ?")->execute([$school_id]);
    $defaults = [
        ['Outstanding', 80, 100, 'Consistently exceeds expectations across assessed competencies.', 4, '#dcfce7'],
        ['Adequate',    60, 79.99, 'Meets expectations for this stage with solid understanding.', 3, '#dbeafe'],
        ['Moderate',    40, 59.99, 'Partially meets expectations; more practice needed.', 2, '#fef3c7'],
        ['Basic',       0,  39.99, 'Beginning to develop the expected competencies.', 1, '#fee2e2'],
    ];
    $ins = $pdo->prepare("INSERT INTO grading_scales (school_id, grade, min_mark, max_mark, remark, points, color) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($defaults as $d) {
        $ins->execute([$school_id, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]);
    }
    $pdo->commit();
    $message = 'Grading scale reset to the competency-based default bands. Review the labels and cutoffs below.';
    $message_type = 'success';
}

// ---- Generic skills: create ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_skill'])) {
    $skill_name = trim($_POST['skill_name'] ?? '');
    if ($skill_name === '') {
        $message = 'Enter a skill name.';
        $message_type = 'error';
    } else {
        $ins = $pdo->prepare("INSERT INTO generic_skills (school_id, skill_name, display_order) VALUES (?, ?, (SELECT n FROM (SELECT COALESCE(MAX(display_order), 0) + 1 AS n FROM generic_skills WHERE school_id = ?) x))");
        $ins->execute([$school_id, $skill_name, $school_id]);
        $message = 'Skill added.';
        $message_type = 'success';
    }
}

// ---- Generic skills: toggle active ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_skill'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $upd = $pdo->prepare("UPDATE generic_skills SET is_active = NOT is_active WHERE id = ? AND school_id = ?");
    $upd->execute([$id, $school_id]);
    $message = 'Skill visibility updated.';
    $message_type = 'success';
}

// ---- Generic skills: delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_skill'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM generic_skills WHERE id = ? AND school_id = ?");
    $del->execute([$id, $school_id]);
    $message = 'Skill deleted (past ratings for it are removed too).';
    $message_type = 'success';
}

// ---- Generic skills: seed defaults ----
// Best-effort placeholder list -- see file header.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_default_skills'])) {
    $defaults = ['Critical Thinking', 'Communication', 'Cooperation', 'Creativity', 'Self-Management'];
    $exists = $pdo->prepare("SELECT id FROM generic_skills WHERE school_id = ? AND skill_name = ?");
    $ins = $pdo->prepare("INSERT INTO generic_skills (school_id, skill_name, display_order) VALUES (?, ?, ?)");
    $order = 1;
    foreach ($defaults as $name) {
        $exists->execute([$school_id, $name]);
        if (!$exists->fetchColumn()) {
            $ins->execute([$school_id, $name, $order]);
        }
        $order++;
    }
    $message = 'Default skills seeded (existing ones left untouched).';
    $message_type = 'success';
}

$bands = $pdo->prepare("SELECT * FROM grading_scales WHERE school_id = ? ORDER BY min_mark DESC");
$bands->execute([$school_id]);
$bands = $bands->fetchAll(PDO::FETCH_ASSOC);

$skills = $pdo->prepare("SELECT * FROM generic_skills WHERE school_id = ? ORDER BY display_order, skill_name");
$skills->execute([$school_id]);
$skills = $skills->fetchAll(PDO::FETCH_ASSOC);

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'grading';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);}
.ghost-btn{background:transparent;color:var(--text);border:1px solid var(--border);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:20px;line-height:1.5;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:120px;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.pill.muted{background:rgba(100,116,139,0.15);color:var(--muted);}
.inline-form{display:inline;}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Report Cards</h1>

    <div class="report-tabs">
        <a href="grading_scales.php" class="active">Grading &amp; Bands</a>
        <a href="report_settings.php">Display Settings</a>
        <a href="bulk_report_print.php">Print Reports</a>
        <a href="remarks.php">Remarks</a>
    </div>

    <?php if ($message): ?><div class="alert <?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="disclaimer">
        <strong>Heads up:</strong> the "reset to competency-based defaults" bands and the
        default skills list below are best-effort placeholders following the general shape of
        Uganda's competency-based curriculum, not a verified official standard. Review the
        labels, cutoffs, and remarks and adjust them to match your school's actual guidance
        before relying on them for real report cards.
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0;">Grading Bands</h2>
        <p class="muted" style="color:var(--muted);font-size:0.85rem;">A student's weighted % score on each subject is matched against these bands to produce the grade/descriptor shown on the report card.</p>

        <table>
            <tr><th>Grade / Descriptor</th><th>Min %</th><th>Max %</th><th>Remark</th><th>Points</th><th>Color</th><th></th></tr>
            <?php if (empty($bands)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">No grading bands configured yet.</td></tr>
            <?php else: foreach ($bands as $b): ?>
                <tr>
                    <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <td style="min-width:130px;"><input type="text" name="grade" value="<?= htmlspecialchars($b['grade'], ENT_QUOTES) ?>" style="margin:0;"></td>
                    <td style="max-width:90px;"><input type="number" step="0.01" name="min_mark" value="<?= htmlspecialchars((string) $b['min_mark'], ENT_QUOTES) ?>" style="margin:0;"></td>
                    <td style="max-width:90px;"><input type="number" step="0.01" name="max_mark" value="<?= htmlspecialchars((string) $b['max_mark'], ENT_QUOTES) ?>" style="margin:0;"></td>
                    <td style="min-width:200px;"><input type="text" name="remark" value="<?= htmlspecialchars($b['remark'] ?? '', ENT_QUOTES) ?>" style="margin:0;"></td>
                    <td style="max-width:80px;"><input type="number" step="0.01" name="points" value="<?= htmlspecialchars((string) ($b['points'] ?? ''), ENT_QUOTES) ?>" style="margin:0;"></td>
                    <td style="white-space:nowrap;">
                        <label style="display:inline-flex;align-items:center;gap:5px;margin:0;font-size:0.75rem;color:var(--muted);">
                            <input type="checkbox" name="use_color" value="1" style="width:auto;" <?= !empty($b['color']) ? 'checked' : '' ?>>
                            <input type="color" name="color" value="<?= htmlspecialchars($b['color'] ?: '#ffffff', ENT_QUOTES) ?>" style="width:36px;height:28px;padding:2px;margin:0;">
                        </label>
                    </td>
                    <td style="white-space:nowrap;">
                        <button type="submit" name="update_band" value="1" class="ghost-btn" style="margin:0;padding:6px 12px;font-size:0.78rem;">Save</button>
                    </td>
                    </form>
                    <td style="white-space:nowrap;">
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this grading band?');">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button type="submit" name="delete_band" value="1" class="danger-btn" style="margin:0;padding:6px 12px;font-size:0.78rem;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </table>

        <div class="row" style="margin-top:20px;">
            <form method="post" class="row" style="flex:3;">
                <div>
                    <label>Grade / Descriptor</label>
                    <input type="text" name="grade" placeholder="e.g. A or Outstanding" required>
                </div>
                <div>
                    <label>Min %</label>
                    <input type="number" step="0.01" name="min_mark" required>
                </div>
                <div>
                    <label>Max %</label>
                    <input type="number" step="0.01" name="max_mark" required>
                </div>
                <div>
                    <label>Remark</label>
                    <input type="text" name="remark" placeholder="Shown on the report card">
                </div>
                <div>
                    <label>Points (optional)</label>
                    <input type="number" step="0.01" name="points">
                </div>
                <div style="flex:0 0 auto;">
                    <label style="display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" name="use_color" value="1" style="width:auto;margin:0;"> Color
                    </label>
                    <input type="color" name="color" value="#ffffff" style="width:60px;height:38px;padding:2px;">
                </div>
                <div style="flex:0 0 auto;align-self:flex-end;">
                    <button type="submit" name="create_band" value="1">Add Band</button>
                </div>
            </form>
        </div>

        <form method="post" style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;" onsubmit="return confirm('This replaces ALL current grading bands with the competency-based defaults. Continue?');">
            <button type="submit" name="seed_competency_defaults" value="1" class="ghost-btn">Reset to Competency-Based Defaults</button>
        </form>
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0;">Generic Skills (optional)</h2>
        <p class="muted" style="color:var(--muted);font-size:0.85rem;">When at least one active skill exists, class teachers can rate students against it each term, and it appears as a supplementary section on the report card.</p>

        <table>
            <tr><th>Skill</th><th>Status</th><th></th></tr>
            <?php if (empty($skills)): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px;">No skills defined yet.</td></tr>
            <?php else: foreach ($skills as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['skill_name'], ENT_QUOTES) ?></td>
                    <td><span class="pill <?= $s['is_active'] ? '' : 'muted' ?>"><?= $s['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                    <td style="white-space:nowrap;">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" name="toggle_skill" value="1" class="ghost-btn" style="margin:0;padding:6px 12px;font-size:0.78rem;"><?= $s['is_active'] ? 'Hide' : 'Activate' ?></button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this skill? Past ratings for it are removed too.');">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" name="delete_skill" value="1" class="danger-btn" style="margin:0;padding:6px 12px;font-size:0.78rem;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </table>

        <div class="row" style="margin-top:16px;">
            <form method="post" class="row" style="flex:1;">
                <div>
                    <label>New Skill Name</label>
                    <input type="text" name="skill_name" placeholder="e.g. Critical Thinking" required>
                </div>
                <div style="flex:0 0 auto;align-self:flex-end;">
                    <button type="submit" name="create_skill" value="1">Add Skill</button>
                </div>
            </form>
            <form method="post" style="flex:0 0 auto;align-self:flex-end;">
                <button type="submit" name="seed_default_skills" value="1" class="ghost-btn" style="margin-top:0;">Seed Default Skills</button>
            </form>
        </div>
    </div>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
