<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK SMS: WHATSAPP CONNECTION
|--------------------------------------------------------------------------
| HR connects the school's own WhatsApp Business number here (Meta Cloud
| API). Once connected, every Bulk SMS send (send.php) attempts WhatsApp
| first for each recipient, falling back to SMS automatically when it's
| not available for that number -- see ScholarCampaignSender::send().
| Mirrors bulksms/whatsapp_settings.php's form shape exactly.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/_whatsapp_helpers.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();
$message = '';
$error = '';

$settings = hr_sms_whatsapp_settings($pdo, $school_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'disable') {
        $result = hr_sms_whatsapp_disable($pdo, $school_id);
        $message = $result['message'];
    } else {
        $result = hr_sms_whatsapp_save(
            $pdo, $school_id, $settings,
            trim($_POST['sender_name'] ?? ''), trim($_POST['whatsapp_number'] ?? ''),
            trim($_POST['phone_number_id'] ?? ''), trim($_POST['access_token'] ?? '')
        );
        if ($result['ok']) { $message = $result['message']; } else { $error = $result['message']; }
    }

    $settings = hr_sms_whatsapp_settings($pdo, $school_id);
}

$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'sms_whatsapp' : 'hr';

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
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;max-width:560px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;max-width:560px;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.sms-alert.info{background:rgba(0,168,168,0.1);color:var(--cyan);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.sms-hint{color:var(--muted);font-size:0.75rem;margin-top:4px;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--muted);margin-top:10px;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.sms-pill.ok{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.sms-pill.muted{background:rgba(148,163,184,0.12);color:var(--muted);}
</style>

<h1 style="margin:0 0 16px;font-size:1.4rem;">Bulk SMS</h1>
<div class="sms-tabs">
    <a href="wallet.php">Wallet</a>
    <a href="contacts.php">Contacts</a>
    <a href="send.php">Send</a>
    <a href="history.php">History</a>
    <a href="whatsapp_settings.php" class="active">WhatsApp</a>
</div>

<?php if ($message): ?><div class="sms-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sms-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sms-section">
    <h3 style="margin:0 0 6px;font-size:1rem;"><i class="bi bi-whatsapp"></i> Connect WhatsApp Business</h3>
    <p style="color:var(--muted);font-size:0.8rem;margin:0 0 10px;">Connect your school's own WhatsApp Business number (Meta Cloud API). Once connected, every Bulk SMS send tries WhatsApp first for each recipient and falls back to SMS automatically when it's not available.</p>

    <?php if ($settings): ?>
        <div style="margin-bottom:6px;">
            <?php if ($settings['status'] === 'active'): ?>
                <span class="sms-pill ok">Connected</span>
            <?php else: ?>
                <span class="sms-pill muted">Disconnected</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save">
        <label>Sender Name</label>
        <input type="text" name="sender_name" value="<?= htmlspecialchars($settings['sender_name'] ?? '') ?>" placeholder="e.g. Greenhill Academy" required>

        <label>WhatsApp Number</label>
        <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_number'] ?? '') ?>" placeholder="+256772000000" required>

        <label>Phone Number ID</label>
        <input type="text" name="phone_number_id" value="<?= htmlspecialchars($settings['phone_number_id'] ?? '') ?>" placeholder="1029384756" required>
        <div class="sms-hint">From your Meta WhatsApp Business API app dashboard.</div>

        <label>Access Token</label>
        <input type="password" name="access_token" placeholder="<?= $settings ? 'Leave blank to keep the current token' : 'EAAG...' ?>" autocomplete="off">
        <div class="sms-hint">Stored encrypted. Only re-enter this if you're rotating the token.</div>

        <button type="submit">Save WhatsApp Settings</button>
    </form>

    <?php if ($settings && $settings['status'] === 'active'): ?>
        <form method="POST" style="margin-top:0;">
            <input type="hidden" name="action" value="disable">
            <button type="submit" class="ghost">Disconnect WhatsApp</button>
        </form>
    <?php endif; ?>
</div>

<div class="sms-alert info">
    Meta only allows free-form text outside approved templates within a 24-hour window after the recipient has messaged your WhatsApp number first. Outside that window, a recipient's message automatically falls back to SMS -- nothing is ever silently lost.
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