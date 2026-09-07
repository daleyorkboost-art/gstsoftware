<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require dirname(__DIR__) . "/config/bootstrap.php";
foreach (glob(ROOT . "/storage/generated/*") as $f) {
    if (
        is_file($f) &&
        !str_ends_with($f, ".gstbackup") &&
        filemtime($f) < time() - 7200
    ) {
        unlink($f);
    }
}
App\DB::run("DELETE FROM rate_limits WHERE expires_at<?", [time() - 3600]);
echo "Expired export files and rate limits cleaned.\n";
