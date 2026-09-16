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
        string $shippingCharges = "0",
        string $gstMode = "Exclude",
    ): array {
        $gstMode = Validation::choice($gstMode, ["Include", "Exclude"], "GST price mode");
        if (!$items || count($items) > 200) {
            throw new HttpError(
                422,
                "An invoice needs between 1 and 200 items.",
            );
        }
        // Normalize and validate both state codes once. A different place of
        // supply is always an inter-state transaction and must use IGST only.
        $sellerState = Validation::state($sellerState);
        $placeOfSupply = Validation::state($placeOfSupply);
        $isInterState = $sellerState !== $placeOfSupply;
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
            $row["entered_rate"] = Money::number($item["rate"] ?? "0", "rate");
            $row["discount_mode"] = Validation::choice(
                $item["discount_mode"] ?? "Flat",
                ["Flat", "Percent"],
                "discount mode",
            );
            $row["discount_value"] = Money::number(
                $item["discount_value"] ?? $item["discount"] ?? "0",
                "discount",
                2,
                $row["discount_mode"] === "Percent" ? "100" : "999999999999999",
            );
            $row["gst_rate"] = Money::number(
                $item["gst_rate"] ?? "0",
                "GST rate",
                2,
                (string) $settings["gst_max"],
            );
            $enteredGross = Money::round(bcmul($row["quantity"], $row["entered_rate"], 8));
            $enteredDiscount =
                $row["discount_mode"] === "Percent"
                    ? Money::round(
                        bcdiv(
                            bcmul($enteredGross, $row["discount_value"], 8),
                            "100",
                            8,
                        ),
                    )
                    : $row["discount_value"];
            if (bccomp($enteredDiscount, $enteredGross, 2) > 0) {
                throw new HttpError(
                    422,
                    "Discount cannot exceed the item gross amount.",
                );
            }
            if ($gstMode === "Include") {
                $factor = bcadd("1", bcdiv($row["gst_rate"], "100", 8), 8);
                $row["rate"] = Money::round(bcdiv($row["entered_rate"], $factor, 8));
                $row["gross"] = Money::round(bcdiv($enteredGross, $factor, 8));
                $row["total"] = bcsub($enteredGross, $enteredDiscount, 2);
                $row["taxable"] = Money::round(bcdiv($row["total"], $factor, 8));
                $row["discount"] = bcsub($row["gross"], $row["taxable"], 2);
                $row["gst"] = bcsub($row["total"], $row["taxable"], 2);
            } else {
                $row["rate"] = $row["entered_rate"];
                $row["gross"] = $enteredGross;
                $row["discount"] = $enteredDiscount;
                $row["taxable"] = bcsub($row["gross"], $row["discount"], 2);
                $row["gst"] = Money::round(
                    bcdiv(bcmul($row["taxable"], $row["gst_rate"], 8), "100", 8),
                );
                $row["total"] = bcadd($row["taxable"], $row["gst"], 2);
            }
            $row["cgst"] = $row["sgst"] = $row["igst"] = "0.00";
            if (!$isInterState) {
                $row["cgst"] = Money::round(bcdiv($row["gst"], "2", 8));
                $row["sgst"] = bcsub($row["gst"], $row["cgst"], 2);
            } else {
                $row["igst"] = $row["gst"];
            }
            foreach ($totals as $key => $v) {
                $totals[$key] = bcadd(
                    $v,
                    $row[$key === "subtotal" ? "gross" : $key],
                    2,
                );
            }
            $rows[] = $row;
        }
        $shipping = Money::number(
            $shippingCharges,
            "shipping charges",
            2,
            "999999999999999",
        );
        $shippingRate = Money::number(
            $settings["shipping_gst_rate"] ?? "0",
            "shipping GST rate",
            2,
            (string) $settings["gst_max"],
        );
        $shippingGst = Money::round(
            bcdiv(bcmul($shipping, $shippingRate, 8), "100", 8),
        );
        $shippingCgst = $shippingSgst = $shippingIgst = "0.00";
        if (!$isInterState) {
            $shippingCgst = Money::round(bcdiv($shippingGst, "2", 8));
            $shippingSgst = bcsub($shippingGst, $shippingCgst, 2);
        } else {
            $shippingIgst = $shippingGst;
        }
        $totals["shipping_charges"] = $shipping;
        $totals["shipping_gst_rate"] = $shippingRate;
        $totals["shipping_gst"] = $shippingGst;
        $totals["taxable"] = bcadd($totals["taxable"], $shipping, 2);
        $totals["cgst"] = bcadd($totals["cgst"], $shippingCgst, 2);
        $totals["sgst"] = bcadd($totals["sgst"], $shippingSgst, 2);
        $totals["igst"] = bcadd($totals["igst"], $shippingIgst, 2);
        $totals["gst"] = bcadd($totals["gst"], $shippingGst, 2);
        $totals["total"] = bcadd($totals["total"], bcadd($shipping, $shippingGst, 2), 2);
        if (bccomp($totals["total"], "999999999999999.00", 2) > 0) {
            throw new HttpError(
                422,
                "Invoice total exceeds the supported accounting limit.",
            );
        }
        $grand =
            ($gstMode === "Exclude" && ($settings["round_to_rupee"] ?? true))
                ? Money::round($totals["total"], 0) . ".00"
                : $totals["total"];
        $totals["round_off"] = bcsub($grand, $totals["total"], 2);
        $totals["grand_total"] = $grand;
        unset($totals["total"]);
        return ["items" => $rows, "totals" => $totals];
    }
}
