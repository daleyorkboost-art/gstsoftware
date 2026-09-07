<?php
declare(strict_types=1);
namespace App;
final class GSTCalculationService
{
    public function calculate(
        array $items,
        string $sellerState,
        string $placeOfSupply,
        array $settings,
    ): array {
        if (!$items || count($items) > 200) {
            throw new HttpError(
                422,
                "An invoice needs between 1 and 200 items.",
            );
        }
        $totals = array_fill_keys(
            [
                "subtotal",
                "discount",
                "taxable",
                "cgst",
                "sgst",
                "igst",
                "gst",
                "total",
            ],
            "0.00",
        );
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new HttpError(422, "Invalid item.");
            }
            $row = [];
            foreach (
                [
                    "product_name" => 160,
                    "hsn_sac" => 12,
                    "description" => 500,
                    "unit" => 20,
                ]
                as $key => $max
            ) {
                $row[$key] = Validation::text(
                    $item[$key] ?? "",
                    $key,
                    $max,
                    $key === "product_name",
                );
            }
            $row["quantity"] = Money::number(
                $item["quantity"] ?? "1",
                "quantity",
                3,
                "999999",
            );
            if (bccomp($row["quantity"], "0", 3) <= 0) {
                throw new HttpError(422, "Quantity must be greater than zero.");
            }
            $row["rate"] = Money::number($item["rate"] ?? "0", "rate");
            $row["discount"] = Money::number(
                $item["discount"] ?? "0",
                "discount",
            );
            $row["gst_rate"] = Money::number(
                $item["gst_rate"] ?? "0",
                "GST rate",
                2,
                (string) $settings["gst_max"],
            );
            $row["gross"] = Money::round(
                bcmul($row["quantity"], $row["rate"], 8),
            );
            if (bccomp($row["discount"], $row["gross"], 2) > 0) {
                throw new HttpError(
                    422,
                    "Discount cannot exceed the item gross amount.",
                );
            }
            $row["taxable"] = bcsub($row["gross"], $row["discount"], 2);
            $row["gst"] = Money::round(
                bcdiv(bcmul($row["taxable"], $row["gst_rate"], 8), "100", 8),
            );
            $row["cgst"] = $row["sgst"] = $row["igst"] = "0.00";
            if ($sellerState === $placeOfSupply) {
                $row["cgst"] = Money::round(bcdiv($row["gst"], "2", 8));
                $row["sgst"] = bcsub($row["gst"], $row["cgst"], 2);
            } else {
                $row["igst"] = $row["gst"];
            }
            $row["total"] = bcadd($row["taxable"], $row["gst"], 2);
            foreach ($totals as $key => $v) {
                $totals[$key] = bcadd(
                    $v,
                    $row[$key === "subtotal" ? "gross" : $key],
                    2,
                );
            }
            $rows[] = $row;
        }
        if (bccomp($totals["total"], "999999999999999.00", 2) > 0) {
            throw new HttpError(
                422,
                "Invoice total exceeds the supported accounting limit.",
            );
        }
        $grand =
            $settings["round_to_rupee"] ?? true
                ? Money::round($totals["total"], 0) . ".00"
                : $totals["total"];
        $totals["round_off"] = bcsub($grand, $totals["total"], 2);
        $totals["grand_total"] = $grand;
        unset($totals["total"]);
        return ["items" => $rows, "totals" => $totals];
    }
}
