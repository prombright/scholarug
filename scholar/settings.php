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
    $school_name = trim($_POST['school_name'] ?? '');
    $phone       = trim($_POST['phone_contact'] ?? '');
    $email       = trim($_POST['email_contact'] ?? '');
    $location    = trim($_POST['address'] ?? '');
    $academic_yr = trim($_POST['current_academic_year'] ?? '2026');
    $curr_term   = trim($_POST['current_term'] ?? 'Term 1');

    // Establish dynamic fallback badge if no previous logo exists
    $logo_destination = $_POST['existing_logo_path'] ?? 'assets/img/default-logo.png';
    
    // Upload Pipeline
    if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['school_logo']['tmp_name'];
        $file_name     = $_FILES['school_logo']['name'];
        $file_ext      = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_ext, $allowed_extensions)) {
            // Self-healing directory configuration
            if (!is_dir('assets/uploads')) {
                mkdir('assets/uploads', 0755, true);
            }
            
            // Structured naming scheme using school ID and Unix epoch to avoid caching issues
            $new_file_name    = 'badge_school_' . $school_id . '_' . time() . '.' . $file_ext;
            $upload_file_path = 'assets/uploads/' . $new_file_name;
            
            if (move_uploaded_file($file_tmp_path, $upload_file_path)) {
                $logo_destination = $upload_file_path;
            } else {
                $msg = "FILE SYSTEM NOTICE: Failed to migrate uploaded asset to destination storage.";
                $msg_type = 'error';
            }
        } else {
            $msg = "VALIDATION ERROR: Unsupported file type. Please use WebP, PNG, JPG, or JPEG.";
            $msg_type = 'error';
        }
    }

    // Execute database synchronization
    if ($msg_type !== 'error') {
        try {
            // phone_contact/email_contact/address (not the older,
            // disconnected phone/email/location columns this used to write
            // to) -- the report card (generate_report.php et al.) has only
            // ever read the *_contact/address columns, so anything saved
            // here previously could never actually reach a printed report.
            $update_stmt = $pdo->prepare("
                UPDATE schools
                SET school_name = ?,
                    phone_contact = ?,
                    email_contact = ?,
                    address = ?,
                    school_badge = ?,
                    current_term = ?,
                    current_year = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $school_name,
                $phone,
                $email,
                $location,
                $logo_destination,
                $curr_term,
                $academic_yr,
                $school_id
            ]);
            
            // DYNAMIC UPDATE: Instantly change the session branding across the platform
            $_SESSION['school_name']     = $school_name;
            $_SESSION['school_badge']    = $logo_destination;
            $_SESSION['school_location'] = $location;

            // This dropdown used to just write these two columns with no
            // effect anywhere else -- every page reading $_SESSION['current_term']
            // was silently stuck on the 'Term 1'/current-year fallback
            // regardless of what was picked here. Mirroring into the
            // session is what actually makes the choice take effect.
            $_SESSION['current_term'] = $curr_term;
            $_SESSION['current_year'] = $academic_yr;

            $msg = "SUCCESS: Core institutional matrix profiles updated. Logo changed successfully!";
            $msg_type = 'success';
        } catch (Exception $e) {
            $msg = "DATABASE ERROR: " . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

// ==========================================
// 3. RECOVERY PIPELINE: PULL CURRENT RECORD
// ==========================================
try {
    $school_profile = $pdo->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
    $school_profile->execute([$school_id]);
    $school = $school_profile->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
      // ✅ Fixed
$insert_init = $pdo->prepare("INSERT INTO schools (id, school_name) VALUES (?, 'My New High School')");
        $insert_init->execute([$school_id]);
        
        $school_profile->execute([$school_id]);
        $school = $school_profile->fetch(PDO::FETCH_ASSOC);
    }
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

    try {
        $pdo->beginTransaction();

        $close_stmt = $pdo->prepare("
            UPDATE assessments SET status = 'Closed'
            WHERE school_id = ? AND term = ? AND year = ? AND status != 'Closed'
        ");
        $close_stmt->execute([$school_id, $term_to_close, $year_to_close]);
        $affected = $close_stmt->rowCount();

        // Closing Term 3 does NOT auto-roll into next year's Term 1 -- that
        // stays Close Year's own deliberate action, so nobody promotes a
        // whole school's students by clicking through term-closes on
        // autopilot.
        $next_term_map = ['Term 1' => 'Term 2', 'Term 2' => 'Term 3', 'Term 3' => 'Term 3'];
        $next_term = $next_term_map[$term_to_close] ?? 'Term 1';

        $pdo->prepare("UPDATE schools SET current_term = ? WHERE id = ?")->execute([$next_term, $school_id]);
        $pdo->commit();

        $_SESSION['current_term'] = $next_term;
        $school['current_term'] = $next_term;

        $msg = $term_to_close === 'Term 3'
            ? "Term 3 {$year_to_close} closed -- {$affected} assessment(s) locked. This was the school's final term for {$year_to_close}; use Close Year below when ready to promote students and start {$year_to_close}+1."
            : "{$term_to_close} {$year_to_close} closed -- {$affected} assessment(s) locked. Now on {$next_term}.";
        $msg_type = 'success';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = "Could not close the term: " . $e->getMessage();
        $msg_type = 'error';
    }
}

// ==========================================
// 5. CLOSE YEAR -- promote every active student to their next class,
//    graduate the top of the ladder, advance to next year's Term 1
// ==========================================
/**
 * Resolves a promotion's target classes.id, reusing whatever spelling of
 * that class already exists rather than assuming the canonical dotted
 * form -- classes.class_name isn't consistently formatted across schools
 * ("S1" vs "S.1"), and creating a fresh "S.2" for a school that already
 * has "S2" would permanently fork it into two parallel spellings of the
 * same class. Only creates a new row (canonical dotted form, matching
 * classes.php's manual "Add Class" form) if truly nothing matches.
 */
function scholar_resolve_or_create_class(PDO $pdo, int $school_id, string $class_name, ?string $stream_name): int
{
    $norm = scholar_normalize_class_name($class_name);
    $all_stmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ?");
    $all_stmt->execute([$school_id]);
    $rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 1. normalized class_name match with the same stream (catches both
    //    "spelling differs but stream matches" -- e.g. target 'S.2' vs an
    //    existing 'S2' that both use stream 'A' -- and the fully-exact case).
    foreach ($rows as $row) {
        $row_stream = $row['stream_name'] ?? null;
        if (scholar_normalize_class_name($row['class_name']) === $norm && $row_stream === $stream_name) {
            return (int) $row['id'];
        }
    }

    // 2. normalized class_name match, any stream -- still reuse rather than
    //    duplicate; a school with no bare/matching-stream target class yet
    //    lands on whatever stream variant already exists over creating a
    //    parallel spelling.
    foreach ($rows as $row) {
        if (scholar_normalize_class_name($row['class_name']) === $norm) {
            return (int) $row['id'];
        }
    }

    // 3. nothing at all matches -- create it, canonical spelling, no stream
    $ins = $pdo->prepare("INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, NULL)");
    $ins->execute([$school_id, $class_name]);
    return (int) $pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_current_year'])) {
    $year_to_close = $school['current_year'] ?? (string) date('Y');

    $open_check = $pdo->prepare("
        SELECT COUNT(*) FROM assessments
        WHERE school_id = ? AND term = 'Term 3' AND year = ? AND status != 'Closed'
    ");
    $open_check->execute([$school_id, $year_to_close]);

    if ((int) $open_check->fetchColumn() > 0) {
        $msg = "Term 3 {$year_to_close} still has open assessments -- close Term 3 first.";
        $msg_type = 'error';
    } else {
        $school_type = $school['school_type'] ?? 'Secondary';
        $ladder = array_merge(...array_values(scholar_class_ladder($school_type)));

        try {
            $pdo->beginTransaction();

            $promoted_total = 0;
            $graduated_total = 0;

            // Top-down, one pass: classes.id rows are shared forever (no
            // year column), so promoting bottom-up would have the very
            // next step immediately re-sweep students who just arrived a
            // moment earlier in the same run -- top-down guarantees each
            // source class is only ever read once.
            for ($i = count($ladder) - 1; $i >= 0; $i--) {
                $current_name = $ladder[$i];
                $norm_current = scholar_normalize_class_name($current_name);

                if ($i === count($ladder) - 1) {
                    $grad_stmt = $pdo->prepare("
                        UPDATE students SET graduated_year = ?, class_id = NULL
                        WHERE school_id = ? AND graduated_year IS NULL
                          AND REPLACE(UPPER(class_name), '.', '') = ?
                    ");
                    $grad_stmt->execute([$year_to_close, $school_id, $norm_current]);
                    $graduated_total += $grad_stmt->rowCount();
                    continue;
                }

                $next_name = $ladder[$i + 1];
                // level_type only flips at the O-Level -> A-Level boundary
                $level_override = ($current_name === 'S.4' && $school_type === 'Secondary') ? 'A-Level' : null;

                $find_stmt = $pdo->prepare("
                    SELECT st.id, c.stream_name
                    FROM students st
                    LEFT JOIN classes c ON st.class_id = c.id
                    WHERE st.school_id = ? AND st.graduated_year IS NULL
                      AND REPLACE(UPPER(st.class_name), '.', '') = ?
                ");
                $find_stmt->execute([$school_id, $norm_current]);
                $matched = $find_stmt->fetchAll(PDO::FETCH_ASSOC);

                $class_cache = []; // stream key => [class_id, class_name]
                $class_name_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
                $upd_student = $pdo->prepare("
                    UPDATE students SET class_id = ?, class_name = ?, level_type = COALESCE(?, level_type)
                    WHERE id = ?
                ");
                foreach ($matched as $stu) {
                    $stream_key = $stu['stream_name'] ?? '';
                    if (!array_key_exists($stream_key, $class_cache)) {
                        $target_id = scholar_resolve_or_create_class($pdo, $school_id, $next_name, $stu['stream_name'] ?: null);
                        // Mirror the resolved class's own spelling, not the
                        // canonical ladder form -- otherwise a student ends
                        // up with class_name='S.2' while class_id points at
                        // an existing 'S2' row, the same class described two
                        // different ways in the same table.
                        $class_name_stmt->execute([$target_id]);
                        $resolved_name = $class_name_stmt->fetchColumn() ?: $next_name;
                        $class_cache[$stream_key] = [$target_id, $resolved_name];
                    }
                    [$target_class_id, $target_class_name] = $class_cache[$stream_key];
                    $upd_student->execute([$target_class_id, $target_class_name, $level_override, $stu['id']]);
                    $promoted_total++;
                }
            }

            $new_year = (string) ((int) $year_to_close + 1);
            $pdo->prepare("UPDATE schools SET current_year = ?, current_term = 'Term 1' WHERE id = ?")
                ->execute([$new_year, $school_id]);
            $pdo->commit();

            $_SESSION['current_year'] = $new_year;
            $_SESSION['current_term'] = 'Term 1';
            $school['current_year'] = $new_year;
            $school['current_term'] = 'Term 1';

            $msg = "Year closed -- {$promoted_total} student(s) promoted, {$graduated_total} graduated. Now on Term 1 {$new_year}.";
            $msg_type = 'success';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = "Could not close the year: " . $e->getMessage();
            $msg_type = 'error';
        }
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