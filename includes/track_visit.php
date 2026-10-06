<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MARKETING SITE — PAGE VIEW TRACKING
|--------------------------------------------------------------------------
| The marketing site (this root-level site) has no database connection
| of its own at all -- unlike the Scholar app, nothing here ever needed
| one before. Deliberately does NOT reuse scholar/db.php, which dies the
| whole page on a connection failure -- exactly the wrong behavior for a
| public marketing page, where a DB hiccup should never be visible to a
| visitor. Opens its own short-lived, best-effort connection instead,
| reusing scholar/config.php's env-var-driven DB_* constants so there's
| still only one place credentials are configured.
|
| Writes into the same `scholar` database's page_views table the app
| itself logs into (system='site' here vs 'scholar' there) -- see
| scholar/_visit_tracking.php and scholar/_setup/page_views_migration.sql.
|--------------------------------------------------------------------------
*/

try {
    require_once __DIR__ . '/../scholar/config.php';
    require_once __DIR__ . '/../scholar/_visit_tracking.php';

    $__trackPdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
    );
    scholar_track_page_view($__trackPdo, 'site');
    unset($__trackPdo);
} catch (\Throwable $e) {
    // Analytics is never allowed to break the page.
}
