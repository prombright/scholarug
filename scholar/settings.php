<?php
// ==========================================
// 1. LIFE-CYCLE CONFIGURATION & FILE ENGINE
// ==========================================
ini_set('display_errors', 0); 
error_reporting(E_ALL);

require_once 'db.php';
// Needed here (not just via _admin_shell.php's later require, which loads
// after the POST handlers below run) for current_term()/current_year()/
// scholar_class_ladder()/scholar_normalize_class_name(), used by the
// Close Term / Close Year handlers.
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_settings_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only the real school admin (or the platform developer) may view settings.
// NOTE: this used to auto-fake every visitor's session as an admin for
// "local testing" — that meant this page had no real access control at all.
if (
    !isset($_SESSION['role'], $_SESSION['school_id'])
    || !in_array($_SESSION['role'], ['school_admin', 'developer'], true)
) {
    die("<p style='color:#ef4444; padding:20px; font-family:sans-serif; background:#060709; height:100vh; margin:0;'>Access Denied. Admin privileges required.</p>");
}

$school_id = (int) $_SESSION['school_id']; 
$msg = '';
$msg_type = 'success';

// ==========================================
// 2. TRANSACTION PROCESSING: POST ROUTINES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['commit_settings_matrix'])) {
    $result = admin_settings_save($pdo, $school_id, $_POST, $_FILES);
    $msg = $result['message'];
    $msg_type = $result['ok'] ? 'success' : 'error';

    if ($result['ok']) {
        // DYNAMIC UPDATE: Instantly change the session branding across the platform.
        // This dropdown used to just write these two columns with no effect
        // anywhere else -- every page reading $_SESSION['current_term'] was
        // silently stuck on the 'Term 1'/current-year fallback regardless of
        // what was picked here. Mirroring into the session is what actually
        // makes the choice take effect.
        $_SESSION['school_name']     = trim($_POST['school_name'] ?? '');
        $_SESSION['school_badge']    = $result['school_badge'];
        $_SESSION['school_location'] = trim($_POST['address'] ?? '');
        $_SESSION['current_term'] = trim($_POST['current_term'] ?? 'Term 1');
        $_SESSION['current_year'] = trim($_POST['current_academic_year'] ?? '2026');
    }
}

// ==========================================
// 3. RECOVERY PIPELINE: PULL CURRENT RECORD
// ==========================================
try {
    $school = admin_settings_fetch_school($pdo, $school_id);
} catch (Exception $e) {
    die("CRITICAL STRUCTURAL ARCHITECTURE RECOVERY FAULT: " . $e->getMessage());
}

// ==========================================
// 4. CLOSE TERM -- lock this term's assessments, advance to the next term
// ==========================================
// 'Closed' used to only hide an assessment from the teacher marks-entry
// dropdown (teachers_portal.php) -- the actual save handlers never
// checked it, so a closed assessment could still silently receive new
// marks. This bulk-closes every assessment for the term and (as of this
// change) that status is now server-enforced on both teachers_portal.php
// write paths, so closing a term here actually locks marks entry.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_current_term'])) {
    $term_to_close = $school['current_term'] ?? 'Term 1';
    $year_to_close = $school['current_year'] ?? (string) date('Y');

    $result = admin_settings_close_term($pdo, $school_id, $term_to_close, $year_to_close);
    $msg = $result['message'];
    $msg_type = $result['ok'] ? 'success' : 'error';

    if ($result['ok']) {
        $_SESSION['current_term'] = $result['new_term'];
        $school['current_term'] = $result['new_term'];
    }
}

// ==========================================
// 5. CLOSE YEAR -- promote every active student to their next class,
//    graduate the top of the ladder, advance to next year's Term 1
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_current_year'])) {
    $year_to_close = $school['current_year'] ?? (string) date('Y');
    $school_type = $school['school_type'] ?? 'Secondary';

    $result = admin_settings_close_year($pdo, $school_id, $year_to_close, $school_type);
    $msg = $result['message'];
    $msg_type = $result['ok'] ? 'success' : 'error';

    if ($result['ok']) {
        $_SESSION['current_year'] = $result['new_year'];
        $_SESSION['current_term'] = 'Term 1';
        $school['current_year'] = $result['new_year'];
        $school['current_term'] = 'Term 1';
    }
}

$ACTIVE_NAV = 'settings';
require_once __DIR__ . '/_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
    <style>
        :root {
            --panel-bg: #0f1115;
            --border-gray: #1e293b;
            --text-muted: #64748b;
            --accent-cyan: #0ea5e9;
        }
        .form-control { background: #161920; border: 1px solid var(--border-gray); padding: 12px; border-radius: 6px; color: #fff; font-size: 0.85rem; box-sizing: border-box; width: 100%; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--accent-cyan); outline: none; box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.15); }
        label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold; display: block; margin-bottom: 6px; letter-spacing: 0.5px; }
        .grid-block { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .grid-block { grid-template-columns: 1fr; } }
        /* Close Term / Close Year use the shared --panel/--border/--cyan/--danger
           vocabulary from _admin_shell.php (the newer pages' convention -- e.g.
           assessments.php, grading_scales.php) rather than this page's own
           bespoke --panel-bg/--border-gray set above. */
        .section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
        .disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:16px;line-height:1.5;}
        .danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:0.85rem;}
        .danger-btn:hover{background:rgba(239,68,68,0.08);}

        /* .form-control/label above use this page's own older --border-gray/
           --text-muted vocabulary, not the shared --border/--muted names
           _admin_shell.php's [data-theme="light"] override targets -- so
           .section panels go light correctly but form inputs stayed dark.
           Re-pointing the bespoke names (and the input itself) here closes
           that gap without touching the rest of the page. */
        [data-theme="light"] { --border-gray: rgba(15,23,42,.16); --text-muted: #64748B; --panel-bg: #FFFFFF; }
        [data-theme="light"] .form-control { background: #FFFFFF; color: #1E293B; }
    </style>

        <?php if(!empty($msg)): ?>
            <div style="background: <?= $msg_type === 'success' ? 'rgba(16, 185, 129, 0.05)' : 'rgba(239, 68, 68, 0.05)' ?>; border: 1px solid <?= $msg_type === 'success' ? '#10b981' : '#ef4444' ?>; padding: 15px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 25px; color: <?= $msg_type === 'success' ? '#34d399' : '#f87171' ?>; font-family: monospace; line-height: 1.4;">
                ▶ <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom: 30px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h2 style="margin: 0 0 5px 0; font-size: 1.4rem; text-transform: uppercase; letter-spacing: 0.5px;">Setup School Settings</h2>
                <p style="color: var(--text-muted); margin: 0; font-size: 0.85rem;">Manage structural parameters, contact coordinates, term limits, and layout branding assets.</p>
            </div>
            
            <?php if (!empty($school['school_badge']) && file_exists($school['school_badge'])): ?>
                <img src="<?= htmlspecialchars($school['school_badge']) ?>?t=<?= time() ?>" alt="Header Logo" style="height: 50px; border-radius: 6px; border: 1px solid var(--border-gray); padding: 4px; background: rgba(255, 255, 255, 0.02);">
            <?php endif; ?>
        </div>

        <form action="settings.php" method="POST" enctype="multipart/form-data" style="background: var(--panel-bg); border: 1px solid var(--border-gray); border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.25);">
            
            

            <div style="display: flex; align-items: center; gap: 25px; border-bottom: 1px solid var(--border-gray); padding-bottom: 25px; margin-bottom: 25px;">
                <div style="width: 100px; height: 100px; background: var(--panel-bg); border: 2px dashed var(--border-gray); border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                    
                </div>
                <div>
                    <label>School Logo / Badge Icon</label>
                    <input type="file" name="school_logo" accept="image/*" class="form-control" style="background: transparent; border: none; padding: 0; color: var(--text-muted);">
                    <span style="font-size: 0.65rem; color: var(--text-muted); display:block; margin-top:5px;">Supports PNG, JPG, JPEG, or WebP formats.</span>
                </div>
            </div>

            <div class="grid-block">
                <div>
                    <label>School Name</label>
                    <input type="text" name="school_name" class="form-control" required value="<?= htmlspecialchars($school['school_name'] ?? '') ?>" placeholder="e.g. Mbarara High School">
                </div>
                <div>
                    <label>Address / Location</label>
                    <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($school['address'] ?? '') ?>" placeholder="e.g. P.O. Box 1, Ruharo, Mbarara">
                </div>
            </div>

            <div class="grid-block">
                <div>
                    <label>Contact Number</label>
                    <input type="text" name="phone_contact" class="form-control" value="<?= htmlspecialchars($school['phone_contact'] ?? '') ?>" placeholder="e.g. +256 701 234567">
                </div>
                <div>
                    <label>School Email</label>
                    <input type="email" name="email_contact" class="form-control" value="<?= htmlspecialchars($school['email_contact'] ?? '') ?>" placeholder="e.g. registrar@school.ac.ug">
                </div>
            </div>

            <div class="grid-block" style="border-top: 1px solid var(--border-gray); padding-top: 25px; margin-top: 25px;">
                <div>
                    <label>Select Current Active Term</label>
                    <select name="current_term" class="form-control">
                        <option value="Term 1" <?= ($school['current_term'] ?? '') === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                        <option value="Term 2" <?= ($school['current_term'] ?? '') === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                        <option value="Term 3" <?= ($school['current_term'] ?? '') === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                    </select>
                </div>
                <div>
                    <label>Current Academic Year</label>
                    <select name="current_academic_year" class="form-control">
                        <option value="2026" <?= ($school['current_year'] ?? '') === '2026' ? 'selected' : '' ?>>2026 Calendar Year</option>
                        <option value="2027" <?= ($school['current_year'] ?? '') === '2027' ? 'selected' : '' ?>>2027 Calendar Year</option>
                    </select>
                </div>
            </div>

            <div style="text-align: right; margin-top: 30px; border-top: 1px solid var(--border-gray); padding-top: 20px;">
                <button type="submit" name="commit_settings_matrix" class="form-control" style="background: var(--accent-cyan); color: #fff; border: none; padding: 12px 35px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem; width: auto; display: inline-block; text-transform: uppercase;">Save Settings Matrix</button>
            </div>

        </form>

        <?php
        $__term = $school['current_term'] ?? 'Term 1';
        $__year = $school['current_year'] ?? (string) date('Y');
        $__is_term3 = $__term === 'Term 3';
        ?>
        <div class="section" style="margin-top:30px;">
            <h2 style="font-size:1rem;margin:0 0 4px;">Close Term</h2>
            <p style="color:var(--muted);font-size:0.85rem;margin:0 0 16px;">Currently on <strong><?= htmlspecialchars($__term . ' ' . $__year, ENT_QUOTES) ?></strong>.</p>
            <div class="disclaimer">
                Closes every assessment for <?= htmlspecialchars($__term . ' ' . $__year, ENT_QUOTES) ?> -- teachers will
                no longer be able to save marks against them, on the entry form or via CSV import -- and advances
                the school to <?= htmlspecialchars($__is_term3 ? 'ready for Close Year' : ($__term === 'Term 1' ? 'Term 2' : 'Term 3'), ENT_QUOTES) ?>.
                This cannot be undone in bulk -- reopening an assessment afterward is a one-at-a-time action on
                <a href="school_admin/assessments.php" style="color:#00A8A8;">Assessments</a>.
            </div>
            <form method="post" onsubmit="return confirm('Close <?= htmlspecialchars(addslashes($__term . ' ' . $__year), ENT_QUOTES) ?> and lock all its assessments? Teachers will no longer be able to save marks against them. This cannot be undone in bulk.');">
                <button type="submit" name="close_current_term" class="danger-btn">Close <?= htmlspecialchars($__term, ENT_QUOTES) ?></button>
            </form>
        </div>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 4px;">Close Year</h2>
            <p style="color:var(--muted);font-size:0.85rem;margin:0 0 16px;">Currently on <strong><?= htmlspecialchars($__year, ENT_QUOTES) ?></strong>.</p>
            <div class="disclaimer">
                Promotes every active student to their next class (e.g. S.1 &rarr; S.2), graduates whoever's at the
                top of the ladder (flagged, not deleted -- their records stay searchable as alumni), and starts
                <?= (int) $__year + 1 ?> on Term 1. Requires Term 3 <?= htmlspecialchars($__year, ENT_QUOTES) ?> to already be closed.
                This cannot be undone in bulk.
            </div>
            <form method="post" onsubmit="return confirm('Close <?= htmlspecialchars(addslashes($__year), ENT_QUOTES) ?> and promote every active student to their next class? Graduating students will be flagged as alumni. This cannot be undone in bulk.');">
                <button type="submit" name="close_current_year" class="danger-btn">Close <?= htmlspecialchars($__year, ENT_QUOTES) ?> &amp; Promote Students</button>
            </form>
        </div>
    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>