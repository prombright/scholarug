<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR: PAYROLL (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/payroll.php, built on hr/_payroll_helpers.php. The
| single-payslip print view (?print=<id>) stays classic-only, linked
| directly by URL from the Vue page.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/_payroll_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$recorded_by = (int) ($_SESSION['user_id'] ?? 0);
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $result = hr_payroll_record(
        $pdo, $school_id, $recorded_by,
        (int) ($body['staff_id'] ?? 0),
        (int) ($body['pay_period_month'] ?? 0),
        (int) ($body['pay_period_year'] ?? 0),
        (float) ($body['amount'] ?? 0),
        (string) ($body['payment_date'] ?? ''),
        trim((string) ($body['notes'] ?? ''))
    );
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }
    $message = $result['message'];
}

echo json_encode([
    'success' => true,
    'staff' => hr_payroll_staff_list($pdo, $school_id),
    'history' => hr_payroll_history($pdo, $school_id),
    'months' => HR_PAYROLL_MONTHS,
    'message' => $message,
], JSON_UNESCAPED_SLASHES);
