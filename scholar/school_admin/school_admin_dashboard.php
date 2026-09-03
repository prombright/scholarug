<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();

$school_type_stmt = $pdo->prepare("SELECT school_type FROM schools WHERE id = ?");
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';
$is_primary = $school_type === 'Primary';

$student_count = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
$student_count->execute([$school_id]);
$student_count = (int) $student_count->fetchColumn();

$staff_count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ?");
$staff_count->execute([$school_id]);
$staff_count = (int) $staff_count->fetchColumn();

$class_count = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ?");
$class_count->execute([$school_id]);
$class_count = (int) $class_count->fetchColumn();

$pending_feedback = $pdo->prepare("SELECT COUNT(*) FROM parent_feedback WHERE school_id = ? AND status = 'new'");
$pending_feedback->execute([$school_id]);
$pending_feedback = (int) $pending_feedback->fetchColumn();

// Real enrollment breakdown for the "Students per Class" bar chart --
// LEFT JOIN so an empty class still shows a zero-length bar instead of
// silently disappearing from the chart.
$class_dist_stmt = $pdo->prepare(
    "SELECT c.class_name, c.stream_name, COUNT(s.id) AS cnt
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id
     WHERE c.school_id = ?
     GROUP BY c.id, c.class_name, c.stream_name
     ORDER BY c.class_name, c.stream_name"
);
$class_dist_stmt->execute([$school_id]);
$class_distribution = $class_dist_stmt->fetchAll();
$class_dist_max = 1;
foreach ($class_distribution as $row) {
    $class_dist_max = max($class_dist_max, (int) $row['cnt']);
}

// Real gender split for the donut chart.
$gender_stmt = $pdo->prepare("SELECT sex, COUNT(*) AS cnt FROM students WHERE school_id = ? GROUP BY sex");
$gender_stmt->execute([$school_id]);
$gender_male = 0;
$gender_female = 0;
foreach ($gender_stmt->fetchAll() as $row) {
    if ($row['sex'] === 'Male') {
        $gender_male = (int) $row['cnt'];
    } elseif ($row['sex'] === 'Female') {
        $gender_female = (int) $row['cnt'];
    }
}
$gender_total = $gender_male + $gender_female;
$gender_male_pct = $gender_total > 0 ? round($gender_male / $gender_total * 100) : 0;
$gender_female_pct = $gender_total > 0 ? (100 - $gender_male_pct) : 0;

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'home';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
:root{ --green:#10b981; --amber:#f59e0b; --purple:#a855f7; }
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:32px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:14px;}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;}
.stat-card.students .stat-icon{background:rgba(0,168,168,.15);color:var(--cyan);}
.stat-card.staff .stat-icon{background:rgba(16,185,129,.15);color:var(--green);}
.stat-card.classes .stat-icon{background:rgba(245,158,11,.15);color:var(--amber);}
.stat-card.messages .stat-icon{background:rgba(168,85,247,.15);color:var(--purple);}
.stat-card.alert .stat-icon{background:rgba(239,68,68,.15);color:var(--danger);}
.stat-card .n{font-size:1.7rem;font-weight:700;line-height:1.1;}
.stat-card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.module-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
.module-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:22px;text-decoration:none;color:var(--text);display:block;transition:border-color .15s;}
.module-card:hover{border-color:var(--cyan);}
.module-card .icon{width:36px;height:36px;border-radius:10px;background:rgba(0,168,168,.12);color:var(--cyan);display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:14px;}
.module-card .title{font-weight:700;font-size:0.9rem;}
.module-card .desc{color:var(--muted);font-size:0.75rem;margin-top:4px;}
.module-card.disabled{opacity:0.4;pointer-events:none;}
h1{font-size:1.4rem;margin:0 0 4px;}
.welcome-sub{color:var(--muted);font-size:0.85rem;margin-bottom:28px;}
.section-label{font-size:0.95rem;font-weight:700;margin:36px 0 16px;}
.chart-section{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;}
@media(max-width:760px){.chart-section{grid-template-columns:1fr;}}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;}
.chart-card h2{font-size:0.9rem;margin-bottom:18px;color:var(--text);}
.chart-empty{color:var(--muted);font-size:0.85rem;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:0.8rem;}
.bar-row .bar-label{width:110px;flex-shrink:0;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bar-track{flex:1;background:var(--border);border-radius:6px;height:10px;overflow:hidden;}
.bar-fill{display:block;height:100%;background:var(--cyan);border-radius:6px;width:0;transition:width 1s cubic-bezier(.22,1,.36,1);}
.reveal.revealed .bar-fill{width:var(--w);}
.bar-row .bar-count{width:24px;text-align:right;color:var(--text);font-weight:600;}
.donut-wrap{display:flex;flex-direction:column;align-items:center;}
.donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;transform:scale(.7);opacity:0;transition:transform .6s cubic-bezier(.22,1,.36,1), opacity .6s ease;}
.reveal.revealed .donut{transform:scale(1);opacity:1;}
.donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-center .n{font-size:1.4rem;font-weight:700;}
.donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.donut-legend{display:flex;gap:18px;font-size:0.8rem;color:var(--muted);}
.donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
@media (prefers-reduced-motion: reduce){
    .bar-fill{transition:none;width:var(--w);}
    .donut{transition:none;transform:none;opacity:1;}
}
</style>
    <main class="main-content">
        <div class="page-inner">

            <h1>Welcome back</h1>
            <div class="welcome-sub">
                <?= htmlspecialchars($_SESSION['school_name'] ?? '', ENT_QUOTES) ?> ·
                <?= $is_primary ? 'Primary School' : 'Secondary School' ?> Admin Panel
                (<?= $is_primary ? 'Baby Class – P.7' : 'S.1 – S.6' ?>)
            </div>

            <div class="stat-grid">
                <div class="stat-card students"><div class="stat-icon"><i class="bi bi-people"></i></div><div><div class="n" data-count="<?= $student_count ?>"><?= $student_count ?></div><div class="label">Students</div></div></div>
                <div class="stat-card staff"><div class="stat-icon"><i class="bi bi-person-badge"></i></div><div><div class="n" data-count="<?= $staff_count ?>"><?= $staff_count ?></div><div class="label">Staff</div></div></div>
                <div class="stat-card classes"><div class="stat-icon"><i class="bi bi-diagram-3"></i></div><div><div class="n" data-count="<?= $class_count ?>"><?= $class_count ?></div><div class="label">Classes</div></div></div>
                <div class="stat-card messages <?= $pending_feedback > 0 ? 'alert' : '' ?>"><div class="stat-icon"><i class="bi bi-chat-dots"></i></div><div><div class="n" data-count="<?= $pending_feedback ?>"><?= $pending_feedback ?></div><div class="label">New Parent Messages</div></div></div>
            </div>

            <div class="section-label">Functionalities</div>
            <div class="module-grid reveal">
                <a class="module-card" href="classes.php">
                    <div class="icon"><i class="bi bi-diagram-3"></i></div>
                    <div class="title">Classes</div>
                    <div class="desc">Set up classes, streams, and assign class teachers.</div>
                </a>
                <a class="module-card" href="students.php">
                    <div class="icon"><i class="bi bi-mortarboard"></i></div>
                    <div class="title">Students</div>
                    <div class="desc">Enroll and manage student records.</div>
                </a>
                <a class="module-card" href="../staff_manager.php">
                    <div class="icon"><i class="bi bi-person-badge"></i></div>
                    <div class="title">Teachers</div>
                    <div class="desc">Register staff and generate login codes.</div>
                </a>
                <a class="module-card" href="assign_teacher.php">
                    <div class="icon"><i class="bi bi-clipboard-check"></i></div>
                    <div class="title">Assignments</div>
                    <div class="desc">Assign teachers to subjects and classes.</div>
                </a>
                <a class="module-card" href="subject_catalog.php">
                    <div class="icon"><i class="bi bi-journal-bookmark"></i></div>
                    <div class="title">Subjects</div>
                    <div class="desc">Tick which subjects your school offers -- O-Level's 7 compulsory ones are attached automatically.</div>
                </a>
                <a class="module-card" href="manage_parents.php">
                    <div class="icon"><i class="bi bi-people"></i></div>
                    <div class="title">Parent Accounts</div>
                    <div class="desc">Create parent logins and link them to students.</div>
                </a>
                <a class="module-card" href="message_parents.php">
                    <div class="icon"><i class="bi bi-chat-dots"></i></div>
                    <div class="title">Message Parents</div>
                    <div class="desc">Send a bulk SMS to a class or the whole school.</div>
                </a>
                <a class="module-card" href="fees.php">
                    <div class="icon"><i class="bi bi-cash-coin"></i></div>
                    <div class="title">Fees</div>
                    <div class="desc">Record and review student fee payments.</div>
                </a>
                <a class="module-card" href="../settings.php">
                    <div class="icon"><i class="bi bi-gear"></i></div>
                    <div class="title">School Settings</div>
                    <div class="desc">School name, logo, term, and academic year.</div>
                </a>
                <a class="module-card" href="grading_scales.php">
                    <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="title">Report Cards</div>
                    <div class="desc">Grading bands, display settings, and bulk/single printing.</div>
                </a>
                <a class="module-card" href="../elections/index.php">
                    <div class="icon"><i class="bi bi-award"></i></div>
                    <div class="title">Student Elections</div>
                    <div class="desc">Review candidacies and run leadership elections, with live turnout.</div>
                </a>
                <a class="module-card" href="assessments.php">
                    <div class="icon"><i class="bi bi-clipboard-data"></i></div>
                    <div class="title">Assessments</div>
                    <div class="desc">Create assessments and choose which count toward the report.</div>
                </a>
                <a class="module-card" href="../portal_handoff.php?to=analytics">
                    <div class="icon"><i class="bi bi-bar-chart-line"></i></div>
                    <div class="title">Performance Analytics</div>
                    <div class="desc">Class, subject, and gender performance, with ranked reports you can download as PDF.</div>
                </a>
            </div>

            <div class="section-label">Enrollment at a Glance</div>
            <div class="chart-section reveal">
                <div class="chart-card">
                    <h2>Students per Class</h2>
                    <?php if (empty($class_distribution)): ?>
                        <div class="chart-empty">No classes set up yet -- add one under Classes to see this chart.</div>
                    <?php else: ?>
                        <?php foreach ($class_distribution as $row): ?>
                            <?php
                                $bar_label = $row['class_name'] . ($row['stream_name'] ? ' - ' . $row['stream_name'] : '');
                                $bar_pct = round(((int) $row['cnt']) / $class_dist_max * 100);
                            ?>
                            <div class="bar-row">
                                <span class="bar-label"><?= htmlspecialchars($bar_label) ?></span>
                                <span class="bar-track"><span class="bar-fill" style="--w:<?= $bar_pct ?>%"></span></span>
                                <span class="bar-count"><?= (int) $row['cnt'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chart-card donut-wrap">
                    <h2 style="align-self:flex-start;">Gender Split</h2>
                    <?php if ($gender_total === 0): ?>
                        <div class="chart-empty">No student records with gender set yet.</div>
                    <?php else: ?>
                        <div class="donut" style="background:conic-gradient(var(--cyan) 0% <?= $gender_male_pct ?>%, var(--purple) <?= $gender_male_pct ?>% 100%);">
                            <div class="donut-center">
                                <div class="n"><?= $gender_total ?></div>
                                <div class="label">Students</div>
                            </div>
                        </div>
                        <div class="donut-legend">
                            <span><span class="dot" style="background:var(--cyan);"></span>Male <?= $gender_male_pct ?>%</span>
                            <span><span class="dot" style="background:var(--purple);"></span>Female <?= $gender_female_pct ?>%</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div><!-- /.app-shell -->
</body>
</html>