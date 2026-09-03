<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT: FEES
|--------------------------------------------------------------------------
| Extracted from the old all-in-one student_portal.php -- same queries,
| now on its own page behind the shared sidebar (_student_shell.php).
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

$stu_stmt = $pdo->prepare("SELECT full_name, class_id FROM students WHERE id = ? AND school_id = ?");
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    die('Student record not found for this school.');
}

$fee_stmt = $pdo->prepare("
    SELECT fs.day_tuition, fs.boarding_tuition, fs.entry_fee
    FROM fee_structures fs
    WHERE fs.school_id = ? AND fs.class_id = ?
    LIMIT 1
");
$fee_stmt->execute([$school_id, $student['class_id']]);
$fee_structure = $fee_stmt->fetch(PDO::FETCH_ASSOC);
$expected = $fee_structure ? (float) $fee_structure['day_tuition'] + (float) $fee_structure['entry_fee'] : 0.0;

$paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE school_id = ? AND student_id = ?");
$paid_stmt->execute([$school_id, $student_id]);
$paid = (float) $paid_stmt->fetchColumn();
$balance = $expected - $paid;

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'fees';
require_once __DIR__ . '/_student_shell.php';
?>
<style>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.card .n{font-size:1.6rem;font-weight:700;}
.card .n.green{color:var(--green);}
.card .n.danger{color:var(--danger);}
.card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:0.9rem;}
th,td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);}
tr:last-child td{border-bottom:none;}
</style>
<div class="section-title">Fees</div>
<div class="grid">
    <div class="card">
        <div class="n <?= $balance > 0 ? 'danger' : 'green' ?>">UGX <?= number_format(abs($balance), 0) ?></div>
        <div class="label"><?= $balance > 0 ? 'Balance Due' : 'Fully Paid' ?></div>
    </div>
</div>
<table>
    <tbody>
        <tr><td>Expected (tuition + entry fee)</td><td>UGX <?= number_format($expected, 0) ?></td></tr>
        <tr><td>Paid to date</td><td>UGX <?= number_format($paid, 0) ?></td></tr>
        <tr><td><strong><?= $balance > 0 ? 'Balance due' : 'Overpaid / Credit' ?></strong></td><td><strong>UGX <?= number_format(abs($balance), 0) ?></strong></td></tr>
    </tbody>
</table>
        </div>
    </div>
</div>
</body>
</html>
