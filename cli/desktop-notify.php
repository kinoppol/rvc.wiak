<?php
/**
 * Cron entry point: records due-date alerts for the desktop notifier.
 *
 * Example crontab (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/app/cli/desktop-notify.php >> /dev/null 2>&1
 *
 * Safe to overlap or re-run: each alert has a unique dedupe key.
 * Exit status 0 on success, 1 on failure. CLI only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    if (!App\Models\DesktopDevice::ready()) {
        fwrite(STDERR, "Desktop notify: migration has not been run yet\n");
        exit(1);
    }
    $r = App\Models\Notification::generateDue();
    printf("Desktop notify: users=%d added=%d pruned=%d\n", $r['users'], $r['added'], $r['pruned']);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Desktop notify FAILED: ' . get_class($e) . "\n");
    exit(1);
}
