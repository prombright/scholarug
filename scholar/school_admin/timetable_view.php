<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_timetable_view_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$DAY_NAMES = SCHOLAR_TIMETABLE_DAY_NAMES;

$overview = admin_timetable_view_overview($pdo, $school_id);
$term = $overview['term'];
$year = $overview['year'];
$classes = $overview['classes'];

$selectedClassId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$error = '';
$success = '';

// ---- Manual override of one slot: reassign or clear it ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cell'])) {
    $cellClassId = (int) ($_POST['class_id'] ?? 0);
    $day = (int) ($_POST['day'] ?? 0);
    $periodId = (int) ($_POST['period_id'] ?? 0);
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

    $save_result = admin_timetable_view_save_cell($pdo, $school_id, $term, $year, $cellClassId, $day, $periodId, $assignmentId);
    if ($save_result['ok']) {
        $success = $save_result['message'];
    } else {
        $error = $save_result['message'];
    }
    $selectedClassId = $cellClassId;
}

$editDay = isset($_GET['edit_day']) ? (int) $_GET['edit_day'] : 0;
$editPeriodId = isset($_GET['edit_period']) ? (int) $_GET['edit_period'] : 0;

$grid = admin_timetable_view_grid($pdo, $school_id, $term, $year, $selectedClassId);
$classAssignments = $grid['class_assignments'];
$gridRows = $grid['grid_rows'];
$activeDays = $grid['active_days'];
$entries = $grid['entries'];

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'timetable';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
select{padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;max-width:300px;}
table{width:100%;border-collapse:collapse;font-size:0.8rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top;}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.time-col{white-space:nowrap;color:var(--muted);font-size:0.72rem;}
.brk-row td{background:rgba(255,255,255,0.02);color:var(--muted);font-style:italic;}
.lesson-cell{background:rgba(0,168,168,0.06);border-radius:6px;padding:8px;}
.lesson-cell .subj{font-weight:700;color:var(--text);}
.lesson-cell .tchr{color:var(--muted);font-size:0.72rem;margin-top:2px;}
.free-cell{color:var(--muted);font-size:0.75rem;opacity:0.5;}
.empty{color:var(--muted);font-size:0.85rem;}
.cell-link{display:block;text-decoration:none;color:inherit;border-radius:6px;transition:background .15s;}
.cell-link:hover{background:rgba(255,255,255,0.05);}
.cell-link.editing{outline:2px solid var(--cyan);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;margin-top:14px;}
.cancel-link{color:var(--muted);font-size:0.8rem;margin-left:14px;text-decoration:none;}
</style>
    <main class="main-content">
    <div class="page-inner">
        <h1 style="font-size:1.4rem;">Timetable</h1>
        <p class="empty" style="margin-bottom:18px;"><?= htmlspecialchars($term, ENT_QUOTES) ?>, <?= htmlspecialchars($year, ENT_QUOTES) ?> — <a href="timetable_generate.php" style="color:var(--cyan);">Regenerate →</a></p>

        <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

        <div class="section">
            <label style="display:block;font-size:0.8rem;color:var(--muted);margin-bottom:6px;">Class</label>
            <select onchange="window.location.href='?class_id='+this.value">
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $selectedClassId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['class_name'] . ' ' . ($c['stream_name'] ?? ''), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (empty($gridRows)): ?>
            <div class="empty">No day structure set up yet. <a href="timetable_setup.php" style="color:var(--cyan);">Set it up first →</a></div>
        <?php elseif (empty($classes)): ?>
            <div class="empty">No classes set up yet.</div>
        <?php else: ?>
            <?php
            // Only offer editing a slot that's actually a real teaching
            // period in the current grid, for the currently selected day --
            // silently ignores a stale/tampered edit_day+edit_period combo
            // rather than erroring.
            $editingRow = null;
            if ($editDay > 0 && $editPeriodId > 0) {
                foreach ($gridRows as $row) {
                    if ($row['is_teaching'] && ($row['by_day'][$editDay] ?? null) === $editPeriodId) {
                        $editingRow = $row;
                        break;
                    }
                }
            }
            ?>
            <?php if ($editingRow): ?>
                <div class="section" id="edit-panel">
                    <h2 style="font-size:1rem;margin:0 0 4px;">Edit <?= $DAY_NAMES[$editDay] ?>, <?= htmlspecialchars($editingRow['label'], ENT_QUOTES) ?></h2>
                    <p class="empty"><?= substr($editingRow['start'], 0, 5) ?>–<?= substr($editingRow['end'], 0, 5) ?></p>
                    <form method="post">
                        <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                        <input type="hidden" name="day" value="<?= $editDay ?>">
                        <input type="hidden" name="period_id" value="<?= $editPeriodId ?>">
                        <label>Subject / Teacher</label>
                        <select name="assignment_id">
                            <option value="0">— Leave Free —</option>
                            <?php
                            $currentEntry = $entries[$editDay . ':' . $editPeriodId] ?? null;
                            foreach ($classAssignments as $ca):
                                $caLabel = $ca['subject_name'] . ((int) $ca['papers_count'] > 1 ? ' P' . $ca['paper_number'] : '') . ' — ' . $ca['first_name'] . ' ' . $ca['last_name'];
                                $isCurrent = $currentEntry
                                    && (int) $currentEntry['subject_id'] === (int) $ca['subject_id']
                                    && (int) $currentEntry['teacher_id'] === (int) $ca['teacher_id']
                                    && (int) $currentEntry['paper_number'] === (int) $ca['paper_number'];
                            ?>
                                <option value="<?= (int) $ca['id'] ?>" <?= $isCurrent ? 'selected' : '' ?>><?= htmlspecialchars($caLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="save_cell" value="1">Save</button>
                        <a href="?class_id=<?= $selectedClassId ?>" class="cancel-link">Cancel</a>
                    </form>
                    <?php if (empty($classAssignments)): ?>
                        <p class="empty" style="margin-top:10px;">This class has no teaching assignments yet — <a href="assign_teacher.php" style="color:var(--cyan);">add some first</a>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="section">
                <table>
                    <tr>
                        <th>Time</th>
                        <?php foreach ($activeDays as $d): ?><th><?= $DAY_NAMES[$d] ?></th><?php endforeach; ?>
                    </tr>
                    <?php foreach ($gridRows as $row): ?>
                        <?php if (!$row['is_teaching']): ?>
                            <tr class="brk-row">
                                <td class="time-col"><?= substr($row['start'], 0, 5) ?>–<?= substr($row['end'], 0, 5) ?></td>
                                <td colspan="<?= count($activeDays) ?>"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="time-col"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?><br><?= substr($row['start'], 0, 5) ?>–<?= substr($row['end'], 0, 5) ?></td>
                                <?php foreach ($activeDays as $d): ?>
                                    <?php
                                    $periodId = $row['by_day'][$d] ?? null;
                                    $entry = $periodId ? ($entries[$d . ':' . $periodId] ?? null) : null;
                                    ?>
                                    <td>
                                        <?php if (!$periodId): ?>
                                            <span class="free-cell">—</span>
                                        <?php else: ?>
                                            <a class="cell-link <?= ($editDay === $d && $editPeriodId === $periodId) ? 'editing' : '' ?>"
                                               href="?class_id=<?= $selectedClassId ?>&edit_day=<?= $d ?>&edit_period=<?= $periodId ?>#edit-panel">
                                                <?php if ($entry): ?>
                                                    <div class="lesson-cell">
                                                        <div class="subj"><?= htmlspecialchars($entry['subject_name'], ENT_QUOTES) ?><?= (int) $entry['papers_count'] > 1 ? ' P' . (int) $entry['paper_number'] : '' ?></div>
                                                        <div class="tchr"><?= htmlspecialchars($entry['first_name'] . ' ' . $entry['last_name'], ENT_QUOTES) ?></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="free-cell">Free</span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
    </main>
</div><!-- /.app-shell -->
</body>
</html>
