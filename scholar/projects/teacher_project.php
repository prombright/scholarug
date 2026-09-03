<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PROJECTS: TEACHER STAGE LOG
|--------------------------------------------------------------------------
| A teacher monitors a class's UNEB project work (S.3-S.6 only) by logging
| per-student stage updates with photo evidence. Only reachable for
| classes this teacher is actually assigned to monitor
| (project_teacher_assignments) -- the project itself is created and the
| teacher assigned by school_admin (school_admin/projects.php).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['teacher']);

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);

$error = '';
$message = '';

// Every project this teacher is assigned to monitor.
$my_projects_stmt = $pdo->prepare("
    SELECT p.id, p.title, p.class_id, c.class_name, c.stream_name
    FROM project_teacher_assignments pta
    JOIN projects p ON p.id = pta.project_id AND p.school_id = ?
    JOIN classes c ON c.id = p.class_id
    WHERE pta.teacher_id = ?
    ORDER BY c.class_name
");
$my_projects_stmt->execute([$school_id, $staff_id]);
$my_projects = $my_projects_stmt->fetchAll(PDO::FETCH_ASSOC);

$sel_project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;
$sel_project = null;
foreach ($my_projects as $p) {
    if ((int) $p['id'] === $sel_project_id) {
        $sel_project = $p;
    }
}

if ($sel_project_id !== null && $sel_project === null) {
    $error = 'You are not assigned to monitor that project.';
    $sel_project_id = null;
}

$students = [];
$stages_by_student = [];

if ($sel_project !== null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stage'])) {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $stage_title = trim($_POST['stage_title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $stu_check = $pdo->prepare("SELECT id FROM students WHERE id = ? AND school_id = ? AND class_id = ?");
        $stu_check->execute([$student_id, $school_id, $sel_project['class_id']]);

        if ($stage_title === '' || !$stu_check->fetch()) {
            $error = 'Please pick a valid student and enter a stage title.';
        } else {
            $ins = $pdo->prepare("
                INSERT INTO project_stages (project_id, student_id, stage_title, description, recorded_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$sel_project['id'], $student_id, $stage_title, $description, $staff_id]);
            $stage_id = (int) $pdo->lastInsertId();

            // Same upload convention as ilearning_save_attachment(): self-
            // healing mkdir, extension allow-list, collision-safe filename.
            if (!empty($_FILES['photos']['name'][0])) {
                $dir = __DIR__ . '/../uploads/projects';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $allowed = ['jpg', 'jpeg', 'png'];
                $count = count($_FILES['photos']['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed, true)) {
                        continue;
                    }
                    $filename = 'stage_' . $stage_id . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $destPath = $dir . '/' . $filename;
                    if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $destPath)) {
                        $pdo->prepare("INSERT INTO project_stage_evidence (stage_id, file_path) VALUES (?, ?)")
                            ->execute([$stage_id, 'uploads/projects/' . $filename]);
                    }
                }
            }

            header('Location: teacher_project.php?project_id=' . $sel_project['id'] . '&saved=1');
            exit;
        }
    }

    $students_stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name");
    $students_stmt->execute([$school_id, $sel_project['class_id']]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stages_stmt = $pdo->prepare("
        SELECT ps.id, ps.student_id, ps.stage_title, ps.description, ps.recorded_at,
            GROUP_CONCAT(pse.file_path SEPARATOR '|') AS photo_paths
        FROM project_stages ps
        LEFT JOIN project_stage_evidence pse ON pse.stage_id = ps.id
        WHERE ps.project_id = ?
        GROUP BY ps.id
        ORDER BY ps.recorded_at DESC
    ");
    $stages_stmt->execute([$sel_project['id']]);
    foreach ($stages_stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $stages_by_student[(int) $s['student_id']][] = $s;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Work — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:900px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.project-list a{display:block;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:10px;color:var(--text);text-decoration:none;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
label:first-child{margin-top:0;}
input,select,textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.student-block{border-bottom:1px solid var(--border);padding:16px 0;}
.student-block:last-child{border-bottom:none;}
.student-name{font-weight:700;margin-bottom:8px;}
.stage{background:var(--panel);border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:0.85rem;}
.stage .meta{color:var(--muted);font-size:0.72rem;margin-top:4px;}
.photos{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.photos img{width:60px;height:60px;object-fit:cover;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;text-align:center;}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<div class="container">
    <div class="header">
        <h1>Project Work<?= $sel_project ? ' — ' . htmlspecialchars($sel_project['class_name'] . ($sel_project['stream_name'] ? ' - ' . $sel_project['stream_name'] : '')) : '' ?></h1>
        <a href="<?= $sel_project ? 'teacher_project.php' : '../teachers_portal.php' ?>" class="btn-link">&larr; <?= $sel_project ? 'All Projects' : 'Back to Portal' ?></a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Stage logged.</div><?php endif; ?>

    <?php if ($sel_project === null): ?>
        <div class="project-list">
            <?php if (empty($my_projects)): ?>
                <p class="empty">You're not assigned to monitor any class's project work yet.</p>
            <?php else: ?>
                <?php foreach ($my_projects as $p): ?>
                    <a href="teacher_project.php?project_id=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['title']) ?> — <?= htmlspecialchars($p['class_name'] . ($p['stream_name'] ? ' - ' . $p['stream_name'] : '')) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="section">
            <form method="POST" enctype="multipart/form-data">
                <label>Student</label>
                <select name="student_id" required>
                    <option value="">-- Select Student --</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Stage Title</label>
                <input type="text" name="stage_title" placeholder="e.g. Topic Selection, Data Collection, Final Write-up" required>
                <label>Description</label>
                <textarea name="description" rows="3"></textarea>
                <label>Evidence Photos (optional)</label>
                <input type="file" name="photos[]" accept="image/*" multiple>
                <button type="submit" name="add_stage">Log Stage</button>
            </form>
        </div>

        <div class="section">
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
</div>
</body>
</html>