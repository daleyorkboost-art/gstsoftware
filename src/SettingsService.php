<?php
declare(strict_types=1);
namespace App;
final class SettingsService
{
    public static function settings(): array
    {
        return json_decode(
            DB::one("SELECT settings FROM system_settings WHERE id=1")[
                "settings"
            ],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
    public static function business(): array
    {
        return DB::one("SELECT * FROM business_profile WHERE id=1");
    }
    public static function saveBusiness(array $data, array $user): array
    {
        $fields = [];
        foreach (
            [
                "name" => 160,
                "address" => 1500,
                "bank_name" => 160,
                "account_number" => 40,
                "ifsc" => 11,
                "branch" => 160,
            ]
            as $k => $max
        ) {
            $fields[$k] = Validation::text(
                $data[$k] ?? "",
                $k,
                $max,
                $k === "name",
            );
        }
        $fields["gstin"] = Validation::gstin($data["gstin"] ?? "");
        $fields["state"] = Validation::state($data["state"] ?? "");
        if (
            $fields["gstin"] !== "" &&
            substr($fields["gstin"], 0, 2) !== $fields["state"]
        ) {
            throw new HttpError(
                422,
                "Business GSTIN state code must match the selected state.",
            );
        }
        if (
            $fields["ifsc"] !== "" &&
            !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/D', $fields["ifsc"])
        ) {
            throw new HttpError(422, "Invalid IFSC format.");
        }
        DB::transaction(function () use ($fields, $user) {
            DB::run(
                "UPDATE business_profile SET " .
                    implode(
                        ",",
                        array_map(fn($k) => "$k=?", array_keys($fields)),
                    ) .
                    " WHERE id=1",
                array_values($fields),
            );
            AuditLogService::record($user, "Business Profile Updated");
        });
        return self::business();
    }
    public static function upload(array $file, array $user): array
    {
        if (
            ($file["error"] ?? 1) !== UPLOAD_ERR_OK ||
            $file["size"] > (int) Config::get("LOGO_MAX_BYTES", "2097152")
        ) {
            throw new HttpError(
                422,
                "Upload a PNG or JPEG logo within the size limit.",
            );
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
        $size = getimagesize($file["tmp_name"]);
        if (
            !in_array($mime, ["image/png", "image/jpeg"], true) ||
            !$size ||
            $size[0] > 3000 ||
            $size[1] > 3000
        ) {
            throw new HttpError(
                422,
                "Invalid logo image. Maximum dimensions: 3000 × 3000.",
            );
        }
        $image =
            $mime === "image/png"
                ? imagecreatefrompng($file["tmp_name"])
                : imagecreatefromjpeg($file["tmp_name"]);
        if (!$image) {
            throw new HttpError(422, "Unable to read logo.");
        }
        $name = bin2hex(random_bytes(20)) . ".png";
        imagepng($image, ROOT . "/storage/uploads/" . $name);
        imagedestroy($image);
        DB::transaction(function () use ($name, $user) {
            DB::run("UPDATE business_profile SET logo=? WHERE id=1", [$name]);
            AuditLogService::record($user, "Logo Uploaded");
        });
        return ["logo" => $name];
    }
    public static function save(array $d, array $u): array
    {
        if (!is_array($d["gst_rates"] ?? null) || count($d["gst_rates"]) > 30) {
            throw new HttpError(422, "Enter standard GST rates.");
        }
        $max = Money::number($d["gst_max"] ?? "100", "maximum GST", 2, "100");
        $rates = [];
        foreach ($d["gst_rates"] as $rate) {
            $rates[] = Money::number($rate, "GST rate", 2, $max);
        }
        if (!is_array($d["payments"] ?? null) || !$d["payments"]) {
            throw new HttpError(422, "Select at least one payment method.");
        }
        foreach ($d["payments"] as $v) {
            Validation::choice(
                $v,
                ["Cash", "UPI", "Card", "Bank Transfer", "Credit"],
                "payment method",
            );
        }
        $settings = [
            "gst_rates" => array_values(array_unique($rates)),
            "gst_max" => $max,
            "payments" => array_values(array_unique($d["payments"])),
            "round_to_rupee" => ($d["round_to_rupee"] ?? false) === true,
            "terms" => Validation::text($d["terms"] ?? "", "terms", 2000),
        ];
        DB::transaction(function () use ($settings, $u) {
            DB::run("UPDATE system_settings SET settings=? WHERE id=1", [
                json_encode($settings),
            ]);
            AuditLogService::record($u, "Settings Updated", null, $settings);
        });
        return $settings;
    }
    public static function numbering(array $d, array $u): array
    {
        $prefix = Validation::text($d["prefix"] ?? "", "prefix", 20);
        if (!preg_match('/^[A-Za-z0-9\/-]*$/D', $prefix)) {
            throw new HttpError(
                422,
                "Use letters, numbers, slash or hyphen in the prefix.",
            );
        }
        $next = filter_var($d["next_number"] ?? null, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1, "max_range" => 999999999999],
        ]);
        $padding = filter_var($d["padding"] ?? null, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1, "max_range" => 12],
        ]);
        if (!$next || !$padding) {
            throw new HttpError(422, "Invalid starting number or padding.");
        }
        return DB::transaction(function () use ($prefix, $next, $padding, $u) {
            $old = DB::one(
                "SELECT * FROM invoice_number_settings WHERE id=1 FOR UPDATE",
            );
            if ($prefix === $old["prefix"] && $next < $old["next_number"]) {
                throw new HttpError(
                    422,
                    "The current series cannot be moved backwards.",
                );
            }
            if (
                DB::one("SELECT id FROM invoices WHERE invoice_number=?", [
                    $prefix .
                    str_pad((string) $next, $padding, "0", STR_PAD_LEFT),
                ])
            ) {
                throw new HttpError(422, "That invoice number already exists.");
            }
            DB::run(
                "UPDATE invoice_number_settings SET prefix=?,next_number=?,padding=? WHERE id=1",
                [$prefix, $next, $padding],
            );
            AuditLogService::record($u, "Numbering Updated", null, [
                "prefix" => $prefix,
                "next_number" => $next,
            ]);
            return DB::one("SELECT * FROM invoice_number_settings WHERE id=1");
        });
    }
}
