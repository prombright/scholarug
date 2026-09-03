<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TIMETABLE DAY STRUCTURE
|--------------------------------------------------------------------------
| Defines this school's own period grid (timetable_periods) that the
| generator (timetable_generate.php) places lessons into. "Quick Setup"
| below builds a uniform pattern across the chosen days in one submit --
| the manual table under it still lets a school hand-edit a single day
| (e.g. a shorter Friday) afterward without redoing the whole week.
|
| Re-running Quick Setup replaces the school's entire existing period grid
| for the days it covers -- ON DELETE CASCADE on timetable_entries.period_id
| means any already-generated timetable is cleared along with it, which is
| the right call: a changed day structure invalidates whatever was placed
| against the old one.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';
$success = '';

$DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ---- Quick Setup: replace the whole grid for the chosen days ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_setup'])) {
    $days = array_map('intval', $_POST['days'] ?? []);
    $dayStart = $_POST['day_start'] ?? '08:00';
    $lessonMinutes = max(20, min(120, (int) ($_POST['lesson_minutes'] ?? 40)));
    $periodsPerDay = max(1, min(14, (int) ($_POST['periods_per_day'] ?? 8)));
    $breakAfter = (int) ($_POST['break_after'] ?? 0);
    $breakMinutes = max(0, min(60, (int) ($_POST['break_minutes'] ?? 20)));
    $lunchAfter = (int) ($_POST['lunch_after'] ?? 0);
    $lunchMinutes = max(0, min(90, (int) ($_POST['lunch_minutes'] ?? 45)));

    if (empty($days)) {
        $error = 'Pick at least one day.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $dayStart)) {
        $error = 'Day start time looks wrong.';
    } else {
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM timetable_periods WHERE school_id = ? AND day_of_week = ?");
            $ins = $pdo->prepare("
                INSERT INTO timetable_periods (school_id, day_of_week, period_number, label, start_time, end_time, is_teaching_period)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($days as $day) {
                if ($day < 1 || $day > 7) {
                    continue;
                }
                $del->execute([$school_id, $day]);

                $cursor = DateTime::createFromFormat('H:i', $dayStart);
                $periodNumber = 0;

                for ($lesson = 1; $lesson <= $periodsPerDay; $lesson++) {
                    $periodNumber++;
                    $start = clone $cursor;
                    $cursor->modify("+{$lessonMinutes} minutes");
                    $ins->execute([$school_id, $day, $periodNumber, "Period {$lesson}", $start->format('H:i:s'), $cursor->format('H:i:s'), 1]);

                    if ($breakAfter > 0 && $lesson === $breakAfter && $breakMinutes > 0) {
                        $periodNumber++;
                        $start = clone $cursor;
                        $cursor->modify("+{$breakMinutes} minutes");
                        $ins->execute([$school_id, $day, $periodNumber, 'Break', $start->format('H:i:s'), $cursor->format('H:i:s'), 0]);
                    }
                    if ($lunchAfter > 0 && $lesson === $lunchAfter && $lunchMinutes > 0) {
                        $periodNumber++;
                        $start = clone $cursor;
                        $cursor->modify("+{$lunchMinutes} minutes");
                        $ins->execute([$school_id, $day, $periodNumber, 'Lunch', $start->format('H:i:s'), $cursor->format('H:i:s'), 0]);
                    }
                }
            }

            $pdo->commit();
            $success = 'Day structure saved. Any previously generated timetable for the affected days was cleared -- regenerate when ready.';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not save the day structure. Please try again.';
        }
    }
}

// ---- Manual edit of one row (time/label tweak without redoing the day) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_period'])) {
    $periodId = (int) ($_POST['period_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $isTeaching = isset($_POST['is_teaching_period']) ? 1 : 0;

    if ($label === '' || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        $error = 'Please fill in a label and valid start/end times.';
    } else {
        $pdo->prepare("
            UPDATE timetable_periods SET label = ?, start_time = ?, end_time = ?, is_teaching_period = ?
            WHERE id = ? AND school_id = ?
        ")->execute([$label, $startTime, $endTime, $isTeaching, $periodId, $school_id]);
        $success = 'Period updated.';
    }
}

// ---- Delete one row ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period'])) {
    $periodId = (int) ($_POST['period_id'] ?? 0);
    $pdo->prepare("DELETE FROM timetable_periods WHERE id = ? AND school_id = ?")->execute([$periodId, $school_id]);
    $success = 'Period removed.';
}

$periods = $pdo->prepare("SELECT * FROM timetable_periods WHERE school_id = ? ORDER BY day_of_week, period_number");
$periods->execute([$school_id]);
$periods = $periods->fetchAll();

$byDay = [];
foreach ($periods as $p) {
    $byDay[(int) $p['day_of_week']][] = $p;
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'timetable';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
select,input{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.75rem;margin-top:0;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.day-check{display:inline-flex;align-items:center;gap:6px;padding:6px 12px 6px 0;font-size:0.85rem;}
.day-check input{width:auto;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:140px;}
.empty{color:var(--muted);font-size:0.85rem;}
.day-heading{font-size:0.95rem;margin:22px 0 10px;color:var(--cyan);}
.brk{color:var(--muted);font-style:italic;}
.inline-form{display:flex;gap:6px;align-items:center;margin:0;flex-wrap:wrap;}
.inline-form input[type=text],.inline-form input[type=time]{width:auto;padding:6px;font-size:0.8rem;}
</style>
    <main class="main-content">
    <div class="page-inner">
        <h1 style="font-size:1.4rem;">Timetable — Day Structure</h1>
        <p class="empty" style="margin-bottom:18px;">Set up your school's periods, breaks and lunch before generating a timetable. This only needs doing once (or whenever your daily schedule changes).</p>

        <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 6px;">Quick Setup</h2>
            <p class="empty">Builds the same pattern across every day you tick below, replacing whatever those days currently have.</p>
            <form method="post">
                <label>Days</label>
                <div>
                    <?php foreach ($DAY_NAMES as $num => $name): ?>
                        <label class="day-check">
                            <input type="checkbox" name="days[]" value="<?= $num ?>" <?= $num <= 5 ? 'checked' : '' ?>>
                            <?= $name ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="row">
                    <div>
                        <label>Day Starts At</label>
                        <input type="time" name="day_start" value="08:00" required>
                    </div>
                    <div>
                        <label>Minutes per Lesson</label>
                        <input type="number" name="lesson_minutes" value="40" min="20" max="120" required>
                    </div>
                    <div>
                        <label>Lessons per Day</label>
                        <input type="number" name="periods_per_day" value="8" min="1" max="14" required>
                    </div>
                </div>
                <div class="row">
                    <div>
                        <label>Break After Lesson #</label>
                        <input type="number" name="break_after" value="2" min="0" max="14">
                    </div>
                    <div>
                        <label>Break Length (min)</label>
                        <input type="number" name="break_minutes" value="20" min="0" max="60">
                    </div>
                    <div>
                        <label>Lunch After Lesson #</label>
                        <input type="number" name="lunch_after" value="4" min="0" max="14">
                    </div>
                    <div>
                        <label>Lunch Length (min)</label>
                        <input type="number" name="lunch_minutes" value="45" min="0" max="90">
                    </div>
                </div>
                <button type="submit" name="quick_setup" value="1">Save Day Structure</button>
            </form>
        </div>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 6px;">Current Structure</h2>
            <?php if (empty($byDay)): ?>
                <div class="empty">Nothing set up yet — use Quick Setup above.</div>
            <?php endif; ?>
            <?php $rowForms = []; ?>
            <?php foreach ($DAY_NAMES as $num => $name): if (empty($byDay[$num])) continue; ?>
                <div class="day-heading"><?= $name ?></div>
                <table>
                    <tr><th>#</th><th>Label</th><th>Start</th><th>End</th><th>Teaching?</th><th></th></tr>
                    <?php foreach ($byDay[$num] as $p):
                        $rowFormId = 'period-form-' . (int) $p['id'];
                        $rowForms[] = $rowFormId;
                        $isTeaching = (int) $p['is_teaching_period'] === 1;
                    ?>
                        <tr>
                            <td><?= (int) $p['period_number'] ?></td>
                            <?php if (!$isTeaching): ?>
                                <td colspan="3" class="brk"><?= htmlspecialchars($p['label'], ENT_QUOTES) ?> (<?= substr($p['start_time'], 0, 5) ?>–<?= substr($p['end_time'], 0, 5) ?>)</td>
                                <td class="brk">Break/Lunch</td>
                                <td>
                                    <button type="submit" form="<?= $rowFormId ?>" name="delete_period" value="1" class="danger-btn">Remove</button>
                                </td>
                            <?php else: ?>
                                <td><input type="text" name="label" form="<?= $rowFormId ?>" value="<?= htmlspecialchars($p['label'], ENT_QUOTES) ?>"></td>
                                <td><input type="time" name="start_time" form="<?= $rowFormId ?>" value="<?= substr($p['start_time'], 0, 5) ?>"></td>
                                <td><input type="time" name="end_time" form="<?= $rowFormId ?>" value="<?= substr($p['end_time'], 0, 5) ?>"></td>
                                <td><input type="checkbox" name="is_teaching_period" form="<?= $rowFormId ?>" checked style="width:auto;"></td>
                                <td>
                                    <button type="submit" form="<?= $rowFormId ?>" name="update_period" value="1" style="padding:6px 10px;font-size:0.72rem;margin:0;">Save</button>
                                    <button type="submit" form="<?= $rowFormId ?>" name="delete_period" value="1" class="danger-btn">Remove</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>
            <?php
            // HTML5 out-of-band forms: every input/button above targets one of
            // these via its form="..." attribute rather than being nested
            // inside a <form>, since a <form> can't legally wrap just some
            // <td>s within a table row. Rendered once here, after the table,
            // each carrying only the hidden period_id its row needs.
            foreach ($rowForms as $rowFormId):
                $pid = (int) substr($rowFormId, strlen('period-form-'));
            ?>
                <form id="<?= $rowFormId ?>" method="post"><input type="hidden" name="period_id" value="<?= $pid ?>"></form>
            <?php endforeach; ?>
        </div>

        <div class="section" style="text-align:center;">
            <a href="timetable_generate.php" style="color:#04222a;background:var(--cyan);padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-size:0.9rem;">Continue to Generate Timetable →</a>
        </div>
    </div>
    </main>
</div><!-- /.app-shell -->
</body>
</html>
