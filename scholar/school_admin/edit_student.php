<?php
// edit_student.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_dir = __DIR__;
require_once $base_dir . '/../config.php';
require_once $base_dir . '/../db.php';
require_once $base_dir . '/../auth_guard.php';
require_once $base_dir . '/../_admin_shell.php';

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id) {
    header("Location: ../login.php");
    exit();
}

// Edits any student's name/class/gender by ID -- had no role check at all,
// meaning any logged-in user of any role could edit any student in their
// school by visiting this URL directly.
require_role(['school_admin']);

$student_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: students.php");
    exit();
}

$classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$school_type_stmt = $pdo->prepare("SELECT school_type FROM schools WHERE id = ?");
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $gender     = trim($_POST['gender'] ?? '');
    $class_id   = trim($_POST['class_id'] ?? '');
    // Never trust the posted value for a Primary school -- the "Curriculum
    // Level" field isn't even rendered for one (see the form below), so a
    // stale/absent field here always used to fall back to the 'O-Level'
    // default, silently overwriting a Primary student's correct level_type
    // the moment anyone saved an edit on their record.
    $level_type = $school_type === 'Primary' ? 'Primary' : trim($_POST['level_type'] ?? 'O-Level');

    if (empty($full_name) || empty($gender) || empty($class_id)) {
        $error = "Please fill in all required fields.";
    } else {
        $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
        $class_stmt->execute([$class_id, $school_id]);
        $class_data = $class_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$class_data) {
            $error = "Invalid class selected.";
        } else {
            // Stored relative to scholar/ root (not scholar/school_admin/),
            // same convention _report_card_render.php already relies on
            // for schools.school_badge -- so file_exists(__DIR__.'/'.path)
            // works from that file's own location.
            $photo_path = $student['photo_path'] ?? null;
            if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($_FILES['student_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $upload_dir = $base_dir . '/../uploads/students';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    if ($photo_path && file_exists($base_dir . '/../' . $photo_path)) {
                        unlink($base_dir . '/../' . $photo_path);
                    }
                    $photo_name = 'stu_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                    move_uploaded_file($_FILES['student_photo']['tmp_name'], $upload_dir . '/' . $photo_name);
                    $photo_path = 'uploads/students/' . $photo_name;
                } else {
                    $error = "Photo must be a JPG, PNG, or WEBP image.";
                }
            }

            if ($error === '') {
                // Write to `sex` — `gender` is a VIRTUAL GENERATED column, read-only.
                $update = $pdo->prepare("
                    UPDATE students SET full_name = ?, sex = ?, class_id = ?, class_name = ?, level_type = ?, photo_path = ?
                    WHERE id = ? AND school_id = ?
                ");
                try {
                    $update->execute([$full_name, $gender, $class_id, $class_data['class_name'], $level_type, $photo_path, $student_id, $school_id]);
                    $message = "Student updated successfully!";
                    $stmt->execute([$student_id, $school_id]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $error = "Failed to update student.";
                }
            }
        }
    }
}
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<main class="main-content">
<div class="page-inner">

<div class="container-fluid py-4 px-4" style="max-width:700px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0 text-dark">Edit Student</h2>
        <a href="students.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Roster</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= safe_text($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= safe_text($error); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="edit_student.php?id=<?= (int) $student_id; ?>" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= (int) $student_id; ?>">
                <div class="mb-3 d-flex align-items-center gap-3">
                    <?php if (!empty($student['photo_path']) && file_exists(__DIR__ . '/../' . $student['photo_path'])): ?>
                        <img src="../<?= safe_text($student['photo_path']); ?>?t=<?= time(); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6;">
                    <?php else: ?>
                        <div style="width:64px;height:64px;border-radius:8px;background:#e9ecef;display:flex;align-items:center;justify-content:center;color:#adb5bd;"><i class="bi bi-person" style="font-size:1.5rem;"></i></div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <label class="form-label">Photo</label>
                        <input type="file" name="student_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required value="<?= safe_text($student['full_name']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select" required>
                        <?php foreach (['Male', 'Female'] as $g): ?>
                            <option value="<?= $g; ?>" <?= ($student['sex'] ?? '') === $g ? 'selected' : ''; ?>><?= $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Class</label>
                    <select name="class_id" class="form-select" required>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id']; ?>" <?= (int) $student['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>>
                                <?= safe_text($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($school_type !== 'Primary'): ?>
                <div class="mb-4">
                    <label class="form-label">Curriculum Level</label>
                    <select name="level_type" class="form-select">
                        <?php foreach (['O-Level', 'A-Level'] as $lvl): ?>
                            <option value="<?= $lvl; ?>" <?= ($student['level_type'] ?? '') === $lvl ? 'selected' : ''; ?>><?= $lvl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Apply Changes</button>
            </form>
        </div>
    </div>
</div>

</div><!-- /.page-inner -->
</main>
</div><!-- /.app-shell (opened in _admin_shell.php) -->
</body>
</html>
