<?php
// ==========================================
// 1. ENVIRONMENT CONFIGURATION & SECURITY
// ==========================================
// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
require 'db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();
$msg = '';
$edit_mode = false;
$edit_id = '';
$edit_name = '';
$edit_code = '';

// ==========================================
// 2. TRANSACTION PROCESSING WORKFLOW
// ==========================================

// --- HANDLE ACTION: CREATE OR UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department_form'])) {
    $dept_name = trim($_POST['department_name'] ?? '');
    $dept_code = strtoupper(trim($_POST['dept_code'] ?? ''));
    $target_id = $_POST['department_id'] ?? ''; 

    if (!empty($dept_name)) {
        try {
            if (!empty($target_id)) {
                // UPDATE EXISTING RECORD
                $ups = $pdo->prepare("
                    UPDATE departments 
                    SET department_name = ?, dept_code = ? 
                    WHERE id = ? AND school_id = ?
                ");
                $ups->execute([$dept_name, $dept_code, $target_id, $school_id]);
                $msg = "SUCCESS: Department configuration updated successfully.";
            } else {
                // CREATE NEW RECORD (Check duplicate first)
                $chk = $pdo->prepare("SELECT id FROM departments WHERE school_id = ? AND department_name = ?");
                $chk->execute([$school_id, $dept_name]);
                
                if ($chk->fetch()) {
                    $msg = "VALIDATION ERROR: A department named '{$dept_name}' already exists.";
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO departments (school_id, department_name, dept_code) 
                        VALUES (?, ?, ?)
                    ");
                    $ins->execute([$school_id, $dept_name, $dept_code]);
                    $msg = "SUCCESS: '{$dept_name}' has been created.";
                }
            }
        } catch (Exception $e) {
            $msg = "DATABASE ERROR: Action failed. " . $e->getMessage();
        }
    } else {
        $msg = "VALIDATION FAULT: The department name cannot be blank.";
    }
}

// --- HANDLE ACTION: DELETE ---
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        $del = $pdo->prepare("DELETE FROM departments WHERE id = ? AND school_id = ?");
        $del->execute([$delete_id, $school_id]);
        $msg = "SUCCESS: Department removed from system architecture.";
    } catch (Exception $e) {
        $msg = "DATABASE ERROR: Unable to drop row. Check foreign relational dependencies. " . $e->getMessage();
    }
}

// --- HANDLE ACTION: FETCH FOR EDIT MODE ---
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $fetch = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND school_id = ?");
    $fetch->execute([$edit_id, $school_id]);
    $target_row = $fetch->fetch(PDO::FETCH_ASSOC);

    if ($target_row) {
        $edit_mode = true;
        $edit_name = $target_row['department_name'];
        $edit_code = $target_row['dept_code'];
    }
}

// ==========================================
// 3. RETRIEVE RECENT ARCHIVE RECORDS WITH METRICS
// ==========================================
try {
    // Counts aggregated staff using the department connection directly to avoid naming mismatch bugs
    $query = "SELECT d.*, COUNT(s.department_id) AS total_staff 
              FROM departments d 
              LEFT JOIN staff s ON d.id = s.department_id 
              WHERE d.school_id = ? 
              GROUP BY d.id 
              ORDER BY d.department_name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$school_id]);
    $all_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Standard structural fallback if relational database metrics encounter exceptions
    $query = "SELECT *, 0 AS total_staff FROM departments WHERE school_id = ? ORDER BY department_name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$school_id]);
    $all_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$SCHOLAR_BASE = '';
$ACTIVE_NAV = 'departments';
require_once __DIR__ . '/_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
    <style>
        .config-container { width: 100%; max-width: 850px; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 35px; box-sizing: border-box; }
        h2 { margin-top: 0; color: var(--text); font-size: 1.3rem; text-transform: uppercase; letter-spacing: 0.5px; }
        p { color: var(--muted); font-size: 0.85rem; margin-top: -5px; margin-bottom: 25px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        label { font-size: 0.75rem; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: 0.3px; }
        input { background: var(--panel); border: 1px solid var(--border); padding: 12px; border-radius: 6px; color: var(--text); font-size: 0.9rem; width: 100%; box-sizing: border-box; transition: border-color 0.2s; }
        input:focus { border-color: #00A8A8; outline: none; }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .btn-submit { background: #00A8A8; color: #fff; border: none; padding: 14px; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; transition: background 0.2s; height: 46px; }
        .btn-submit:hover { background: #0A3D62; }
        .btn-cancel { background: var(--border); color: var(--muted); border: none; padding: 14px; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; text-decoration: none; text-align: center; height: 16px; line-height: 16px; }
        .btn-cancel:hover { background: #334155; color: #fff; }
        .log-box { background: rgba(0,168,168, 0.05); border: 1px solid #00A8A8; padding: 15px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 25px; color: #67e8f9; font-family: monospace; line-height: 1.4; }

        /* Table Controls */
        .registry-section { margin-top: 40px; border-top: 1px solid var(--border); padding-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; text-align: left; font-size: 0.9rem; }
        th { background: var(--panel); color: var(--muted); font-size: 0.75rem; text-transform: uppercase; padding: 12px 16px; border: 1px solid var(--border); }
        td { padding: 12px 16px; border: 1px solid var(--border); color: var(--text); }
        tr:nth-child(even) { background: rgba(128,128,128,0.06); }
        .action-links { display: flex; gap: 12px; }
        .act-edit { color: #00A8A8; text-decoration: none; font-weight: bold; font-size: 0.85rem; }
        .act-edit:hover { text-decoration: underline; }
        .act-del { color: #ef4444; text-decoration: none; font-weight: bold; font-size: 0.85rem; }
        .act-del:hover { text-decoration: underline; }
        .empty-signal { color: var(--muted); font-style: italic; text-align: center; padding: 20px; }
        .badge-edit { background: #eab308; color: #000; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; vertical-align: middle; margin-left: 5px; }
        .staff-count { background: var(--border); color: var(--cyan); padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; font-family: monospace; }
    </style>
    <div class="config-container">
        <h2>Scholar Department Management Console 
            <?php if ($edit_mode): ?>
                <span class="badge-edit">EDIT MODE</span>
            <?php endif; ?>
        </h2>
        <p>Create, change, or remove structural operational departments across your active Scholar infrastructure node.</p>

        <?php if (!empty($msg)): ?>
            <div class="log-box">▶ <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form action="departments.php" method="POST">
            <input type="hidden" name="save_department_form" value="1">
            <input type="hidden" name="department_id" value="<?= htmlspecialchars($edit_id) ?>">
            
            <div class="grid-2">
                <div class="form-group">
                    <label>Department Name</label>
                    <input type="text" name="department_name" required value="<?= htmlspecialchars($edit_name) ?>" placeholder="e.g., Vocational Studies">
                </div>
                <div class="form-group">
                    <label>Code / Short-name</label>
                    <input type="text" name="dept_code" value="<?= htmlspecialchars($edit_code) ?>" placeholder="e.g., VOC">
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 5px;">
                <?php if ($edit_mode): ?>
                    <a href="departments.php" class="btn-cancel">Abort Changes</a>
                    <button type="submit" class="btn-submit" style="background: #eab308; color: #000;">Commit Modifications</button>
                <?php else: ?>
                    <button type="submit" class="btn-submit" style="width: 100%;">Instantiate System Department</button>
                <?php endif; ?>
            </div>
        </form>

        <div class="registry-section">
            <h2>Active Departments Index</h2>
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%;">ID</th>
                        <th style="width: 40%;">Department Name</th>
                        <th style="width: 15%;">Identifier Code</th>
                        <th style="width: 15%; text-align: center;">Onboarded Staff</th>
                        <th style="width: 20%; text-align: center;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($all_departments) > 0): ?>
                        <?php foreach ($all_departments as $row): ?>
                            <tr>
                                <td><code>#<?= $row['id'] ?></code></td>
                                <td><strong><?= htmlspecialchars($row['department_name']) ?></strong></td>
                                <td><span style="color: #a855f7; font-weight: bold;"><?= htmlspecialchars($row['dept_code'] ?: 'N/A') ?></span></td>
                                <td style="text-align: center;"><span class="staff-count"><?= htmlspecialchars($row['total_staff']) ?> Assigned</span></td>
                                <td style="text-align: center;">
                                    <div class="action-links" style="justify-content: center;">
                                        <a href="departments.php?edit=<?= $row['id'] ?>" class="act-edit">Change</a>
                                        <a href="departments.php?delete=<?= $row['id'] ?>" class="act-del" onclick="return confirm('Are you sure you want to completely remove the <?= htmlspecialchars($row['department_name'], ENT_QUOTES) ?> department?');">Delete</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-signal">No system departments initialized for this school workspace node yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 35px; text-align: center; display: flex; justify-content: space-between;">
            <a href="staff_manager.php" style="color: #00A8A8; font-size: 0.8rem; text-decoration: none; font-weight: 600;">➔ Proceed to Faculty Onboarding</a>
        </div>
    </div><!-- /.config-container -->
    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>