<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require dirname(__DIR__) . "/config/bootstrap.php";
try {
    $service = new App\BackupService();
    $path = $service->create();
    if (App\Config::get("BACKUP_CLOUD_URL") !== "") {
        $service->cloud($path);
    }
    echo "Backup created: " . basename($path) . PHP_EOL;
    $cutoff =
        time() -
        max(1, (int) App\Config::get("BACKUP_RETENTION_DAYS", "30")) * 86400;
    foreach (glob(ROOT . "/storage/generated/backup-*.gstbackup") as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed: " . get_class($e) . PHP_EOL);
    exit(1);
}
