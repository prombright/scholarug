<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK SMS: SEND
|--------------------------------------------------------------------------
| Two-step: preview (recipient count + cost, no money moved) then confirm
| (actually calls ScholarCampaignSender, which debits the wallet). Two
| steps deliberately, same reasoning as any real-money action in this
| app -- an accidental single click should never spend a school's wallet
| balance.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/ScholarSmsWallet.php';
require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';
require_once __DIR__ . '/../../lib/ScholarSmsContacts.php';
require_once __DIR__ . '/../../lib/ScholarCampaignSender.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$currency = ScholarSmsPricing::currency($pdo);
$balance = ScholarSmsWallet::balance($pdo, $school_id);

$error = '';
$preview = null;
$sent_result = null;

$groups_stmt = $pdo->prepare("SELECT * FROM sms_contact_groups WHERE school_id = ? ORDER BY name");
$groups_stmt->execute([$school_id]);
$groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

// Resolves the posted group selection (a real group id, or the sentinel
// "whole_school" for the always-available option with no stored row) into
// the group array ScholarSmsContacts::resolveGroup() expects.
function scholar_sms_resolve_selection(PDO $pdo, int $schoolId, string $selection, array $groups): ?array
{
    if ($selection === 'whole_school') {
        return ['id' => 0, 'group_type' => 'whole_school', 'class_id' => null];
    }
    $groupId = (int) $selection;
    foreach ($groups as $g) {
        if ((int) $g['id'] === $groupId) {
            return $g;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    $selection = (string) ($_POST['group_selection'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $group = scholar_sms_resolve_selection($pdo, $school_id, $selection, $groups);

    if ($group === null) {
        $error = 'Choose who to send to.';
    } elseif ($message === '') {
        $error = 'Write a message.';
    } else {
        $recipients = ScholarSmsContacts::resolveGroup($pdo, $school_id, $group);
        $info = ScholarSmsPricing::segmentInfo($message);
        $cost = ScholarSmsPricing::totalCost($pdo, max(1, $info['segments']), count($recipients));

        $preview = [
            'group_selection' => $selection,
            'group_label' => $group['group_type'] === 'whole_school' ? 'Whole School' : $group['name'],
            'message' => $message,
            'recipient_count' => count($recipients),
            'segments' => max(1, $info['segments']),
            'cost' => $cost,
            'can_afford' => $balance >= $cost,
        ];

        if (count($recipients) === 0) {
            $error = 'No reachable phone numbers for that selection yet.';
            $preview = null;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_send') {
    $selection = (string) ($_POST['group_selection'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $group = scholar_sms_resolve_selection($pdo, $school_id, $selection, $groups);

    if ($group === null || $message === '') {
        $error = 'Something about that send was invalid -- please try again.';
    } else {
        $recipients = ScholarSmsContacts::resolveGroup($pdo, $school_id, $group);
        try {
            $sent_result = ScholarCampaignSender::send($pdo, $school_id, $user_id, $message, $recipients);
            $balance = ScholarSmsWallet::balance($pdo, $school_id);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'sms_send' : 'hr';

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
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;max-width:600px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.sms-alert.warn{background:rgba(245,158,11,0.12);color:var(--amber, #f59e0b);}
.sms-balance-note{color:var(--muted);font-size:0.8rem;margin-bottom:16px;}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section select,.sms-section textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;font-family:inherit;}
.sms-section textarea{resize:vertical;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--text);margin-left:8px;}
.sms-preview-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:0.88rem;}
.sms-preview-row .label{color:var(--muted);}
</style>

<h1 style="margin:0 0 16px;font-size:1.4rem;">Bulk SMS</h1>
<div class="sms-tabs">
    <a href="wallet.php">Wallet</a>
    <a href="contacts.php">Contacts</a>
    <a href="send.php" class="active">Send</a>
    <a href="history.php">History</a>
    <a href="whatsapp_settings.php">WhatsApp</a>
</div>

<div class="sms-balance-note">Wallet balance: <strong><?= htmlspecialchars($currency) ?> <?= number_format($balance, 2) ?></strong> — <a href="wallet.php" style="color:var(--cyan);">top up</a></div>

<?php if ($error): ?><div class="sms-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($sent_result): ?>
    <div class="sms-section">
        <div class="sms-alert success">
            Sent to <?= $sent_result['delivered'] ?> recipient(s)<?= $sent_result['failed'] > 0 ? ", {$sent_result['failed']} failed" : '' ?>.
            Debited <?= htmlspecialchars($sent_result['currency']) ?> <?= number_format($sent_result['total_cost'], 2) ?>.
        </div>
        <?php if ($sent_result['simulated']): ?>
            <div class="sms-alert warn">Delivery is currently simulated — no real SMS was sent to any carrier yet. This will start sending for real once a live provider is connected.</div>
        <?php endif; ?>
        <a href="history.php" style="color:var(--cyan);">View in History &rarr;</a>
    </div>

<?php elseif ($preview): ?>
    <div class="sms-section">
        <h3 style="margin:0 0 16px;font-size:1rem;">Confirm Send</h3>
        <div class="sms-preview-row"><span class="label">To</span><span><?= htmlspecialchars($preview['group_label']) ?></span></div>
        <div class="sms-preview-row"><span class="label">Recipients</span><span><?= (int) $preview['recipient_count'] ?></span></div>
        <div class="sms-preview-row"><span class="label">Segments per message</span><span><?= (int) $preview['segments'] ?></span></div>
        <div class="sms-preview-row"><span class="label">Estimated Cost</span><span><?= htmlspecialchars($currency) ?> <?= number_format($preview['cost'], 2) ?></span></div>
        <div style="margin-top:16px;padding:12px;background:var(--panel);border-radius:6px;font-size:0.85rem;white-space:pre-wrap;"><?= htmlspecialchars($preview['message']) ?></div>

        <?php if (!$preview['can_afford']): ?>
            <div class="sms-alert warn" style="margin-top:16px;">Not enough wallet balance for this send. <a href="wallet.php" style="color:inherit;text-decoration:underline;">Top up first</a>.</div>
        <?php else: ?>
            <form method="POST" style="margin-top:8px;">
                <input type="hidden" name="action" value="confirm_send">
                <input type="hidden" name="group_selection" value="<?= htmlspecialchars($preview['group_selection']) ?>">
                <input type="hidden" name="message" value="<?= htmlspecialchars($preview['message']) ?>">
                <button type="submit">Confirm &amp; Send</button>
                <button type="button" class="ghost" onclick="window.location.href='send.php'">Cancel</button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="sms-section">
        <form method="POST">
            <input type="hidden" name="action" value="preview">
            <label>Send To</label>
            <select name="group_selection" required>
                <option value="">-- Select --</option>
                <option value="whole_school">Whole School</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['name']) ?> (<?= $g['group_type'] === 'class' ? 'Class' : 'Imported' ?>)</option>
                <?php endforeach; ?>
            </select>
            <label>Message</label>
            <textarea name="message" rows="5" required placeholder="Type your message..."></textarea>
            <button type="submit">Preview &amp; Estimate Cost</button>
        </form>
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