<?php
declare(strict_types=1);
namespace App;
final class CreditNoteService
{
    public function get(int $id): array
    {
        $credit = DB::one("SELECT c.*,i.invoice_number,i.invoice_date,i.customer_name,i.customer_mobile,i.customer_email,i.customer_gstin,i.billing_address,i.customer_state,i.business_snapshot FROM credit_notes c JOIN invoices i ON i.id=c.invoice_id WHERE c.id=?", [$id]);
        if (!$credit) throw new HttpError(404, "Credit note not found.");
        $credit["credit_note_number"] = (int) DB::one(
            "SELECT COUNT(*) number FROM credit_notes WHERE id<=?",
            [$id],
        )["number"];
        $credit["business"] = json_decode($credit["business_snapshot"], true, 512, JSON_THROW_ON_ERROR);
        $credit["items"] = DB::all("SELECT r.*,it.product_name,it.hsn_sac,it.description,it.unit,it.rate,it.gst_rate FROM sales_returns r JOIN invoice_items it ON it.id=r.invoice_item_id WHERE r.credit_note_id=? ORDER BY it.position", [$id]);
        return $credit;
    }
    public function create(array $d, array $u): array
    {
        return DB::transaction(function () use ($d, $u) {
            $invoice = (new InvoiceService())->get(
                (int) ($d["invoice_id"] ?? 0),
                true,
            );
            if ($invoice["status"] !== "Active") {
                throw new HttpError(409, "Cannot return a cancelled invoice.");
            }
            $date = Validation::date($d["note_date"] ?? date("Y-m-d"));
            if ($date < $invoice["invoice_date"]) {
                throw new HttpError(
                    422,
                    "Return date cannot precede the invoice.",
                );
            }
            $reason = Validation::text(
                $d["reason"] ?? "",
                "return reason",
                500,
                true,
            );
            $settlement = Validation::choice(
                $d["settlement"] ?? "Adjustment",
                ["Refund", "Adjustment"],
                "settlement",
            );
            $ref = Validation::text($d["reference"] ?? "", "reference", 100);
            if (!is_array($d["items"] ?? null) || !$d["items"]) {
                throw new HttpError(422, "Select items and return quantities.");
            }
            $totals = array_fill_keys(
                ["taxable", "cgst", "sgst", "igst", "gst", "total"],
                "0.00",
            );
            $returns = [];
            $seen = [];
            foreach ($d["items"] as $entry) {
                $id = (int) ($entry["invoice_item_id"] ?? 0);
                if (isset($seen[$id])) {
                    throw new HttpError(422, "Duplicate return item.");
                }
                $seen[$id] = true;
                $item = null;
                foreach ($invoice["items"] as $row) {
                    if ((int) $row["id"] === $id) {
                        $item = $row;
                    }
                }
                if (!$item) {
                    throw new HttpError(
                        422,
                        "Return item does not belong to this invoice.",
                    );
                }
                $qty = Money::number(
                    $entry["quantity"] ?? "0",
                    "return quantity",
                    3,
                    "999999",
                );
                if (bccomp($qty, "0", 3) <= 0) {
                    throw new HttpError(
                        422,
                        "Return quantity must be positive.",
                    );
                }
                $previous = DB::one(
                    "SELECT COALESCE(SUM(quantity),0) quantity,COALESCE(SUM(taxable),0) taxable,COALESCE(SUM(cgst),0) cgst,COALESCE(SUM(sgst),0) sgst,COALESCE(SUM(igst),0) igst FROM sales_returns WHERE invoice_item_id=?",
                    [$id],
                );
                $remaining = bcsub($item["quantity"], $previous["quantity"], 3);
                if (bccomp($qty, $remaining, 3) > 0) {
                    throw new HttpError(
                        422,
                        "Return exceeds the unreturned quantity.",
                    );
                }
                $r = ["invoice_item_id" => $id, "quantity" => $qty];
                $returnedToDate = bcadd($previous["quantity"], $qty, 3);
                foreach (["taxable", "cgst", "sgst", "igst"] as $key) {
                    $target =
                        bccomp($qty, $remaining, 3) === 0
                            ? $item[$key]
                            : Money::round(
                                bcdiv(
                                    bcmul($item[$key], $returnedToDate, 8),
                                    $item["quantity"],
                                    8,
                                ),
                            );
                    $r[$key] = bcsub($target, $previous[$key], 2);
                }
                $r["gst"] = bcadd(
                    bcadd($r["cgst"], $r["sgst"], 2),
                    $r["igst"],
                    2,
                );
                $r["total"] = bcadd($r["taxable"], $r["gst"], 2);
                foreach ($totals as $k => $v) {
                    $totals[$k] = bcadd($v, $r[$k], 2);
                }
                $returns[] = $r;
            }
            $shippingTotal = bcadd($invoice["shipping_charges"], $invoice["shipping_gst"], 2);
            $original = bcsub(bcadd($invoice["taxable"], $invoice["gst"], 2), $shippingTotal, 2);
            $goodsGrand = bccomp($shippingTotal, "0", 2) > 0 ? $original : $invoice["grand_total"];
            $prior = DB::one(
                "SELECT COALESCE(SUM(total),0) total,COALESCE(SUM(taxable+gst),0) base FROM credit_notes WHERE invoice_id=?",
                [$invoice["id"]],
            );
            $cumulative = bcadd($prior["base"], $totals["total"], 2);
            $cumulativeRounded =
                bccomp($original, "0", 2) === 0
                    ? "0.00"
                    : Money::round(
                        bcdiv(
                            bcmul($goodsGrand, $cumulative, 8),
                            $original,
                            8,
                        ),
                    );
            $adjusted = bcsub($cumulativeRounded, $prior["total"], 2);
            $roundOff = bcsub($adjusted, $totals["total"], 2);
            $totals["total"] = $adjusted;
            DB::run(
                "INSERT INTO credit_notes(invoice_id,note_date,reason,settlement,reference,taxable,cgst,sgst,igst,gst,total,round_off,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $invoice["id"],
                    $date,
                    $reason,
                    $settlement,
                    $ref,
                    ...array_values($totals),
                    $roundOff,
                    $u["id"],
                ],
            );
            $id = (int) DB::connection()->lastInsertId();
            $number = (int) DB::one(
                "SELECT COUNT(*) number FROM credit_notes WHERE id<=?",
                [$id],
            )["number"];
            foreach ($returns as $r) {
                DB::run(
                    "INSERT INTO sales_returns(credit_note_id,invoice_item_id,quantity,taxable,cgst,sgst,igst,gst,total) VALUES(?,?,?,?,?,?,?,?,?)",
                    [$id, ...array_values($r)],
                );
            }
            AuditLogService::record(
                $u,
                "Credit Note Created",
                (int) $invoice["id"],
                [
                    "credit_note" => $id,
                    "totals" => $totals,
                    "reason" => $reason,
                ],
            );
            return ["id" => $id, "number" => "CN-" . $number, "totals" => $totals];
        });
    }
}
