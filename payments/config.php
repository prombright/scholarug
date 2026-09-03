<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED PAYMENTS LAYER — CONFIG
|--------------------------------------------------------------------------
| MTN/Airtel Mobile Money credentials for the whole platform: one ABN
| merchant account serves every app (scholar, medicare, bulksms, ...), so
| these constants live here instead of being duplicated per-app config.php.
| Same "getenv() with an empty fallback" idiom as every other credential in
| this codebase — nothing is ever hardcoded, and the gateway classes report
| themselves as "not configured" (falling back to the stub gateway, see
| Gateways.php) until real values are set as actual environment variables
| on deploy.
|
| Also supports an optional config.local.php override (same mechanism as
| mail/config.php and scholar/config.php) — needed because on this stack's
| cPanel/LiteSpeed host, .htaccess `SetEnv` values have been observed to
| intermittently fail to reach getenv() ("some requests see it, some
| don't"). config.local.php is read directly by PHP, not via the request
| environment, so it isn't subject to that flakiness. Gitignored, never
| committed — see config.local.php.example.
|--------------------------------------------------------------------------
*/

$__payments_defaults = [
    'PAYMENTS_MTN_SUBSCRIPTION_KEY'  => '',
    'PAYMENTS_MTN_API_USER'          => '',
    'PAYMENTS_MTN_API_KEY'           => '',
    'PAYMENTS_MTN_TARGET_ENV'        => 'sandbox',
    'PAYMENTS_MTN_BASE_URL'          => 'https://sandbox.momodeveloper.mtn.com',
    'PAYMENTS_AIRTEL_CLIENT_ID'      => '',
    'PAYMENTS_AIRTEL_CLIENT_SECRET'  => '',
    'PAYMENTS_AIRTEL_COUNTRY'        => 'UG',
    'PAYMENTS_AIRTEL_BASE_URL'       => 'https://openapiuat.airtel.africa',
];

$__payments_local_file = __DIR__ . '/config.local.php';
if (file_exists($__payments_local_file)) {
    $__payments_local = require $__payments_local_file; // expected to `return [...]`
    if (is_array($__payments_local)) {
        $__payments_defaults = array_merge($__payments_defaults, $__payments_local);
    }
}

foreach ($__payments_defaults as $__payments_name => $__payments_default) {
    if (!defined($__payments_name)) {
        define($__payments_name, getenv($__payments_name) ?: $__payments_default);
    }
}
unset($__payments_defaults, $__payments_local_file, $__payments_local, $__payments_name, $__payments_default);

// Grace window after a trial/subscription period ends before a tenant is
// treated as lapsed for the *mutating-action* guard (require_active_subscription()).
// The read-only soft-lock banner shows immediately once the period ends;
// this only delays the harder redirect, to absorb late MoMo confirmations.
if (!defined('PAYMENTS_GRACE_PERIOD_DAYS')) {
    define('PAYMENTS_GRACE_PERIOD_DAYS', 3);
}
