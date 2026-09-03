<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();
$DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$schoolStmt = $pdo->prepare("SELECT current_term, current_year FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
$term = $school['current_term'] ?: 'Term 1';
$year = $school['current_year'] ?: (string) date('Y');

$classes = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

$selectedClassId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$error = '';
$success = '';

// ---- Manual override of one slot: reassign or clear it ----
// Same two conflict rules the generator itself enforces -- a teacher can't
// be in two places in one slot, and this class can't have two lessons in
// one slot (trivially satisfied here since we DELETE this exact
// class/day/period combo before inserting the replacement).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cell'])) {
    $cellClassId = (int) ($_POST['class_id'] ?? 0);
    $day = (int) ($_POST['day'] ?? 0);
    $periodId = (int) ($_POST['period_id'] ?? 0);
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

    $newEntry = null;
    if ($assignmentId > 0) {
        $aStmt = $pdo->prepare("SELECT teacher_id, subject_id, paper_number FROM teacher_assignments WHERE id = ? AND school_id = ? AND class_id = ?");
        $aStmt->execute([$assignmentId, $school_id, $cellClassId]);
        $newEntry = $aStmt->fetch();
        if (!$newEntry) {
            $error = 'That assignment no longer exists for this class.';
        }
    }

    if ($error === '') {
        if ($newEntry) {
            $conflictStmt = $pdo->prepare("
                SELECT te.id FROM timetable_entries te
                WHERE te.school_id = ? AND te.teacher_id = ? AND te.day_of_week = ? AND te.period_id = ?
                  AND te.term = ? AND te.academic_year = ? AND te.class_id != ?
            ");
            $conflictStmt->execute([$school_id, $newEntry['teacher_id'], $day, $periodId, $term, $year, $cellClassId]);
            if ($conflictStmt->fetchColumn()) {
                $error = 'That teacher already has a lesson with another class at this exact time.';
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    DELETE FROM timetable_entries
                    WHERE school_id = ? AND class_id = ? AND day_of_week = ? AND period_id = ? AND term = ? AND academic_year = ?
                ")->execute([$school_id, $cellClassId, $day, $periodId, $term, $year]);

                if ($newEntry) {
                    $pdo->prepare("
                        INSERT INTO timetable_entries (school_id, class_id, subject_id, teacher_id, paper_number, day_of_week, period_id, term, academic_year)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$school_id, $cellClassId, $newEntry['subject_id'], $newEntry['teacher_id'], $newEntry['paper_number'], $day, $periodId, $term, $year]);
                }
                $pdo->commit();
                $success = 'Slot updated.';
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not save that change. Please try again.';
            }
        }
    }
    $selectedClassId = $cellClassId;
}

$editDay = isset($_GET['edit_day']) ? (int) $_GET['edit_day'] : 0;
$editPeriodId = isset($_GET['edit_period']) ? (int) $_GET['edit_period'] : 0;

$classAssignments = [];
if ($selectedClassId > 0) {
    $caStmt = $pdo->prepare("
        SELECT ta.id, ta.subject_id, ta.teacher_id, sub.subject_name, sub.papers_count, ta.paper_number, st.first_name, st.last_name
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.id = ta.subject_id
        JOIN staff st ON st.staff_id = ta.teacher_id
        WHERE ta.school_id = ? AND ta.class_id = ?
        ORDER BY sub.subject_name, ta.paper_number
    ");
    $caStmt->execute([$school_id, $selectedClassId]);
    $classAssignments = $caStmt->fetchAll();
}

// The full grid structure comes from timetable_periods (so breaks/lunch
// show up even though they never have an entry), one row per distinct
// period_number/label/time combination that appears on ANY day -- schools
// using Quick Setup have identical structure across their chosen days, so
// this naturally produces one clean row per lesson slot.
$gridRows = [];
$daysPresent = [];
$periodsStmt = $pdo->prepare("SELECT * FROM timetable_periods WHERE school_id = ? ORDER BY period_number, day_of_week");
$periodsStmt->execute([$school_id]);
foreach ($periodsStmt->fetchAll() as $p) {
    $daysPresent[(int) $p['day_of_week']] = true;
    $rowKey = $p['period_number'];
    if (!isset($gridRows[$rowKey])) {
        $gridRows[$rowKey] = ['label' => $p['label'], 'start' => $p['start_time'], 'end' => $p['end_time'], 'is_teaching' => (int) $p['is_teaching_period'], 'by_day' => []];
    }
    $gridRows[$rowKey]['by_day'][(int) $p['day_of_week']] = (int) $p['id'];
}
ksort($gridRows);
$activeDays = array_keys($daysPresent);
sort($activeDays);

$entries = [];
if ($selectedClassId > 0) {
    $eStmt = $pdo->prepare("
        SELECT te.day_of_week, te.period_id, te.subject_id, te.teacher_id, sub.subject_name, sub.papers_count, te.paper_number,
               st.first_name, st.last_name
        FROM timetable_entries te
        JOIN subjects sub ON sub.id = te.subject_id
        JOIN staff st ON st.staff_id = te.teacher_id
        WHERE te.school_id = ? AND te.class_id = ? AND te.term = ? AND te.academic_year = ?
    ");
    $eStmt->execute([$school_id, $selectedClassId, $term, $year]);
    foreach ($eStmt->fetchAll() as $e) {
        $entries[(int) $e['day_of_week'] . ':' . (int) $e['period_id']] = $e;
    }
}

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
