<?php
declare(strict_types=1);
namespace App;
final class Config
{
    private static array $values = [];
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if (
                $line === "" ||
                str_starts_with($line, "#") ||
                !str_contains($line, "=")
            ) {
                continue;
            }
            [$key, $value] = explode("=", $line, 2);
            $value = trim($value);
            if (
                strlen($value) >= 2 &&
                in_array($value[0], ["\"", "'"], true) &&
                substr($value, -1) === $value[0]
            ) {
                $value = substr($value, 1, -1);
            }
            self::$values[trim($key)] = $value;
        }
    }
    public static function get(string $key, string $default = ""): string
    {
        $env = getenv($key);
        return $env !== false ? $env : self::$values[$key] ?? $default;
    }
    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === "") {
            throw new HttpError(
                503,
                "Application setup is incomplete. Please contact the administrator.",
            );
        }
        return $value;
    }
}
