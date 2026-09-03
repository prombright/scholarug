<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

require_role(['nurse']);

$school_id = current_school_id();
$nurse_username = $_SESSION['username'] ?? 'nurse';

const CLINIC_CONSULTATION_FEE = 2000.00;

/**
 * Find (or create) the clinic_patients row for a visit's chosen patient.
 * Mirrors iClinic/nurse.php's resolvePatientId(), but against Scholar's
 * own PDO connection — both write into the same shared clinic_patients
 * table (see _setup/clinic_unification_migration.sql), which is what lets
 * a nurse enrolled through Scholar and a nurse logged into iClinic
 * directly see the exact same patients for their school.
 */
function resolvePatientId(PDO $pdo, int $school_id, string $source, string $ref, string $newName, string $newType, string $newGender, string $newPhone, string $notes): ?int
{
    if ($source === 'patient') {
        $stmt = $pdo->prepare("SELECT id FROM clinic_patients WHERE id = ? AND school_id = ? LIMIT 1");
        $stmt->execute([(int) $ref, $school_id]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    if ($source === 'student') {
        $student_id = (int) $ref;
        if ($student_id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id, full_name, sex FROM students WHERE id = ? AND school_id = ? LIMIT 1");
        $stmt->execute([$student_id, $school_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            return null;
        }

        $find = $pdo->prepare("SELECT id FROM clinic_patients WHERE school_id = ? AND student_id = ? LIMIT 1");
        $find->execute([$school_id, $student_id]);
        $existing = $find->fetchColumn();
        if ($existing) {
            if ($notes !== '') {
                $pdo->prepare("UPDATE clinic_patients SET notes = ? WHERE id = ?")->execute([$notes, $existing]);
            }
            return (int) $existing;
        }

        $ins = $pdo->prepare("INSERT INTO clinic_patients (school_id, student_id, patient_type, full_name, gender, notes) VALUES (?, ?, 'student', ?, ?, ?)");
        $ins->execute([$school_id, $student_id, $student['full_name'], $student['sex'], $notes]);
        return (int) $pdo->lastInsertId();
    }

    if ($source === 'new' && $newName !== '') {
        $type = in_array($newType, ['staff', 'walkin'], true) ? $newType : 'walkin';
        $gender = in_array($newGender, ['Male', 'Female'], true) ? $newGender : null;
        $phone = $newPhone !== '' ? $newPhone : null;

        $ins = $pdo->prepare("INSERT INTO clinic_patients (school_id, patient_type, full_name, gender, phone, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$school_id, $type, $newName, $gender, $phone, $notes]);
        return (int) $pdo->lastInsertId();
    }

    return null;
}

$feedback = '';
$feedback_type = '';

// ==========================================
// LOG VISIT (+ optional leave pass, prescribe & dispense, auto-invoice)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_visit'])) {
    $source = $_POST['patient_source'] ?? '';
    $ref = $_POST['patient_ref'] ?? '';
    $new_name = trim($_POST['new_patient_name'] ?? '');
    $new_type = $_POST['new_patient_type'] ?? 'walkin';
    $new_gender = $_POST['new_patient_gender'] ?? '';
    $new_phone = trim($_POST['new_patient_phone'] ?? '');
    $notes = trim($_POST['medical_flags'] ?? '');

    $symptoms = trim($_POST['symptoms'] ?? '');
    $disease = trim($_POST['disease'] ?? '');
    $medicine_note = trim($_POST['medicine_given'] ?? '');
    $tracking = isset($_POST['requires_dose_tracking']) ? 1 : 0;
    $status = $tracking === 1 ? 'In Progress' : 'Completed';

    $next_dose_time = null;
    if ($tracking === 1 && !empty($_POST['next_dose_hours'])) {
        $next_dose_time = date('Y-m-d H:i:s', strtotime('+' . (int) $_POST['next_dose_hours'] . ' hours'));
    }

    $patient_id = resolvePatientId($pdo, $school_id, $source, $ref, $new_name, $new_type, $new_gender, $new_phone, $notes);

    if (!$patient_id || $symptoms === '' || $disease === '') {
        $feedback = 'Pick a patient, and fill in symptoms and disease.';
        $feedback_type = 'error';
    } else {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("INSERT INTO clinic_visits (school_id, patient_id, symptoms, disease, medicine_given, requires_dose_tracking, dose_status, next_dose_time, nurse_username) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$school_id, $patient_id, $symptoms, $disease, $medicine_note !== '' ? $medicine_note : null, $tracking, $status, $next_dose_time, $nurse_username]);
        $visit_id = (int) $pdo->lastInsertId();

        if (isset($_POST['issue_pass']) && $_POST['issue_pass'] === '1') {
            $destination = $_POST['pass_destination'] ?? 'Classroom';
            $valid_until = date('Y-m-d H:i:s', strtotime('+' . (int) ($_POST['pass_duration'] ?? 2) . ' hours'));
            $reason_text = 'Medical Release: ' . $disease;
            $pdo->prepare("INSERT INTO clinic_leave_passes (school_id, visit_id, patient_id, destination, reason, valid_until) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$school_id, $visit_id, $patient_id, $destination, $reason_text, $valid_until]);
        }

        $invoice_total = CLINIC_CONSULTATION_FEE;
        $invoice_items = [];

        $rx_drugs = $_POST['rx_inventory_id'] ?? [];
        $rx_dosage = $_POST['rx_dosage'] ?? [];
        $rx_frequency = $_POST['rx_frequency'] ?? [];
        $rx_duration = $_POST['rx_duration'] ?? [];
        $rx_quantity = $_POST['rx_quantity'] ?? [];

        for ($i = 0; $i < count($rx_drugs); $i++) {
            $inventory_id = (int) $rx_drugs[$i];
            $qty_requested = max(0, (int) ($rx_quantity[$i] ?? 0));
            if ($inventory_id <= 0 || $qty_requested <= 0) {
                continue;
            }

            $drug_stmt = $pdo->prepare("SELECT drug_name, available_stock, unit_price FROM clinic_inventory WHERE id = ? AND school_id = ? LIMIT 1");
            $drug_stmt->execute([$inventory_id, $school_id]);
            $drug = $drug_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$drug) {
                continue;
            }

            $qty_dispensed = min($qty_requested, (int) $drug['available_stock']);
            $rx_status = $qty_dispensed <= 0 ? 'pending' : ($qty_dispensed < $qty_requested ? 'partial' : 'dispensed');

            $rx_stmt = $pdo->prepare("INSERT INTO clinic_prescriptions (school_id, visit_id, patient_id, inventory_id, dosage, frequency, duration, quantity_prescribed, quantity_dispensed, prescribed_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $rx_stmt->execute([$school_id, $visit_id, $patient_id, $inventory_id, $rx_dosage[$i] ?? '', $rx_frequency[$i] ?? '', $rx_duration[$i] ?? '', $qty_requested, $qty_dispensed, $nurse_username, $rx_status]);
            $prescription_id = (int) $pdo->lastInsertId();

            if ($qty_dispensed > 0) {
                $pdo->prepare("UPDATE clinic_inventory SET available_stock = available_stock - ? WHERE id = ? AND school_id = ? AND available_stock >= ?")
                    ->execute([$qty_dispensed, $inventory_id, $school_id, $qty_dispensed]);
                $pdo->prepare("INSERT INTO clinic_dispense_log (prescription_id, quantity, dispensed_by) VALUES (?, ?, ?)")
                    ->execute([$prescription_id, $qty_dispensed, $nurse_username]);

                $line_total = $qty_dispensed * (float) $drug['unit_price'];
                $invoice_items[] = [$drug['drug_name'] . ' (dispensed)', (float) $drug['unit_price'], $qty_dispensed, $line_total];
                $invoice_total += $line_total;
            }
        }

        $inv_stmt = $pdo->prepare("INSERT INTO clinic_invoices (school_id, visit_id, patient_id, total_amount, status) VALUES (?, ?, ?, ?, 'unpaid')");
        $inv_stmt->execute([$school_id, $visit_id, $patient_id, $invoice_total]);
        $invoice_id = (int) $pdo->lastInsertId();

        $item_stmt = $pdo->prepare("INSERT INTO clinic_invoice_items (invoice_id, description, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?)");
        $item_stmt->execute([$invoice_id, 'Consultation Fee', CLINIC_CONSULTATION_FEE, 1, CLINIC_CONSULTATION_FEE]);
        foreach ($invoice_items as [$desc, $price, $qty, $lineTotal]) {
            $item_stmt->execute([$invoice_id, $desc, $price, $qty, $lineTotal]);
        }

        $pdo->commit();
        $feedback = 'Visit logged successfully.';
        $feedback_type = 'success';
    }
}

// ==========================================
// TERMINATE PASS / COMPLETE DOSE TRACKING
// ==========================================
if (isset($_GET['terminate_pass_id'])) {
    $pdo->prepare("UPDATE clinic_leave_passes SET status = 'Terminated' WHERE id = ? AND school_id = ?")
        ->execute([(int) $_GET['terminate_pass_id'], $school_id]);
    header('Location: nurse_dashboard.php');
    exit();
}
if (isset($_GET['complete_visit_id'])) {
    $pdo->prepare("UPDATE clinic_visits SET dose_status = 'Completed', next_dose_time = NULL WHERE id = ? AND school_id = ?")
        ->execute([(int) $_GET['complete_visit_id'], $school_id]);
    header('Location: nurse_dashboard.php');
    exit();
}

// ==========================================
// DATA FOR THE PAGE
// ==========================================
$students_stmt = $pdo->prepare("SELECT id, full_name, class_name FROM students WHERE school_id = ? ORDER BY full_name ASC");
$students_stmt->execute([$school_id]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$patients_stmt = $pdo->prepare("SELECT id, full_name, patient_type FROM clinic_patients WHERE school_id = ? AND patient_type != 'student' ORDER BY full_name ASC");
$patients_stmt->execute([$school_id]);
$existing_patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);

$drugs_stmt = $pdo->prepare("SELECT id, drug_name, available_stock, unit_price FROM clinic_inventory WHERE school_id = ? AND expiry_date > NOW() ORDER BY drug_name ASC");
$drugs_stmt->execute([$school_id]);
$drugs = $drugs_stmt->fetchAll(PDO::FETCH_ASSOC);

$visits_stmt = $pdo->prepare("
    SELECT v.id, v.disease, v.medicine_given, v.dose_status, v.next_dose_time, v.visit_date, cp.full_name AS patient_name, cp.patient_type
    FROM clinic_visits v JOIN clinic_patients cp ON v.patient_id = cp.id
    WHERE v.school_id = ? ORDER BY v.id DESC LIMIT 15
");
$visits_stmt->execute([$school_id]);
$recent_visits = $visits_stmt->fetchAll(PDO::FETCH_ASSOC);

$passes_stmt = $pdo->prepare("
    SELECT p.id, p.destination, p.reason, p.valid_until, cp.full_name AS patient_name
    FROM clinic_leave_passes p JOIN clinic_patients cp ON p.patient_id = cp.id
    WHERE p.school_id = ? AND p.status = 'Active' ORDER BY p.valid_until ASC
");
$passes_stmt->execute([$school_id]);
$active_passes = $passes_stmt->fetchAll(PDO::FETCH_ASSOC);

$tracking_stmt = $pdo->prepare("
    SELECT v.id, v.disease, v.next_dose_time, cp.full_name AS patient_name
    FROM clinic_visits v JOIN clinic_patients cp ON v.patient_id = cp.id
    WHERE v.school_id = ? AND v.requires_dose_tracking = 1 AND v.dose_status = 'In Progress' ORDER BY v.next_dose_time ASC
");
$tracking_stmt->execute([$school_id]);
$dose_tracking = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);

$invoices_stmt = $pdo->prepare("
    SELECT i.id, i.total_amount, i.amount_paid, i.status, i.created_at, cp.full_name AS patient_name
    FROM clinic_invoices i JOIN clinic_patients cp ON i.patient_id = cp.id
    WHERE i.school_id = ? ORDER BY i.id DESC LIMIT 10
");
$invoices_stmt->execute([$school_id]);
$invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);

$stats_stmt = $pdo->prepare("SELECT COUNT(*) FROM clinic_visits WHERE school_id = ?");
$stats_stmt->execute([$school_id]);
$total_visits = (int) $stats_stmt->fetchColumn();

$low_stock_stmt = $pdo->prepare("SELECT COUNT(*) FROM clinic_inventory WHERE school_id = ? AND available_stock <= low_stock_threshold");
$low_stock_stmt->execute([$school_id]);
$low_stock_count = (int) $low_stock_stmt->fetchColumn();

$outstanding_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - amount_paid), 0) FROM clinic_invoices WHERE school_id = ? AND status IN ('unpaid','partial')");
$outstanding_stmt->execute([$school_id]);
$outstanding_total = (float) $outstanding_stmt->fetchColumn();

// Real visit breakdown by patient type for the donut chart.
$visits_by_type_stmt = $pdo->prepare(
    "SELECT cp.patient_type, COUNT(*) AS cnt
     FROM clinic_visits v
     JOIN clinic_patients cp ON v.patient_id = cp.id
     WHERE v.school_id = ?
     GROUP BY cp.patient_type"
);
$visits_by_type_stmt->execute([$school_id]);
$visit_type_colors = ['student' => 'var(--primary)', 'staff' => 'var(--secondary)', 'walkin' => '#f59e0b'];
$visit_type_total = 0;
$visit_type_rows = [];
foreach ($visits_by_type_stmt->fetchAll() as $row) {
    $visit_type_rows[$row['patient_type']] = (int) $row['cnt'];
    $visit_type_total += (int) $row['cnt'];
}
$visit_type_gradient_stops = [];
$visit_type_legend = [];
$__vcursor = 0;
foreach ($visit_type_colors as $type => $color) {
    $n = $visit_type_rows[$type] ?? 0;
    if ($n === 0) {
        continue;
    }
    $pct = round($n / $visit_type_total * 100);
    $visit_type_gradient_stops[] = "{$color} {$__vcursor}% " . ($__vcursor + $pct) . '%';
    $__vcursor += $pct;
    $visit_type_legend[] = ['label' => ucfirst($type), 'n' => $n, 'color' => $color];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholar | School Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0A3D62;
            --secondary: #00A8A8;
            --accent: #38ada9;
            --dark: #071E26;
            --light: #f7fbfd;
            --text: #334155;
        }
        /* This page is Scholar's one light-by-default dashboard (the rest are
           dark-by-default); the shared toggle in preloader.php only defines
           an override for the --bg/--panel naming most other pages use, so
           it intentionally has no effect here -- this page stays in its
           existing light look regardless of the site-wide toggle state. */
        body { font-family: 'Segoe UI', sans-serif; margin:0; background: linear-gradient(135deg, #f7fbfd 0%, #eef8ff 100%); color: var(--text); }
        .wrap { max-width: 1300px; margin: 0 auto; padding: 24px; }
        .topbar { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border-radius: 24px; padding: 24px 28px; box-shadow: 0 16px 40px rgba(10,61,98,0.18); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .topbar h1 { margin:0 0 8px; font-size: 26px; }
        .topbar p { margin:0; opacity: 0.95; }
        .topbar a { color: #fff; background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 999px; text-decoration: none; font-size: 13px; font-weight: 600; }
        /* Override the shared .scholar-logout-btn's red hover here -- this
           page's own white-on-gradient language reads better than red
           flashing against the teal/blue topbar every other Scholar page
           doesn't have. */
        .topbar .scholar-logout-btn:hover { background: rgba(255,255,255,0.28) !important; border-color: rgba(255,255,255,0.5) !important; }
        .cards { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 20px; }
        .card { background: #fff; border-radius: 20px; padding: 18px; box-shadow: 0 12px 30px rgba(15,23,42,0.08); display:flex; align-items:center; gap:14px; }
        .card-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.2rem; background:rgba(0,168,168,0.12); color:var(--secondary); }
        .card.low-stock .card-icon { background:rgba(245,158,11,0.12); color:#b45309; }
        .card.outstanding .card-icon { background:rgba(239,68,68,0.12); color:#b91c1c; }
        .card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: .08em; }
        .card .value { font-size: 24px; font-weight: 800; color: var(--primary); margin-top: 8px; }

        .reveal { opacity:0; transform:translateY(16px); transition:opacity .5s ease, transform .5s ease; }
        .reveal.revealed { opacity:1; transform:translateY(0); }
        .chart-panel { background:#fff; border-radius:24px; padding:20px; box-shadow:0 12px 30px rgba(15,23,42,0.08); margin-top:20px; display:flex; flex-direction:column; align-items:center; }
        .chart-panel h3 { margin:0 0 18px; color:var(--primary); font-size:16px; align-self:flex-start; }
        .donut { width:150px; height:150px; border-radius:50%; margin-bottom:18px; position:relative; transform:scale(.7); opacity:0; transition:transform .6s cubic-bezier(.22,1,.36,1), opacity .6s ease; }
        .reveal.revealed .donut { transform:scale(1); opacity:1; }
        .donut::after { content:''; position:absolute; inset:20px; background:#fff; border-radius:50%; }
        .donut-center { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .donut-center .n { font-size:1.4rem; font-weight:800; color:var(--primary); }
        .donut-center .label { font-size:0.65rem; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
        .donut-legend { display:flex; gap:18px; font-size:0.85rem; color:#64748b; }
        .donut-legend .dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:6px; }
        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity:1; transform:none; transition:none; }
            .donut { transition:none; transform:none; opacity:1; }
        }
        .layout { display: grid; grid-template-columns: 380px 1fr; gap: 20px; margin-top: 20px; align-items: start; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
        .panel { background:#fff; border-radius: 24px; padding: 20px; box-shadow: 0 12px 30px rgba(15,23,42,0.08); margin-top: 20px; }
        .panel:first-child { margin-top: 0; }
        .panel h3 { margin: 0 0 14px; color: var(--primary); font-size: 16px; }
        label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; margin: 10px 0 4px; letter-spacing: .05em; }
        input, select, textarea { width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 14px; box-sizing: border-box; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--secondary); }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius: 999px; background: var(--primary); color:white; text-decoration:none; font-weight:600; border: none; cursor: pointer; font-size: 14px; }
        .btn.secondary { background: #fff; color: var(--primary); border: 1px solid #dbeafe; }
        .btn.small { padding: 6px 12px; font-size: 12px; }
        .btn-block { width: 100%; margin-top: 16px; }
        table { width:100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align:left; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
        th { color:#64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
        .pill { display:inline-block; padding: 4px 10px; border-radius:999px; background: rgba(0,168,168,0.12); color: var(--secondary); font-weight:700; font-size: 11px; }
        .pill.warn { background: rgba(245,158,11,0.12); color: #b45309; }
        .pill.danger { background: rgba(239,68,68,0.12); color: #b91c1c; }
        .muted { color: #64748b; }
        .rx-row { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; margin-bottom: 10px; background: #f8fafc; }
        .rx-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .patient-toggle { display: flex; gap: 8px; margin-bottom: 10px; }
        .patient-toggle button { flex: 1; padding: 8px; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; font-size: 12px; font-weight: 600; }
        .patient-toggle button.active { background: var(--secondary); color: #fff; border-color: var(--secondary); }
        .feedback { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .feedback.success { background: rgba(16,185,129,0.1); color: #047857; }
        .feedback.error { background: rgba(239,68,68,0.1); color: #b91c1c; }
        table{display:block;overflow-x:auto;}
        @media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}
        /* The toggle from preloader.php has no effect here (see note above)
           -- hidden rather than left as a dead control, same convention
           already used on the developer/* pages for the same reason. */
        .scholar-theme-toggle{display:none !important;}
    </style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
    <div class="wrap">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:14px;">
                <?php if ($__badge_url): ?>
                    <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="" style="width:46px;height:46px;object-fit:contain;border-radius:8px;background:#fff;padding:3px;flex-shrink:0;">
                <?php else: ?>
                    <div style="width:46px;height:46px;border-radius:8px;background:rgba(255,255,255,0.2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
                <?php endif; ?>
                <div>
                    <h1><i class="bi bi-heart-pulse"></i> School Clinic</h1>
                    <p>Welcome, <?= safe_text($_SESSION['username'] ?? 'Nurse'); ?> — logged in via Scholar.</p>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="leave_requests.php"><i class="bi bi-calendar-minus"></i> My Leave</a>
                <a href="logout.php" class="scholar-logout-btn" style="background:rgba(255,255,255,0.15);border-color:rgba(255,255,255,0.3);color:#fff;">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Log Out
                </a>
            </div>
        </div>

        <div class="cards reveal">
            <div class="card">
                <div class="card-icon"><i class="bi bi-clipboard2-pulse"></i></div>
                <div><div class="label">Total Visits</div><div class="value" data-count="<?= $total_visits; ?>"><?= $total_visits; ?></div></div>
            </div>
            <div class="card">
                <div class="card-icon"><i class="bi bi-door-open"></i></div>
                <div><div class="label">Active Leave Passes</div><div class="value" data-count="<?= count($active_passes); ?>"><?= count($active_passes); ?></div></div>
            </div>
            <div class="card low-stock">
                <div class="card-icon"><i class="bi bi-capsule"></i></div>
                <div><div class="label">Low Stock Drugs</div><div class="value" data-count="<?= $low_stock_count; ?>"><?= $low_stock_count; ?></div></div>
            </div>
            <div class="card outstanding">
                <div class="card-icon"><i class="bi bi-cash-coin"></i></div>
                <div><div class="label">Outstanding Balance</div><div class="value">UGX <span data-count="<?= (int) $outstanding_total; ?>"><?= number_format($outstanding_total, 0); ?></span></div></div>
            </div>
        </div>

        <?php if ($visit_type_total > 0): ?>
        <div class="chart-panel reveal">
            <h3>Visits by Patient Type</h3>
            <div class="donut" style="background:conic-gradient(<?= implode(', ', $visit_type_gradient_stops) ?>);">
                <div class="donut-center">
                    <div class="n"><?= $visit_type_total ?></div>
                    <div class="label">Visits</div>
                </div>
            </div>
            <div class="donut-legend">
                <?php foreach ($visit_type_legend as $entry): ?>
                    <span><span class="dot" style="background:<?= $entry['color'] ?>;"></span><?= htmlspecialchars($entry['label']) ?> <?= $entry['n'] ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($feedback !== ''): ?>
            <div class="feedback <?= $feedback_type; ?>" style="margin-top:20px;"><?= safe_text($feedback); ?></div>
        <?php endif; ?>

        <div class="layout">
            <div>
                <div class="panel">
                    <h3>Log Patient Visit</h3>
                    <form method="POST">
                        <input type="hidden" name="log_visit" value="1">
                        <input type="hidden" name="patient_source" id="patient_source" value="student">
                        <input type="hidden" name="patient_ref" id="patient_ref" value="">

                        <div class="patient-toggle">
                            <button type="button" class="active" id="tabStudent" onclick="showTab('student')">Student</button>
                            <button type="button" id="tabExisting" onclick="showTab('existing')">Staff / Walk-in (existing)</button>
                            <button type="button" id="tabNew" onclick="showTab('new')">New Walk-in / Staff</button>
                        </div>

                        <div id="paneStudent">
                            <label>Student</label>
                            <select onchange="document.getElementById('patient_ref').value=this.value">
                                <option value="">-- Select student --</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?= (int) $s['id']; ?>"><?= safe_text($s['full_name']); ?> — <?= safe_text($s['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="paneExisting" style="display:none;">
                            <label>Existing Staff / Walk-in Patient</label>
                            <select onchange="document.getElementById('patient_ref').value=this.value">
                                <option value="">-- Select patient --</option>
                                <?php foreach ($existing_patients as $p): ?>
                                    <option value="<?= (int) $p['id']; ?>"><?= safe_text($p['full_name']); ?> (<?= ucfirst($p['patient_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="paneNew" style="display:none;">
                            <label>Full Name</label>
                            <input type="text" name="new_patient_name" placeholder="e.g. Mr. Okello (Security Guard)">
                            <label>Type</label>
                            <select name="new_patient_type">
                                <option value="staff">Staff</option>
                                <option value="walkin">Walk-in / Community</option>
                            </select>
                            <label>Gender</label>
                            <select name="new_patient_gender">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <label>Phone (optional)</label>
                            <input type="text" name="new_patient_phone">
                        </div>

                        <label>Medical Flags / Allergies</label>
                        <input type="text" name="medical_flags" placeholder="Asthmatic, allergies...">

                        <label>Symptoms</label>
                        <textarea name="symptoms" rows="2" required></textarea>

                        <label>Diagnosed Disease</label>
                        <input type="text" name="disease" required placeholder="e.g. Malaria">

                        <label>Treatment Note (optional)</label>
                        <input type="text" name="medicine_given" placeholder="e.g. Rested, observed for 1 hour">

                        <div style="margin-top:14px; padding:12px; background:#f8fafc; border-radius:12px;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <strong style="font-size:13px; color:var(--primary);">Prescribe & Dispense</strong>
                                <button type="button" class="btn small secondary" onclick="addRxRow()">+ Add Drug</button>
                            </div>
                            <div id="rxRows"></div>
                            <p class="muted" style="font-size:11px;">Quantity dispensed is capped to what's in stock.</p>
                        </div>

                        <div style="margin-top:14px;">
                            <label style="display:flex; align-items:center; gap:8px; text-transform:none; font-weight:600;">
                                <input type="checkbox" id="doseTracking" name="requires_dose_tracking" value="1" style="width:auto;" onchange="document.getElementById('doseWrap').style.display=this.checked?'block':'none'">
                                Dose Tracking
                            </label>
                            <div id="doseWrap" style="display:none;">
                                <select name="next_dose_hours">
                                    <option value="4">In 4 Hours</option>
                                    <option value="6">In 6 Hours</option>
                                    <option value="8" selected>In 8 Hours</option>
                                    <option value="12">In 12 Hours</option>
                                    <option value="24">In 24 Hours</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:10px;">
                            <label style="display:flex; align-items:center; gap:8px; text-transform:none; font-weight:600;">
                                <input type="checkbox" id="issuePass" name="issue_pass" value="1" style="width:auto;" onchange="document.getElementById('passWrap').style.display=this.checked?'block':'none'">
                                Issue Leave Pass
                            </label>
                            <div id="passWrap" style="display:none;">
                                <select name="pass_destination">
                                    <option value="Dormitory">Dormitory (Bed Rest)</option>
                                    <option value="Home">Home</option>
                                    <option value="Classroom">Classroom</option>
                                </select>
                                <select name="pass_duration" style="margin-top:8px;">
                                    <option value="2">For 2 Hours</option>
                                    <option value="8">For 8 Hours</option>
                                    <option value="48">For 48 Hours</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-block">Save Visit</button>
                    </form>
                </div>
            </div>

            <div>
                <div class="panel">
                    <h3>Active Leave Passes</h3>
                    <table>
                        <thead><tr><th>Patient</th><th>Destination</th><th>Valid Until</th><th></th></tr></thead>
                        <tbody>
                            <?php if (empty($active_passes)): ?>
                                <tr><td colspan="4" class="muted">No active leave passes.</td></tr>
                            <?php else: foreach ($active_passes as $p): ?>
                                <tr>
                                    <td><?= safe_text($p['patient_name']); ?></td>
                                    <td><span class="pill"><?= safe_text($p['destination']); ?></span></td>
                                    <td><?= date('h:i A', strtotime($p['valid_until'])); ?></td>
                                    <td><a class="btn small secondary" href="nurse_dashboard.php?terminate_pass_id=<?= (int) $p['id']; ?>">Clear</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h3>Dose Tracking</h3>
                    <table>
                        <thead><tr><th>Patient</th><th>Condition</th><th>Next Dose</th><th></th></tr></thead>
                        <tbody>
                            <?php if (empty($dose_tracking)): ?>
                                <tr><td colspan="4" class="muted">Nothing in progress.</td></tr>
                            <?php else: foreach ($dose_tracking as $d):
                                $overdue = strtotime($d['next_dose_time']) < time(); ?>
                                <tr>
                                    <td><?= safe_text($d['patient_name']); ?></td>
                                    <td><?= safe_text($d['disease']); ?></td>
                                    <td><span class="pill <?= $overdue ? 'danger' : 'warn'; ?>"><?= date('h:i A (d M)', strtotime($d['next_dose_time'])); ?></span></td>
                                    <td><a class="btn small secondary" href="nurse_dashboard.php?complete_visit_id=<?= (int) $d['id']; ?>">Complete</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h3>Recent Visits</h3>
                    <table>
                        <thead><tr><th>Patient</th><th>Type</th><th>Disease</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php if (empty($recent_visits)): ?>
                                <tr><td colspan="5" class="muted">No visits recorded yet.</td></tr>
                            <?php else: foreach ($recent_visits as $v): ?>
                                <tr>
                                    <td><?= safe_text($v['patient_name']); ?></td>
                                    <td class="muted" style="text-transform:capitalize;"><?= safe_text($v['patient_type']); ?></td>
                                    <td><?= safe_text($v['disease']); ?></td>
                                    <td><span class="pill <?= $v['dose_status'] === 'Completed' ? '' : 'warn'; ?>"><?= safe_text($v['dose_status']); ?></span></td>
                                    <td class="muted"><?= date('d M Y', strtotime($v['visit_date'])); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h3>Recent Invoices</h3>
                    <table>
                        <thead><tr><th>Patient</th><th>Total</th><th>Paid</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr><td colspan="4" class="muted">No invoices yet.</td></tr>
                            <?php else: foreach ($invoices as $inv): ?>
                                <tr>
                                    <td><?= safe_text($inv['patient_name']); ?></td>
                                    <td>UGX <?= number_format((float) $inv['total_amount'], 0); ?></td>
                                    <td>UGX <?= number_format((float) $inv['amount_paid'], 0); ?></td>
                                    <td><span class="pill <?= $inv['status'] === 'paid' ? '' : ($inv['status'] === 'unpaid' ? 'danger' : 'warn'); ?>"><?= safe_text($inv['status']); ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <p class="muted" style="font-size:12px; margin-top:10px;">Manage payment status for all schools using iClinic's <a href="../iClinic/billing.php">Billing</a> page.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const availableDrugs = <?= json_encode(array_map(fn($d) => ['id' => (int) $d['id'], 'name' => $d['drug_name'], 'stock' => (int) $d['available_stock'], 'price' => (float) $d['unit_price']], $drugs)); ?>;

        function showTab(tab) {
            document.getElementById('paneStudent').style.display = tab === 'student' ? 'block' : 'none';
            document.getElementById('paneExisting').style.display = tab === 'existing' ? 'block' : 'none';
            document.getElementById('paneNew').style.display = tab === 'new' ? 'block' : 'none';
            document.getElementById('tabStudent').classList.toggle('active', tab === 'student');
            document.getElementById('tabExisting').classList.toggle('active', tab === 'existing');
            document.getElementById('tabNew').classList.toggle('active', tab === 'new');
            document.getElementById('patient_source').value = tab === 'student' ? 'student' : (tab === 'existing' ? 'patient' : 'new');
            document.getElementById('patient_ref').value = '';
        }

        function addRxRow() {
            const wrap = document.createElement('div');
            wrap.className = 'rx-row';
            let options = '<option value="">Select drug...</option>';
            availableDrugs.forEach(d => {
                options += `<option value="${d.id}" ${d.stock <= 0 ? 'disabled' : ''}>${d.name} (Stock: ${d.stock}, UGX ${d.price})</option>`;
            });
            wrap.innerHTML = `
                <select name="rx_inventory_id[]">${options}</select>
                <div class="rx-grid" style="margin-top:8px;">
                    <input type="text" name="rx_dosage[]" placeholder="Dosage">
                    <input type="text" name="rx_frequency[]" placeholder="Frequency">
                    <input type="number" name="rx_quantity[]" placeholder="Qty" min="1" value="1">
                </div>
                <div class="rx-grid" style="margin-top:8px; grid-template-columns: 1fr auto;">
                    <input type="text" name="rx_duration[]" placeholder="Duration e.g. 5 days">
                    <button type="button" class="btn small secondary" onclick="this.closest('.rx-row').remove()">Remove</button>
                </div>
            `;
            document.getElementById('rxRows').appendChild(wrap);
        }

        // If the New Patient tab is used, treat filling the name field as selecting it.
        document.querySelector('[name="new_patient_name"]').addEventListener('input', function () {
            if (document.getElementById('patient_source').value === 'new') {
                document.getElementById('patient_ref').value = this.value;
            }
        });
    </script>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>
