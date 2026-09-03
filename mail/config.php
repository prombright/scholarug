<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED MAIL LAYER — CONFIG
|--------------------------------------------------------------------------
| One SMTP account sends transactional email (password resets, etc.) for
| the whole platform (scholar, medicare, bulksms, ...), same "one shared
| account instead of per-app duplication" philosophy as payments/config.php
| for Mobile Money. getenv() with a fallback is the idiom every other
| credential in this codebase uses -- SMTP_PASS's fallback is always empty
| (never a real secret in source control); Mailer.php reports itself as
| "not configured" and refuses to send until a real value is set.
|
| FROM_EMAIL/USER default to info@scholarug.com, ScholarUg's real mailbox
| on its own domain -- production sets these (and SMTP_PASS) via
| mail/config.local.php rather than editing this file directly.
|--------------------------------------------------------------------------
*/

$__mail_defaults = [
    'SMTP_HOST'      => 'mail.scholarug.com',
    'SMTP_PORT'      => '587',
    'SMTP_USER'      => 'info@scholarug.com',
    'SMTP_PASS'      => '',
    'SMTP_FROM_EMAIL' => 'info@scholarug.com',
    'SMTP_FROM_NAME' => 'ScholarUg',
    // Shared hosting mail clusters often terminate TLS with one cert shared
    // across every reseller domain (e.g. an autoconfig.* CN) rather than a
    // cert matching SMTP_HOST itself -- leave empty to verify against
    // SMTP_HOST as normal, or set to the cert's real CN so STARTTLS still
    // validates a name instead of skipping verification outright.
    'SMTP_TLS_PEER_NAME' => '',
];

// Local/dev secret override -- gitignored, never committed (see
// .gitignore). Lets a real Gmail App Password be tested on this machine
// without it ever touching git history. Production instead sets the same
// names as real environment variables; this file is purely a local
// convenience and is entirely optional -- see config.local.php.example.
$__mail_local_file = __DIR__ . '/config.local.php';
if (file_exists($__mail_local_file)) {
    $__mail_local = require $__mail_local_file; // expected to `return [...]`
    if (is_array($__mail_local)) {
        $__mail_defaults = array_merge($__mail_defaults, $__mail_local);
    }
}

foreach ($__mail_defaults as $__mail_name => $__mail_default) {
    if (!defined($__mail_name)) {
        $__mail_value = getenv($__mail_name) ?: $__mail_default;
        if ($__mail_name === 'SMTP_PASS') {
            // Gmail App Passwords are displayed as "xxxx xxxx xxxx xxxx"
            // for readability, but the real credential has no spaces --
            // pasted-with-spaces is the single most common way this
            // silently fails auth, so strip defensively rather than
            // document "remember to remove the spaces" and hope.
            $__mail_value = str_replace(' ', '', $__mail_value);
        }
        define($__mail_name, $__mail_value);
    }
}
unset($__mail_value);
unset($__mail_defaults, $__mail_local_file, $__mail_local, $__mail_name, $__mail_default);

require_once __DIR__ . '/Mailer.php';
