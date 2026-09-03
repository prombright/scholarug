<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT: ATTENDANCE
|--------------------------------------------------------------------------
| Extracted from the old all-in-one student_portal.php -- same query, now
| on its own page behind the shared sidebar (_student_shell.php).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['student']);

$school_id  = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    die('This login is not linked to a student record. Ask your school admin to re-create your login.');
}

$stu_stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ? AND school_id = ?");
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    die('Student record not found for this school.');
}

$att_stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS c
    FROM attendance
    WHERE student_id = ?
    GROUP BY status
");
$att_stmt->execute([$student_id]);
$attendance = ['present' => 0, 'absent' => 0, 'sick' => 0, 'permission' => 0];
foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendance[$row['status']] = (int) $row['c'];
}

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'attendance';
require_once __DIR__ . '/_student_shell.php';
?>
<style>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-align:center;}
.card .n{font-size:1.8rem;font-weight:700;}
.card .n.green{color:var(--green);}
.card .n.danger{color:var(--danger);}
.card .n.amber{color:var(--amber);}
.card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:0.75rem;background:rgba(148,163,184,0.15);color:var(--muted);margin-left:8px;}
</style>
<div class="section-title">Attendance <span class="pill">all recorded terms</span></div>
<div class="grid">
    <div class="card"><div class="n green"><?= $attendance['present'] ?></div><div class="label">Present</div></div>
    <div class="card"><div class="n danger"><?= $attendance['absent'] ?></div><div class="label">Absent</div></div>
    <div class="card"><div class="n amber"><?= $attendance['sick'] ?></div><div class="label">Sick</div></div>
    <div class="card"><div class="n"><?= $attendance['permission'] ?></div><div class="label">Permission</div></div>
</div>
        </div>
    </div>
</div>
</body>
</html>
