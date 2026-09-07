<?php
declare(strict_types=1);
namespace App;
final class BackupService
{
    private const TABLES = [
        "roles",
        "permissions",
        "role_permissions",
        "users",
        "user_permissions",
        "business_profile",
        "invoice_number_settings",
        "system_settings",
        "invoices",
        "invoice_items",
        "activity_logs",
        "invoice_edit_history",
        "credit_notes",
        "sales_returns",
        "integration_requests",
    ];
    public function create(): string
    {
        $key = hash("sha256", Config::required("APP_KEY"), true);
        $data = DB::transaction(function () {
            $d = [
                "version" => 1,
                "created_at" => date(DATE_ATOM),
                "tables" => [],
                "logos" => [],
            ];
            foreach (self::TABLES as $t) {
                $d["tables"][$t] = DB::all(
                    "SELECT * FROM $t" .
                        ($t === "invoices" ? " ORDER BY id" : ""),
                );
            }
            foreach (glob(ROOT . "/storage/uploads/*.png") as $f) {
                $d["logos"][basename($f)] = base64_encode(
                    file_get_contents($f),
                );
            }
            return $d;
        });
        $plain = gzencode(json_encode($data, JSON_THROW_ON_ERROR), 6);
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt(
            $plain,
            "aes-256-gcm",
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            "GSTBACKUP1",
        );
        if ($encrypted === false) {
            throw new HttpError(500, "Backup encryption failed.");
        }
        $path =
            ROOT .
            "/storage/generated/backup-" .
            date("Ymd-His") .
            "-" .
            bin2hex(random_bytes(6)) .
            ".gstbackup";
        file_put_contents(
            $path,
            "GSTBACKUP1" . $iv . $tag . $encrypted,
            LOCK_EX,
        );
        return $path;
    }
    public function restore(string $path, array $user): void
    {
        if (filesize($path) > 100 * 1024 * 1024) {
            throw new HttpError(
                422,
                "Backup is too large for browser restore. Use the CLI restore workflow.",
            );
        }
        $raw = file_get_contents($path);
        if (substr($raw, 0, 10) !== "GSTBACKUP1") {
            throw new HttpError(422, "Invalid backup format.");
        }
        $plain = openssl_decrypt(
            substr($raw, 38),
            "aes-256-gcm",
            hash("sha256", Config::required("APP_KEY"), true),
            OPENSSL_RAW_DATA,
            substr($raw, 10, 12),
            substr($raw, 22, 16),
            "GSTBACKUP1",
        );
        if ($plain === false) {
            throw new HttpError(
                422,
                "Backup authentication failed. Use the original APP_KEY.",
            );
        }
        $json = gzdecode($plain, 128 * 1024 * 1024);
        if ($json === false) {
            throw new HttpError(422, "Invalid compressed backup.");
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (
            ($data["version"] ?? 0) !== 1 ||
            array_keys($data["tables"] ?? []) !== self::TABLES
        ) {
            throw new HttpError(
                422,
                "Backup schema does not match this application.",
            );
        }
        $admin = null;
        foreach ($data["tables"]["users"] as $u) {
            if (
                $u["firebase_uid"] === $user["firebase_uid"] &&
                $u["role_id"] === "ADMIN" &&
                $u["active"]
            ) {
                $admin = $u;
            }
        }
        if (!$admin) {
            throw new HttpError(
                422,
                "The backup must retain your active administrator identity.",
            );
        }
        $this->create(); // Always preserve the current state before restore.
        DB::transaction(function () use ($data, $admin) {
            DB::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            DB::run("UPDATE invoices SET duplicated_from=NULL");
            foreach (array_reverse(self::TABLES) as $t) {
                DB::run("DELETE FROM $t");
            }
            foreach (self::TABLES as $t) {
                $columns = array_column(
                    DB::all("SHOW COLUMNS FROM $t"),
                    "Field",
                );
                foreach ($data["tables"][$t] as $row) {
                    if (array_diff(array_keys($row), $columns)) {
                        throw new HttpError(
                            422,
                            "Backup contains unknown columns.",
                        );
                    }
                    DB::run(
                        "INSERT INTO $t (`" .
                            implode("`,`", array_keys($row)) .
                            "`) VALUES (" .
                            implode(",", array_fill(0, count($row), "?")) .
                            ")",
                        array_values($row),
                    );
                }
            }
            foreach ($data["logos"] ?? [] as $name => $content) {
                if (!preg_match('/^[a-f0-9]{40}\.png$/D', $name)) {
                    throw new HttpError(422, "Invalid backup logo.");
                }
                $bytes = base64_decode($content, true);
                if (
                    $bytes === false ||
                    !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
                ) {
                    throw new HttpError(422, "Invalid backup image.");
                }
                file_put_contents(
                    ROOT . "/storage/uploads/" . $name,
                    $bytes,
                    LOCK_EX,
                );
            }
            AuditLogService::record($admin, "Backup Restored");
            $_SESSION["uid"] = $admin["id"];
        });
        if (PHP_SAPI !== "cli") {
            foreach (glob(ROOT . "/storage/sessions/sess_*") as $sessionFile) {
                if (basename($sessionFile) !== "sess_" . session_id()) {
                    unlink($sessionFile);
                }
            }
        }
    }
    public function cloud(string $path): void
    {
        $url = Config::required("BACKUP_CLOUD_URL");
        if (!str_starts_with($url, "https://")) {
            throw new HttpError(503, "Cloud backup endpoint must use HTTPS.");
        }
        $fp = fopen($path, "rb");
        $ch = curl_init(rtrim($url, "/") . "/" . rawurlencode(basename($path)));
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => filesize($path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " .
                Config::required("BACKUP_CLOUD_TOKEN"),
                "Content-Type: application/octet-stream",
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new HttpError(
                502,
                "Cloud backup upload failed. The local backup is retained.",
            );
        }
    }
}
