<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — CENTRAL CONFIG
|--------------------------------------------------------------------------
| Single source of truth for anything environment-specific: the app's
| base URL path and database credentials. Previously these lived in two
| places (auth_guard.php AND _admin_shell.php both hardcoded SCHOLAR_BASE
| independently, and db.php hardcoded DB creds inline) — meaning a change
| on deploy had to be made in multiple files and was easy to miss.
|
| Three school_admin/*.php files (manage_teachers.php, students.php,
| fees.php) already expected a config.php to exist at the scholar root —
| it never did, which made manage_teachers.php fatal-error on every visit.
| This file is that missing piece.
|--------------------------------------------------------------------------
*/

// Local/host override -- gitignored, never committed, same convention as
// mail/config.local.php. Expected to `return [...]` an assoc array of any
// of the SCHOLAR_* names below. Exists because Apache's SetEnv, on some
// hosts (observed on a CloudLinux/PHP-FPM shared-hosting account),
// intermittently fails to reach getenv() -- some requests see it, some
// don't, with no code-level cause -- which real env vars can't work
// around from inside PHP. A plain `require` has no such failure mode.
$__scholarLocalFile = __DIR__ . '/config.local.php';
$__scholarLocal = [];
if (file_exists($__scholarLocalFile)) {
    $__scholarLocal = require $__scholarLocalFile;
    if (!is_array($__scholarLocal)) {
        $__scholarLocal = [];
    }
}

function scholar_env(array $local, string $name, $default)
{
    if (array_key_exists($name, $local)) {
        return $local[$name];
    }
    $value = getenv($name);
    return $value !== false ? $value : $default;
}

if (!defined('SCHOLAR_BASE')) {
    // Override with SCHOLAR_BASE_PATH on deploy if the app is served from
    // a different sub-path than /ABNsystems/scholar -- including an empty
    // string for a deployment at its domain's root, which `?:` can't
    // express (PHP treats "" as falsy, so it would silently fall through
    // to the /ABNsystems/scholar default instead of staying empty).
    define('SCHOLAR_BASE', scholar_env($__scholarLocal, 'SCHOLAR_BASE_PATH', '/ABNsystems/scholar'));
}

/*
|--------------------------------------------------------------------------
| DATABASE CREDENTIALS
|--------------------------------------------------------------------------
| Falls back to the local XAMPP/WAMP defaults so nothing breaks on a dev
| machine, but on a real host set these as actual environment variables
| (Apache: SetEnv in vhost / .htaccess with php_value, or your host's
| control panel) -- or, if that proves unreliable on a given host, via
| config.local.php -- instead of committing real credentials to the repo.
|--------------------------------------------------------------------------
*/

define('DB_HOST', scholar_env($__scholarLocal, 'SCHOLAR_DB_HOST', 'localhost'));
define('DB_NAME', scholar_env($__scholarLocal, 'SCHOLAR_DB_NAME', 'scholar'));
define('DB_USER', scholar_env($__scholarLocal, 'SCHOLAR_DB_USER', 'root'));
define('DB_PASS', scholar_env($__scholarLocal, 'SCHOLAR_DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| APP ENVIRONMENT
|--------------------------------------------------------------------------
| Controls whether PHP errors are shown on-screen (never do that in
| production) — set SCHOLAR_ENV=production as a real env var (or
| config.local.php entry) on deploy.
|--------------------------------------------------------------------------
*/

if (!defined('SCHOLAR_ENV')) {
    define('SCHOLAR_ENV', scholar_env($__scholarLocal, 'SCHOLAR_ENV', 'development'));
}

unset($__scholarLocalFile, $__scholarLocal);

if (SCHOLAR_ENV === 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/*
|--------------------------------------------------------------------------
| ABN BULK SMS — SERVICE CREDENTIALS
|--------------------------------------------------------------------------
| Lets Scholar send parent SMS through the Bulk SMS platform's service API
| (bulksms/api/send.php) instead of its own SMS integration — see
| school_admin/message_parents.php. The API key is provisioned once from
| the Bulk SMS admin panel (Accounts -> generate API key for the "Scholar"
| service account) and set here as a real environment variable; there is
| no local fallback because, unlike DB_PASS on a dev machine, an empty
| key should fail loudly (a caught RuntimeException, not a silent no-op)
| rather than pretend to send.
|--------------------------------------------------------------------------
*/

if (!defined('BULKSMS_API_URL')) {
    define('BULKSMS_API_URL', getenv('SCHOLAR_BULKSMS_API_URL') ?: 'http://localhost/ABNsystems/bulksms/api');
}
if (!defined('BULKSMS_API_KEY')) {
    define('BULKSMS_API_KEY', getenv('SCHOLAR_BULKSMS_API_KEY') ?: '');
}

// One shared SMTP account for the whole platform -- see mail/config.php.
// Used for password-reset emails.
require_once __DIR__ . '/../mail/config.php';

// Used only to build a clickable link in the "new message" notification
// email sent to the developer (school_admin/contact_developer.php) --
// devportal itself derives its own equivalent URLs the same way (see
// devportal/config.php's DEVPORTAL_SCHOLAR_URL block).
if (!defined('DEVPORTAL_PUBLIC_URL')) {
    $__scholarScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__scholarHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('DEVPORTAL_PUBLIC_URL', getenv('DEVPORTAL_PUBLIC_URL') ?: "{$__scholarScheme}://{$__scholarHost}/ABNsystems/devportal");
}

/*
|--------------------------------------------------------------------------
| SCHOLAR-APP / SCHOLAR-SPA — NEW STACK URLS
|--------------------------------------------------------------------------
| Used by scholar/portal_handoff.php to send an already-logged-in legacy
| session into the new decoupled portal (scholar-spa), authenticated --
| the mirror image of scholar/sso_from_portal.php, which brings a portal
| session the other way. See scholar_test/ARCHITECTURE.md.
|
| Points through Apache's reverse proxy (/scholar_test/scholar/portal-api
| -> scholar-app on :8200 -- see
| c:\xamp\apache\conf\extra\httpd-scholar-portal-proxy.conf), not directly
| at :8200, so the browser's address bar stays on this same origin all the
| way through the handoff instead of visibly jumping to a different port.
|--------------------------------------------------------------------------
*/
if (!defined('SCHOLAR_APP_API_URL')) {
    // Host-relative (no scheme/domain) is deliberate -- Location headers
    // may be relative per RFC 7231 and every browser resolves them
    // against the current origin, which is exactly what keeps this
    // same-origin regardless of hostname.
    define('SCHOLAR_APP_API_URL', getenv('SCHOLAR_APP_API_URL') ?: SCHOLAR_BASE . '/portal-api');
}
