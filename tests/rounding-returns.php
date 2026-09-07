<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
if (
    PHP_SAPI !== "cli" ||
    !str_ends_with(App\Config::get("DB_DATABASE"), "_test")
) {
    exit(1);
}
$user = App\DB::one(
    "SELECT * FROM users WHERE role_id='ADMIN' AND active=1 LIMIT 1",
);
$invoice = (new App\InvoiceService())->save(
    [
        "invoice_date" => "2025-03-01",
        "items" => [
            [
                "product_name" => "Small-value rounding fixture",
                "quantity" => "4",
                "rate" => "0.25",
                "gst_rate" => "5",
            ],
        ],
    ],
    $user,
);
$total = "0.00";
for ($n = 0; $n < 4; $n++) {
    $credit = (new App\CreditNoteService())->create(
        [
            "invoice_id" => $invoice["id"],
            "note_date" => "2025-03-01",
            "reason" => "Rounding test",
            "settlement" => "Refund",
            "items" => [
                [
                    "invoice_item_id" => $invoice["items"][0]["id"],
                    "quantity" => "1",
                ],
            ],
        ],
        $user,
    );
    foreach ($credit["totals"] as $amount) {
        if (bccomp($amount, "0", 2) < 0) {
            throw new RuntimeException("Negative return component.");
        }
    }
    $total = bcadd($total, $credit["totals"]["total"], 2);
}
if ($total !== $invoice["grand_total"]) {
    throw new RuntimeException(
        "Full refund does not reconcile to rounded invoice total.",
    );
}
echo "PASS repeated small partial returns remain nonnegative and reconcile exactly\n";
