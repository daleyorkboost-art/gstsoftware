<?php
$b = $credit["business"];
$asset = function (string $field) use ($b): string {
    $name = $b[$field] ?? "";
    return $name && preg_match('/^[a-f0-9]{40}\.png$/D', $name) && is_file(ROOT . "/storage/uploads/" . $name)
        ? "data:image/png;base64," . base64_encode(file_get_contents(ROOT . "/storage/uploads/" . $name))
        : "";
};
$logo = $asset("logo");
$signature = $asset("signature");
?>
<article class="invoice-document">
<header class="invoice-header">
  <div class="business-identity">
    <?php if ($logo): ?><div class="business-logo-wrap"><img class="business-logo" src="<?= e($logo) ?>" alt="Business logo"></div><?php endif; ?>
    <div class="business-information"><h1><?= e($b["name"]) ?></h1><div><?= nl2br(e($b["address"])) ?></div><div>GSTIN: <?= e($b["gstin"]) ?> · <?= e($b["email"] ?? "") ?></div></div>
  </div>
  <div class="invoice-number"><h2>CREDIT NOTE</h2><strong>CN-<?= e($credit["credit_note_number"]) ?></strong><p><?= e($credit["note_date"]) ?></p></div>
</header>
<table class="document-details bordered"><tr><td>Buyer reference<br><b><?= e($credit["invoice_number"]) ?></b></td><td>Original invoice date<br><b><?= e($credit["invoice_date"]) ?></b></td><td>Other reference<br><b><?= e($credit["reference"]) ?></b></td></tr></table>
<table class="parties bordered"><tr><td><b>Buyer</b><br><?= e($credit["customer_name"]) ?><br><?= nl2br(e($credit["billing_address"])) ?><br><?= e($credit["customer_mobile"]) ?> · <?= e($credit["customer_email"]) ?><br>State: <?= e(\App\DocumentData::stateName($credit["customer_state"])) ?> · Code: <?= e($credit["customer_state"]) ?> · GSTIN: <?= e($credit["customer_gstin"]) ?></td><td><b>Reason</b><br><?= nl2br(e($credit["reason"])) ?><br>Settlement: <?= e($credit["settlement"]) ?></td></tr></table>
<table class="invoice-items bordered"><thead><tr><th>Sl.</th><th>Description of Goods</th><th>HSN/SAC</th><th>Quantity</th><th>Rate</th><th>Unit</th><th>GST %</th><th>Amount</th></tr></thead><tbody><?php foreach ($credit["items"] as $n => $item): ?><tr><td><?= $n + 1 ?></td><td><?= e($item["product_name"]) ?><br><?= e($item["description"]) ?></td><td><?= e($item["hsn_sac"]) ?></td><td><?= e($item["quantity"]) ?></td><td><?= e($item["rate"]) ?></td><td><?= e($item["unit"]) ?></td><td><?= e($item["gst_rate"]) ?></td><td class="num"><?= e($item["total"]) ?></td></tr><?php endforeach; ?></tbody></table>
<table class="invoice-bottom"><tr><td><b>Total Amount in Words:</b><br><?= e($credit["amount_words"]) ?></td><td><table class="totals-table bordered"><tr><td>Taxable</td><td class="num"><?= e($credit["taxable"]) ?></td></tr><tr><td>Total GST</td><td class="num"><?= e($credit["gst"]) ?></td></tr><tr class="grand"><td>Total Amount</td><td class="num"><?= e($credit["total"]) ?></td></tr></table></td></tr></table>
<table class="authorization"><tr><td></td><td class="signature">For <?= e($b["name"]) ?> <?= e($credit["financial_year"]) ?><br><?php if ($signature): ?><img src="<?= e($signature) ?>" alt="Authorized signature"><?php else: ?><br><br><?php endif; ?><br>Authorised Signatory</td></tr></table>
<footer class="invoice-footer">This is a computer generated credit note</footer>
</article>
