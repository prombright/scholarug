<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — MANAGE CLASSES (v2)
|--------------------------------------------------------------------------
| Classes are always S.1-S.4 (O-Level) or S.5-S.6 (A-Level) — fixed, not
| freeform. Streams are admin-defined per class name and reused via the
| `streams` table (already existed in the schema, just wasn't wired up).
| A-Level gets a one-click seed for the usual Sciences/Arts streams, but
| nothing stops the admin from adding their own instead or as well.
|
| Class teacher assignment lives here too -- classes.class_teacher_id
| existed in the schema but nothing ever set it, so is_class_teacher_of()
| in auth_guard.php was dead code and every 'teacher' could view/print
| any student's report school-wide. Assigning a class teacher here is
| what scopes generate_report.php to just their own class.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_subject_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';
$success = '';

// Creates the classes row for a class+stream and keeps the reusable
// streams catalog (other pages read class_name/stream_name pairs from it)
// pointed at that row's id -- the one action both add_stream and
// seed_alevel_streams need, so a school never has to "define a stream"
// and then separately "add the class" as two trips through the UI.
function scholar_add_class_stream(PDO $pdo, int $schoolId, string $className, string $streamName): int
{
    $pdo->prepare("INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, ?)")
        ->execute([$schoolId, $className, $streamName]);
    $classId = (int) $pdo->lastInsertId();

    $exists = $pdo->prepare("SELECT id FROM streams WHERE school_id = ? AND class_name = ? AND stream_name = ?");
    $exists->execute([$schoolId, $className, $streamName]);
    if ($exists->fetchColumn()) {
        $pdo->prepare("UPDATE streams SET class_id = ? WHERE school_id = ? AND class_name = ? AND stream_name = ?")
            ->execute([$classId, $schoolId, $className, $streamName]);
    } else {
        $pdo->prepare("INSERT INTO streams (school_id, class_name, stream_name, class_id) VALUES (?, ?, ?, ?)")
            ->execute([$schoolId, $className, $streamName, $classId]);
    }

    return $classId;
}

$school_type_stmt = $pdo->prepare("SELECT school_type FROM schools WHERE id = ?");
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

// Primary/Secondary schools get entirely different class structures --
// see _setup/school_type_primary_secondary.sql. Sourced from
// scholar_class_ladder() in auth_guard.php -- the single source of truth
// also used by Close Year's promotion logic in settings.php, so the two
// can never drift apart.
$LEVEL_CLASSES = scholar_class_ladder($school_type);
$ALL_CLASS_NAMES = array_merge(...array_values($LEVEL_CLASSES));

// ---- Auto-create the ladder's base classes (no stream) ----
// The ladder (S.1-S.6, or Primary's P.1-P.7) is fixed and known in
// advance -- there's nothing for the admin to actually decide by manually
// adding "S.1", "S.2", ... one at a time through a wizard. Every class
// name with no row yet at all gets one, unstreamed, automatically; a
// school that genuinely needs streams still adds those explicitly below,
// but starting from "everything already exists" instead of "everything
// needs to be built" is the whole point.
$existing_class_names_stmt = $pdo->prepare("SELECT DISTINCT class_name FROM classes WHERE school_id = ?");
$existing_class_names_stmt->execute([$school_id]);
$missing_class_names = array_diff($ALL_CLASS_NAMES, $existing_class_names_stmt->fetchAll(PDO::FETCH_COLUMN));
if ($missing_class_names) {
    $auto_ins = $pdo->prepare("INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, NULL)");
    foreach ($missing_class_names as $cn) {
        $auto_ins->execute([$school_id, $cn]);
        scholar_ensure_compulsory_subjects($pdo, $school_id, $cn);
    }
}

// ---- Add a stream: one action instead of "define stream" then "add
// class" -- creates the reusable streams-catalog row (other pages read
// from it) AND the actual classes row for it together. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stream'])) {
    $sclass = trim($_POST['stream_class_name'] ?? '');
    $sname = trim($_POST['stream_name'] ?? '');

    if (!in_array($sclass, $ALL_CLASS_NAMES, true) || $sname === '') {
        $error = 'Enter a stream name.';
    } else {
        $dup_class = $pdo->prepare("SELECT id FROM classes WHERE school_id = ? AND class_name = ? AND stream_name = ?");
        $dup_class->execute([$school_id, $sclass, $sname]);
        if ($dup_class->fetchColumn()) {
            $error = "{$sclass} {$sname} already exists.";
        } else {
            scholar_add_class_stream($pdo, $school_id, $sclass, $sname);
            scholar_ensure_compulsory_subjects($pdo, $school_id, $sclass);
            $success = "{$sclass} {$sname} added.";
        }
    }
}

// ---- One-click seed: Sciences + Arts for a given A-Level class (Secondary only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seed_alevel_streams'])) {
    $sclass = trim($_POST['seed_class_name'] ?? '');
    if ($school_type !== 'Secondary' || !in_array($sclass, $LEVEL_CLASSES['A-Level'] ?? [], true)) {
        $error = 'Invalid class for A-Level streams.';
    } else {
        foreach (['Sciences', 'Arts'] as $default_stream) {
            $dup_class = $pdo->prepare("SELECT id FROM classes WHERE school_id = ? AND class_name = ? AND stream_name = ?");
            $dup_class->execute([$school_id, $sclass, $default_stream]);
            if ($dup_class->fetchColumn()) continue;
            scholar_add_class_stream($pdo, $school_id, $sclass, $default_stream);
        }
        $success = "Sciences and Arts classes added for {$sclass}.";
    }
}

// ---- Delete a class ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_class'])) {
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
    $del->execute([$class_id, $school_id]);
    $success = 'Class deleted. Students in it are now unassigned rather than deleted.';
}

// ---- Assign / change a class's class teacher ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_class_teacher'])) {
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $teacher_id = (int) ($_POST['teacher_staff_id'] ?? 0);

    if ($teacher_id <= 0) {
        $error = 'Pick a teacher to assign.';
    } else {
        $valid_teacher = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ? AND staff_category = 'Teaching'");
        $valid_teacher->execute([$teacher_id, $school_id]);
        if (!$valid_teacher->fetchColumn()) {
            $error = 'That teacher was not found for this school.';
        } else {
            $upd = $pdo->prepare("UPDATE classes SET class_teacher_id = ? WHERE id = ? AND school_id = ?");
            $upd->execute([$teacher_id, $class_id, $school_id]);
            $success = 'Class teacher assigned.';
        }
    }
}

// ---- Remove a class's class teacher ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_class_teacher'])) {
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE classes SET class_teacher_id = NULL WHERE id = ? AND school_id = ?");
    $upd->execute([$class_id, $school_id]);
    $success = 'Class teacher removed.';
}

// $ALL_CLASS_NAMES only ever comes from the fixed Primary/Secondary
// arrays above (never user input), so interpolating it into FIELD() is
// safe -- there's no way to build this ordering with a bound parameter.
$class_order_sql = "'" . implode("','", $ALL_CLASS_NAMES) . "'";

$classes = $pdo->prepare("
    SELECT c.id, c.class_name, c.stream_name, c.class_teacher_id,
           CONCAT(t.first_name, ' ', t.last_name) AS class_teacher_name,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
    FROM classes c
    LEFT JOIN staff t ON t.staff_id = c.class_teacher_id AND t.school_id = c.school_id
    WHERE c.school_id = ?
    ORDER BY FIELD(c.class_name, $class_order_sql), c.stream_name
");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

$teaching_staff = $pdo->prepare("
    SELECT staff_id, CONCAT(first_name, ' ', last_name) AS full_name
    FROM staff
    WHERE school_id = ? AND staff_category = 'Teaching'
    ORDER BY first_name, last_name
");
$teaching_staff->execute([$school_id]);
$teaching_staff = $teaching_staff->fetchAll();

$streams_raw = $pdo->prepare("
    SELECT class_name, stream_name FROM streams
    WHERE school_id = ?
    ORDER BY FIELD(class_name, $class_order_sql), stream_name
");
$streams_raw->execute([$school_id]);
$streams_by_class = [];
foreach ($streams_raw->fetchAll() as $row) {
    $streams_by_class[$row['class_name']][] = $row['stream_name'];
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'classes';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);}
.ghost-btn{background:transparent;color:var(--purple);border:1px solid rgba(168,85,247,0.4);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;margin:2px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.muted{color:var(--muted);font-size:0.8rem;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:160px;}
.stream-block{border-top:1px solid var(--border);padding-top:14px;margin-top:14px;}
</style>
    <main class="main-content">
    <div class="page-inner">
    <h1 style="font-size:1.4rem;">Manage Classes</h1>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="section">
        <h2 style="font-size:1rem;margin:0;">Streams</h2>
        <p class="muted">Every class from <?= htmlspecialchars(reset($ALL_CLASS_NAMES), ENT_QUOTES) ?> to <?= htmlspecialchars(end($ALL_CLASS_NAMES), ENT_QUOTES) ?> already exists below — only add a stream here if this school actually splits a class into more than one (e.g. S.1 A / S.1 B). No streams means the class stays as one group.</p>

        <?php foreach ($ALL_CLASS_NAMES as $cn): ?>
        <div class="stream-block">
            <strong><?= $cn ?></strong>
            <?php if (!empty($streams_by_class[$cn])): ?>
                <?php foreach ($streams_by_class[$cn] as $sn): ?>
                    <span class="pill"><?= htmlspecialchars($sn, ENT_QUOTES) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="muted">No streams.</span>
            <?php endif; ?>

            <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin-left:10px;">
                <input type="hidden" name="stream_class_name" value="<?= $cn ?>">
                <input type="text" name="stream_name" placeholder="e.g. A, Blue, Sciences" style="width:150px;padding:6px 8px;margin:0;" required>
                <button type="submit" name="add_stream" value="1" style="margin-top:0;padding:6px 12px;font-size:0.78rem;">+ Add Stream</button>
            </form>

            <?php if ($school_type === 'Secondary' && in_array($cn, $LEVEL_CLASSES['A-Level'] ?? [], true) && empty($streams_by_class[$cn])): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="seed_class_name" value="<?= $cn ?>">
                    <button type="submit" name="seed_alevel_streams" value="1" class="ghost-btn" style="margin-top:0;padding:4px 10px;font-size:0.75rem;">+ Seed Sciences &amp; Arts</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 14px;">All Classes</h2>
        <p class="muted" style="margin-top:-8px;">The class teacher can view and print report cards for students in their class, and is who a "Print My Class's Reports" bulk action scopes to.</p>
        <table>
            <tr><th>Class</th><th>Stream</th><th>Students</th><th>Class Teacher</th><th></th></tr>
            <?php foreach ($classes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['class_name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($c['stream_name'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= (int) $c['student_count'] ?></td>
                <td>
                    <?php if ($c['class_teacher_id']): ?>
                        <span class="pill"><?= htmlspecialchars(trim($c['class_teacher_name']), ENT_QUOTES) ?></span>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" name="remove_class_teacher" value="1" class="danger-btn" style="margin-top:4px;padding:3px 10px;font-size:0.75rem;">Remove</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="row" style="gap:6px;margin:0;">
                            <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                            <select name="teacher_staff_id" style="min-width:160px;" required>
                                <option value="">-- Select teacher --</option>
                                <?php foreach ($teaching_staff as $t): ?>
                                    <option value="<?= (int) $t['staff_id'] ?>"><?= htmlspecialchars(trim($t['full_name']), ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="assign_class_teacher" value="1" style="margin-top:0;padding:8px 14px;font-size:0.78rem;">Assign</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" onsubmit="return confirm('Delete this class? Students in it will become unassigned, not deleted.');">
                        <input type="hidden" name="class_id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" name="delete_class" value="1" class="danger-btn" style="margin-top:0;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>