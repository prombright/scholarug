<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT SPA ENTRY POINT
|--------------------------------------------------------------------------
| Same pattern as app_teacher.php -- serves the built Vue app
| (scholar/assets/spa-student/, built locally via `npm run build` in
| scholar/spa-student/, since the production host has no shell access).
| This file's only job is the auth gate; the SPA's own data calls go
| through api/student/*.php, which re-check auth on every request.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_role(['student']);

$indexPath = __DIR__ . '/assets/spa-student/index.html';
if (!file_exists($indexPath)) {
    http_response_code(503);
    echo 'The student portal pilot has not been built yet. Run `npm run build` in scholar/spa-student/.';
    exit;
}

// See app_teacher.php for the full reasoning on both of these.
$scholarBase = rtrim(SCHOLAR_BASE, '/') . '/';
$inject = '<base href="assets/spa-student/">'
    . '<script>'
    . 'window.__SCHOLAR_BASE__ = ' . json_encode($scholarBase) . ';'
    . 'window.__SCHOLAR_API_BASE__ = ' . json_encode($scholarBase . 'api/') . ';'
    . '</script>';

$html = file_get_contents($indexPath);
$html = str_replace('<head>', '<head>' . $inject, $html);
echo $html;
