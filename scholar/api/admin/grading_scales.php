<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: GRADING SCALE SETTINGS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/grading_scales.php, built on
| school_admin/_grading_scales_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_grading_scales_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_grading_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function admin_grading_snapshot(PDO $pdo, int $schoolId): array
{
    return [
        'success' => true,
        'bands' => [
            'O-Level' => admin_grading_fetch_bands($pdo, $schoolId, 'O-Level'),
            'A-Level' => admin_grading_fetch_bands($pdo, $schoolId, 'A-Level'),
        ],
        'skills' => admin_grading_fetch_skills($pdo, $schoolId),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(admin_grading_snapshot($pdo, $school_id), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $result = ['ok' => true, 'message' => ''];

    switch ($action) {
        case 'create_band':
            $result = admin_grading_create_band($pdo, $school_id, $body);
            break;
        case 'update_band':
            $result = admin_grading_update_band($pdo, $school_id, (int) ($body['id'] ?? 0), $body);
            break;
        case 'delete_band':
            admin_grading_delete_band($pdo, $school_id, (int) ($body['id'] ?? 0));
            $result = ['ok' => true, 'message' => 'Grading band deleted.'];
            break;
        case 'seed_competency_defaults':
            admin_grading_seed_competency_defaults($pdo, $school_id);
            $result = ['ok' => true, 'message' => 'O-Level scale reset to the competency-based default bands. Review the labels and cutoffs below.'];
            break;
        case 'seed_uace_defaults':
            admin_grading_seed_uace_defaults($pdo, $school_id);
            $result = ['ok' => true, 'message' => 'A-Level scale reset to the UACE standard bands. Review the labels and cutoffs below.'];
            break;
        case 'create_skill':
            $result = admin_grading_create_skill($pdo, $school_id, trim((string) ($body['skill_name'] ?? '')));
            break;
        case 'toggle_skill':
            admin_grading_toggle_skill($pdo, $school_id, (int) ($body['id'] ?? 0));
            $result = ['ok' => true, 'message' => 'Skill visibility updated.'];
            break;
        case 'delete_skill':
            admin_grading_delete_skill($pdo, $school_id, (int) ($body['id'] ?? 0));
            $result = ['ok' => true, 'message' => 'Skill deleted (past ratings for it are removed too).'];
            break;
        case 'seed_default_skills':
            admin_grading_seed_default_skills($pdo, $school_id);
            $result = ['ok' => true, 'message' => 'Default skills seeded (existing ones left untouched).'];
            break;
        default:
            admin_grading_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_grading_json_error($result['message']);
    }

    $snapshot = admin_grading_snapshot($pdo, $school_id);
    $snapshot['message'] = $result['message'];
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_grading_json_error('Method not allowed.', 405);
