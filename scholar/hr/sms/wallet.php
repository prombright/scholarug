<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK SMS: WALLET
|--------------------------------------------------------------------------
| Per-school SMS wallet (sms_wallets/sms_wallet_transactions) -- separate
| from the one shared platform-wide Bulk SMS account message_parents.php
| uses. Top-up flow mirrors subscription_initiate.php/bulksms's own
| wallet.php exactly: mobile money via the shared payments/Gateways.php
| layer, AJAX initiate + poll, wallet only ever credited in
| topup_status.php once a payment is confirmed.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/ScholarSmsWallet.php';
require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';
require_once __DIR__ . '/../../../payments/Gateways.php';
require_once __DIR__ . '/../../../payments/CountryCodes.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();

$balance = ScholarSmsWallet::balance($pdo, $school_id);
$currency = ScholarSmsPricing::currency($pdo);

$mtnGateway = Gateways::mobileMoney('mtn');
$airtelGateway = Gateways::mobileMoney('airtel');

$tx_stmt = $pdo->prepare("SELECT type, amount, balance_after, reference, created_by, created_at FROM sms_wallet_transactions WHERE school_id = ? ORDER BY created_at DESC LIMIT 50");
$tx_stmt->execute([$school_id]);
$transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_stmt = $pdo->prepare("SELECT reference, network, phone, amount, currency, created_at FROM sms_topup_requests WHERE school_id = ? AND status = 'pending' ORDER BY created_at DESC");
$pending_stmt->execute([$school_id]);
$pending_topups = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'sms_wallet' : 'hr';

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
.sms-balance{font-size:2.2rem;font-weight:800;color:var(--cyan);}
.sms-balance-label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;display:none;}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.sms-alert.info{background:rgba(245,158,11,0.12);color:var(--amber, #f59e0b);}
.sms-method-grid{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.sms-method-card{display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 24px;border-radius:10px;border:1px solid var(--border);background:var(--panel);color:var(--text);cursor:pointer;min-width:120px;}
.sms-method-card.active{border-color:var(--cyan);background:rgba(0,168,168,0.08);}
.sms-method-card:disabled{opacity:0.4;cursor:not-allowed;}
.sms-badge{font-size:0.65rem;padding:2px 8px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section input,.sms-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--text);margin-left:8px;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.sms-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--cyan);border-radius:50%;margin:0 auto;animation:sms-spin 0.8s linear infinite;}
@keyframes sms-spin{to{transform:rotate(360deg);}}
</style>

<h1 style="margin:0 0 16px;font-size:1.4rem;">Bulk SMS</h1>
<div class="sms-tabs">
    <a href="wallet.php" class="active">Wallet</a>
    <a href="contacts.php">Contacts</a>
    <a href="send.php">Send</a>
    <a href="history.php">History</a>
    <a href="whatsapp_settings.php">WhatsApp</a>
</div>

<div class="sms-section">
    <div class="sms-balance"><?= htmlspecialchars($currency) ?> <?= number_format($balance, 2) ?></div>
    <div class="sms-balance-label">Wallet Balance</div>
</div>

<div class="sms-section">
    <h3 style="margin:0 0 16px;font-size:1rem;">Load Wallet</h3>
    <div id="topupAlert" class="sms-alert"></div>

    <div class="sms-method-grid" id="topupMethods">
        <button type="button" class="sms-method-card" data-network="mtn">
            <span>MTN Mobile Money</span>
            <?php if (!$mtnGateway->isConfigured()): ?><span class="sms-badge">Demo mode</span><?php endif; ?>
        </button>
        <button type="button" class="sms-method-card" data-network="airtel">
            <span>Airtel Money</span>
            <?php if (!$airtelGateway->isConfigured()): ?><span class="sms-badge">Demo mode</span><?php endif; ?>
        </button>
    </div>

    <form id="topupForm" style="display:none;">
        <input type="hidden" name="network" id="topupNetwork" value="">
        <div style="display:flex;gap:10px;">
            <div style="flex:0 0 140px;">
                <label>Country</label>
                <select name="dial_code" id="topupDialCode">
                    <?php foreach (CountryCodes::LIST as $c): ?>
                        <option value="<?= htmlspecialchars($c['dial']) ?>"><?= htmlspecialchars($c['dial'] . ' ' . $c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;">
                <label>Phone Number</label>
                <input type="tel" name="phone" id="topupPhone" placeholder="7XX XXX XXX" required>
            </div>
        </div>
        <label>Amount (<?= htmlspecialchars($currency) ?>)</label>
        <input type="number" name="amount" id="topupAmount" min="500" max="5000000" step="1" placeholder="10000" required>
        <button type="submit" id="topupSubmit">Load Now</button>
        <button type="button" class="ghost" id="topupCancel">Cancel</button>
    </form>

    <div id="topupWaiting" style="display:none;text-align:center;padding:20px 0;">
        <div class="sms-spinner"></div>
        <p style="margin-top:12px;color:var(--muted);" id="topupWaitingText">Check your phone to approve the payment...</p>
    </div>
</div>

<?php if (!empty($pending_topups)): ?>
<div class="sms-section">
    <h3 style="margin:0 0 16px;font-size:1rem;">Pending Top-ups</h3>
    <table>
        <tr><th>Network</th><th>Amount</th><th>Phone</th><th>Submitted</th><th></th></tr>
        <?php foreach ($pending_topups as $p): ?>
        <tr data-reference="<?= htmlspecialchars($p['reference']) ?>">
            <td><?= htmlspecialchars(strtoupper($p['network'])) ?></td>
            <td><?= htmlspecialchars($p['currency']) ?> <?= number_format((float) $p['amount'], 2) ?></td>
            <td><?= htmlspecialchars($p['phone']) ?></td>
            <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($p['created_at']))) ?></td>
            <td><button type="button" class="ghost sms-check-status" data-reference="<?= htmlspecialchars($p['reference']) ?>" style="margin-top:0;padding:6px 12px;font-size:0.75rem;">Check Status</button></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="sms-section">
    <h3 style="margin:0 0 16px;font-size:1rem;">Transaction History</h3>
    <?php if (empty($transactions)): ?>
        <div class="sms-empty">No activity yet.</div>
    <?php else: ?>
    <table>
        <tr><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Date</th></tr>
        <?php foreach ($transactions as $tx): $isCredit = in_array($tx['type'], ['deposit', 'admin_credit', 'refund'], true); ?>
        <tr>
            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $tx['type']))) ?></td>
            <td style="color:<?= $isCredit ? 'var(--green, #10b981)' : 'var(--danger, #ef4444)' ?>;font-weight:700;"><?= $isCredit ? '+' : '-' ?><?= htmlspecialchars($currency) ?> <?= number_format((float) $tx['amount'], 2) ?></td>
            <td><?= htmlspecialchars($currency) ?> <?= number_format((float) $tx['balance_after'], 2) ?></td>
            <td><?= htmlspecialchars($tx['reference'] ?? '—') ?></td>
            <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($tx['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    "use strict";
    var methodButtons = document.querySelectorAll(".sms-method-card[data-network]");
    var form = document.getElementById("topupForm");
    var networkInput = document.getElementById("topupNetwork");
    var alertBox = document.getElementById("topupAlert");
    var waitingBox = document.getElementById("topupWaiting");
    var waitingText = document.getElementById("topupWaitingText");
    var submitBtn = document.getElementById("topupSubmit");
    var cancelBtn = document.getElementById("topupCancel");
    if (!form) return;

    var pollTimer = null, pollDeadline = null;

    function showAlert(kind, message) {
        alertBox.className = "sms-alert " + kind;
        alertBox.textContent = message;
        alertBox.style.display = "block";
    }
    function hideAlert() { alertBox.style.display = "none"; }

    methodButtons.forEach(function (btn) {
        btn.addEventListener("click", function () {
            methodButtons.forEach(function (b) { b.classList.remove("active"); });
            btn.classList.add("active");
            networkInput.value = btn.getAttribute("data-network");
            form.style.display = "block";
            waitingBox.style.display = "none";
            hideAlert();
        });
    });

    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            form.style.display = "none";
            methodButtons.forEach(function (b) { b.classList.remove("active"); });
            hideAlert();
            form.reset();
        });
    }

    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function pollStatus(reference, onDone) {
        stopPolling();
        pollDeadline = Date.now() + 2 * 60 * 1000;
        pollTimer = setInterval(function () {
            if (Date.now() > pollDeadline) { stopPolling(); onDone("timeout"); return; }
            fetch("topup_status.php?reference=" + encodeURIComponent(reference), { credentials: "same-origin" })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === "successful") { stopPolling(); onDone("successful"); }
                    else if (data.status === "failed") { stopPolling(); onDone("failed", data.error); }
                })
                .catch(function () {});
        }, 3000);
    }

    document.querySelectorAll(".sms-check-status").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var reference = btn.getAttribute("data-reference");
            btn.disabled = true;
            btn.textContent = "…";
            fetch("topup_status.php?reference=" + encodeURIComponent(reference), { credentials: "same-origin" })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === "successful" || data.status === "failed") { window.location.reload(); }
                    else { btn.disabled = false; btn.textContent = "Check Status"; }
                })
                .catch(function () { btn.disabled = false; btn.textContent = "Check Status"; });
        });
    });

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        hideAlert();
        var formData = new FormData(form);
        submitBtn.disabled = true;

        fetch("topup_initiate.php", { method: "POST", body: formData, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === "pending") {
                    form.style.display = "none";
                    waitingBox.style.display = "block";
                    waitingText.textContent = data.message || "Check your phone to approve the payment...";
                    pollStatus(data.reference, function (outcome, error) {
                        if (outcome === "successful") {
                            waitingText.textContent = "Payment received! Updating your balance...";
                            setTimeout(function () { window.location.href = "wallet.php"; }, 1200);
                        } else if (outcome === "failed") {
                            waitingBox.style.display = "none";
                            form.style.display = "block";
                            submitBtn.disabled = false;
                            showAlert("error", error || "The payment failed or was declined.");
                        } else if (outcome === "timeout") {
                            waitingText.textContent = "Still waiting for confirmation -- your wallet will be credited automatically once approved. You can check back later on this page.";
                        }
                    });
                } else if (data.status === "not-configured") {
                    submitBtn.disabled = false;
                    showAlert("info", data.message);
                } else {
                    submitBtn.disabled = false;
                    showAlert("error", data.error || "Something went wrong. Please try again.");
                }
            })
            .catch(function () {
                submitBtn.disabled = false;
                showAlert("error", "Could not reach the server. Please check your connection and try again.");
            });
    });
})();
</script>

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