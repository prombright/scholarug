<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — REPORT CARD SETTINGS
|--------------------------------------------------------------------------
| How this school's report cards look/behave, not their grading taxonomy
| (that's grading_scales.php) -- student photos, whether students can
| download their own report, and the background color for a subject row
| with no marks recorded. Upserts into school_settings, which existed
| before this page but had zero rows and zero references anywhere in the
| codebase.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_report_card_render.php'; // scholar_fetch_report_settings()

require_role(['school_admin']);

$school_id = current_school_id();
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $show_photos = isset($_POST['show_student_photos']) ? 1 : 0;
    $allow_download = isset($_POST['allow_student_download']) ? 1 : 0;
    $no_data_color = trim($_POST['no_data_color'] ?? '');
    $no_data_color = (!empty($_POST['use_no_data_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $no_data_color)) ? $no_data_color : null;

    $pdo->prepare("
        INSERT INTO school_settings (school_id, show_student_photos, allow_student_download, no_data_color)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            show_student_photos = VALUES(show_student_photos),
            allow_student_download = VALUES(allow_student_download),
            no_data_color = VALUES(no_data_color)
    ")->execute([$school_id, $show_photos, $allow_download, $no_data_color]);

    $message = 'Report card settings saved.';
    $message_type = 'success';
}

$settings = scholar_fetch_report_settings($pdo, $school_id);

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'report_settings';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;max-width:600px;}
label.toggle-row{display:flex;align-items:center;gap:12px;font-size:0.9rem;color:var(--text);padding:12px 0;border-bottom:1px solid var(--border);}
label.toggle-row:last-of-type{border-bottom:none;}
label.toggle-row input[type=checkbox]{width:auto;transform:scale(1.2);}
.hint{color:var(--muted);font-size:0.78rem;margin:2px 0 0;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;max-width:600px;}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Report Cards</h1>

    <div class="report-tabs">
        <a href="grading_scales.php">Grading &amp; Bands</a>
        <a href="report_settings.php" class="active">Display Settings</a>
        <a href="bulk_report_print.php">Print Reports</a>
        <a href="remarks.php">Remarks</a>
    </div>

    <p style="color:var(--muted);font-size:0.85rem;max-width:600px;">How report cards look and behave for this school.</p>

    <?php if ($message): ?><div class="alert <?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <form method="post" class="section">
        <label class="toggle-row">
            <input type="checkbox" name="show_student_photos" value="1" <?= $settings['show_student_photos'] ? 'checked' : '' ?>>
            <div>
                Show student photos on report cards
                <div class="hint">Turn off if your students don't have photos uploaded yet -- reports render cleanly either way.</div>
            </div>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="allow_student_download" value="1" <?= $settings['allow_student_download'] ? 'checked' : '' ?>>
            <div>
                Allow students to print/download their own report card
                <div class="hint">Off by default -- students can view their report, but the Print button is hidden until you turn this on.</div>
            </div>
        </label>

        <label class="toggle-row" style="align-items:flex-start;">
            <input type="checkbox" name="use_no_data_color" value="1" style="margin-top:2px;" <?= $settings['no_data_color'] ? 'checked' : '' ?>>
            <div>
                Background color for subjects with no marks recorded
                <div class="hint">Applied to a subject row when a student has no score for it yet, so an incomplete report still looks intentional.</div>
                <input type="color" name="no_data_color" value="<?= htmlspecialchars($settings['no_data_color'] ?: '#f1f5f9', ENT_QUOTES) ?>" style="width:60px;height:38px;padding:2px;margin-top:8px;">
            </div>
        </label>

        <button type="submit" name="save_settings" value="1">Save Settings</button>
    </form>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
