<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: REPORT SETTINGS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/report_settings.php, built on
| school_admin/_report_settings_helpers.php and
| scholar_fetch_report_settings() from _report_card_render.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_report_card_render.php';
require_once __DIR__ . '/../../school_admin/_report_settings_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $no_data_color = admin_report_settings_clean_color(!empty($body['use_no_data_color']), (string) ($body['no_data_color'] ?? ''));
    admin_report_settings_save(
        $pdo, $school_id,
        !empty($body['show_student_photos']),
        !empty($body['allow_student_download']),
        $no_data_color
    );
}

echo json_encode([
    'success' => true,
    'settings' => scholar_fetch_report_settings($pdo, $school_id),
], JSON_UNESCAPED_SLASHES);
