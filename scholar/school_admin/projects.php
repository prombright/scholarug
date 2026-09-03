<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PROJECTS: ADMIN OVERSIGHT
|--------------------------------------------------------------------------
| Create a project for a class (S.3-S.6 only) and assign a teacher to
| monitor it; once created, read-only view of every student's stage
| history + evidence photos, logged by that teacher via
| scholar/projects/teacher_project.php. Compiling this into one document
| for UNEB submission is a deliberate later step -- see
| _setup/projects_migration.sql's header.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_projects_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$admin_user_id = (int) ($_SESSION['user_id'] ?? 0);

$error = '';
$message = '';

$project_classes = admin_projects_eligible_classes($pdo, $school_id);

$sel_class_id = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
if ($sel_class_id !== null) {
    $allowed_ids = array_map('intval', array_column($project_classes, 'id'));
    if (!in_array($sel_class_id, $allowed_ids, true)) {
        $error = 'Projects are only tracked for S.3-S.6.';
        $sel_class_id = null;
    }
}

$teachers = admin_projects_teachers($pdo, $school_id);

$project = null;
$assigned_teachers = [];
$students = [];
$stages_by_student = [];

if ($sel_class_id !== null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
        $result = admin_projects_create(
            $pdo, $school_id, $sel_class_id, $admin_user_id,
            trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''), (int) ($_POST['teacher_id'] ?? 0)
        );
        if ($result['ok']) { $message = $result['message']; } else { $error = $result['message']; }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_teacher'])) {
        $result = admin_projects_assign_teacher(
            $pdo, $school_id, $sel_class_id,
            (int) ($_POST['project_id'] ?? 0), (int) ($_POST['teacher_id'] ?? 0)
        );
        if ($result['ok']) {
            $message = $result['message'];
        }
    }

    $state = admin_projects_class_state($pdo, $school_id, $sel_class_id);
    $project = $state['project'];
    $assigned_teachers = $state['assigned_teachers'];
    $students = $state['students'];
    $stages_by_student = $state['stages_by_student'];
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'projects';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;}
.filter-bar div{min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
label:first-child{margin-top:0;}
select,input,textarea{width:100%;padding:9px 10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;margin-top:16px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);margin-right:6px;}
.student-block{border-bottom:1px solid var(--border);padding:16px 0;}
.student-block:last-child{border-bottom:none;}
.student-name{font-weight:700;margin-bottom:8px;}
.stage{background:var(--panel);border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:0.85rem;}
.stage .meta{color:var(--muted);font-size:0.72rem;margin-top:4px;}
.photos{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.photos img{width:60px;height:60px;object-fit:cover;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;text-align:center;}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Projects</h1>
    <p style="color:var(--muted);font-size:0.85rem;">UNEB O-Level project work, S.3-S.6 only.</p>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="section">
        <form method="GET" class="filter-bar">
            <div>
                <label>Class</label>
                <select name="class_id">
                    <option value="">-- Select Class --</option>
                    <?php foreach ($project_classes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sel_class_id === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit">Load</button></div>
        </form>
    </div>

    <?php if ($sel_class_id !== null): ?>
        <?php if (!$project): ?>
            <div class="section">
                <h2 style="font-size:1rem;margin:0;">Create Project</h2>
                <form method="POST">
                    <label>Title</label>
                    <input type="text" name="title" placeholder="e.g. S.3 Community-Based Project" required>
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                    <label>Monitoring Teacher</label>
                    <select name="teacher_id" required>
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= (int) $t['staff_id'] ?>"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="create_project">Create Project</button>
                </form>
            </div>
        <?php else: ?>
            <div class="section">
                <h2 style="font-size:1rem;margin:0 0 8px;"><?= htmlspecialchars($project['title']) ?></h2>
                <?php if ($project['description']): ?><p style="color:var(--muted);font-size:0.85rem;"><?= htmlspecialchars($project['description']) ?></p><?php endif; ?>
                <div style="margin:10px 0;">
                    <?php foreach ($assigned_teachers as $t): ?>
                        <span class="pill"><?= htmlspecialchars($t['teacher_name']) ?></span>
                    <?php endforeach; ?>
                </div>
                <form method="POST" style="display:flex;gap:10px;align-items:flex-end;">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <div style="flex:1;">
                        <label style="margin-top:0;">Assign Another Teacher</label>
                        <select name="teacher_id" required>
                            <option value="">-- Select Teacher --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int) $t['staff_id'] ?>"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="assign_teacher" style="margin-top:0;">Assign</button>
                </form>
            </div>

            <div class="section">
                <h2 style="font-size:1rem;margin:0 0 8px;">Student Progress</h2>
                <?php if (empty($students)): ?>
                    <p class="empty">No students in this class.</p>
                <?php else: ?>
                    <?php foreach ($students as $s):
                        $sid = (int) $s['id'];
                        $stages = $stages_by_student[$sid] ?? [];
                    ?>
                        <div class="student-block">
                            <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                            <?php if (empty($stages)): ?>
                                <div class="stage" style="color:var(--muted);">No stages logged yet.</div>
                            <?php else: ?>
                                <?php foreach ($stages as $st): ?>
                                    <div class="stage">
                                        <strong><?= htmlspecialchars($st['stage_title']) ?></strong>
                                        <?php if ($st['description']): ?><div><?= htmlspecialchars($st['description']) ?></div><?php endif; ?>
                                        <?php if ($st['photo_paths']): ?>
                                            <div class="photos">
                                                <?php foreach (explode('|', $st['photo_paths']) as $path): ?>
                                                    <img src="../<?= htmlspecialchars($path) ?>" alt="">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="meta"><?= htmlspecialchars(date('d M Y, H:i', strtotime($st['recorded_at']))) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>