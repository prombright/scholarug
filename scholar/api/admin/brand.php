<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — ADMIN SPA: BRAND (JSON)
|--------------------------------------------------------------------------
| Lightweight "school name + badge" fetch for App.vue's boot sequence,
| shared by every role allowed into this SPA (not just school_admin) --
| dos/headteacher/bursar only ever see one scoped page each, never the
| full Dashboard.vue (which calls the heavier, school_admin-only
| dashboard.php endpoint), but the app shell itself still needs a brand
| to render the sidebar with.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin', 'dos', 'headteacher', 'bursar']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$school_stmt = $pdo->prepare('SELECT school_name, school_badge FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];

$badge_url = null;
if (!empty($school_row['school_badge']) && file_exists(__DIR__ . '/../../' . $school_row['school_badge'])) {
    $badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school_row['school_badge'], '/');
}

echo json_encode([
    'success' => true,
    'school_name' => $school_row['school_name'] ?? 'Scholar',
    'badge_url' => $badge_url,
], JSON_UNESCAPED_SLASHES);
