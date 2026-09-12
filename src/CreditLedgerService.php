<?php
declare(strict_types=1);
namespace App;
final class CreditLedgerService
{
    public function list(array $q): array
    {
        $search = Validation::text($q["search"] ?? "", "search", 190);
        $status = Validation::choice($q["status"] ?? "All", ["All", "Pending", "Partially Cleared", "Cleared"], "credit status");
        $where = ["1=1"];
        $args = [];
        if ($search !== "") {
            $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], $search) . "%";
            $where[] = "(a.customer_name LIKE ? ESCAPE '!' OR a.customer_mobile LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM credit_transactions t JOIN invoices i ON i.id=t.invoice_id WHERE t.credit_account_id=a.id AND i.invoice_number LIKE ? ESCAPE '!'))";
            array_push($args, $like, $like, $like);
        }
        if ($status === "Cleared") $where[] = "a.balance=0";
        if ($status === "Pending") $where[] = "a.balance>0 AND a.total_cleared=0";
        if ($status === "Partially Cleared") $where[] = "a.balance>0 AND a.total_cleared>0";
        return ["rows" => DB::all("SELECT a.*,CASE WHEN balance=0 THEN 'Cleared' WHEN total_cleared>0 THEN 'Partially Cleared' ELSE 'Pending' END credit_status FROM credit_accounts a WHERE " . implode(" AND ", $where) . " ORDER BY balance DESC,a.id", $args)];
    }
    public function get(int $id): array
    {
        $account = DB::one("SELECT * FROM credit_accounts WHERE id=?", [$id]);
        if (!$account) throw new HttpError(404, "Credit customer not found.");
        $account["transactions"] = DB::all("SELECT t.*,i.invoice_number,u.name created_by_name FROM credit_transactions t LEFT JOIN invoices i ON i.id=t.invoice_id JOIN users u ON u.id=t.created_by WHERE t.credit_account_id=? ORDER BY t.transaction_date,t.id", [$id]);
        foreach ($account["transactions"] as &$transaction) {
            $transaction["allocations"] = DB::all("SELECT method,amount,reference FROM credit_transaction_allocations WHERE credit_transaction_id=? ORDER BY position", [$transaction["id"]]);
        }
        return $account;
    }
    public function historyRows(): array
    {
        $rows = DB::all(
            "SELECT a.customer_name,a.customer_mobile,a.customer_address,t.id,t.transaction_date,t.kind,i.invoice_number,t.receipt_number,t.amount,t.balance_after,u.name created_by_name FROM credit_transactions t JOIN credit_accounts a ON a.id=t.credit_account_id LEFT JOIN invoices i ON i.id=t.invoice_id JOIN users u ON u.id=t.created_by ORDER BY t.transaction_date,t.id",
        );
        foreach ($rows as &$row) {
            $allocations = DB::all(
                "SELECT method,amount,reference FROM credit_transaction_allocations WHERE credit_transaction_id=? ORDER BY position",
                [$row["id"]],
            );
            $row["payment_modes"] = implode(" + ", array_map(
                fn($allocation) => $allocation["method"] . " " . $allocation["amount"] . ($allocation["reference"] !== "" ? " (" . $allocation["reference"] . ")" : ""),
                $allocations,
            ));
        }
        unset($row);
        return $rows;
    }
    public function clear(array $d, array $u): array
    {
        return DB::transaction(function () use ($d, $u) {
            DB::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            $id = (int) ($d["credit_account_id"] ?? 0);
            $account = DB::one("SELECT * FROM credit_accounts WHERE id=? FOR UPDATE", [$id]);
            if (!$account) throw new HttpError(404, "Credit customer not found.");
            $date = Validation::date($d["date"] ?? date("Y-m-d"));
            $latest = DB::one("SELECT MAX(transaction_date) d FROM credit_transactions WHERE credit_account_id=?", [$id]);
            if ($latest["d"] && $date < $latest["d"]) {
                throw new HttpError(422, "Clearance date cannot precede the account's latest transaction.");
            }
            if (!is_array($d["allocations"] ?? null) || !$d["allocations"] || count($d["allocations"]) > 20) throw new HttpError(422, "Add at least one payment allocation.");
            $settings = SettingsService::settings();
            $allowed = array_values(array_filter($settings["payments"], fn($v) => $v !== "Credit"));
            $allocations = [];
            $amount = "0.00";
            foreach ($d["allocations"] as $row) {
                $method = Validation::choice($row["method"] ?? "", $allowed, "payment method");
                $value = Money::number($row["amount"] ?? "0", "clearance amount");
                if (bccomp($value, "0", 2) <= 0) throw new HttpError(422, "Clearance amounts must be greater than zero.");
                $amount = bcadd($amount, $value, 2);
                $allocations[] = ["method" => $method, "amount" => $value, "reference" => Validation::text($row["reference"] ?? "", "reference", 100)];
            }
            if (bccomp($amount, $account["balance"], 2) > 0) throw new HttpError(422, "Clearance cannot exceed the current credit balance.");
            $balance = bcsub($account["balance"], $amount, 2);
            DB::run("INSERT INTO credit_transactions(credit_account_id,kind,receipt_number,transaction_date,amount,balance_after,created_by) VALUES(?,'Clearance',NULL,?,?,?,?)", [$id, $date, $amount, $balance, $u["id"]]);
            $tx = (int) DB::connection()->lastInsertId();
            $snapshot = ["business" => SettingsService::business(), "customer_name" => $account["customer_name"], "customer_mobile" => $account["customer_mobile"], "customer_address" => $account["customer_address"], "created_by_name" => $u["name"]];
            DB::run("UPDATE credit_transactions SET receipt_snapshot=? WHERE id=?", [json_encode($snapshot, JSON_THROW_ON_ERROR), $tx]);
            $nextReceipt = (int) DB::one(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_number,4) AS UNSIGNED)),0)+1 number FROM credit_transactions WHERE kind='Clearance' AND receipt_number IS NOT NULL",
            )["number"];
            $receipt = "CR-" . str_pad((string) $nextReceipt, 6, "0", STR_PAD_LEFT);
            DB::run("UPDATE credit_transactions SET receipt_number=? WHERE id=?", [$receipt, $tx]);
            foreach ($allocations as $position => $row) DB::run("INSERT INTO credit_transaction_allocations(credit_transaction_id,position,method,amount,reference) VALUES(?,?,?,?,?)", [$tx, $position + 1, $row["method"], $row["amount"], $row["reference"]]);
            DB::run("UPDATE credit_accounts SET total_cleared=total_cleared+?,balance=? WHERE id=?", [$amount, $balance, $id]);
            $this->syncInvoiceStatuses($id, bcadd($account["total_cleared"], $amount, 2));
            AuditLogService::record($u, "Credit Cleared", null, ["credit_account_id" => $id, "receipt" => $receipt, "amount" => $amount, "new_balance" => $balance, "allocations" => $allocations]);
            return ["id" => $tx, "receipt_number" => $receipt, "amount" => $amount, "balance" => $balance];
        });
    }
    private function syncInvoiceStatuses(int $accountId, string $cleared): void
    {
        $credits = DB::all("SELECT t.amount,t.invoice_id,i.amount_paid,i.status FROM credit_transactions t JOIN invoices i ON i.id=t.invoice_id WHERE t.credit_account_id=? AND t.kind='Credit' ORDER BY t.transaction_date,t.id FOR UPDATE", [$accountId]);
        foreach ($credits as $credit) {
            $applied = bccomp($cleared, $credit["amount"], 2) >= 0 ? $credit["amount"] : $cleared;
            $due = bcsub($credit["amount"], $applied, 2);
            $cleared = bcsub($cleared, $applied, 2);
            $status = $due === "0.00" ? "Credit Cleared" : (bccomp($credit["amount_paid"], "0", 2) > 0 || bccomp($applied, "0", 2) > 0 ? "Partially Paid" : "Due");
            if ($credit["status"] === "Active") DB::run("UPDATE invoices SET amount_due=?,payment_status=? WHERE id=?", [$due, $status, $credit["invoice_id"]]);
        }
    }
    public function receipt(int $id): array
    {
        $row = DB::one("SELECT t.*,a.customer_name,a.customer_mobile,a.customer_address,a.balance current_balance,u.name created_by_name FROM credit_transactions t JOIN credit_accounts a ON a.id=t.credit_account_id JOIN users u ON u.id=t.created_by WHERE t.id=? AND t.kind='Clearance'", [$id]);
        if (!$row) throw new HttpError(404, "Clearance receipt not found.");
        if ($row["receipt_snapshot"]) {
            $snapshot = json_decode($row["receipt_snapshot"], true, 512, JSON_THROW_ON_ERROR);
            foreach (["business", "customer_name", "customer_mobile", "customer_address", "created_by_name"] as $field) {
                if (array_key_exists($field, $snapshot)) $row[$field] = $snapshot[$field];
            }
        }
        $row["allocations"] = DB::all("SELECT method,amount,reference FROM credit_transaction_allocations WHERE credit_transaction_id=? ORDER BY position", [$id]);
        return $row;
    }
}
