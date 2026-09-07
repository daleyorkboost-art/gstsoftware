<?php
declare(strict_types=1);
namespace App;
final class PDFService
{
    public function invoiceHtml(array $invoice): string
    {
        ob_start();
        require ROOT . "/templates/invoice.php";
        return ob_get_clean();
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
    public function auditHtml(array $invoices, int $offset = 0): string
    {
        $html =
            '<h2>Invoice audit report</h2><table class="audit-table"><thead><tr>';
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
                "Payment / Status",
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
                        $i["payment_method"] . " / " . $i["status"],
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
