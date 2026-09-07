<?php
declare(strict_types=1);
namespace App;
final class DB
{
    private static ?\PDO $pdo = null;
    public static function connection(): \PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        self::$pdo = new \PDO(
            "mysql:host=" .
                Config::get("DB_HOST", "localhost") .
                ";port=" .
                Config::get("DB_PORT", "3306") .
                ";dbname=" .
                Config::required("DB_DATABASE") .
                ";charset=utf8mb4",
            Config::required("DB_USERNAME"),
            Config::get("DB_PASSWORD"),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $tz = self::$pdo->prepare("SET time_zone=?");
        $tz->execute([date("P")]);
        return self::$pdo;
    }
    public static function run(string $sql, array $args = []): \PDOStatement
    {
        $s = self::connection()->prepare($sql);
        $s->execute($args);
        return $s;
    }
    public static function one(string $sql, array $args = []): ?array
    {
        return self::run($sql, $args)->fetch() ?: null;
    }
    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }
    public static function transaction(callable $fn): mixed
    {
        $db = self::connection();
        $db->beginTransaction();
        try {
            // One business mutation at a time also makes full backup/restore consistent with billing.
            self::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            $result = $fn();
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
