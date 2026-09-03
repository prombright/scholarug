<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK SMS: HISTORY
|--------------------------------------------------------------------------
| Past campaigns + per-recipient status. Delivery is simulated today
| (ScholarTestSmsProvider) -- every "Delivered" pill here is labeled as
| such so nobody mistakes it for a real carrier confirmation.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();

$campaigns_stmt = $pdo->prepare("SELECT * FROM sms_campaigns WHERE school_id = ? ORDER BY created_at DESC LIMIT 50");
$campaigns_stmt->execute([$school_id]);
$campaigns = $campaigns_stmt->fetchAll(PDO::FETCH_ASSOC);

$open_campaign_id = isset($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : null;
$open_campaign = null;
$recipients = [];

if ($open_campaign_id !== null) {
    foreach ($campaigns as $c) {
        if ((int) $c['id'] === $open_campaign_id) {
            $open_campaign = $c;
        }
    }
    if ($open_campaign !== null) {
        $r_stmt = $pdo->prepare("SELECT phone, channel, full_name, delivery_status FROM sms_campaign_recipients WHERE campaign_id = ? ORDER BY id");
        $r_stmt->execute([$open_campaign_id]);
        $recipients = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'sms_history' : 'hr';

if ($is_hr_role) {
    $__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
    $__school_brand->execute([$school_id]);
    $__school_brand = $__school_brand->fetch(PDO::FETCH_ASSOC) ?: [];
    $__badge_url = null;
    if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../../' . $__school_brand['school_badge'])) {
        $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
    }
    require_once __DIR__ . '/../../_hr_shell.php';
} else {
    require_once __DIR__ . '/../../_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
<style>
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.sms-pill.delivered{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.sms-pill.failed{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.sms-pill.pending{background:rgba(148,163,184,0.15);color:var(--muted);}
.sms-note{color:var(--amber, #f59e0b);font-size:0.78rem;margin-bottom:12px;}
a.sms-link{color:var(--cyan);text-decoration:none;font-size:0.85rem;}
</style>

<h1 style="margin:0 0 16px;font-size:1.4rem;">Bulk SMS</h1>
<div class="sms-tabs">
    <a href="wallet.php">Wallet</a>
    <a href="contacts.php">Contacts</a>
    <a href="send.php">Send</a>
    <a href="history.php" class="active">History</a>
    <a href="whatsapp_settings.php">WhatsApp</a>
</div>

<?php if ($open_campaign !== null): ?>
    <div class="sms-section">
        <a href="history.php" class="sms-link">&larr; Back to all campaigns</a>
        <h3 style="margin:16px 0 4px;font-size:1rem;">Campaign #<?= (int) $open_campaign['id'] ?></h3>
        <p style="color:var(--muted);font-size:0.85rem;white-space:pre-wrap;"><?= htmlspecialchars($open_campaign['message']) ?></p>
        <div class="sms-note">SMS delivery status below is simulated -- no live SMS gateway is connected yet. WhatsApp status reflects the real Meta API response for schools that have connected a number.</div>
        <table>
            <tr><th>Phone</th><th>Name</th><th>Channel</th><th>Status</th></tr>
            <?php foreach ($recipients as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['phone']) ?></td>
                <td><?= htmlspecialchars($r['full_name'] ?: '—') ?></td>
                <td><span class="sms-pill <?= $r['channel'] === 'whatsapp' ? 'delivered' : 'pending' ?>"><?= $r['channel'] === 'whatsapp' ? 'WhatsApp' : 'SMS' ?></span></td>
                <td><span class="sms-pill <?= $r['delivery_status'] ?>"><?= ucfirst($r['delivery_status']) ?><?= $r['channel'] === 'whatsapp' ? '' : ' (simulated)' ?></span></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php else: ?>
    <div class="sms-section">
        <div class="sms-note">SMS delivery statuses shown here are simulated for now -- no live SMS gateway is connected yet. Recipients reached via a connected WhatsApp number get a real delivery status.</div>
        <?php if (empty($campaigns)): ?>
            <div class="sms-empty">No campaigns sent yet.</div>
        <?php else: ?>
        <table>
            <tr><th>Date</th><th>Message</th><th>Recipients</th><th>Cost</th><th>Status</th><th></th></tr>
            <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($c['created_at']))) ?></td>
                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($c['message']) ?></td>
                <td><?= (int) $c['recipient_count'] ?></td>
                <td>UGX <?= number_format((float) $c['total_cost'], 2) ?></td>
                <td><span class="sms-pill <?= $c['status'] === 'sent' ? 'delivered' : ($c['status'] === 'failed' ? 'failed' : 'pending') ?>"><?= ucfirst($c['status']) ?></span></td>
                <td><a class="sms-link" href="history.php?campaign_id=<?= (int) $c['id'] ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

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