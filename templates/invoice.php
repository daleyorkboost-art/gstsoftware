<?php /** @var array $invoice */ $b = $invoice["business"]; ?>
<article class="invoice-document">
<header class="invoice-header"><div><?php if (
    !empty($b["logo"]) &&
    preg_match('/^[a-f0-9]{40}\.png$/D', $b["logo"]) &&
    is_file(ROOT . "/storage/uploads/" . $b["logo"])
): ?><img class="business-logo" src="data:image/png;base64,<?= base64_encode(
    file_get_contents(ROOT . "/storage/uploads/" . $b["logo"]),
) ?>" alt="Business logo"><?php endif; ?><h1><?= e(
    $b["name"],
) ?></h1><div><?= nl2br(e($b["address"])) ?></div><div>GSTIN: <?= e(
    $b["gstin"] ?: "Not provided",
) ?> · State: <?= e(
     $b["state"],
 ) ?></div></div><div class="invoice-number"><h2>TAX INVOICE</h2><strong><?= e(
    $invoice["invoice_number"],
) ?></strong><p><?= e($invoice["invoice_date"]) ?></p><b><?= e(
    $invoice["status"],
) ?></b></div></header>
<table class="parties"><tr><td><span class="eyebrow">BILL TO</span><h3><?= e(
    $invoice["customer_name"] ?: "Walk-in customer",
) ?></h3><?= nl2br(e($invoice["billing_address"])) ?><br><?= e(
    $invoice["customer_mobile"],
) ?><br>GSTIN: <?= e($invoice["customer_gstin"] ?: "—") ?><br>State: <?= e(
    $invoice["customer_state"] ?: "—",
) ?> · <?= e(
     $invoice["customer_type"],
 ) ?></td><td><span class="eyebrow">SHIP TO</span><p><?= nl2br(
    e($invoice["shipping_address"] ?: $invoice["billing_address"]),
) ?></p>Place of supply: <?= e(
    $invoice["place_of_supply"],
) ?><br>Transporter: <?= e($invoice["transporter"]) ?><br>Vehicle: <?= e(
    $invoice["vehicle_number"],
) ?></td></tr></table>
<table class="invoice-items"><thead><tr><th>#</th><th>Item / HSN / Description</th><th>Qty / unit</th><th>Rate</th><th>Discount</th><th>Taxable</th><th>GST %</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Total</th></tr></thead><tbody><?php foreach (
    $invoice["items"]
    as $n => $item
): ?><tr><td><?= $n + 1 ?></td><td><b><?= e(
    $item["product_name"],
) ?></b><br><?= e($item["hsn_sac"]) ?><br><?= nl2br(
    e($item["description"]),
) ?></td><td><?= e($item["quantity"]) ?><br><?= e(
    $item["unit"],
) ?></td><?php foreach (
    ["rate", "discount", "taxable", "gst_rate", "cgst", "sgst", "igst", "total"]
    as $key
): ?><td class="num"><?= e(
    $item[$key],
) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
<table class="invoice-bottom"><tr><td class="bank"><h3>Payment & bank details</h3><p><?= e(
    $invoice["payment_method"],
) ?> · Reference: <?= e($invoice["payment_reference"] ?: "—") ?></p><?= e(
    $b["bank_name"],
) ?><br>Account: <?= e($b["account_number"]) ?><br>IFSC: <?= e(
    $b["ifsc"],
) ?> · <?= e($b["branch"]) ?><h3>Terms & notes</h3><?= nl2br(
    e($invoice["notes"]),
) ?></td><td><table class="totals-table"><?php foreach (
    [
        "subtotal" => "Subtotal",
        "discount" => "Discount",
        "taxable" => "Taxable amount",
        "cgst" => "CGST",
        "sgst" => "SGST",
        "igst" => "IGST",
        "gst" => "Total GST",
        "round_off" => "Round-off",
        "grand_total" => "Grand total (INR)",
    ]
    as $key => $label
): ?><tr class="<?= $key === "grand_total"
    ? "grand"
    : "" ?>"><td><?= $label ?></td><td class="num"><?= e(
    $invoice[$key],
) ?></td></tr><?php endforeach; ?></table></td></tr></table>
<footer class="invoice-footer"><span>Amounts in INR. GSTIN format is not registration verification.</span><div class="signature">For <?= e(
    $b["name"],
) ?><br><br><br>Authorized signature</div></footer>
</article>
