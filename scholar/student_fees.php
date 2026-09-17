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
require_once __DIR__ . '/school_admin/_fees_helpers.php';

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

// Same ledger computation the bursar's office actually uses
// (admin_fees_annotate_ledger()) -- see the comment on
// admin_fees_fetch_ledger_for_student() for why the old flat
// day_tuition + entry_fee formula here didn't match the real ledger for
// boarders, bursary recipients, or returning (non-new) students.
$fee_row = admin_fees_fetch_ledger_for_student($pdo, $school_id, $student_id, current_term(), current_year());
$expected = (float) ($fee_row['net_due'] ?? 0.0);
$paid = (float) ($fee_row['total_paid'] ?? 0.0);
// Signed, not the ledger's own clamped 'balance' field -- this page shows
// "Overpaid / Credit" for a negative difference, which a max(0, ...)
// balance would hide behind a flat 0.
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
