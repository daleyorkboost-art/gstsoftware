<?php
declare(strict_types=1);
namespace App;
final class ReportService
{
    public function dashboard(array $q): array
    {
        [$from, $to] = Validation::range($q);
        $data = DB::one(
            "SELECT COUNT(*) invoice_count,COALESCE(SUM(status='Cancelled'),0) cancelled,COALESCE(SUM(IF(status='Active',grand_total,0)),0) sales,COALESCE(SUM(IF(status='Active',gst,0)),0) gst,COALESCE(SUM(IF(status='Active' AND credit_bill=1,amount_due,0)),0) credit_sales,COALESCE(SUM(status='Active' AND payment_status IN ('Paid','Credit Cleared')),0) paid_invoices,COALESCE(SUM(status='Active' AND payment_status IN ('Partially Paid','Due')),0) due_invoices FROM invoices WHERE invoice_date BETWEEN ? AND ?",
            [$from, $to],
        );
        $data["today_sales"] = DB::one(
            "SELECT COALESCE(SUM(grand_total),0) n FROM invoices WHERE status='Active' AND invoice_date=?",
            [date("Y-m-d")],
        )["n"];
        $creditLedger = DB::one("SELECT COALESCE(SUM(balance),0) outstanding,COALESCE(SUM(balance>0),0) pending_customers FROM credit_accounts");
        $data["outstanding_credit"] = $creditLedger["outstanding"];
        $data["pending_credit_customers"] = $creditLedger["pending_customers"];
        $credits = DB::one(
            "SELECT COALESCE(SUM(total),0) total,COALESCE(SUM(gst),0) gst FROM credit_notes WHERE note_date BETWEEN ? AND ?",
            [$from, $to],
        );
        $data["returns"] = $credits["total"];
        $data["net_sales"] = bcsub($data["sales"], $credits["total"], 2);
        $data["net_gst"] = bcsub($data["gst"], $credits["gst"], 2);
        $data["recent"] = (new InvoiceService())->list([
            "from" => $from,
            "to" => $to,
        ])["rows"];
        return $data;
    }
    public function report(array $q): array
    {
        [$from, $to] = Validation::range($q);
        $type = Validation::choice(
            $q["type"] ?? "daily",
            ["daily", "monthly", "customer", "gst", "hsn", "credit"],
            "report",
        );
        if ($type === "credit") {
            return [
                "rows" => DB::all(
                    "SELECT c.id,(SELECT COUNT(*) FROM credit_notes cn_seq WHERE cn_seq.id<=c.id) credit_note_number,c.note_date,i.invoice_number,c.reason,c.settlement,c.reference,c.taxable,c.cgst,c.sgst,c.igst,c.gst,c.total FROM credit_notes c JOIN invoices i ON i.id=c.invoice_id WHERE c.note_date BETWEEN ? AND ? ORDER BY c.note_date,c.id",
                    [$from, $to],
                ),
            ];
        }
        if ($type === "hsn") {
            $rows = DB::all(
                "SELECT label,SUM(taxable) taxable,SUM(cgst) cgst,SUM(sgst) sgst,SUM(igst) igst,SUM(gst) gst,SUM(total) total FROM (SELECT COALESCE(NULLIF(it.hsn_sac,''),'Unspecified') label,it.taxable,it.cgst,it.sgst,it.igst,it.gst,it.total FROM invoice_items it JOIN invoices i ON i.id=it.invoice_id WHERE i.status='Active' AND i.invoice_date BETWEEN ? AND ? UNION ALL SELECT 'Shipping',i.shipping_charges,IF(i.place_of_supply=JSON_UNQUOTE(JSON_EXTRACT(i.business_snapshot,'$.state')),ROUND(i.shipping_gst/2,2),0),IF(i.place_of_supply=JSON_UNQUOTE(JSON_EXTRACT(i.business_snapshot,'$.state')),i.shipping_gst-ROUND(i.shipping_gst/2,2),0),IF(i.place_of_supply<>JSON_UNQUOTE(JSON_EXTRACT(i.business_snapshot,'$.state')),i.shipping_gst,0),i.shipping_gst,i.shipping_charges+i.shipping_gst FROM invoices i WHERE i.status='Active' AND i.shipping_charges>0 AND i.invoice_date BETWEEN ? AND ? UNION ALL SELECT COALESCE(NULLIF(it.hsn_sac,''),'Unspecified'),-r.taxable,-r.cgst,-r.sgst,-r.igst,-r.gst,-r.total FROM sales_returns r JOIN invoice_items it ON it.id=r.invoice_item_id JOIN credit_notes c ON c.id=r.credit_note_id WHERE c.note_date BETWEEN ? AND ?) t GROUP BY label ORDER BY label",
                [$from, $to, $from, $to, $from, $to],
            );
        } else {
            $group = match ($type) {
                "monthly" => "DATE_FORMAT(d,'%Y-%m')",
                "customer"
                    => "COALESCE(NULLIF(customer_gstin,''),NULLIF(customer_mobile,''),NULLIF(customer_name,''),'Walk-in')",
                "gst" => "'GST summary'",
                default => "d",
            };
            $rows = DB::all(
                "SELECT $group label,SUM(sales) gross_sales,SUM(returns) credit_adjustments,SUM(taxable) taxable,SUM(cgst) cgst,SUM(sgst) sgst,SUM(igst) igst,SUM(gst) gst,SUM(total) net_sales FROM (SELECT invoice_date d,customer_name,customer_mobile,customer_gstin,grand_total sales,0 returns,taxable,cgst,sgst,igst,gst,grand_total total FROM invoices WHERE status='Active' AND invoice_date BETWEEN ? AND ? UNION ALL SELECT c.note_date,i.customer_name,i.customer_mobile,i.customer_gstin,0,c.total,-c.taxable,-c.cgst,-c.sgst,-c.igst,-c.gst,-c.total FROM credit_notes c JOIN invoices i ON i.id=c.invoice_id WHERE c.note_date BETWEEN ? AND ?) t GROUP BY $group ORDER BY label",
                [$from, $to, $from, $to],
            );
        }
        return ["rows" => $rows, "from" => $from, "to" => $to, "type" => $type];
    }
}
