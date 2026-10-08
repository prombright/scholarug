<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — HR SPA ENTRY POINT (superseded)
|--------------------------------------------------------------------------
| HR used to be its own separate built Vue app (scholar/spa-hr), reached
| by a full browser page reload from the admin dashboard's "Human
| Resources" link -- the entire reason this file existed was to serve
| that separate bundle. Its pages (Dashboard/Leave/Payroll/SMS) now live
| as real routes inside the admin SPA itself (see spa-admin's
| src/pages/hr/, router/index.js, and AdminLayout.vue's HR_GROUP), so
| clicking into HR is instant in-app navigation like everything else,
| not a reload into a second app.
|
| Kept only as a redirect so old bookmarks/links still land somewhere
| useful -- same pattern as hr_dashboard.php's own redirect to this file
| (which now just forwards again). The #/hr hash lands directly on the
| HR dashboard inside the admin SPA (Vue Router's hash mode, same as
| every other deep link in this app).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_role(['school_admin', 'hr']);

header("Location: " . SCHOLAR_BASE . "/app_admin.php#/hr");
exit;
