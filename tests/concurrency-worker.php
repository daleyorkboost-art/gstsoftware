<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
if (
    PHP_SAPI !== "cli" ||
    !str_ends_with(App\Config::get("DB_DATABASE"), "_test")
) {
    exit(1);
}
$u = App\DB::one(
    "SELECT * FROM users WHERE role_id='ADMIN' AND active=1 LIMIT 1",
);
$i = (new App\InvoiceService())->save(
    [
        "invoice_date" => "2025-02-01",
        "items" => [
            [
                "product_name" => "Concurrency fixture",
                "rate" => "1",
                "quantity" => "1",
                "gst_rate" => "0",
            ],
        ],
    ],
    $u,
);
echo $i["invoice_number"];
