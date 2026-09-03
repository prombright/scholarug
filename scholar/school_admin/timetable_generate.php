<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_timetable_engine.php';

require_role(['school_admin']);

$school_id = current_school_id();

$schoolStmt = $pdo->prepare("SELECT current_term, current_year FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
$term = $school['current_term'] ?: 'Term 1';
$year = $school['current_year'] ?: (string) date('Y');

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $result = scholar_generate_timetable($pdo, $school_id, $term, $year);

    if (!empty($result['unplaced'])) {
        // Attach readable names for the report -- the engine itself only
        // deals in ids, it has no business knowing about display strings.
        $teacherNames = [];
        $classNames = [];
        $subjectNames = [];
        $tStmt = $pdo->prepare("SELECT staff_id, first_name, last_name FROM staff WHERE school_id = ?");
        $tStmt->execute([$school_id]);
        foreach ($tStmt->fetchAll() as $t) {
            $teacherNames[(int) $t['staff_id']] = trim($t['first_name'] . ' ' . $t['last_name']);
        }
        $cStmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ?");
        $cStmt->execute([$school_id]);
        foreach ($cStmt->fetchAll() as $c) {
            $classNames[(int) $c['id']] = $c['class_name'] . ($c['stream_name'] ? ' ' . $c['stream_name'] : '');
        }
        $sStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ?");
        $sStmt->execute([$school_id]);
        foreach ($sStmt->fetchAll() as $s) {
            $subjectNames[(int) $s['id']] = $s['subject_name'];
        }
        foreach ($result['unplaced'] as &$u) {
            $u['teacher_name'] = $teacherNames[$u['teacher_id']] ?? 'Unknown';
            $u['class_name'] = $classNames[$u['class_id']] ?? 'Unknown';
            $u['subject_name'] = $subjectNames[$u['subject_id']] ?? 'Unknown';
        }
        unset($u);
    }
}

// Small overview so the admin knows what the generator is about to work with.
$periodCountStmt = $pdo->prepare("SELECT COUNT(*) FROM timetable_periods WHERE school_id = ? AND is_teaching_period = 1");
$periodCountStmt->execute([$school_id]);
$periodCount = (int) $periodCountStmt->fetchColumn();

$assignmentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_assignments WHERE school_id = ?");
$assignmentCountStmt->execute([$school_id]);
$assignmentCount = (int) $assignmentCountStmt->fetchColumn();

$hasExistingStmt = $pdo->prepare("SELECT COUNT(*) FROM timetable_entries WHERE school_id = ? AND term = ? AND academic_year = ?");
$hasExistingStmt->execute([$school_id, $term, $year]);
$hasExisting = (int) $hasExistingStmt->fetchColumn();

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'timetable';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.stat-row{display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid var(--border);font-size:0.85rem;}
.stat-row:first-child{border-top:none;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 24px;border-radius:8px;cursor:pointer;font-size:0.9rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.alert.warn{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#fbbf24;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.empty{color:var(--muted);font-size:0.85rem;}
</style>
    <main class="main-content">
    <div class="page-inner">
        <h1 style="font-size:1.4rem;">Generate Timetable</h1>
        <p class="empty" style="margin-bottom:18px;">Generating for <strong><?= htmlspecialchars($term, ENT_QUOTES) ?>, <?= htmlspecialchars($year, ENT_QUOTES) ?></strong> — this school's current term/year.</p>

        <div class="section">
            <div class="stat-row"><span>Teaching periods/week set up</span><strong><?= $periodCount ?></strong></div>
            <div class="stat-row"><span>Teaching assignments on file</span><strong><?= $assignmentCount ?></strong></div>
            <div class="stat-row"><span>Existing timetable entries this term</span><strong><?= $hasExisting ?></strong></div>
        </div>

        <?php if ($periodCount === 0): ?>
            <div class="alert error">No teaching periods set up yet. <a href="timetable_setup.php" style="color:inherit;text-decoration:underline;">Set up your day structure first</a>.</div>
        <?php elseif ($assignmentCount === 0): ?>
            <div class="alert error">No teaching assignments on file yet. <a href="assign_teacher.php" style="color:inherit;text-decoration:underline;">Assign teachers to subjects/classes first</a>.</div>
        <?php else: ?>
            <?php if ($hasExisting > 0 && $result === null): ?>
                <div class="alert warn">A timetable already exists for this term. Generating again replaces it completely.</div>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('Generate the timetable for <?= htmlspecialchars($term, ENT_QUOTES) ?> <?= htmlspecialchars($year, ENT_QUOTES) ?>? Any existing timetable for this term will be replaced.');">
                <button type="submit" name="generate" value="1">Generate Timetable</button>
            </form>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <?php if (!empty($result['error'])): ?>
                <div class="alert error" style="margin-top:20px;"><?= htmlspecialchars($result['error'], ENT_QUOTES) ?></div>
            <?php else: ?>
                <div class="alert <?= empty($result['unplaced']) ? 'success' : 'warn' ?>" style="margin-top:20px;">
                    Placed <?= (int) $result['placed_count'] ?> of <?= (int) $result['requested_count'] ?> required weekly lessons.
                    <?= empty($result['unplaced']) ? ' Every lesson was placed with no conflicts.' : ' ' . count($result['unplaced']) . ' assignment(s) couldn\'t be fully placed — see below.' ?>
                    <a href="timetable_view.php" style="color:inherit;text-decoration:underline;">View the timetable →</a>
                </div>

                <?php if (!empty($result['unplaced'])): ?>
                <div class="section">
                    <h2 style="font-size:1rem;margin:0 0 14px;">Couldn't Fully Place</h2>
                    <table>
                        <tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Needed</th><th>Placed</th></tr>
                        <?php foreach ($result['unplaced'] as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['teacher_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($u['subject_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($u['class_name'], ENT_QUOTES) ?></td>
                                <td><?= (int) $u['needed'] ?></td>
                                <td><?= (int) $u['placed'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="empty" style="margin-top:12px;">Usually fixed by adding more teaching periods to the day structure, or lowering this assignment's periods/week.</p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </main>
</div><!-- /.app-shell -->
</body>
</html>
