<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
use App\{GSTCalculationService, Money, Validation, HttpError, ExportService};
$passed = 0;
function check(bool $condition, string $name): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $name);
    }
    $passed++;
    echo "PASS $name\n";
}
function rejects(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (HttpError $e) {
        check(true, $name);
        return;
    }
    throw new RuntimeException("FAIL: " . $name);
}
$engine = new GSTCalculationService();
$settings = ["gst_max" => "100", "round_to_rupee" => true];
$item = [
    "product_name" => "Consulting",
    "quantity" => "2",
    "rate" => "100",
    "discount" => "10",
    "gst_rate" => "18",
];
$r = $engine->calculate([$item], "29", "29", $settings);
check($r["totals"]["taxable"] === "190.00", "discount before GST");
check(
    $r["totals"]["cgst"] === "17.10" && $r["totals"]["sgst"] === "17.10",
    "intra-state split",
);
check(
    $r["totals"]["grand_total"] === "224.00" &&
        $r["totals"]["round_off"] === "-0.20",
    "whole-rupee round off",
);
$r = $engine->calculate([$item], "29", "27", $settings);
check(
    $r["totals"]["igst"] === "34.20" && $r["totals"]["cgst"] === "0.00",
    "inter-state IGST",
);
foreach (
    [
        "0" => "0.00",
        "5" => "5.00",
        "12" => "12.00",
        "18" => "18.00",
        "28" => "28.00",
        "3" => "3.00",
    ]
    as $rate => $expected
) {
    $r = $engine->calculate(
        [
            array_merge($item, [
                "quantity" => "1",
                "rate" => "100",
                "discount" => "0",
                "gst_rate" => (string) $rate,
            ]),
        ],
        "29",
        "27",
        $settings,
    );
    check($r["totals"]["gst"] === $expected, "GST rate " . $rate);
}
$r = $engine->calculate(
    [
        array_merge($item, [
            "quantity" => "0.125",
            "rate" => "8",
            "discount" => "0",
            "gst_rate" => "5",
        ]),
    ],
    "29",
    "29",
    $settings,
);
check(
    $r["totals"]["cgst"] === "0.03" && $r["totals"]["sgst"] === "0.02",
    "odd GST paise reconcile",
);
$r = $engine->calculate([$item, $item], "29", "29", $settings);
check($r["totals"]["gst"] === "68.40", "multiple items aggregate");
check(
    Money::round("1.005") === "1.01" && Money::round("-1.005") === "-1.01",
    "half-up positive and negative",
);
foreach (
    [
        ["quantity" => "0"],
        ["quantity" => "-1"],
        ["rate" => "-1"],
        ["discount" => "500"],
        ["gst_rate" => "101"],
        ["rate" => "1e3"],
        ["quantity" => 1.5],
    ]
    as $bad
) {
    rejects(
        fn() => $engine->calculate(
            [array_merge($item, $bad)],
            "29",
            "29",
            $settings,
        ),
        "reject invalid financial value " . json_encode($bad),
    );
}
rejects(
    fn() => $engine->calculate([], "29", "29", $settings),
    "reject empty items",
);
check(
    Validation::gstin("29ABCDE1234F1Z5") === "29ABCDE1234F1Z5",
    "GSTIN format",
);
rejects(fn() => Validation::gstin("malformed"), "reject GSTIN");
check(Validation::gstin("") === "", "optional GSTIN");
rejects(fn() => Validation::date("2026-02-30"), "reject impossible date");
rejects(
    fn() => Validation::range(["from" => "2026-09-10", "to" => "2026-09-01"]),
    "reject reversed range",
);
check(
    ExportService::csvSafe('=HYPERLINK("x")') === '\'=HYPERLINK("x")',
    "spreadsheet formula protection",
);
echo "\n$passed checks passed.\n";
