<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER SPA ENTRY POINT (pilot)
|--------------------------------------------------------------------------
| Serves the built Vue app (scholar/assets/spa-teacher/, built locally via
| `npm run build` in scholar/spa/ -- the host has no shell access to run
| that itself, see the eSpace-comparison discussion this came out of).
|
| This file's only job is the same auth gate every other teacher page has;
| everything after that is the SPA's own router. The SPA's actual data
| calls go through api/teacher/analytics.php, which re-checks auth itself
| on every request -- this gate is what stops an unauthenticated visitor
| from loading the empty app shell in the first place.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_role(['teacher']);

$indexPath = __DIR__ . '/assets/spa-teacher/index.html';
if (!file_exists($indexPath)) {
    http_response_code(503);
    echo 'The Performance Analytics pilot has not been built yet. Run `npm run build` in scholar/spa/.';
    exit;
}

// The build's asset URLs are relative ("./assets/index-xxx.js"), resolved
// by the browser against the page's OWN url -- but that page is served
// from here (scholar/app_teacher.php), not from scholar/assets/spa-teacher/
// where the files actually live. A <base href> tag redirects everything
// relative on the page (JS/CSS included) to resolve against that folder
// instead. That also redirects the app's own API calls, though -- so the
// app is handed its API root explicitly via SCHOLAR_BASE (the same
// portable-path constant every PHP page already uses) rather than relying
// on relative resolution for those.
$scholarBase = rtrim(SCHOLAR_BASE, '/') . '/';
$inject = '<base href="assets/spa-teacher/">'
    . '<script>'
    . 'window.__SCHOLAR_BASE__ = ' . json_encode($scholarBase) . ';'
    . 'window.__SCHOLAR_API_BASE__ = ' . json_encode($scholarBase . 'api/') . ';'
    . '</script>';

$html = file_get_contents($indexPath);
$html = str_replace('<head>', '<head>' . $inject, $html);
echo $html;
