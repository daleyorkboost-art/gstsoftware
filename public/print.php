<?php
require dirname(__DIR__) . "/config/bootstrap.php";
try {
    App\Auth::start();
    $u = App\Auth::user();
    App\Auth::require($u, "print_invoice");
    $invoice = (new App\InvoiceService())->get((int) ($_GET["id"] ?? 0));
} catch (Throwable $e) {
    http_response_code($e instanceof App\HttpError ? $e->status : 500);
    echo "<p>" .
        e(
            $e instanceof App\HttpError
                ? $e->getMessage()
                : "Unable to load invoice.",
        ) .
        '</p><a href="index.php">Return to billing</a>';
    exit();
}
header("Cache-Control: no-store");
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'",
);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e(
    $invoice["invoice_number"],
) ?></title><link rel="stylesheet" href="assets/invoice.css"></head><body data-invoice="<?= e(
    $invoice["id"],
) ?>" data-csrf="<?= e(
    $_SESSION["csrf"],
) ?>"><div class="no-print"><a href="index.php#invoice/<?= e(
    $invoice["id"],
) ?>">Back to invoice</a> <button id="print">Print A4</button> <button id="thermal">Print thermal (80 mm)</button><p id="print-error" role="alert"></p></div><?= (new App\PDFService())->invoiceHtml($invoice) ?><script src="assets/print.js"></script></body></html>
