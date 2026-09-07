<?php
declare(strict_types=1);
namespace App;
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_save_path(ROOT . "/storage/sessions");
        session_name("gst_session");
        ini_set("session.use_strict_mode", "1");
        session_set_cookie_params([
            "lifetime" => 0,
            "path" => "/",
            "secure" => Config::get("APP_ENV", "production") === "production",
            "httponly" => true,
            "samesite" => "Strict",
        ]);
        session_start();
        $_SESSION["csrf"] ??= bin2hex(random_bytes(32));
    }
    public static function csrf(): void
    {
        if (
            !hash_equals($_SESSION["csrf"], $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "")
        ) {
            throw new HttpError(
                403,
                "Your session changed. Reload and try again.",
            );
        }
    }
    public static function user(): array
    {
        if (empty($_SESSION["uid"]) || ($_SESSION["expires"] ?? 0) < time()) {
            throw new HttpError(401, "Please sign in to continue.");
        }
        $u = DB::one("SELECT * FROM users WHERE id=? AND active=1", [
            $_SESSION["uid"],
        ]);
        if (
            !$u ||
            !hash_equals(
                (string) $u["firebase_uid"],
                (string) ($_SESSION["firebase_uid"] ?? ""),
            ) ||
            !in_array($u["role_id"], ["ADMIN", "OWNER", "STAFF"], true)
        ) {
            throw new HttpError(
                401,
                "This account is disabled or your session has changed.",
            );
        }
        $u["permissions"] = self::permissions($u);
        return $u;
    }
    public static function permissions(array $u): array
    {
        $rows = DB::all(
            "SELECT p.id,p.admin_only,COALESCE(up.allowed,IF(rp.permission_id IS NULL,0,1)) allowed FROM permissions p LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND rp.role_id=? LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=?",
            [$u["role_id"], $u["id"]],
        );
        return array_values(
            array_map(
                fn($r) => $r["id"],
                array_filter(
                    $rows,
                    fn($r) => $u["role_id"] === "ADMIN" ||
                        (!$r["admin_only"] && $r["allowed"]),
                ),
            ),
        );
    }
    public static function require(array $u, string $permission): void
    {
        if (!in_array($permission, $u["permissions"], true)) {
            throw new HttpError(
                403,
                "You do not have permission for this action.",
            );
        }
    }
    public static function login(string $token): array
    {
        self::limit("login", 20);
        $identity = (new FirebaseService())->verify($token);
        $u = DB::transaction(function () use ($identity) {
            DB::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            $u = DB::one("SELECT * FROM users WHERE firebase_uid=?", [
                $identity["uid"],
            ]);
            if (!$u) {
                $invited = DB::one(
                    "SELECT * FROM users WHERE email=? AND firebase_uid IS NULL",
                    [$identity["email"]],
                );
                if ($invited && $identity["verified"]) {
                    DB::run("UPDATE users SET firebase_uid=? WHERE id=?", [
                        $identity["uid"],
                        $invited["id"],
                    ]);
                    $u = $invited;
                } elseif (
                    !DB::one("SELECT id FROM users LIMIT 1") &&
                    $identity["verified"] &&
                    $identity["email"] !== "" &&
                    $identity["email"] ===
                        strtolower(Config::get("BOOTSTRAP_ADMIN_EMAIL"))
                ) {
                    DB::run(
                        "INSERT INTO users(firebase_uid,email,name,role_id) VALUES(?,?,?,?)",
                        [
                            $identity["uid"],
                            $identity["email"],
                            "Administrator",
                            "ADMIN",
                        ],
                    );
                    $u = DB::one("SELECT * FROM users WHERE id=?", [
                        DB::connection()->lastInsertId(),
                    ]);
                }
            }
            if (!$u || !$u["active"]) {
                throw new HttpError(
                    403,
                    "Your account has not been granted access. Contact the administrator.",
                );
            }
            return $u;
        });
        session_regenerate_id(true);
        $_SESSION["uid"] = $u["id"];
        $_SESSION["firebase_uid"] = $identity["uid"];
        $_SESSION["expires"] =
            time() +
            min(3600, max(300, (int) Config::get("SESSION_SECONDS", "3600")));
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
        AuditLogService::record($u, "Login");
        return self::user();
    }
    public static function limit(string $action, int $max): void
    {
        $bucket = hash(
            "sha256",
            $action . "|" . ($_SERVER["REMOTE_ADDR"] ?? "cli"),
        );
        DB::run(
            "INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at<?,1,hits+1),expires_at=IF(expires_at<?,?,expires_at)",
            [$bucket, time() + 300, time(), time(), time() + 300],
        );
        if (
            (int) DB::one("SELECT hits FROM rate_limits WHERE bucket=?", [
                $bucket,
            ])["hits"] > $max
        ) {
            throw new HttpError(
                429,
                "Too many attempts. Try again in five minutes.",
            );
        }
    }
}
