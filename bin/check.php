<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require dirname(__DIR__) . "/config/bootstrap.php";
$failed = false;
$check = function (bool $ok, string $label) use (&$failed) {
    echo ($ok ? "OK   " : "FAIL ") . $label . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
};
$check(version_compare(PHP_VERSION, "8.2", ">="), "PHP 8.2 or newer");
foreach (
    [
        "bcmath",
        "pdo_mysql",
        "curl",
        "gd",
        "mbstring",
        "openssl",
        "dom",
        "fileinfo",
        "zip",
        "zlib",
    ]
    as $extension
) {
    $check(extension_loaded($extension), "PHP extension " . $extension);
}
$check(is_file(ROOT . "/vendor/autoload.php"), "Bundled PHP dependencies");
$check(is_writable(ROOT . "/storage"), "Writable private storage");
$check(
    strlen(App\Config::get("APP_KEY")) >= 32,
    "APP_KEY has at least 32 characters",
);
foreach (
    [
        "DB_DATABASE",
        "DB_USERNAME",
        "FIREBASE_API_KEY",
        "FIREBASE_PROJECT_ID",
        "BOOTSTRAP_ADMIN_EMAIL",
    ]
    as $name
) {
    $check(App\Config::get($name) !== "", $name . " configured");
}
$check(
    App\Config::get("APP_ENV") !== "production" ||
        str_starts_with(App\Config::get("APP_URL"), "https://"),
    "HTTPS application URL in production",
);
try {
    App\DB::one("SELECT id FROM system_settings WHERE id=1");
    $check(true, "Database connection and seeded schema");
} catch (Throwable) {
    $check(false, "Database connection and seeded schema");
}
exit($failed ? 1 : 0);
