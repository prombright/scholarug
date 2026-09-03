<?php
// ==========================================
// 1. ENGINE CONFIGURATION & SESSION MANAGEMENT
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_report_card_render.php';

// Previously had no auth check at all, and fell back to school_id = 1 when
// there was no session -- meaning anyone with the URL, logged in or not,
// could view (and print) any student's report card from any school.
require_role(['school_admin', 'headteacher', 'dos', 'teacher', 'bursar', 'student']);

$school_id = current_school_id();

// A student can only ever see their own report card, no matter what
// student_id shows up in the query string.
if ($_SESSION['role'] === 'student') {
    $student_id = current_student_id();
} else {
    $student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;
}

$term = $_GET['term'] ?? 'Term 1';
$year = (int) ($_GET['year'] ?? 2026);

// ==========================================
// 2. RETRIEVE SCHOOL BRANDING
// ==========================================
try {
    $school_stmt = $pdo->prepare("SELECT school_name, school_badge, phone_contact, email_contact, address FROM schools WHERE id = ?");
    $school_stmt->execute([$school_id]);
    $school = $school_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $school = [];
}

$report_settings = scholar_fetch_report_settings($pdo, $school_id);

// ==========================================
// 3. ACCESS CHECK, THEN RENDER VIA THE SHARED HELPER
// ==========================================
$access_denied_msg = null;
$result = ['found' => false, 'html' => '', 'error' => null];

if ($student_id) {
    // A 'teacher' can only print report cards for their own class --
    // previously any teacher could view any student school-wide by
    // editing ?student_id= in the URL. school_admin/headteacher/dos/
    // bursar stay school-wide, matching their require_role() above.
    if ($_SESSION['role'] === 'teacher') {
        $class_check = $pdo->prepare("SELECT class_id FROM students WHERE id = ? AND school_id = ?");
        $class_check->execute([$student_id, $school_id]);
        $target_class_id = $class_check->fetchColumn();

        if ($target_class_id === false || !is_class_teacher_of($pdo, current_staff_id(), (int) $target_class_id)) {
            $access_denied_msg = 'You can only view report cards for students in a class you are the class teacher of.';
        }
    }

    if ($access_denied_msg === null) {
        $result = render_report_card_html($pdo, $school, $school_id, $student_id, $term, $year, null, $report_settings);
    }
}

// Students only get the in-app print/download button when the school has
// explicitly opted in -- every other role always gets it. This is a soft
// UI gate (it hides the button); it cannot stop a browser's own Ctrl+P.
$show_print_button = $_SESSION['role'] !== 'student' || !empty($report_settings['allow_student_download']);

// This page is reachable from a handful of different places depending on
// role (student_results.php, a class roster, various dashboards) and had
// no way back at all otherwise. history.back() returns to whichever of
// those actually opened it; role_destination() is only the fallback for
// when there's no history to go back to (e.g. opened in a fresh tab).
$back_fallback_url = role_destination($_SESSION['role']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card | <?= htmlspecialchars($result['student_name'] ?? 'Data Lookup') ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #ffffff; color: #1e293b; margin: 0; padding: 25px; font-size: 13px; line-height: 1.4; }
        .report-card-wrapper { margin: 0 auto; }
        <?php include __DIR__ . '/_report_card_style.php'; ?>

        .action-bar { max-width: 850px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .print-btn { background: #0ea5e9; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; font-weight: bold; cursor: pointer; font-size: 13px; transition: background 0.2s; }
        .print-btn:hover { background: #0284c7; }
        .back-btn { color: #0ea5e9; text-decoration: none; font-weight: bold; font-size: 13px; }
        .back-btn:hover { text-decoration: underline; }

        @media print {
            body { padding: 0; background: #fff; }
            .report-card-wrapper { border: none; padding: 0; max-width: 100%; }
            .action-bar { display: none !important; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>

    <div class="action-bar">
        <a href="#" class="back-btn" onclick="if(window.history.length>1){history.back();}else{window.location.href=<?= json_encode($back_fallback_url) ?>;}return false;">&larr; Back</a>
        <?php if ($show_print_button): ?>
            <button onclick="window.print();" class="print-btn">Print Report</button>
        <?php endif; ?>
    </div>

    <?php if (!empty($result['error']) && $_SESSION['role'] !== 'student'): ?>
        <div style="max-width:850px;margin:0 auto 20px;padding:14px 18px;border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;border-radius:6px;font-size:13px;">
            <strong>Report data error:</strong> <?= htmlspecialchars($result['error']) ?>
            <div style="margin-top:4px;color:#7f1d1d;">Marks below may be incomplete because of this — not shown to students/parents.</div>
        </div>
    <?php endif; ?>

    <?php if ($access_denied_msg): ?>
        <div style="text-align:center; padding:60px 20px; border:2px dashed #fca5a5; max-width:850px; margin:0 auto; border-radius:8px; background:#fef2f2;">
            <h3 style="color:#991b1b; margin-top:0; font-size:1.4rem;">Access Denied</h3>
            <p style="color:#7f1d1d; font-size:0.95rem; margin-bottom:0;"><?= htmlspecialchars($access_denied_msg) ?></p>
        </div>
    <?php elseif (!$result['found']): ?>
        <div style="text-align:center; padding:60px 20px; border:2px dashed #cbd5e1; max-width:850px; margin:0 auto; border-radius:8px; background:#fafafa;">
            <h3 style="color:#0f172a; margin-top:0; font-size:1.4rem;">[ Core Student Target Missing ]</h3>
            <p style="color:#64748b; font-size:0.95rem; margin-bottom:0;">Please load this page with a valid query parameter string.<br><code style="background:#e2e8f0; padding:3px 6px; border-radius:4px; font-weight:bold; font-size:12px; display:inline-block; margin-top:10px;">generate_report.php?student_id=1&term=Term 1&year=2026</code></p>
        </div>
    <?php else: ?>
        <?= $result['html'] ?>
    <?php endif; ?>

    <script src="assets/js/qrcode.js"></script>
    <script>
        document.querySelectorAll('.rc-qr-target').forEach(function (el) {
            var q = qrcode(0, 'M');
            q.addData(el.getAttribute('data-qr'));
            q.make();
            el.innerHTML = q.createSvgTag(4, 0);
        });
    </script>
</body>
</html>
