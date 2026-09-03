<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: FEES (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/fees.php, built on
| school_admin/_fees_helpers.php -- identical due/balance/status formula,
| same summary metrics.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_fees_helpers.php';
require_role(['school_admin', 'bursar']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
admin_fees_ensure_schema($pdo);

function admin_fees_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function admin_fees_snapshot(PDO $pdo, int $schoolId, string $search, string $classFilter): array
{
    $structures = admin_fees_fetch_structures($pdo, $schoolId);
    $ledger_raw = admin_fees_fetch_ledger($pdo, $schoolId, $search, $classFilter);
    $annotated = admin_fees_annotate_ledger($ledger_raw);

    return [
        'success' => true,
        'fee_structures' => $structures,
        'ledger' => $annotated['ledger'],
        'metrics' => $annotated['metrics'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = trim($_GET['q'] ?? '');
    $classFilter = trim($_GET['class_id'] ?? '');
    echo json_encode(admin_fees_snapshot($pdo, $school_id, $search, $classFilter), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $result = null;

    if ($action === 'save_fee_structure') {
        $result = admin_fees_save_structure(
            $pdo, $school_id,
            (int) ($body['class_id'] ?? 0),
            (float) ($body['day_tuition'] ?? 0),
            (float) ($body['boarding_tuition'] ?? 0),
            (float) ($body['entry_fee'] ?? 0)
        );
    } elseif ($action === 'record_payment') {
        $result = admin_fees_record_payment(
            $pdo, $school_id,
            (int) ($body['student_id'] ?? 0),
            (float) ($body['amount_paid'] ?? 0),
            (float) ($body['bursary_amount'] ?? 0),
            trim((string) ($body['residence_type'] ?? 'Day')),
            (bool) ($body['is_new_student'] ?? false),
            trim((string) ($body['payment_notes'] ?? ''))
        );
    } else {
        admin_fees_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_fees_json_error($result['message']);
    }

    $snapshot = admin_fees_snapshot($pdo, $school_id, '', '');
    $snapshot['message'] = $result['message'];
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_fees_json_error('Method not allowed.', 405);
