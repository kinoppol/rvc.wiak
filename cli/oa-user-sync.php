<?php
/**
 * Cron entry point for the RVC-ONE User-sync (App\Services\OaUserSync).
 *
 *   php cli/oa-user-sync.php          incremental (since the last complete run)
 *   php cli/oa-user-sync.php --full   re-fetch everyone
 *
 * Example crontab (every 30 minutes, incremental):
 *   0,30 * * * * php /path/to/app/cli/oa-user-sync.php >> /dev/null 2>&1
 *
 * Exit status 0 on success, 1 on failure. A per-run line of counts goes to
 * storage/logs/oa-user-sync.log. CLI only — cli/.htaccess also denies web access.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$full = in_array('--full', $argv, true);

try {
    $r = App\Services\OaUserSync::run($full);
    printf(
        "RVC-ONE user sync (%s): pages=%d total=%d created=%d updated=%d suspended=%d skipped=%d failed=%d since=%s\n",
        $r['mode'],
        $r['pages'],
        $r['total'],
        $r['created'],
        $r['updated'],
        $r['suspended'],
        $r['skipped'],
        $r['failed'],
        $r['since_advanced'] ? (string) $r['server_time'] : 'unchanged'
    );
    foreach ($r['errors'] as $err) {
        fwrite(STDERR, "  - {$err}\n");
    }
    exit($r['failed'] === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'RVC-ONE user sync FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
