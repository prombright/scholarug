<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_hr_dashboard_helpers.php';

require_role(['school_admin', 'hr']);

// Superseded by the Vue HR SPA (app_hr.php) -- kept only so old
// bookmarks/links to this URL still land somewhere useful.
header("Location: " . SCHOLAR_BASE . "/app_hr.php");
exit;

$school_id = current_school_id();

$stats = hr_dashboard_stats($pdo, $school_id);
$staff_count = $stats['staff_count'];
$sms_balance = $stats['sms_balance'];
$pending_leave = $stats['pending_leave'];
$teaching_count = $stats['teaching_count'];
$non_teaching_count = $stats['non_teaching_count'];
$staff_category_total = $stats['staff_category_total'];
$teaching_pct = $stats['teaching_pct'];

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

// The dedicated 'HR' role gets its own small sidebar (_hr_shell.php) --
// school_admin keeps the normal full admin sidebar it always had, just
// consistently on every HR page now instead of only staff_manager.php.
$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'dashboard' : 'hr';

if ($is_hr_role) {
    require_once __DIR__ . '/_hr_shell.php';
} else {
    require_once __DIR__ . '/_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
<style>
:root{ --purple:#a855f7; }
.hr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
.hr-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;align-items:center;gap:14px;}
.hr-card-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;background:rgba(0,168,168,.15);color:var(--cyan);}
.hr-card.leave .hr-card-icon{background:rgba(245,158,11,.15);color:var(--amber, #f59e0b);}
.hr-card.wallet .hr-card-icon{background:rgba(16,185,129,.15);color:var(--green, #10b981);}
.hr-card .n{font-size:1.7rem;font-weight:700;color:var(--text);}
.hr-card .n.amber{color:var(--amber, #f59e0b);}
.hr-card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.hr-links{display:flex;gap:12px;flex-wrap:wrap;}
.hr-cta{display:inline-block;background:var(--cyan);color:#04222a;font-weight:700;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:0.85rem;}
.hr-cta.ghost{background:transparent;border:1px solid var(--border);color:var(--text);}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}

.hr-chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;display:flex;flex-direction:column;align-items:center;}
.hr-chart-card h2{font-size:0.9rem;margin:0 0 18px;align-self:flex-start;}
.hr-donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;transform:scale(.7);opacity:0;transition:transform .6s cubic-bezier(.22,1,.36,1), opacity .6s ease;}
.reveal.revealed .hr-donut{transform:scale(1);opacity:1;}
.hr-donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.hr-donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.hr-donut-center .n{font-size:1.4rem;font-weight:700;}
.hr-donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.hr-donut-legend{display:flex;gap:18px;font-size:0.8rem;color:var(--muted);}
.hr-donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
@media (prefers-reduced-motion: reduce){
    .hr-donut{transition:none;transform:none;opacity:1;}
}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
    <h1 style="margin:0;font-size:1.4rem;">Human Resources</h1>
    <a class="btn-link" href="leave_requests.php">My Leave</a>
</div>

<div class="hr-grid reveal">
    <div class="hr-card"><div class="hr-card-icon"><i class="bi bi-people"></i></div><div><div class="n" data-count="<?= $staff_count ?>"><?= $staff_count ?></div><div class="label">Active Staff</div></div></div>
    <div class="hr-card leave"><div class="hr-card-icon"><i class="bi bi-calendar2-week"></i></div><div><div class="n amber" data-count="<?= $pending_leave ?>"><?= $pending_leave ?></div><div class="label">Pending Leave Requests</div></div></div>
    <div class="hr-card wallet"><div class="hr-card-icon"><i class="bi bi-wallet2"></i></div><div><div class="n">UGX <span data-count="<?= (int) $sms_balance ?>"><?= number_format($sms_balance, 0) ?></span></div><div class="label">Bulk SMS Wallet</div></div></div>
</div>

<?php if ($staff_category_total > 0): ?>
<div class="hr-chart-card reveal">
    <h2>Staff Composition</h2>
    <div class="hr-donut" style="background:conic-gradient(var(--cyan) 0% <?= $teaching_pct ?>%, var(--purple) <?= $teaching_pct ?>% 100%);">
        <div class="hr-donut-center">
            <div class="n"><?= $staff_category_total ?></div>
            <div class="label">Staff</div>
        </div>
    </div>
    <div class="hr-donut-legend">
        <span><span class="dot" style="background:var(--cyan);"></span>Teaching <?= $teaching_count ?></span>
        <span><span class="dot" style="background:var(--purple);"></span>Non-Teaching <?= $non_teaching_count ?></span>
    </div>
</div>
<?php endif; ?>

<div class="hr-links">
    <a class="hr-cta" href="staff_manager.php">Staff</a>
    <a class="hr-cta ghost" href="hr/leave_review.php">Leave Requests</a>
    <a class="hr-cta ghost" href="hr/payroll.php">Payroll</a>
    <a class="hr-cta ghost" href="hr/sms/wallet.php">Bulk SMS</a>
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
