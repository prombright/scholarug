<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin', 'headteacher', 'dos']);

$school_id = current_school_id();
$addonActive = scholar_ilearning_addon_is_active($pdo, $school_id);

$addonStmt = $pdo->prepare('SELECT * FROM ilearning_addons WHERE school_id = ?');
$addonStmt->execute([$school_id]);
$addon = $addonStmt->fetch();

$topics_stmt = $pdo->prepare("
    SELECT t.title, t.status, t.term, t.year, c.class_name, s.subject_name,
           CONCAT(st.first_name, ' ', st.last_name) AS teacher_name,
           (SELECT COUNT(*) FROM ilearning_progress p WHERE p.topic_id = t.id AND p.status = 'completed') AS completed_count
    FROM ilearning_topics t
    JOIN classes c ON c.id = t.class_id
    JOIN subjects s ON s.id = t.subject_id
    JOIN staff st ON st.staff_id = t.teacher_id
    WHERE t.school_id = ?
    ORDER BY t.updated_at DESC
    LIMIT 100
");
$topics_stmt->execute([$school_id]);
$topics = $topics_stmt->fetchAll();

$sessions_stmt = $pdo->prepare("
    SELECT ls.title, ls.status, ls.scheduled_at, c.class_name, s.subject_name,
           CONCAT(st.first_name, ' ', st.last_name) AS teacher_name,
           (SELECT COUNT(*) FROM ilearning_live_attendance a WHERE a.session_id = ls.id) AS attendee_count
    FROM ilearning_live_sessions ls
    JOIN classes c ON c.id = ls.class_id
    JOIN subjects s ON s.id = ls.subject_id
    JOIN staff st ON st.staff_id = ls.teacher_id
    WHERE ls.school_id = ?
    ORDER BY ls.scheduled_at DESC
    LIMIT 50
");
$sessions_stmt->execute([$school_id]);
$sessions = $sessions_stmt->fetchAll();

$ACTIVE_NAV = 'ilearning';
require_once __DIR__ . '/../_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
<style>
.ilearn-card{background:#131b28;border:1px solid #2a3a52;border-radius:10px;padding:20px;margin-bottom:20px;}
.ilearn-card table{width:100%;border-collapse:collapse;font-size:0.85rem;}
.ilearn-card th,.ilearn-card td{text-align:left;padding:10px 14px;border-bottom:1px solid #2a3a52;}
.ilearn-card th{color:#64748b;text-transform:uppercase;font-size:0.7rem;}
.upgrade-box{background:rgba(0,168,168,0.08);border:1px solid #00A8A8;border-radius:10px;padding:16px;margin-bottom:20px;color:#67e8f9;}
.upgrade-box a{color:#00A8A8;font-weight:700;}
</style>

    <h2 style="color:#fff;">iLearning Overview</h2>
    <p style="color:#64748b;">School-wide view of teacher-posted topics and live classes.</p>

    <div class="upgrade-box">
        Live Classes Add-On: <strong><?= $addon ? htmlspecialchars(ucfirst($addon['status']), ENT_QUOTES, 'UTF-8') : 'Not purchased' ?></strong>
        <?php if (!$addonActive): ?> — <a href="addon_upgrade.php">Purchase / Manage →</a><?php else: ?> — <a href="addon_upgrade.php">Manage →</a><?php endif; ?>
    </div>

    <div class="ilearn-card">
        <h3 style="color:#fff;margin-top:0;">Topics (latest 100)</h3>
        <table>
            <thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Term</th><th>Status</th><th>Completed</th></tr></thead>
            <tbody>
            <?php if (empty($topics)): ?><tr><td colspan="7" style="color:#64748b;">No topics yet.</td></tr><?php endif; ?>
            <?php foreach ($topics as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['teacher_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['term'] . ' ' . $t['year'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int) $t['completed_count'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ilearn-card">
        <h3 style="color:#fff;margin-top:0;">Live Sessions (latest 50)</h3>
        <table>
            <thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Teacher</th><th>When</th><th>Status</th><th>Attendees</th></tr></thead>
            <tbody>
            <?php if (empty($sessions)): ?><tr><td colspan="7" style="color:#64748b;">No live sessions yet.</td></tr><?php endif; ?>
            <?php foreach ($sessions as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($s['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($s['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($s['teacher_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($s['scheduled_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int) $s['attendee_count'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>
