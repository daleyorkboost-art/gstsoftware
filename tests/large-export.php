<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
if (
    PHP_SAPI !== "cli" ||
    !str_ends_with(App\Config::get("DB_DATABASE"), "_test")
) {
    exit(1);
}
$user = App\DB::one("SELECT * FROM users WHERE id=1");
$user["permissions"] = App\Auth::permissions($user);
$svc = new App\InvoiceService();
$range = ["from" => "2025-01-15", "to" => "2025-01-15"];
if ($svc->list($range)["total"] !== 0) {
    throw new RuntimeException("Run on a fresh test database.");
}
for ($n = 1; $n <= 25; $n++) {
    $svc->save(
        [
            "invoice_date" => "2025-01-15",
            "customer_name" => "Export fixture " . $n,
            "items" => [
                [
                    "product_name" => "Item " . $n,
                    "rate" => "100",
                    "quantity" => "1",
                    "gst_rate" => "18",
                ],
            ],
        ],
        $user,
    );
}
$export = new App\ExportService();
$job = $export->start($range + ["kind" => "invoices"], $user);
while (!$job["ready"]) {
    $job = $export->step($job["id"], $user);
}
require ROOT . "/vendor/autoload.php";
$parser = new setasign\Fpdi\Fpdi();
$pages = $parser->setSourceFile(
    ROOT . "/storage/generated/" . $job["id"] . ".pdf",
);
if ($pages !== 25) {
    throw new RuntimeException("Expected 25 invoice pages; got " . $pages);
}
echo "PASS 25 invoices produce one PDF with 25 pages\n";
copy(
    ROOT . "/storage/generated/" . $job["id"] . ".pdf",
    ROOT . "/tmp/25-invoice-test.pdf",
);
$items = [];
for ($n = 1; $n <= 50; $n++) {
    $items[] = [
        "product_name" => "Detailed item " . $n,
        "description" => str_repeat("Full description retained. ", 6),
        "rate" => "125.50",
        "quantity" => "1",
        "gst_rate" => "3",
    ];
}
$long = $svc->save(["invoice_date" => "2025-01-16", "items" => $items], $user);
$pdf = new App\PDFService();
file_put_contents(
    ROOT . "/tmp/long-invoice-test.pdf",
    $pdf->render($pdf->invoiceHtml($long)),
);
file_put_contents(
    ROOT . "/tmp/long-audit-test.pdf",
    $pdf->render($pdf->auditHtml([$long]), true),
);
echo "PASS long invoice and audit PDF render without truncating item arrays\n";
$j = $export->start($range + ["kind" => "excel"], $user);
while (!$j["ready"]) {
    $j = $export->step($j["id"], $user);
}
$zip = new ZipArchive();
if ($zip->open(ROOT . "/storage/generated/" . $j["id"] . ".xlsx") !== true) {
    throw new RuntimeException("Invalid XLSX");
}
$sheet = simplexml_load_string($zip->getFromName("xl/worksheets/sheet1.xml"));
if (count($sheet->sheetData->row) !== 26) {
    throw new RuntimeException("Workbook row count mismatch");
}
$zip->close();
echo "PASS XLSX contains header and all 25 invoices\n";
echo "Peak memory: " .
    round(memory_get_peak_usage(true) / 1048576, 1) .
    " MB\n";
