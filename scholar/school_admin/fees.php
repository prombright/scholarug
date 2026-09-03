<?php
// fees.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Robust File Resolution for Config & DB
$base_dir = __DIR__;
if (file_exists($base_dir . '/config.php')) {
    require_once $base_dir . '/config.php';
} elseif (file_exists($base_dir . '/../config.php')) {
    require_once $base_dir . '/../config.php';
} elseif (file_exists($base_dir . '/../../config.php')) {
    require_once $base_dir . '/../../config.php';
}

if (file_exists($base_dir . '/db.php')) {
    require_once $base_dir . '/db.php';
} elseif (file_exists($base_dir . '/../db.php')) {
    require_once $base_dir . '/../db.php';
} elseif (file_exists($base_dir . '/../../db.php')) {
    require_once $base_dir . '/../../db.php';
}

if (file_exists($base_dir . '/auth_guard.php')) {
    require_once $base_dir . '/auth_guard.php';
} elseif (file_exists($base_dir . '/../auth_guard.php')) {
    require_once $base_dir . '/../auth_guard.php';
} elseif (file_exists($base_dir . '/../../auth_guard.php')) {
    require_once $base_dir . '/../../auth_guard.php';
}

// This page reads/writes fee payments and bursary discounts for the whole
// school -- it had no role check at all, meaning any logged-in user of any
// role (teacher, student, parent, nurse) could open it directly by URL.
require_role(['school_admin', 'bursar']);

$ACTIVE_NAV = 'fees';

if (file_exists($base_dir . '/_admin_shell.php')) {
    require_once $base_dir . '/_admin_shell.php';
} elseif (file_exists($base_dir . '/../_admin_shell.php')) {
    require_once $base_dir . '/../_admin_shell.php';
}

if (!function_exists('safe_text')) {
    function safe_text($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id) {
    header("Location: ../login.php");
    exit();
}

// ==========================================
// AUTO SCHEMA CHECK (Ensures tables exist)
// ==========================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fee_structures` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `school_id` INT NOT NULL,
          `class_id` INT NOT NULL,
          `day_tuition` DECIMAL(12,2) DEFAULT 0.00,
          `boarding_tuition` DECIMAL(12,2) DEFAULT 0.00,
          `entry_fee` DECIMAL(12,2) DEFAULT 0.00,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `school_class_unique` (`school_id`, `class_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `fee_payments` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `school_id` INT NOT NULL,
          `student_id` INT NOT NULL,
          `amount_paid` DECIMAL(12,2) DEFAULT 0.00,
          `bursary_discount` DECIMAL(12,2) DEFAULT 0.00,
          `residence_type` ENUM('Day', 'Boarding') DEFAULT 'Day',
          `is_new_student` TINYINT(1) DEFAULT 0,
          `notes` VARCHAR(255) DEFAULT NULL,
          `paid_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    // Schema exists
}

$message = '';
$error = '';

// ==========================================
// 1. POST HANDLERS (Fee Structure & Payments)
// ==========================================

// A. Save / Update Fee Structure for a Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_fee_structure') {
    $class_id       = intval($_POST['class_id'] ?? 0);
    $day_tuition    = floatval($_POST['day_tuition'] ?? 0);
    $board_tuition  = floatval($_POST['boarding_tuition'] ?? 0);
    $entry_fee      = floatval($_POST['entry_fee'] ?? 0);

    if ($class_id <= 0) {
        $error = "Please select a valid class to configure fees.";
    } elseif ($day_tuition < 0 || $board_tuition < 0 || $entry_fee < 0) {
        // The form's number inputs already have min="0", but that's
        // browser-side only -- a direct POST could otherwise record a
        // negative fee amount with nothing stopping it here.
        $error = "Fee amounts cannot be negative.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO fee_structures (school_id, class_id, day_tuition, boarding_tuition, entry_fee)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                day_tuition = VALUES(day_tuition),
                boarding_tuition = VALUES(boarding_tuition),
                entry_fee = VALUES(entry_fee)
        ");
        if ($stmt->execute([$school_id, $class_id, $day_tuition, $board_tuition, $entry_fee])) {
            $message = "Fee structure updated successfully!";
        } else {
            $error = "Failed to update fee structure.";
        }
    }
}

// B. Record Student Payment / Bursary Discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $student_id     = intval($_POST['student_id'] ?? 0);
    $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
    $bursary_amount = floatval($_POST['bursary_amount'] ?? 0);
    $residence_type = trim($_POST['residence_type'] ?? 'Day');
    $is_new_student = isset($_POST['is_new_student']) ? 1 : 0;
    $payment_notes  = trim($_POST['payment_notes'] ?? '');

    if ($student_id <= 0) {
        $error = "Invalid student selected.";
    } elseif ($amount_paid < 0 || $bursary_amount < 0) {
        $error = "Payment and bursary amounts cannot be negative.";
    } else {
        $pay_stmt = $pdo->prepare("
            INSERT INTO fee_payments (school_id, student_id, amount_paid, bursary_discount, residence_type, is_new_student, notes, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if ($pay_stmt->execute([$school_id, $student_id, $amount_paid, $bursary_amount, $residence_type, $is_new_student, $payment_notes])) {
            $message = "Payment record saved successfully!";
        } else {
            $error = "Error recording payment transaction.";
        }
    }
}

// ==========================================
// 2. QUERY DATA FOR REPORTING & INTERFACE
// ==========================================

// Fetch Class Fee Structures
$classes_fees_stmt = $pdo->prepare("
    SELECT c.id AS class_id, c.class_name, 
           COALESCE(fs.day_tuition, 0) AS day_tuition,
           COALESCE(fs.boarding_tuition, 0) AS boarding_tuition,
           COALESCE(fs.entry_fee, 0) AS entry_fee
    FROM classes c
    LEFT JOIN fee_structures fs ON c.id = fs.class_id AND fs.school_id = c.school_id
    WHERE c.school_id = ?
    ORDER BY c.class_name ASC
");
$classes_fees_stmt->execute([$school_id]);
$fee_structures = $classes_fees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Search and Filter Ledger Query
$search_query = trim($_GET['q'] ?? '');
$filter_class = trim($_GET['class_id'] ?? '');

$ledger_sql = "
    SELECT 
        s.id AS student_id, 
        s.full_name, 
        s.class_id, 
        c.class_name,
        COALESCE(fs.day_tuition, 0) AS base_day,
        COALESCE(fs.boarding_tuition, 0) AS base_boarding,
        COALESCE(fs.entry_fee, 0) AS base_entry,
        COALESCE(SUM(fp.amount_paid), 0) AS total_paid,
        COALESCE(SUM(fp.bursary_discount), 0) AS total_bursary,
        MAX(fp.residence_type) AS active_residence,
        MAX(fp.is_new_student) AS is_new
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN fee_structures fs ON s.class_id = fs.class_id AND fs.school_id = s.school_id
    LEFT JOIN fee_payments fp ON s.id = fp.student_id AND fp.school_id = s.school_id
    WHERE s.school_id = ?
";

$params = [$school_id];

if (!empty($search_query)) {
    $ledger_sql .= " AND s.full_name LIKE ?";
    $params[] = '%' . $search_query . '%';
}

if (!empty($filter_class)) {
    $ledger_sql .= " AND s.class_id = ?";
    $params[] = $filter_class;
}

$ledger_sql .= " GROUP BY s.id ORDER BY s.full_name ASC";

$ledger_stmt = $pdo->prepare($ledger_sql);
$ledger_stmt->execute($params);
$student_ledger = $ledger_stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics Calculation
$total_students = count($student_ledger);
$fully_paid_count = 0;
$partial_paid_count = 0;
$unpaid_count = 0;
$total_collected = 0;

foreach ($student_ledger as $row) {
    $is_boarder = ($row['active_residence'] === 'Boarding');
    $tuition = $is_boarder ? $row['base_boarding'] : $row['base_day'];
    $entry = ($row['is_new'] == 1) ? $row['base_entry'] : 0;
    
    $gross_due = $tuition + $entry;
    $net_due = max(0, $gross_due - $row['total_bursary']);
    $balance = $net_due - $row['total_paid'];

    $total_collected += $row['total_paid'];

    if ($row['total_paid'] >= $net_due && $net_due > 0) {
        $fully_paid_count++;
    } elseif ($row['total_paid'] > 0 && $balance > 0) {
        $partial_paid_count++;
    } else {
        $unpaid_count++;
    }
}
?>
    <main class="main-content">
    <div class="page-inner">

<!-- Inject Assets in case shell didn't load them -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<style>
    :root {
        --abn-navy: #0f172a;
        --abn-blue: #0284c7;
        --abn-emerald: #059669;
        --abn-amber: #d97706;
        --abn-rose: #e11d48;
    }
    body {
        background-color: #f8fafc;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }
    .abn-header {
        background: linear-gradient(135deg, var(--abn-navy) 0%, #1e293b 100%);
        color: #ffffff;
        border-radius: 10px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .badge-abn-full { background-color: var(--abn-emerald); color: #fff; padding: 6px 12px; border-radius: 20px; font-weight: 500;}
    .badge-abn-partial { background-color: var(--abn-amber); color: #fff; padding: 6px 12px; border-radius: 20px; font-weight: 500;}
    .badge-abn-unpaid { background-color: var(--abn-rose); color: #fff; padding: 6px 12px; border-radius: 20px; font-weight: 500;}
    .btn-abn-primary {
        background-color: var(--abn-blue);
        color: #ffffff;
        border: none;
        font-weight: 500;
    }
    .btn-abn-primary:hover {
        background-color: #0369a1;
        color: #ffffff;
    }
    .dev-grayed-out {
        background-color: #f1f5f9;
        border: 2px dashed #94a3b8;
        border-radius: 10px;
    }
    .stat-card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
    }
</style>

<div class="container-fluid py-4 px-4">
    <!-- Header Banner -->
    <div class="p-4 mb-4 abn-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <h2 class="mb-1 text-white fw-bold"><i class="bi bi-wallet2 me-2"></i>Bursar & Financial Portal</h2>
            <p class="mb-0 text-light opacity-75">ScholarUg &bull; School Fee Ledger & Collections</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-light fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#configFeesModal">
                <i class="bi bi-gear-fill me-1"></i> Fee Structures
            </button>
            <button class="btn btn-abn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                <i class="bi bi-plus-circle-fill me-1"></i> Record Payment
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= safe_text($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= safe_text($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Statistics Dashboard -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card border-start border-4 border-success p-3">
                <div class="text-muted small fw-bold text-uppercase">Total Revenue Collected</div>
                <h3 class="fw-bold mb-0 text-success mt-1">UGX <?= number_format($total_collected); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-4 border-primary p-3">
                <div class="text-muted small fw-bold text-uppercase">Fully Paid Students</div>
                <h3 class="fw-bold mb-0 text-dark mt-1"><?= number_format($fully_paid_count); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-4 border-warning p-3">
                <div class="text-muted small fw-bold text-uppercase">Partial Payments</div>
                <h3 class="fw-bold mb-0 text-dark mt-1"><?= number_format($partial_paid_count); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-start border-4 border-danger p-3">
                <div class="text-muted small fw-bold text-uppercase">Unpaid / Balances Due</div>
                <h3 class="fw-bold mb-0 text-dark mt-1"><?= number_format($unpaid_count); ?></h3>
            </div>
        </div>
    </div>

    <!-- UNDER DEVELOPMENT: Advanced Financial Reporting Banner (Grayed Out) -->
    <div class="dev-grayed-out p-3 mb-4 text-center">
        <div class="d-flex align-items-center justify-content-center">
            <i class="bi bi-tools text-secondary fs-5 me-2"></i>
            <div>
                <strong class="text-secondary">Advanced Audit Reports & Export Center</strong> 
                <span class="badge bg-secondary ms-2">Under Development</span>
            </div>
        </div>
        <p class="text-muted small mb-0 mt-1">Automated PDF statements, bank reconciliations, and termly comparison reports will be unlocked in the upcoming release.</p>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" action="fees.php" class="row g-2">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0" placeholder="Search student by name..." value="<?= safe_text($search_query); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="class_id" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($fee_structures as $fs): ?>
                            <option value="<?= $fs['class_id']; ?>" <?= $filter_class == $fs['class_id'] ? 'selected' : ''; ?>>
                                <?= safe_text($fs['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 fw-medium">Filter Ledger</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Student Ledger Table -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-receipt me-2 text-primary"></i>Student Fee Accounts</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Student Name</th>
                            <th>Class</th>
                            <th>Type</th>
                            <th class="text-end">Base Fee</th>
                            <th class="text-end">Bursary</th>
                            <th class="text-end">Paid Amount</th>
                            <th class="text-end">Balance</th>
                            <th class="text-center pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($student_ledger)): ?>
                            <?php foreach ($student_ledger as $s): 
                                $is_boarder = ($s['active_residence'] === 'Boarding');
                                $tuition = $is_boarder ? $s['base_boarding'] : $s['base_day'];
                                $entry = ($s['is_new'] == 1) ? $s['base_entry'] : 0;
                                $gross_due = $tuition + $entry;
                                $net_due = max(0, $gross_due - $s['total_bursary']);
                                $balance = $net_due - $s['total_paid'];
                            ?>
                                <tr>
                                    <td class="ps-3"><strong><?= safe_text($s['full_name']); ?></strong></td>
                                    <td><?= safe_text($s['class_name'] ?? 'Unassigned'); ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= safe_text($s['active_residence'] ?? 'Day'); ?>
                                            <?= ($s['is_new'] == 1) ? ' (Entry)' : ''; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">UGX <?= number_format($gross_due); ?></td>
                                    <td class="text-end text-success">
                                        <?= $s['total_bursary'] > 0 ? '- UGX ' . number_format($s['total_bursary']) : '—'; ?>
                                    </td>
                                    <td class="text-end fw-bold text-primary">UGX <?= number_format($s['total_paid']); ?></td>
                                    <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-muted'; ?>">
                                        UGX <?= number_format(max(0, $balance)); ?>
                                    </td>
                                    <td class="text-center pe-3">
                                        <?php if ($s['total_paid'] >= $net_due && $net_due > 0): ?>
                                            <span class="badge badge-abn-full">Cleared</span>
                                        <?php elseif ($s['total_paid'] > 0): ?>
                                            <span class="badge badge-abn-partial">Partial</span>
                                        <?php else: ?>
                                            <span class="badge badge-abn-unpaid">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No fee accounts found matching parameters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 1: Configure Class Fee Structure -->
<div class="modal fade" id="configFeesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="fees.php">
            <input type="hidden" name="action" value="save_fee_structure">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold">Configure Class Fee Structure</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Select Class</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Choose Class...</option>
                            <?php foreach ($fee_structures as $fs): ?>
                                <option value="<?= $fs['class_id']; ?>"><?= safe_text($fs['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Day Student Tuition (UGX)</label>
                        <input type="number" step="1000" name="day_tuition" class="form-control" placeholder="e.g. 450000" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Boarding Student Tuition (UGX)</label>
                        <input type="number" step="1000" name="boarding_tuition" class="form-control" placeholder="e.g. 850000" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Entry/Beginner Fee (Uniform, Admission, etc.)</label>
                        <input type="number" step="1000" name="entry_fee" class="form-control" placeholder="e.g. 150000" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-abn-primary">Save Structure</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Record Payment / Bursary -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="fees.php">
            <input type="hidden" name="action" value="record_payment">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold">Record Payment / Bursary Discount</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Student</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">Select Student...</option>
                            <?php foreach ($student_ledger as $s): ?>
                                <option value="<?= $s['student_id']; ?>">
                                    <?= safe_text($s['full_name']); ?> (<?= safe_text($s['class_name'] ?? 'N/A'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Residence Status</label>
                            <select name="residence_type" class="form-select">
                                <option value="Day">Day Scholar</option>
                                <option value="Boarding">Boarding</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_new_student" value="1" id="entryFeeCheck">
                                <label class="form-check-label fw-medium" for="entryFeeCheck">
                                    Include Entry Fee
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Amount Paid (UGX)</label>
                        <input type="number" step="500" name="amount_paid" class="form-control" placeholder="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Bursary / Fee Reduction (UGX)</label>
                        <input type="number" step="500" name="bursary_amount" class="form-control" placeholder="0">
                        <div class="form-text">Subtracts directly from total tuition obligation.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Notes / Receipt Reference</label>
                        <input type="text" name="payment_notes" class="form-control" placeholder="e.g. Bank Slip #48201">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>

    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->

<!-- Bootstrap 5 JS Bundle for Modals -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>