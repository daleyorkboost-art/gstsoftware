<?php
declare(strict_types=1);
define("ROOT", dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, "App\\")) {
        $file =
            ROOT . "/src/" . str_replace("\\", "/", substr($class, 4)) . ".php";
        if (is_file($file)) {
            require $file;
        }
    }
});
App\Config::load(ROOT . "/.env");
date_default_timezone_set(App\Config::get("APP_TIMEZONE", "Asia/Kolkata"));
ini_set("display_errors", "0");
foreach (["uploads", "logs", "generated", "sessions"] as $dir) {
    if (!is_dir(ROOT . "/storage/" . $dir)) {
        mkdir(ROOT . "/storage/" . $dir, 0700, true);
    }
}
function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    );
}
