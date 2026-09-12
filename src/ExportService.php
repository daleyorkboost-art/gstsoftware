<?php
declare(strict_types=1);
namespace App;
final class ExportService
{
    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new HttpError(404, "Export not found.");
        }
        return ROOT . "/storage/generated/" . $id;
    }
    public function start(array $q, array $u): array
    {
        [$from, $to] = Validation::range($q);
        $kind = Validation::choice(
            $q["kind"] ?? "invoices",
            ["invoices", "audit", "csv", "excel"],
            "export type",
        );
        Auth::require(
            $u,
            $kind === "audit" ? "export_audit_report" : "export_invoice",
        );
        Auth::limit("export-" . $u["id"], 10);
        $id = bin2hex(random_bytes(16));
        $base = $this->path($id);
        $file = fopen($base . ".jsonl", "xb");
        $count = 0;
        $generation = 0;
        try {
            DB::transaction(function () use ($from, $to, $file, &$count, &$generation) {
                $generation = (int) DB::one("SELECT COALESCE(MAX(id),0) n FROM activity_logs WHERE action='Bulk Transaction Deletion'")["n"];
                $last = 0;
                $service = new InvoiceService();
                do {
                    $ids = DB::all(
                        "SELECT id FROM invoices WHERE invoice_date BETWEEN ? AND ? AND id>? ORDER BY id LIMIT 50",
                        [$from, $to, $last],
                    );
                    foreach ($ids as $row) {
                        $last = (int) $row["id"];
                        fwrite(
                            $file,
                            json_encode(
                                $service->get($last),
                                JSON_THROW_ON_ERROR,
                            ) . "\n",
                        );
                        $count++;
                    }
                } while (count($ids) === 50);
            });
        } finally {
            fclose($file);
        }
        if (!$count) {
            unlink($base . ".jsonl");
            throw new HttpError(422, "No invoices found for this date range.");
        }
        $job = [
            "id" => $id,
            "user_id" => $u["id"],
            "transaction_generation" => $generation,
            "kind" => $kind,
            "total" => $count,
            "done" => 0,
            "offset" => 0,
            "parts" => [],
            "ready" => false,
            "expires" => time() + 3600,
            "from" => $from,
            "to" => $to,
        ];
        file_put_contents($base . ".json", json_encode($job), LOCK_EX);
        AuditLogService::record($u, "Export Requested", null, [
            "job" => $id,
            "kind" => $kind,
            "from" => $from,
            "to" => $to,
            "count" => $count,
        ]);
        return $job;
    }
    public function job(string $id, array $u): array
    {
        $base = $this->path($id);
        $job = is_file($base . ".json")
            ? json_decode(file_get_contents($base . ".json"), true)
            : null;
        if (
            !$job ||
            (int) $job["user_id"] !== (int) $u["id"] ||
            $job["expires"] < time()
        ) {
            throw new HttpError(404, "Export expired or not found.");
        }
        $generation = (int) DB::one("SELECT COALESCE(MAX(id),0) n FROM activity_logs WHERE action='Bulk Transaction Deletion'")["n"];
        if ((int) ($job["transaction_generation"] ?? 0) !== $generation) {
            throw new HttpError(404, "Export was invalidated by transaction-data deletion. Create a new export.");
        }
        Auth::require(
            $u,
            $job["kind"] === "audit" ? "export_audit_report" : "export_invoice",
        );
        return $job;
    }
    public function step(string $id, array $u): array
    {
        $base = $this->path($id);
        $lock = fopen($base . ".lock", "c");
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new HttpError(409, "Export is already processing.");
        }
        try {
            $job = $this->job($id, $u);
            if ($job["ready"]) {
                return $job;
            }
            $fp = fopen($base . ".jsonl", "rb");
            fseek($fp, $job["offset"]);
            $rows = [];
            $size = max(
                1,
                min(20, (int) Config::get("EXPORT_CHUNK_SIZE", "20")),
            );
            $itemCount = 0;
            while (count($rows) < $size) {
                $position = ftell($fp);
                $line = fgets($fp);
                if ($line === false) {
                    break;
                }
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($rows && $itemCount + count($row["items"]) > 200) {
                    fseek($fp, $position);
                    break;
                }
                $rows[] = $row;
                $itemCount += count($row["items"]);
            }
            $job["offset"] = ftell($fp);
            fclose($fp);
            if (in_array($job["kind"], ["csv", "excel"], true)) {
                $out = fopen($base . ".csv", "c+b");
                ftruncate($out, $job["output_bytes"] ?? 0);
                fseek($out, 0, SEEK_END);
                if ($job["done"] === 0) {
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, [
                        "Invoice",
                        "Date",
                        "Customer",
                        "Mobile",
                        "GSTIN",
                        "Products",
                        "Taxable",
                        "CGST",
                        "SGST",
                        "IGST",
                        "GST",
                        "Total INR",
                        "Payment",
                        "Payment Status",
                        "Amount Paid",
                        "Amount Due",
                        "Shipping Charges",
                        "Shipping GST Rate (%)",
                        "Shipping GST (included in total GST)",
                        "Status",
                    ]);
                }
                foreach ($rows as $i) {
                    $values = [
                        $i["invoice_number"],
                        $i["invoice_date"],
                        $i["customer_name"],
                        $i["customer_mobile"],
                        $i["customer_gstin"],
                        implode(
                            "; ",
                            array_column($i["items"], "product_name"),
                        ),
                        $i["taxable"],
                        $i["cgst"],
                        $i["sgst"],
                        $i["igst"],
                        $i["gst"],
                        $i["grand_total"],
                        $i["payment_method"],
                        $i["payment_status"],
                        $i["amount_paid"],
                        $i["amount_due"],
                        $i["shipping_charges"],
                        $i["shipping_gst_rate"],
                        $i["shipping_gst"],
                        $i["status"],
                    ];
                    fputcsv($out, array_map([self::class, "csvSafe"], $values));
                }
                $job["output_bytes"] = ftell($out);
                fclose($out);
            } else {
                $pdf = new PDFService();
                $html =
                    $job["kind"] === "audit"
                        ? $pdf->auditHtml($rows, $job["done"], $job["from"], $job["to"])
                        : implode("", array_map([$pdf, "invoiceHtml"], $rows));
                $part = $base . ".part" . count($job["parts"]) . ".pdf";
                file_put_contents(
                    $part,
                    $pdf->render($html, $job["kind"] === "audit"),
                );
                $job["parts"][] = $part;
            }
            $job["done"] += count($rows);
            if ($job["done"] >= $job["total"]) {
                if ($job["parts"]) {
                    (new PDFService())->merge($job["parts"], $base . ".pdf");
                }
                if ($job["kind"] === "excel") {
                    (new SpreadsheetService())->fromCsv(
                        $base . ".csv",
                        $base . ".xlsx",
                    );
                }
                $job["ready"] = true;
                AuditLogService::record($u, "Invoice Exported", null, [
                    "job" => $id,
                    "count" => $job["total"],
                    "kind" => $job["kind"],
                ]);
            }
            file_put_contents($base . ".json", json_encode($job), LOCK_EX);
            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public function download(string $id, array $u): never
    {
        $job = $this->job($id, $u);
        if (!$job["ready"]) {
            throw new HttpError(409, "Export is still generating.");
        }
        $ext = match ($job["kind"]) {
            "csv" => "csv",
            "excel" => "xlsx",
            default => "pdf",
        };
        $mime = match ($ext) {
            "csv" => "text/csv; charset=utf-8",
            "xlsx"
                => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            default => "application/pdf",
        };
        header("Content-Type: " . $mime);
        header(
            'Content-Disposition: attachment; filename="' .
                $job["kind"] .
                "-" .
                $job["from"] .
                "-" .
                $job["to"] .
                "." .
                $ext .
                '"',
        );
        readfile($this->path($id) . "." . $ext);
        exit();
    }
    public static function csvSafe(mixed $v): string
    {
        $s = (string) $v;
        return preg_match("/^[\s]*[=+@\-]/u", $s) ? "'" . $s : $s;
    }
}
