<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['bursar']);

$school_id = current_school_id();

// NOTE: school_admin/fees.php records payments into fee_payments, not the
// legacy `fees` table — this used to read from `fees` and always showed
// UGX 0 / 0 payments no matter how much had actually been collected.
$total_collected = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE school_id = ?");
$total_collected->execute([$school_id]);
$total_collected = (float) $total_collected->fetchColumn();

$payment_count = $pdo->prepare("SELECT COUNT(*) FROM fee_payments WHERE school_id = ?");
$payment_count->execute([$school_id]);
$payment_count = (int) $payment_count->fetchColumn();

// Real per-class breakdown for the "Fees Collected per Class" bar chart.
$fees_by_class_stmt = $pdo->prepare(
    "SELECT s.class_name, SUM(fp.amount_paid) AS total
     FROM fee_payments fp
     JOIN students s ON s.id = fp.student_id
     WHERE fp.school_id = ?
     GROUP BY s.class_name
     ORDER BY total DESC"
);
$fees_by_class_stmt->execute([$school_id]);
$fees_by_class = $fees_by_class_stmt->fetchAll();
$fees_by_class_max = 1;
foreach ($fees_by_class as $row) {
    $fees_by_class_max = max($fees_by_class_max, (float) $row['total']);
}

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bursar Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:900px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;align-items:center;gap:14px;}
.card-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;background:rgba(16,185,129,.15);color:var(--green);}
.card.collected .card-icon{background:rgba(0,168,168,.15);color:var(--cyan);}
.card .n{font-size:1.7rem;font-weight:700;color:var(--text);}
.card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.cta{display:inline-block;background:var(--cyan);color:#04222a;font-weight:700;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:0.85rem;}
.logout{color:var(--danger);text-decoration:none;font-size:0.75rem;font-weight:700;text-transform:uppercase;border:1px solid rgba(239,68,68,0.3);padding:8px 16px;border-radius:6px;}
table{display:block;overflow-x:auto;}
@media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}

.section-label{font-size:0.95rem;font-weight:700;margin:32px 0 16px;}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;}
.chart-card h2{font-size:0.9rem;margin:0 0 18px;color:var(--text);}
.chart-empty{color:var(--muted);font-size:0.85rem;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:0.8rem;}
.bar-row .bar-label{width:110px;flex-shrink:0;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bar-track{flex:1;background:var(--border);border-radius:6px;height:10px;overflow:hidden;}
.bar-fill{display:block;height:100%;background:var(--green);border-radius:6px;width:0;transition:width 1s cubic-bezier(.22,1,.36,1);}
.reveal{opacity:0;transform:translateY(16px);transition:opacity .5s ease, transform .5s ease;}
.reveal.revealed{opacity:1;transform:translateY(0);}
.reveal.revealed .bar-fill{width:var(--w);}
.bar-row .bar-count{width:90px;text-align:right;color:var(--text);font-weight:600;}
@media (prefers-reduced-motion: reduce){
    .reveal{opacity:1;transform:none;transition:none;}
    .bar-fill{transition:none;width:var(--w);}
}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="container">
    <div class="header">
        <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($__badge_url): ?>
                <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:6px;">
            <?php else: ?>
                <div style="width:40px;height:40px;border-radius:6px;background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <h1 style="margin:0;font-size:1.4rem;">Bursar — Finance</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a class="logout" href="leave_requests.php" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">My Leave</a>
            <a class="scholar-logout-btn" href="logout.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>
    <div class="grid reveal">
        <div class="card collected"><div class="card-icon"><i class="bi bi-cash-stack"></i></div><div><div class="n">UGX <span data-count="<?= (int) $total_collected ?>"><?= number_format($total_collected, 0) ?></span></div><div class="label">Total Collected</div></div></div>
        <div class="card"><div class="card-icon"><i class="bi bi-receipt"></i></div><div><div class="n" data-count="<?= $payment_count ?>"><?= $payment_count ?></div><div class="label">Payments Recorded</div></div></div>
    </div>

    <div class="section-label">Fees Collected per Class</div>
    <div class="chart-card reveal">
        <?php if (empty($fees_by_class)): ?>
            <div class="chart-empty">No fee payments recorded yet.</div>
        <?php else: ?>
            <?php foreach ($fees_by_class as $row): ?>
                <?php $bar_pct = round(((float) $row['total']) / $fees_by_class_max * 100); ?>
                <div class="bar-row">
                    <span class="bar-label"><?= htmlspecialchars($row['class_name']) ?></span>
                    <span class="bar-track"><span class="bar-fill" style="--w:<?= $bar_pct ?>%"></span></span>
                    <span class="bar-count">UGX <?= number_format((float) $row['total'], 0) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a class="cta" href="school_admin/fees.php" style="margin-top:24px;">Open Fees Ledger &rarr;</a>
</div>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>
