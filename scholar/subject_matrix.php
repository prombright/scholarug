<?php
// ==========================================
// 1. ENVIRONMENT CONFIGURATION & LIFECYCLE
// ==========================================
// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
require 'db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_subject_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY FIX: this used to silently grant school_id=1 + role='admin' to
// ANY visitor with no session at all -- the same auth bypass found and
// fixed in staff_manager.php, just copy-pasted here too.
require_role(['school_admin']);

$school_id = current_school_id();
$msg = '';

$school_type_stmt = $pdo->prepare("SELECT school_type FROM schools WHERE id = ?");
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

// ---- One-click seed: default Primary subjects (idempotent, Primary schools only) ----
// Best-effort structure -- P.1-P.3 follows the thematic curriculum, P.4-P.7
// is subject-based -- not a verified official NCDC syllabus. Review the
// codes/names before relying on them for a real school.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_primary_subjects']) && $school_type === 'Primary') {
    $seeded = 0;
    foreach (['P.1', 'P.2', 'P.3', 'P.4', 'P.5', 'P.6', 'P.7'] as $class_name) {
        $seeded += scholar_seed_default_primary_subjects($pdo, $school_id, $class_name);
    }
    header("Location: subject_matrix.php?status=seeded&count=" . $seeded);
    exit;
}

// Handle redirect flash alerts
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'purged') {
        $msg = "DECOUPLED TASK: Subject coordinates dropped. Database cleared downstream mappings automatically.";
    } elseif ($_GET['status'] === 'success') {
        $msg = "SUCCESS: Core parameters processed cleanly into the matrix repository.";
    } elseif ($_GET['status'] === 'seeded') {
        $seeded_count = (int) ($_GET['count'] ?? 0);
        $msg = $seeded_count > 0
            ? "Seeded {$seeded_count} default primary subject(s). Existing ones were left untouched."
            : "Default primary subjects were already seeded for this school -- nothing new added.";
    }
}

// ==========================================
// 2. CONTROLLER / TRANSACTION INGESTION
// ==========================================

// --- 2.1 Inline Grid Quick Registration Form ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['register_single_subject']) || isset($_POST['subject_name']))) {
    if (!isset($_POST['persist_matrix_item'])) {
        $level_type    = $_POST['level_type'] ?? 'O-Level';
        $subject_name  = trim($_POST['subject_name'] ?? '');
        $subject_code  = strtoupper(trim($_POST['subject_code'] ?? ''));
        $is_compulsory = isset($_POST['is_compulsory']) ? 1 : 0;
        $papers_count  = isset($_POST['papers_count']) ? (int)$_POST['papers_count'] : 1;
        $class_name    = trim($_POST['class_name'] ?? '');

        if (!empty($subject_name) && !empty($subject_code) && !empty($class_name)) {
            try {
                $prefix = $level_type === 'A-Level' ? 'A-' : ($level_type === 'Primary' ? 'P-' : 'O-');
                if (substr($subject_code, 0, 2) !== $prefix) {
                    $final_code = $prefix . $subject_code;
                } else {
                    $final_code = $subject_code;
                }

                $check = $pdo->prepare("SELECT id FROM subjects WHERE school_id = ? AND subject_code = ?");
                $check->execute([$school_id, $final_code]);
                
                if ($check->fetch()) {
                    $msg = "VALIDATION ERROR: A subject with code " . $final_code . " already exists in your registry.";
                } else {
                    $ins = $pdo->prepare("INSERT INTO subjects (school_id, level_type, subject_name, subject_code, is_compulsory, papers_count, class_name, subject_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $type_default = ($is_compulsory === 1) ? 'Core' : 'Elective';
                    
                    $ins->execute([$school_id, $level_type, $subject_name, $final_code, $is_compulsory, $papers_count, $class_name, $type_default]);
                    
                    header("Location: subject_matrix.php?status=success");
                    exit;
                }
            } catch (Exception $e) {
                $msg = "DATABASE CRITICAL FAULT: " . $e->getMessage();
            }
        } else {
            $msg = "VALIDATION ERROR: Please ensure Subject Name, Code, and Class are all filled out.";
        }
    }
}

// --- 2.2 Independent Delete Request ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    try {
        $del = $pdo->prepare("DELETE FROM subjects WHERE id = ? AND school_id = ?");
        $del->execute([$delete_id, $school_id]);
        header("Location: subject_matrix.php?status=purged");
        exit;
    } catch (Exception $e) {
        $msg = "DATABASE CRITICAL FAULT: " . $e->getMessage();
    }
}

// --- 2.3 Independent Save / Update Request (Workbench Modal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['persist_matrix_item'])) {
    $action         = $_POST['form_action'] ?? 'add'; 
    $subject_row_id = !empty($_POST['subject_row_id']) ? (int)$_POST['subject_row_id'] : null;
    
    $subject_code   = strtoupper(trim($_POST['subject_code'] ?? ''));
    $subject_name   = trim($_POST['subject_name'] ?? '');
    $class_name     = trim($_POST['class_name'] ?? '');
    $subject_type   = $_POST['subject_type'] ?? 'Core';
    $papers_count   = (int)($_POST['papers_count'] ?? 1);

    if (empty($subject_code) || empty($subject_name) || empty($class_name)) {
        $msg = "VALIDATION ERROR: Code, Subject Title, and Class are required parameters.";
    } else {
        try {
            $is_comp_val = ($subject_type === 'Core') ? 1 : 0;
            // Was matching literal "Senior 5"/"Senior 6" strings, but this
            // form's own class dropdown has only ever offered "S.5"/"S.6"
            // since it was switched to a real dropdown -- meaning every
            // A-Level subject saved here was silently misclassified as
            // O-Level. Reuses the same class ladder every other class
            // dropdown in the app is already built from, so this can't
            // drift out of sync with the dropdown's actual values again.
            $a_level_classes = array_map('scholar_normalize_class_name', scholar_class_ladder('Secondary')['A-Level']);
            $level_calc = in_array(scholar_normalize_class_name($class_name), $a_level_classes, true) ? 'A-Level' : 'O-Level';

            if ($action === 'add') {
                $ins = $pdo->prepare("INSERT INTO subjects (school_id, subject_code, subject_name, class_name, subject_type, papers_count, level_type, is_compulsory) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$school_id, $subject_code, $subject_name, $class_name, $subject_type, $papers_count, $level_calc, $is_comp_val]);
                
                header("Location: subject_matrix.php?status=success");
                exit;
            } else if ($action === 'update' && $subject_row_id) {
                $upd = $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, class_name = ?, subject_type = ?, papers_count = ?, level_type = ?, is_compulsory = ? WHERE id = ? AND school_id = ?");
                $upd->execute([$subject_code, $subject_name, $class_name, $subject_type, $papers_count, $level_calc, $is_comp_val, $subject_row_id, $school_id]);
                
                header("Location: subject_matrix.php?status=success");
                exit;
            }
        } catch (Exception $e) {
            $msg = "ISOLATED CORE FAULT: " . $e->getMessage();
        }
    }
}

// ==========================================
// 3. RETRIEVAL & RENDERING PIPELINE
// ==========================================
try {
    $staff_list = $pdo->prepare("SELECT first_name, staff_id FROM staff WHERE school_id = ? AND staff_category = 'Teaching' ORDER BY first_name ASC");
    $staff_list->execute([$school_id]);
    $teachers = $staff_list->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teachers = [];
}

// Real classes for the Class dropdowns below -- previously free-typed text
// on both subject forms, which is exactly why subjects ended up with
// class_name values ("Senior 1" from a hardcoded default, or whatever
// someone typed) that didn't match the actual classes table. That
// mismatch is why a class's subject dropdown elsewhere in the app (e.g.
// staff assignment) could come up empty for real classes.
try {
    $classes_stmt = $pdo->prepare("SELECT DISTINCT class_name FROM classes WHERE school_id = ? ORDER BY class_name");
    $classes_stmt->execute([$school_id]);
    $class_names = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $class_names = [];
}

$subjects_matrix_collection = [];
try {
    // Previously joined staff.assign_subject (an unrelated INT column,
    // default 1, unused by any real assignment flow) against
    // sub.subject_name (a string) with no school scoping -- MySQL
    // silently coerced the string comparison, matching every subject
    // against the same handful of unrelated staff rows school-wide and
    // multiplying the result set (a school with 22 subjects was showing
    // 110 rows). teacher_assignments is the actual source of truth for
    // who teaches what -- same table _report_card_render.php and
    // teachers_portal.php already rely on.
    // A subject with no class_name (legacy rows that pre-date per-class
    // subjects) can carry one teacher_assignments row per class it's
    // taught in, so GROUP_CONCAT + GROUP BY collapses those back to one
    // display row instead of one row per assignment.
    $subjects_matrix_stmt = $pdo->prepare("
        SELECT
            sub.*,
            GROUP_CONCAT(DISTINCT stf.first_name ORDER BY stf.first_name SEPARATOR ', ') AS instructor_name
        FROM subjects sub
        LEFT JOIN teacher_assignments ta ON ta.subject_id = sub.id AND ta.school_id = sub.school_id
        LEFT JOIN staff stf ON stf.staff_id = ta.teacher_id AND stf.school_id = sub.school_id
        WHERE sub.school_id = ?
        GROUP BY sub.id
        ORDER BY sub.level_type DESC, sub.subject_name ASC
    ");
    $subjects_matrix_stmt->execute([$school_id]);
    $subjects_matrix_collection = $subjects_matrix_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = "DATABASE RETRIEVAL FAULT: " . $e->getMessage();
}
$ACTIVE_NAV = 'subjects';
require_once __DIR__ . '/_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
    <style>
        /* Switched from hardcoded hex + a bolted-on [data-theme="light"]
           override to the shared --bg/--panel/--border/--text/--muted
           vocabulary _admin_shell.php's :root[data-theme="light"] rule
           already redefines -- these now follow the toggle automatically,
           same as everything else in the shell, no per-page override
           block to keep in sync. */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
        table { width:100%; border-collapse:collapse; text-align:left; min-width:900px; }
        th, td { padding:14px 10px; border-bottom:1px solid var(--border); font-size:0.85rem; color: var(--text); }
        th { color:var(--muted); font-size:0.7rem; text-transform:uppercase; border-bottom:2px solid var(--border); }
        input, select { width:100%; background:var(--panel); border:1px solid var(--border); padding:10px; border-radius:6px; color:var(--text); font-size:0.85rem; box-sizing:border-box; }
    </style>

        <?php if ($school_type === 'Secondary'): ?>
            <div style="background: rgba(0,168,168, 0.06); border: 1px solid rgba(0,168,168,0.3); padding: 14px 18px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 20px; color: #67e8f9;">
                Adding a standard O-Level/A-Level subject? Use the <a href="school_admin/subject_catalog.php" style="color:#00A8A8; font-weight:700;">Subject Catalog</a> to tick it in instead of typing it here -- faster, and no spelling/code mistakes. This page stays for anything the catalog doesn't cover.
            </div>
        <?php endif; ?>

        <?php if(!empty($msg)): ?>
            <div style="background: rgba(0,168,168, 0.05); border: 1px solid #00A8A8; padding: 15px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 25px; color: #67e8f9; font-family: monospace;">
                ▶ <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin-top:0; font-size:1.2rem; color:var(--text); text-transform:uppercase; margin-bottom:5px;">Subject Matrix Repository</h3>
                <p style="color:var(--muted); font-size:0.8rem; margin:0;">Autonomous curriculum engine configuration workspace panel layout.</p>
            </div>
            <div style="display:flex; gap:10px;">
                <?php if ($school_type === 'Primary'): ?>
                    <form method="POST" onsubmit="return confirm('Seed the default Primary subject list (P.1-P.7)? Existing subjects are left untouched.');">
                        <button type="submit" name="seed_primary_subjects" value="1" style="background:transparent; color:#00A8A8; border:1px solid rgba(0,168,168,0.4); padding:12px 20px; border-radius:6px; font-size:0.8rem; font-weight:700; cursor:pointer; text-transform:uppercase;">Seed Default Subjects</button>
                    </form>
                <?php endif; ?>
                <button type="button" onclick="openMatrixModal('add')" style="background:#a855f7; color:#fff; border:none; padding:12px 20px; border-radius:6px; font-size:0.8rem; font-weight:700; cursor:pointer; text-transform:uppercase;">+ Add Subject Target</button>
            </div>
        </div>

        <div style="background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 25px; margin-bottom: 30px;">
            <h4 style="margin: 0 0 5px 0; font-size: 0.95rem; color: var(--text); text-transform: uppercase; letter-spacing: 0.5px;">Register New Curriculum Subject Vector</h4>
            <p style="margin: 0 0 20px 0; font-size: 0.8rem; color: var(--muted);">Append standalone custom course offerings mapping directly to localized institutional standards. A matching department is created (or renamed) automatically — no separate step needed.</p>
            <?php if (empty($class_names)): ?>
                <div style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.3); border-radius:6px; padding:10px 12px; font-size:0.8rem; color:#fbbf24; margin-bottom:16px;">
                    No classes set up yet, so there's nothing to attach a subject to.
                    <a href="school_admin/classes.php" style="color:#00A8A8; font-weight:600;">Set up classes first &rarr;</a>
                </div>
            <?php endif; ?>
            
            <form action="subject_matrix.php" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; align-items: end;">
                <div>
                    <label style="display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 700;">Curricular Bracket</label>
                    <?php if ($school_type === 'Primary'): ?>
                        <input type="hidden" name="level_type" value="Primary">
                        <input type="text" value="Primary" disabled style="width:100%;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--muted);">
                    <?php else: ?>
                        <select name="level_type">
                            <option value="O-Level">O-Level Bracket</option>
                            <option value="A-Level">A-Level Bracket</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label style="display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 700;">Class</label>
                    <select name="class_name" required>
                        <option value="">— Select Class —</option>
                        <?php foreach ($class_names as $cn): ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 700;">Subject Title Descriptor</label>
                    <input type="text" name="subject_name" required placeholder="e.g., Entrepreneurship">
                </div>
                <div>
                    <label style="display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 700;">Subject Target Code</label>
                    <input type="text" name="subject_code" required placeholder="e.g., 845">
                </div>
                <div>
                    <label style="display: block; font-size: 0.7rem; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-weight: 700;">Exam Papers Count</label>
                    <select name="papers_count">
                        <option value="1">1 Paper</option>
                        <option value="2" selected>2 Papers</option>
                        <option value="3">3 Papers</option>
                        <option value="4">4 Papers</option>
                    </select>
                </div>
                <div style="padding-bottom: 12px; display: flex; align-items: center; min-height: 40px;">
                    <label style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: var(--text); cursor: pointer; user-select: none;">
                        <input type="checkbox" name="is_compulsory" value="1" style="width: 16px; height: 16px; accent-color: #00A8A8; cursor: pointer;">
                        <span>Compulsory Core</span>
                    </label>
                </div>
                <div>
                    <input type="submit" name="register_single_subject" value="Save Subject" style="background: #00A8A8; color: #fff; border: none; padding: 12px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; transition: background 0.2s; text-align: center;">
                </div>
            </form>
        </div>

        <div style="background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 25px; overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Actions</th>
                        <th>Subject Code</th>
                        <th>Subject Title Name</th>
                        <th>Target Level</th>
                        <th>Classification</th>
                        <th>Exam Papers</th>
                        <th>Assigned Staff Examiner</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($subjects_matrix_collection)): ?>
                        <tr><td colspan="7" style="padding:30px; text-align:center; color:var(--muted); font-family:monospace;">[No subjects mapped into standard repository loop parameters yet]</td></tr>
                    <?php else: foreach($subjects_matrix_collection as $sub): 
                        $js_payload = json_encode([
                            'id' => $sub['id'], 
                            'code' => $sub['subject_code'], 
                            'name' => $sub['subject_name'],
                            'class' => $sub['class_name'] ?? '', 
                            'type' => $sub['subject_type'] ?? 'Core', 
                            'papers' => $sub['papers_count']
                        ]);
                    ?>
                        <tr style="transition: background 0.15s;" onmouseover="this.style.background='var(--panel)'" onmouseout="this.style.background='transparent'">
                            <td>
                                <button type="button" onclick='openMatrixModal("update", <?= htmlspecialchars($js_payload, ENT_QUOTES, 'UTF-8') ?>)' style="background:transparent; border:none; cursor:pointer; color:var(--cyan,#00A8A8); font-size:0.8rem; font-weight:600; margin-right:8px;" title="Edit">Edit</button>
                                <?php if (($sub['subject_type'] ?? 'Core') === 'Elective'): ?>
                                <a href="school_admin/subject_enrollment.php?subject_id=<?= $sub['id'] ?>" style="text-decoration:none; color:#00A8A8; font-size:0.8rem; font-weight:600; margin-right:8px;" title="Assign Students">Assign Students</a>
                                <?php endif; ?>
                                <a href="subject_matrix.php?delete_id=<?= $sub['id'] ?>" onclick="return confirm('Drop matrix target row? Cleanup is handled automatically.');" style="text-decoration:none; color:#ef4444; font-size:0.8rem; font-weight:600;" title="Delete">Delete</a>
                            </td>
                            <td style="font-family:monospace; color:#a855f7; font-weight:700;"><?= htmlspecialchars($sub['subject_code']) ?></td>
                            <td style="font-weight:700; color:var(--text); text-transform:capitalize;"><?= htmlspecialchars($sub['subject_name']) ?></td>
                            <td style="color:var(--text); font-weight:600;"><?= htmlspecialchars($sub['class_name'] ?? 'Unassigned') ?></td>
                            <td>
                                <span style="font-size:0.7rem; padding:4px 8px; border-radius:4px; font-weight:bold; font-family:monospace; background:<?= ($sub['subject_type'] ?? 'Core') === 'Core' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' ?>; color:<?= ($sub['subject_type'] ?? 'Core') === 'Core' ? '#10b981' : '#f59e0b' ?>;">
                                    <?= htmlspecialchars($sub['subject_type'] ?? 'Core') ?>
                                </span>
                            </td>
                            <td><span style="font-weight:700; color:var(--text); font-family:monospace;"><?= (int)$sub['papers_count'] ?></span> Paper(s)</td>
                            <td><?= !empty($sub['instructor_name']) ? htmlspecialchars($sub['instructor_name']) : '<span style="color:var(--muted); font-style:italic;">Unassigned (Staff Mapping Dynamic)</span>' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    <div id="matrixModalOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(4,5,7,0.85); backdrop-filter:blur(6px); z-index:9999; justify-content:center; align-items:center; box-sizing:border-box; padding:20px;">
        <div style="background:var(--panel); border:1px solid var(--border); width:100%; max-width:520px; border-radius:16px; overflow:hidden;">
            <div style="padding:20px 25px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--bg);">
                <h4 id="matrixModalTitle" style="margin:0; font-size:1rem; text-transform:uppercase; color:var(--text);">Matrix Workbench</h4>
                <button type="button" onclick="closeMatrixModal()" style="background:transparent; border:none; color:var(--muted); font-size:1.2rem; cursor:pointer;">✕</button>
            </div>
            <form action="subject_matrix.php" method="POST" style="padding:25px; margin:0; display:flex; flex-direction:column; gap:16px;">
                <input type="hidden" name="form_action" id="matrixFormAction" value="add">
                <input type="hidden" name="subject_row_id" id="matrixFormRowId" value="">

                <div style="display:grid; grid-template-columns:1.2fr 2fr; gap:15px;">
                    <div>
                        <label style="display:block; font-size:0.7rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px;">Subject Code *</label>
                        <input type="text" name="subject_code" id="mat_code" placeholder="e.g., MAT101" required style="font-family:monospace;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.7rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px;">Subject Title *</label>
                        <input type="text" name="subject_name" id="mat_name" placeholder="e.g., Mathematics" required>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div>
                        <label style="display:block; font-size:0.7rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px;">Target Class *</label>
                        <select name="class_name" id="mat_class">
                            <option value="">— Select Class —</option>
                            <?php foreach ($class_names as $cn): ?>
                                <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.7rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px;">Curriculum Mode</label>
                        <select name="subject_type" id="mat_type">
                            <option value="Core">Core Course</option>
                            <option value="Elective">Elective Choice</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr; gap:15px;">
                    <div>
                        <label style="display:block; font-size:0.7rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px;">Number of Papers</label>
                        <input type="number" name="papers_count" id="mat_papers" min="1" max="4" value="1" required style="font-family:monospace;">
                    </div>
                </div>

                <button type="submit" name="persist_matrix_item" style="background:#a855f7; color:#fff; border:none; padding:14px; border-radius:6px; font-weight:700; cursor:pointer; text-transform:uppercase; margin-top:5px;">Commit Matrix Mapping</button>
            </form>
        </div>
    </div>

    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->

    <script>
        function openMatrixModal(actionType, data = null) {
            document.getElementById('matrixFormAction').value = actionType;
            if (actionType === 'add') {
                document.getElementById('matrixModalTitle').innerText = "Map Matrix Node";
                document.getElementById('matrixFormRowId').value = "";
                document.getElementById('mat_code').value = "";
                document.getElementById('mat_name').value = "";
                document.getElementById('mat_class').value = "";
                document.getElementById('mat_type').value = "Core";
                document.getElementById('mat_papers').value = "1";
            } else {
                document.getElementById('matrixModalTitle').innerText = "Modify Metric Configuration";
                document.getElementById('matrixFormRowId').value = data.id;
                document.getElementById('mat_code').value = data.code;
                document.getElementById('mat_name').value = data.name;

                var classSel = document.getElementById('mat_class');
                var hasOption = Array.from(classSel.options).some(o => o.value === data.class);
                if (data.class && !hasOption) {
                    var opt = document.createElement('option');
                    opt.value = data.class;
                    opt.textContent = data.class + ' (not in class list)';
                    classSel.appendChild(opt);
                }
                classSel.value = data.class || "";

                document.getElementById('mat_type').value = data.type;
                document.getElementById('mat_papers').value = data.papers;
            }
            document.getElementById('matrixModalOverlay').style.display = 'flex';
        }
        function closeMatrixModal() { document.getElementById('matrixModalOverlay').style.display = 'none'; }
    </script>
</body>
</html>
