<?php
declare(strict_types=1);
namespace App;
final class PDFService
{
    public function invoiceHtml(array $invoice): string
    {
        $invoice["amount_words"] = $this->amountWords($invoice["grand_total"]);
        $invoice["tax_words"] = $this->amountWords($invoice["gst"]);
        $invoice["tax_summary"] = [];
        foreach ($invoice["items"] as $item) {
            $key = ($item["hsn_sac"] ?: "Unspecified") . "|" . $item["gst_rate"];
            $invoice["tax_summary"][$key] ??= ["hsn_sac" => $item["hsn_sac"] ?: "Unspecified", "gst_rate" => $item["gst_rate"], "taxable" => "0.00", "cgst" => "0.00", "sgst" => "0.00", "igst" => "0.00", "gst" => "0.00"];
            foreach (["taxable", "cgst", "sgst", "igst", "gst"] as $field) $invoice["tax_summary"][$key][$field] = bcadd($invoice["tax_summary"][$key][$field], $item[$field], 2);
        }
        if (bccomp($invoice["shipping_charges"], "0", 2) > 0) {
            $cgst = $sgst = $igst = "0.00";
            if ($invoice["place_of_supply"] === $invoice["business"]["state"]) {
                $cgst = Money::round(bcdiv($invoice["shipping_gst"], "2", 8));
                $sgst = bcsub($invoice["shipping_gst"], $cgst, 2);
            } else {
                $igst = $invoice["shipping_gst"];
            }
            $invoice["tax_summary"]["Shipping|" . $invoice["shipping_gst_rate"]] = ["hsn_sac" => "Shipping", "gst_rate" => $invoice["shipping_gst_rate"], "taxable" => $invoice["shipping_charges"], "cgst" => $cgst, "sgst" => $sgst, "igst" => $igst, "gst" => $invoice["shipping_gst"]];
        }
        ob_start();
        require ROOT . "/templates/invoice.php";
        return ob_get_clean();
    }
    public function creditNoteHtml(array $credit): string
    {
        $credit["amount_words"] = $this->amountWords($credit["total"]);
        $credit["financial_year"] = DocumentData::financialYear($credit["note_date"]);
        if (!$credit["financial_year"]) {
            $year = (int) substr($credit["note_date"], 0, 4);
            $start = (int) substr($credit["note_date"], 5, 2) < 4 ? $year - 1 : $year;
            $credit["financial_year"] = $start . "-" . substr((string) ($start + 1), -2);
        }
        ob_start();
        require ROOT . "/templates/credit-note.php";
        return ob_get_clean();
    }
    public function receiptHtml(array $receipt): string
    {
        $business = $receipt["business"] ?? SettingsService::business();
        $previous = bcadd($receipt["amount"], $receipt["balance_after"], 2);
        $allocations = implode(", ", array_map(fn($row) => $row["method"] . " INR " . $row["amount"] . ($row["reference"] ? " (" . $row["reference"] . ")" : ""), $receipt["allocations"]));
        return '<article class="invoice-document receipt-document"><header class="invoice-header"><div><h1>' . e($business["name"]) . '</h1><div>' . nl2br(e($business["address"])) . '</div></div><div class="invoice-number"><h2>CREDIT CLEARANCE RECEIPT</h2><strong>' . e($receipt["receipt_number"]) . '</strong><p>' . e($receipt["transaction_date"]) . '</p></div></header><table class="parties bordered"><tr><td><b>Customer</b><br>' . e($receipt["customer_name"]) . '<br>' . e($receipt["customer_mobile"]) . '<br>' . nl2br(e($receipt["customer_address"])) . '</td><td><b>Payment</b><br>' . e($allocations) . '</td></tr></table><table class="totals-table bordered"><tr><td>Previous credit account</td><td class="num">' . e($previous) . '</td></tr><tr><td>Clear amount</td><td class="num">' . e($receipt["amount"]) . '</td></tr><tr class="grand"><td>Balance credit amount</td><td class="num">' . e($receipt["balance_after"]) . '</td></tr></table><p>Received by ' . e($receipt["created_by_name"]) . '.</p><footer class="invoice-footer">This is a computer generated credit clearance receipt.</footer></article>';
    }
    public function render(string $html, bool $landscape = false): string
    {
        require_once ROOT . "/vendor/autoload.php";
        $options = new \Dompdf\Options();
        $options->set("isRemoteEnabled", false);
        $options->set("isPhpEnabled", false);
        $options->set("chroot", ROOT . "/storage/uploads");
        $options->set("tempDir", ROOT . "/storage/generated");
        $options->set("fontCache", ROOT . "/storage/generated");
        $options->set("defaultFont", "DejaVu Sans");
        $options->set("defaultMediaType", "print");
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper("A4", $landscape ? "landscape" : "portrait");
        $pdf->loadHtml(
            '<!doctype html><html><head><meta charset="utf-8"><style>' .
                file_get_contents(ROOT . "/public/assets/invoice.css") .
                ($landscape ? "@page{size:A4 landscape}" : "") .
                "</style></head><body>" .
                $html .
                "</body></html>",
        );
        $pdf->render();
        return $pdf->output();
    }
    public function auditHtml(array $invoices, int $offset = 0, string $from = "", string $to = ""): string
    {
        $business = SettingsService::business();
        $logo = $business["logo"] && preg_match('/^[a-f0-9]{40}\.png$/D', $business["logo"]) && is_file(ROOT . "/storage/uploads/" . $business["logo"])
            ? '<img class="business-logo" src="data:image/png;base64,' . base64_encode(file_get_contents(ROOT . "/storage/uploads/" . $business["logo"])) . '" alt="Business logo">'
            : '';
        $html = '<header class="audit-header">' . $logo . '<h2>' . e($business["name"]) . '</h2><p>Invoice audit report · ' . e($from) . ' to ' . e($to) . '</p></header><table class="audit-table"><thead><tr>';
        foreach (
            [
                "#",
                "Date",
                "Invoice no.",
                "Customer name",
                "Mobile",
                "GSTIN",
                "Products",
                "Total INR",
                "Taxable",
                "CGST",
                "SGST",
                "IGST",
                "GST",
                "Shipping",
                "Payment methods",
                "Payment status",
                "Paid",
                "Due",
                "Created by",
                "Last updated by",
            ]
            as $c
        ) {
            $html .= "<th>" . e($c) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        foreach ($invoices as $i) {
            ++$offset;
            foreach (array_chunk($i["items"], 2) as $part => $items) {
                $html .= "<tr>";
                foreach (
                    [
                        $part === 0 ? $offset : "cont.",
                        $i["invoice_date"],
                        $i["invoice_number"],
                        $i["customer_name"] ?: "Walk-in",
                        $i["customer_mobile"],
                        $i["customer_gstin"],
                        implode(
                            "; ",
                            array_map(
                                fn($r) => $r["product_name"] .
                                    " × " .
                                    $r["quantity"] .
                                    " " .
                                    $r["unit"],
                                $items,
                            ),
                        ),
                        $part === 0 ? $i["grand_total"] : "",
                        $part === 0 ? $i["taxable"] : "",
                        $part === 0 ? $i["cgst"] : "",
                        $part === 0 ? $i["sgst"] : "",
                        $part === 0 ? $i["igst"] : "",
                        $part === 0 ? $i["gst"] : "",
                        $part === 0 ? $i["shipping_charges"] : "",
                        $part === 0 ? implode(" + ", array_column($i["payment_allocations"], "method")) : "",
                        $part === 0 ? ($i["status"] === "Cancelled" ? "Cancelled" : $i["payment_status"]) : "",
                        $part === 0 ? $i["amount_paid"] : "",
                        $part === 0 ? $i["amount_due"] : "",
                        $part === 0 ? ($i["created_by_name"] ?? "") : "",
                        $part === 0 ? ($i["updated_by_name"] ?? "") : "",
                    ]
                    as $v
                ) {
                    $html .= "<td>" . e($v) . "</td>";
                }
                $html .= "</tr>";
            }
        }
        return $html . "</tbody></table>";
    }
    public function amountWords(string $amount): string
    {
        [$whole, $paise] = array_pad(explode(".", Money::round($amount), 2), 2, "00");
        $n = (int) $whole;
        $words = $n === 0 ? "Zero" : $this->integerWords($n);
        return $words . " Rupees" . ((int) $paise ? " and " . $this->integerWords((int) $paise) . " Paise" : "") . " Only";
    }
    private function integerWords(int $n): string
    {
        $ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
        $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
        if ($n < 20) return $ones[$n];
        if ($n < 100) return trim($tens[intdiv($n, 10)] . " " . $ones[$n % 10]);
        if ($n < 1000) return trim($ones[intdiv($n, 100)] . " Hundred " . $this->integerWords($n % 100));
        foreach ([[10000000, "Crore"], [100000, "Lakh"], [1000, "Thousand"]] as [$value, $label]) if ($n >= $value) return trim($this->integerWords(intdiv($n, $value)) . " " . $label . " " . $this->integerWords($n % $value));
        return (string) $n;
    }
    public function merge(array $files, string $target): void
    {
        require_once ROOT . "/vendor/autoload.php";
        $pdf = new \setasign\Fpdi\Fpdi();
        foreach ($files as $file) {
            $count = $pdf->setSourceFile($file);
            for ($page = 1; $page <= $count; $page++) {
                $tpl = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size["orientation"], [
                    $size["width"],
                    $size["height"],
                ]);
                $pdf->useTemplate($tpl);
            }
        }
        $pdf->Output("F", $target);
    }
}
