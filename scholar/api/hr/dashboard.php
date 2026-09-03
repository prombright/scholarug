<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR: DASHBOARD (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr_dashboard.php, built on _hr_dashboard_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_hr_dashboard_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$school_stmt = $pdo->prepare('SELECT school_name, school_badge FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];

$badge_url = null;
if (!empty($school_row['school_badge']) && file_exists(__DIR__ . '/../../' . $school_row['school_badge'])) {
    $badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school_row['school_badge'], '/');
}

$stats = hr_dashboard_stats($pdo, $school_id);

echo json_encode([
    'success' => true,
    'school_name' => $school_row['school_name'] ?? 'Scholar',
    'badge_url' => $badge_url,
] + $stats, JSON_UNESCAPED_SLASHES);
