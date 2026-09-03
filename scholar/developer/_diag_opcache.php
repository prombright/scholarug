<?php
declare(strict_types=1);

/*
| Temporary diagnostic -- delete after use. Gated behind an active
| developer session, same pattern as every other one-time deploy helper
| this session used.
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'developer' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden. Log in as developer first.');
}

header('Content-Type: text/plain');

echo "PHP version: " . phpversion() . "\n\n";

echo "== OPcache ==\n";
if (!function_exists('opcache_get_status')) {
    echo "opcache extension: NOT LOADED\n";
} else {
    echo "opcache extension: loaded\n";
    echo "opcache.enable: " . ini_get('opcache.enable') . "\n";
    echo "opcache.enable_cli: " . ini_get('opcache.enable_cli') . "\n";
    echo "opcache.memory_consumption: " . ini_get('opcache.memory_consumption') . " MB\n";
    echo "opcache.max_accelerated_files: " . ini_get('opcache.max_accelerated_files') . "\n";
    echo "opcache.validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
    echo "opcache.revalidate_freq: " . ini_get('opcache.revalidate_freq') . "\n";
    $status = opcache_get_status(false);
    if ($status === false) {
        echo "opcache_get_status(): FALSE -- opcache is not actually running\n";
    } else {
        echo "opcache_enabled (runtime): " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
        echo "cache_full: " . ($status['cache_full'] ? 'YES (!!)' : 'no') . "\n";
        echo "memory_usage.used_memory: " . round($status['memory_usage']['used_memory'] / 1048576, 1) . " MB\n";
        echo "memory_usage.free_memory: " . round($status['memory_usage']['free_memory'] / 1048576, 1) . " MB\n";
        echo "opcache_statistics.num_cached_scripts: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
        echo "opcache_statistics.hits: " . $status['opcache_statistics']['hits'] . "\n";
        echo "opcache_statistics.misses: " . $status['opcache_statistics']['misses'] . "\n";
        echo "opcache_statistics.opcache_hit_rate: " . round($status['opcache_statistics']['opcache_hit_rate'], 2) . "%\n";
    }
}

echo "\n== Other perf-relevant settings ==\n";
echo "realpath_cache_size: " . ini_get('realpath_cache_size') . "\n";
echo "realpath_cache_ttl: " . ini_get('realpath_cache_ttl') . "\n";
echo "session.save_handler: " . ini_get('session.save_handler') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";

echo "\n== APCu (alternative object cache) ==\n";
echo "apcu loaded: " . (extension_loaded('apcu') ? 'YES' : 'no') . "\n";

echo "\n== Timing this request itself ==\n";
echo "Request start to now: " . round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 1) . " ms\n";
