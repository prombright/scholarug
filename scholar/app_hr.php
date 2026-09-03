<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — HR SPA ENTRY POINT
|--------------------------------------------------------------------------
| Same pattern as app_admin.php / app_teacher.php / app_student.php.
| Reachable by both 'hr' and 'school_admin' (same dual-role access as the
| classic hr_dashboard.php/staff_manager.php/hr/*.php pages).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_role(['school_admin', 'hr']);

$indexPath = __DIR__ . '/assets/spa-hr/index.html';
if (!file_exists($indexPath)) {
    http_response_code(503);
    echo 'The HR portal has not been built yet. Run `npm run build` in scholar/spa-hr/.';
    exit;
}

$scholarBase = rtrim(SCHOLAR_BASE, '/') . '/';
$inject = '<base href="assets/spa-hr/">'
    . '<script>'
    . 'window.__SCHOLAR_BASE__ = ' . json_encode($scholarBase) . ';'
    . 'window.__SCHOLAR_API_BASE__ = ' . json_encode($scholarBase . 'api/') . ';'
    // Same "scholar-theme" localStorage key preloader.php's site-wide
    // toggle uses, applied before Vue mounts (and before the bundle's own
    // stylesheet is even requested) so there's no flash of the wrong theme.
    . 'if (localStorage.getItem("scholar-theme") === "light") { document.documentElement.setAttribute("data-theme", "light"); }'
    . '</script>';

$html = file_get_contents($indexPath);
$html = str_replace('<head>', '<head>' . $inject, $html);
echo $html;
