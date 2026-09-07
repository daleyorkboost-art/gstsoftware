<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require dirname(__DIR__) . "/config/bootstrap.php";
if (count($argv) !== 4 || $argv[3] !== "--confirm-replace") {
    fwrite(
        STDERR,
        "Usage: php bin/restore.php /absolute/backup.gstbackup ADMIN_USER_ID --confirm-replace\n",
    );
    exit(1);
}
$admin = App\DB::one(
    "SELECT * FROM users WHERE id=? AND role_id='ADMIN' AND active=1",
    [(int) $argv[2]],
);
if (!$admin) {
    fwrite(STDERR, "Active administrator required.\n");
    exit(1);
}
try {
    (new App\BackupService())->restore($argv[1], $admin);
    echo "Backup restored.\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        ($e instanceof App\HttpError
            ? $e->getMessage()
            : "Restore failed; the transaction was rolled back.") . PHP_EOL,
    );
    exit(1);
}
