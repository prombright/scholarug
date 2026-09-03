<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

$school_id = (int) $_SESSION['school_id'];
$staff_id = (int) $_SESSION['staff_id'];
$DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$schoolStmt = $pdo->prepare("SELECT current_term, current_year FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
$term = $school['current_term'] ?: 'Term 1';
$year = $school['current_year'] ?: (string) date('Y');

// Same grid-building approach as school_admin/timetable_view.php, just
// scoped to this one teacher's own lessons instead of a chosen class.
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
$eStmt = $pdo->prepare("
    SELECT te.day_of_week, te.period_id, sub.subject_name, sub.papers_count, te.paper_number,
           c.class_name, c.stream_name
    FROM timetable_entries te
    JOIN subjects sub ON sub.id = te.subject_id
    JOIN classes c ON c.id = te.class_id
    WHERE te.school_id = ? AND te.teacher_id = ? AND te.term = ? AND te.academic_year = ?
");
$eStmt->execute([$school_id, $staff_id, $term, $year]);
foreach ($eStmt->fetchAll() as $e) {
    $entries[(int) $e['day_of_week'] . ':' . (int) $e['period_id']] = $e;
}

$staff_name_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
$staff_name_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $staff_name_stmt->fetchColumn() > 0;

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$staff_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'timetable';
require_once __DIR__ . '/_teacher_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 4px;}
.page-sub{color:var(--muted);font-size:0.85rem;margin-bottom:18px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top;}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.time-col{white-space:nowrap;color:var(--muted);font-size:0.72rem;}
.brk-row td{background:rgba(255,255,255,0.02);color:var(--muted);font-style:italic;}
.lesson-cell{background:rgba(0,168,168,0.06);border-radius:6px;padding:8px;}
.lesson-cell .subj{font-weight:700;color:var(--text);}
.lesson-cell .cls{color:var(--muted);font-size:0.72rem;margin-top:2px;}
.free-cell{color:var(--muted);font-size:0.75rem;opacity:0.5;}
.empty{color:var(--muted);font-size:0.9rem;}
</style>
<div class="page-title">My Timetable</div>
<div class="page-sub"><?= htmlspecialchars($term, ENT_QUOTES) ?>, <?= htmlspecialchars($year, ENT_QUOTES) ?></div>

    <?php if (empty($gridRows)): ?>
        <div class="section"><div class="empty">Your school hasn't set up a timetable yet.</div></div>
    <?php else: ?>
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
                                    <?php elseif ($entry): ?>
                                        <div class="lesson-cell">
                                            <div class="subj"><?= htmlspecialchars($entry['subject_name'], ENT_QUOTES) ?><?= (int) $entry['papers_count'] > 1 ? ' P' . (int) $entry['paper_number'] : '' ?></div>
                                            <div class="cls"><?= htmlspecialchars($entry['class_name'] . ' ' . ($entry['stream_name'] ?? ''), ENT_QUOTES) ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="free-cell">Free</span>
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
    </div>
</div>
</body>
</html>
