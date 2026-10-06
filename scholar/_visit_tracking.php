<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PAGE VIEW TRACKING
|--------------------------------------------------------------------------
| One function, used from two places: scholar/preloader.php (every
| Scholar app page) and includes/track_visit.php (every marketing site
| page, which has no other database connection at all -- see that
| file's own header comment for why it opens its own PDO instead of
| reusing scholar/db.php).
|
| Never allowed to break the page it's called from -- a tracking write
| failing is not a reason to show a visitor an error. See
| page_views_migration.sql for why this replaced the old
| scholar_track_visit(), which silently wrote nowhere for the same
| reason (just via an unreachable cross-database connection instead of
| a try/catch'd insert).
|--------------------------------------------------------------------------
*/

if (!function_exists('scholar_track_page_view')) {
    function scholar_track_page_view(PDO $pdo, string $system, ?int $schoolId = null, ?string $role = null): void
    {
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $referrer = $_SERVER['HTTP_REFERER'] ?? null;
            // Hashed, not raw -- enough to roughly tell "same visitor
            // again" apart from "new visitor" without keeping anyone's
            // actual IP address on file.
            $ipHash = isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', $_SERVER['REMOTE_ADDR']) : null;

            $stmt = $pdo->prepare(
                'INSERT INTO page_views (system, path, school_id, role, ip_hash, referrer) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $system,
                mb_substr($path, 0, 255),
                $schoolId,
                $role !== null ? mb_substr($role, 0, 30) : null,
                $ipHash,
                $referrer !== null ? mb_substr($referrer, 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            // Table not migrated yet, DB unreachable, whatever -- never
            // allowed to break the page. Same "learned the hard way"
            // rule as every other migration-dependent code path this
            // app has added defensive fallbacks for.
        }
    }
}
