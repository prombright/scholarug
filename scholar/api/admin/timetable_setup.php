<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: TIMETABLE DAY STRUCTURE (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/timetable_setup.php, built on
| school_admin/_timetable_setup_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_timetable_setup_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_timetable_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'day_names' => ADMIN_TIMETABLE_DAY_NAMES,
        'by_day' => admin_timetable_fetch_by_day($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'quick_setup') {
        $result = admin_timetable_quick_setup(
            $pdo, $school_id,
            array_map('intval', $body['days'] ?? []),
            (string) ($body['day_start'] ?? '08:00'),
            (int) ($body['lesson_minutes'] ?? 40),
            (int) ($body['periods_per_day'] ?? 8),
            (int) ($body['break_after'] ?? 0),
            (int) ($body['break_minutes'] ?? 20),
            (int) ($body['lunch_after'] ?? 0),
            (int) ($body['lunch_minutes'] ?? 45)
        );
    } elseif ($action === 'update_period') {
        $result = admin_timetable_update_period(
            $pdo, $school_id, (int) ($body['period_id'] ?? 0),
            trim((string) ($body['label'] ?? '')), (string) ($body['start_time'] ?? ''), (string) ($body['end_time'] ?? ''),
            (bool) ($body['is_teaching_period'] ?? false)
        );
    } elseif ($action === 'delete_period') {
        admin_timetable_delete_period($pdo, $school_id, (int) ($body['period_id'] ?? 0));
        $result = ['ok' => true, 'message' => 'Period removed.'];
    } else {
        admin_timetable_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_timetable_json_error($result['message']);
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'by_day' => admin_timetable_fetch_by_day($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_timetable_json_error('Method not allowed.', 405);
