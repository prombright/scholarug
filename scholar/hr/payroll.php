<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — HR: PAYROLL (simple monthly pay ledger)
|--------------------------------------------------------------------------
| Record what was paid each month per staff member; print a single
| payment as a basic payslip (?print=<id>). Not a tax/statutory
| computation engine -- see _setup/payroll_migration.sql's header.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();
$recorded_by = (int) ($_SESSION['user_id'] ?? 0);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $staff_id = (int) ($_POST['staff_id'] ?? 0);
    $month = (int) ($_POST['pay_period_month'] ?? 0);
    $year = (int) ($_POST['pay_period_year'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $staff_check = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ?");
    $staff_check->execute([$staff_id, $school_id]);

    if (!$staff_check->fetch() || $month < 1 || $month > 12 || $amount <= 0 || $payment_date === '') {
        $error = 'Please fill in a valid staff member, month, amount, and payment date.';
    } else {
        $ins = $pdo->prepare("
            INSERT INTO payroll_payments (school_id, staff_id, pay_period_month, pay_period_year, amount, payment_date, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$school_id, $staff_id, $month, $year, $amount, $payment_date, $notes, $recorded_by]);
        $message = 'Payment recorded.';
    }
}

// Single-payslip print view.
if (isset($_GET['print'])) {
    $pid = (int) $_GET['print'];
    $stmt = $pdo->prepare("
        SELECT pp.*, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS staff_name, s.staff_code
        FROM payroll_payments pp
        JOIN staff s ON s.staff_id = pp.staff_id AND s.school_id = pp.school_id
        WHERE pp.id = ? AND pp.school_id = ?
    ");
    $stmt->execute([$pid, $school_id]);
    $payslip = $stmt->fetch(PDO::FETCH_ASSOC);

    $school_stmt = $pdo->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_stmt->execute([$school_id]);
    $school_name = $school_stmt->fetchColumn() ?: 'School';

    $months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Payslip</title>
    <style>
    body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;color:#1e293b;margin:0;padding:40px;}
    .toolbar{margin-bottom:20px;}
    .payslip{max-width:500px;border:2px solid #0f172a;border-radius:6px;padding:30px;}
    .payslip h2{margin:0 0 4px;}
    .payslip .sub{color:#64748b;font-size:13px;margin-bottom:20px;}
    .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;}
    .row .label{color:#64748b;}
    .amount{font-size:22px;font-weight:800;margin-top:16px;}
    @media print{.toolbar{display:none;}}
    </style>
    </head>
    <body>
    <div class="toolbar"><button onclick="window.print();">Print</button> <a href="payroll.php">&larr; Back</a></div>
    <?php if (!$payslip): ?>
        <p>Payslip not found.</p>
    <?php else: ?>
    <div class="payslip">
        <h2><?= htmlspecialchars($school_name) ?></h2>
        <div class="sub">Payslip</div>
        <div class="row"><span class="label">Staff</span><span><?= htmlspecialchars($payslip['staff_name']) ?></span></div>
        <div class="row"><span class="label">Staff Code</span><span><?= htmlspecialchars($payslip['staff_code'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Pay Period</span><span><?= $months[(int) $payslip['pay_period_month']] ?> <?= (int) $payslip['pay_period_year'] ?></span></div>
        <div class="row"><span class="label">Payment Date</span><span><?= htmlspecialchars(date('d M Y', strtotime($payslip['payment_date']))) ?></span></div>
        <?php if ($payslip['notes']): ?><div class="row"><span class="label">Notes</span><span><?= htmlspecialchars($payslip['notes']) ?></span></div><?php endif; ?>
        <div class="amount">UGX <?= number_format((float) $payslip['amount'], 0) ?></div>
    </div>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

$staff_stmt = $pdo->prepare("SELECT staff_id, first_name, last_name FROM staff WHERE school_id = ? AND status = 'active' ORDER BY first_name");
$staff_stmt->execute([$school_id]);
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$history_stmt = $pdo->prepare("
    SELECT pp.*, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS staff_name
    FROM payroll_payments pp
    JOIN staff s ON s.staff_id = pp.staff_id AND s.school_id = pp.school_id
    WHERE pp.school_id = ?
    ORDER BY pp.payment_date DESC, pp.id DESC
    LIMIT 100
");
$history_stmt->execute([$school_id]);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

$months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Same role-based shell split as hr_dashboard.php/staff_manager.php --
// 'HR' gets its own small sidebar, school_admin keeps the normal one.
$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'payroll' : 'hr';

if ($is_hr_role) {
    $__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
    $__school_brand->execute([$school_id]);
    $__school_brand = $__school_brand->fetch(PDO::FETCH_ASSOC) ?: [];
    $__badge_url = null;
    if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
        $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
    }
    require_once __DIR__ . '/../_hr_shell.php';
} else {
    require_once __DIR__ . '/../_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
<style>
.pr-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.pr-alert-success{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.pr-alert-danger{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.pr-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.pr-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.pr-section label:first-child{margin-top:0;}
.pr-section input,.pr-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.pr-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.pr-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.pr-section table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
.pr-section th,.pr-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.pr-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pr-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
a.pr-print-link{color:var(--cyan);text-decoration:none;font-size:0.78rem;}
</style>

<h1 style="margin:0 0 24px;font-size:1.4rem;">Payroll</h1>

<?php if ($message): ?><div class="pr-alert pr-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pr-alert pr-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="pr-section">
        <form method="POST">
            <label>Staff Member</label>
            <select name="staff_id" required>
                <option value="">-- Select --</option>
                <?php foreach ($staff_list as $s): ?>
                    <option value="<?= (int) $s['staff_id'] ?>"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="pr-row">
                <div>
                    <label>Month</label>
                    <select name="pay_period_month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === (int) date('n') ? 'selected' : '' ?>><?= $months[$m] ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label>Year</label>
                    <input type="number" name="pay_period_year" value="<?= date('Y') ?>" required>
                </div>
                <div>
                    <label>Payment Date</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <label>Amount (UGX)</label>
            <input type="number" name="amount" min="0" step="0.01" required>
            <label>Notes (optional)</label>
            <input type="text" name="notes" placeholder="e.g. Net salary after advance deduction">
            <button type="submit" name="record_payment">Record Payment</button>
        </form>
    </div>

    <div class="pr-section">
        <table>
            <tr><th>Staff</th><th>Period</th><th>Amount</th><th>Paid</th><th></th></tr>
            <?php if (empty($history)): ?>
                <tr><td colspan="5" class="pr-empty">No payments recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= htmlspecialchars($h['staff_name']) ?></td>
                        <td><?= $months[(int) $h['pay_period_month']] ?> <?= (int) $h['pay_period_year'] ?></td>
                        <td>UGX <?= number_format((float) $h['amount'], 0) ?></td>
                        <td><?= htmlspecialchars(date('d M Y', strtotime($h['payment_date']))) ?></td>
                        <td><a class="pr-print-link" href="payroll.php?print=<?= (int) $h['id'] ?>" target="_blank">Print Payslip</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>

<?php if ($is_hr_role): ?>
        </div>
    </div>
</div>
</body>
</html>
<?php else: ?>
    </div><!-- /.page-inner -->
    </main>
    </div><!-- /.app-shell -->
    </body>
    </html>
<?php endif; ?>
