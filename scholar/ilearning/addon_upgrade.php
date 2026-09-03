<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['headteacher', 'school_admin', 'bursar']);

require_once __DIR__ . '/../../payments/Gateways.php';
require_once __DIR__ . '/../../payments/CountryCodes.php';
require_once __DIR__ . '/../../payments/Plans.php';

$schoolId = current_school_id();

$stmt = $pdo->prepare('SELECT * FROM ilearning_addons WHERE school_id = ?');
$stmt->execute([$schoolId]);
$addon = $stmt->fetch();

$mtnGateway = Gateways::mobileMoney('mtn');
$airtelGateway = Gateways::mobileMoney('airtel');
$isDemoMode = !Gateways::isLive('mtn') && !Gateways::isLive('airtel');
$plans = Plans::forApp('scholar_ilearning');

$pendingCharges = [];
if ($addon) {
    $pendingStmt = $pdo->prepare(
        "SELECT reference, network, phone, amount, currency, created_at
         FROM ilearning_addon_charges WHERE addon_id = ? AND status = 'pending' ORDER BY created_at DESC"
    );
    $pendingStmt->execute([$addon['id']]);
    $pendingCharges = $pendingStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ScholarUg | iLearning Live Classes Add-On</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { margin:0; background:#080b11; font-family:Inter,Segoe UI,sans-serif; color:#e2e8f0; }
.container { max-width:800px; margin:60px auto; padding:30px; }
.card { background:#0d1118; border:1px solid #1e293b; border-radius:15px; padding:30px; margin-bottom:24px; }
h1 { color:white; margin-bottom:10px; }
h3 { color:white; margin:0 0 16px; font-size:1rem; }
p { color:#64748b; }
.stat-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
.stat { background:#0d1118; border:1px solid #1e293b; border-radius:12px; padding:18px; }
.stat .label { font-size:.7rem; text-transform:uppercase; color:#64748b; margin-bottom:6px; }
.stat .value { font-size:1.2rem; font-weight:700; color:#fff; }
.method-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px; }
.method-card { background:#161920; border:1px solid #1e293b; border-radius:10px; padding:14px; cursor:pointer; text-align:center; color:#e2e8f0; }
.method-card.active { border-color:#00A8A8; }
.method-card .badge { display:block; margin-top:8px; font-size:.75rem; color:#94a3b8; }
.field { margin-bottom:16px; }
label { display:block; font-size:.75rem; text-transform:uppercase; color:#64748b; margin-bottom:8px; font-weight:bold; }
input, select { width:100%; padding:14px; background:#080b11; border:1px solid #1e293b; border-radius:8px; color:white; box-sizing:border-box; }
button { padding:14px 25px; border:none; border-radius:8px; background:#00A8A8; color:white; font-weight:bold; cursor:pointer; }
button:disabled { opacity:.5; cursor:not-allowed; }
.btn-ghost { background:#1e293b; color:#94a3b8; margin-left:10px; }
.alert { padding:15px; border-radius:8px; margin-bottom:20px; display:none; }
.alert.error { background:rgba(239,68,68,.1); color:#fca5a5; }
.alert.info { background:rgba(0,168,168,.1); color:#67e8f9; }
table { width:100%; border-collapse:collapse; margin-top:10px; }
th, td { padding:10px; border:1px solid #1e293b; text-align:left; font-size:.85rem; }
.back { display:inline-block; margin-top:10px; color:#00A8A8; text-decoration:none; }
.spinner { width:32px; height:32px; border:3px solid #1e293b; border-top-color:#00A8A8; border-radius:50%; margin:0 auto; animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<style>.scholar-theme-toggle{display:none !important;}</style>

<div class="container">
<h1>iLearning Live Classes</h1>
<p>A paid add-on on top of your Scholar subscription — notes, auto-marked assessments and progress tracking are already included free.</p>

<?php if ($isDemoMode): ?>
<div class="card" style="background:rgba(0,168,168,.08);border-color:#00A8A8;">
    <i class="bi bi-info-circle"></i>
    Demo Mode — no real MTN/Airtel merchant account is connected yet, so payments here simulate a real charge
    (phone numbers ending in 0 simulate a declined payment; any other number succeeds).
</div>
<?php endif; ?>

<?php if ($addon): ?>
<div class="stat-grid">
    <div class="stat">
        <div class="label">Add-On Status</div>
        <div class="value"><?= htmlspecialchars(ucfirst($addon['status']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= $addon['status'] === 'trialing' ? 'Trial Ends' : 'Current Period Ends' ?></div>
        <div class="value">
            <?php
            $deadline = $addon['status'] === 'trialing' ? $addon['trial_ends_at'] : $addon['current_period_end'];
            echo $deadline ? htmlspecialchars(date('d M Y', strtotime($deadline)), ENT_QUOTES, 'UTF-8') : '—';
            ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>Choose a Plan</h3>
    <div class="method-grid" id="planMethods">
        <?php foreach ($plans as $code => $plan): ?>
        <div class="method-card" data-plan="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($plan['label'], ENT_QUOTES, 'UTF-8') ?>
            <span class="badge"><?= htmlspecialchars($plan['currency'] . ' ' . number_format((float) $plan['amount'], 0), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3>Pay With Mobile Money</h3>
    <div id="subAlert" class="alert"></div>

    <div class="method-grid" id="subMethods">
        <div class="method-card" data-network="mtn">MTN<span class="badge"><?= htmlspecialchars($mtnGateway->label(), ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="method-card" data-network="airtel">Airtel<span class="badge"><?= htmlspecialchars($airtelGateway->label(), ENT_QUOTES, 'UTF-8') ?></span></div>
    </div>

    <form id="subForm" style="display:none;">
        <input type="hidden" name="network" id="subNetwork" value="">
        <input type="hidden" name="plan_code" id="subPlanCode" value="">

        <div class="field" style="display:flex;gap:10px;">
            <div style="flex:0 0 140px;">
                <label>Country</label>
                <select name="dial_code" id="subDialCode">
                    <?php foreach (CountryCodes::LIST as $c): ?>
                        <option value="<?= htmlspecialchars($c['dial'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['dial'] . ' ' . $c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;">
                <label>Phone Number</label>
                <input type="tel" name="phone" id="subPhone" placeholder="7XX XXX XXX" required>
            </div>
        </div>

        <button type="submit" id="subSubmit" disabled><i class="bi bi-credit-card"></i> Pay Now</button>
        <button type="button" class="btn-ghost" id="subCancel">Cancel</button>
    </form>

    <div id="subWaiting" style="display:none;text-align:center;padding:20px 0;">
        <div class="spinner"></div>
        <p style="margin-top:12px;" id="subWaitingText">Check your phone to approve the payment...</p>
    </div>
</div>

<?php if (!empty($pendingCharges)): ?>
<div class="card">
    <h3>Pending Payments</h3>
    <table>
        <thead><tr><th>Network</th><th>Amount</th><th>Phone</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($pendingCharges as $p): ?>
            <tr data-reference="<?= htmlspecialchars($p['reference'], ENT_QUOTES, 'UTF-8') ?>">
                <td><?= htmlspecialchars(strtoupper($p['network']), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $p['amount'], 0) ?></td>
                <td><?= htmlspecialchars($p['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($p['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><button type="button" class="sub-check-status btn-ghost" data-reference="<?= htmlspecialchars($p['reference'], ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 14px;font-size:.8rem;">Check Status</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<a class="back" href="live_sessions.php">← Back to Live Classes</a>
</div>

<script>
(function () {
    "use strict";
    var planButtons = document.querySelectorAll("#planMethods .method-card[data-plan]");
    var methodButtons = document.querySelectorAll("#subMethods .method-card[data-network]");
    var form = document.getElementById("subForm");
    var networkInput = document.getElementById("subNetwork");
    var planCodeInput = document.getElementById("subPlanCode");
    var alertBox = document.getElementById("subAlert");
    var waitingBox = document.getElementById("subWaiting");
    var waitingText = document.getElementById("subWaitingText");
    var submitBtn = document.getElementById("subSubmit");
    var cancelBtn = document.getElementById("subCancel");
    var pollTimer = null, pollDeadline = null;

    function showAlert(kind, message) {
        alertBox.className = "alert " + kind;
        alertBox.textContent = message;
        alertBox.style.display = "block";
    }
    function hideAlert() { alertBox.style.display = "none"; }
    function updateSubmitEnabled() { submitBtn.disabled = !(planCodeInput.value && networkInput.value); }

    planButtons.forEach(function (btn) {
        btn.addEventListener("click", function () {
            planButtons.forEach(function (b) { b.classList.remove("active"); });
            btn.classList.add("active");
            planCodeInput.value = btn.getAttribute("data-plan");
            updateSubmitEnabled();
        });
    });

    methodButtons.forEach(function (btn) {
        btn.addEventListener("click", function () {
            methodButtons.forEach(function (b) { b.classList.remove("active"); });
            btn.classList.add("active");
            networkInput.value = btn.getAttribute("data-network");
            form.style.display = "block";
            waitingBox.style.display = "none";
            hideAlert();
            updateSubmitEnabled();
        });
    });

    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            form.style.display = "none";
            methodButtons.forEach(function (b) { b.classList.remove("active"); });
            hideAlert();
            form.reset();
            networkInput.value = "";
            updateSubmitEnabled();
        });
    }

    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function pollStatus(reference, onDone) {
        stopPolling();
        pollDeadline = Date.now() + 2 * 60 * 1000;
        pollTimer = setInterval(function () {
            if (Date.now() > pollDeadline) { stopPolling(); onDone("timeout"); return; }
            fetch("addon_status.php?reference=" + encodeURIComponent(reference), { credentials: "same-origin" })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === "successful") { stopPolling(); onDone("successful"); }
                    else if (data.status === "failed") { stopPolling(); onDone("failed", data.error); }
                })
                .catch(function () {});
        }, 3000);
    }

    document.querySelectorAll(".sub-check-status").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var reference = btn.getAttribute("data-reference");
            btn.disabled = true;
            btn.textContent = "…";
            fetch("addon_status.php?reference=" + encodeURIComponent(reference), { credentials: "same-origin" })
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

        fetch("addon_initiate.php", { method: "POST", body: formData, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === "pending") {
                    form.style.display = "none";
                    waitingBox.style.display = "block";
                    waitingText.textContent = data.message || "Check your phone to approve the payment...";
                    pollStatus(data.reference, function (outcome, error) {
                        if (outcome === "successful") {
                            waitingText.textContent = "Payment received! Unlocking live classes...";
                            setTimeout(function () { window.location.href = "live_sessions.php"; }, 1200);
                        } else if (outcome === "failed") {
                            waitingBox.style.display = "none";
                            form.style.display = "block";
                            updateSubmitEnabled();
                            showAlert("error", error || "The payment failed or was declined.");
                        } else if (outcome === "timeout") {
                            waitingText.textContent = "Still waiting for confirmation -- the add-on will unlock automatically once approved.";
                        }
                    });
                } else if (data.status === "not-configured") {
                    updateSubmitEnabled();
                    showAlert("info", data.message);
                } else {
                    updateSubmitEnabled();
                    showAlert("error", data.error || "Something went wrong. Please try again.");
                }
            })
            .catch(function () {
                updateSubmitEnabled();
                showAlert("error", "Could not reach the server. Please check your connection and try again.");
            });
    });
})();
</script>
</body>
</html>
