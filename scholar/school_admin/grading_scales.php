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
| IMPORTANT: the "reset to competency-based defaults" band labels (A -
| Exceptional through E - Elementary, no F) match Uganda's new lower-
| secondary curriculum. Their percentage cutoffs are even 20-point bands,
| not an official boundary -- adjust per school guidance. The default
| skills list below is still a best-effort placeholder. Nothing on this
| page changes automatically; every action here is an explicit admin click.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_grading_scales_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$message = '';
$message_type = '';

// ---- Grading bands: create ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_band'])) {
    $result = admin_grading_create_band($pdo, $school_id, $_POST);
    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'error';
}

// ---- Grading bands: update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_band'])) {
    $result = admin_grading_update_band($pdo, $school_id, (int) ($_POST['id'] ?? 0), $_POST);
    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'error';
}

// ---- Grading bands: delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_band'])) {
    admin_grading_delete_band($pdo, $school_id, (int) ($_POST['id'] ?? 0));
    $message = 'Grading band deleted.';
    $message_type = 'success';
}

// ---- Grading bands: explicit opt-in reset to competency-based defaults ----
// Never runs without this exact POST + the confirm() dialog on the button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_competency_defaults'])) {
    admin_grading_seed_competency_defaults($pdo, $school_id);
    $message = 'Grading scale reset to the competency-based default bands. Review the labels and cutoffs below.';
    $message_type = 'success';
}

// ---- Generic skills: create ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_skill'])) {
    $result = admin_grading_create_skill($pdo, $school_id, trim($_POST['skill_name'] ?? ''));
    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'error';
}

// ---- Generic skills: toggle active ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_skill'])) {
    admin_grading_toggle_skill($pdo, $school_id, (int) ($_POST['id'] ?? 0));
    $message = 'Skill visibility updated.';
    $message_type = 'success';
}

// ---- Generic skills: delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_skill'])) {
    admin_grading_delete_skill($pdo, $school_id, (int) ($_POST['id'] ?? 0));
    $message = 'Skill deleted (past ratings for it are removed too).';
    $message_type = 'success';
}

// ---- Generic skills: seed defaults ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_default_skills'])) {
    admin_grading_seed_default_skills($pdo, $school_id);
    $message = 'Default skills seeded (existing ones left untouched).';
    $message_type = 'success';
}

$bands = admin_grading_fetch_bands($pdo, $school_id);
$skills = admin_grading_fetch_skills($pdo, $school_id);

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
        <strong>Heads up:</strong> the "reset to competency-based defaults" band labels
        (A - Exceptional through E - Elementary, no F) match Uganda's new lower-secondary
        curriculum. Their percentage cutoffs are even 20-point bands, not an official boundary —
        adjust them below if your school's guidance differs. The default skills list is still a
        best-effort placeholder; review it before relying on it for real report cards.
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
