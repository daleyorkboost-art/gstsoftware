<?php
declare(strict_types=1);
namespace App;
final class InvoiceService
{
    public function get(int $id, bool $lock = false): array
    {
        $i = DB::one(
            "SELECT * FROM invoices WHERE id=?" . ($lock ? " FOR UPDATE" : ""),
            [$id],
        );
        if (!$i) {
            throw new HttpError(404, "Invoice not found.");
        }
        $i["items"] = DB::all(
            "SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY position",
            [$id],
        );
        $i["payment_allocations"] = DB::all(
            "SELECT id,method,amount,reference FROM payment_allocations WHERE invoice_id=? ORDER BY position",
            [$id],
        );
        $i["business"] = json_decode($i["business_snapshot"], true);
        $i["initial_amount_paid"] = $i["amount_paid"];
        $i["amount_paid"] = bcsub($i["grand_total"], $i["amount_due"], 2);
        $i["created_by_name"] = DB::one("SELECT name FROM users WHERE id=?", [$i["created_by"]])["name"] ?? "";
        $i["updated_by_name"] = DB::one("SELECT name FROM users WHERE id=?", [$i["updated_by"]])["name"] ?? "";
        return $i;
    }
    public function prepare(array $d, ?array $business = null): array
    {
        $business ??= SettingsService::business();
        if (!$business["name"]) {
            throw new HttpError(
                422,
                "Complete the business profile before creating an invoice.",
            );
        }
        $settings = SettingsService::settings();
        // Saved invoices carry their shipping rate; a settings change must not
        // silently change the tax when an old invoice is edited.
        if (array_key_exists("shipping_gst_rate", $d)) {
            $settings["shipping_gst_rate"] = $d["shipping_gst_rate"];
        }
        $fields = [];
        foreach (
            [
                "customer_name" => 160,
                "customer_mobile" => 30,
                "customer_email" => 190,
                "billing_address" => 1500,
                "shipping_address" => 1500,
                "shipping_name" => 160,
                "shipping_mobile" => 30,
                "shipping_email" => 190,
                "payment_reference" => 100,
                "notes" => 2000,
                "transporter" => 160,
                "vehicle_number" => 30,
                "delivery_note" => 160,
                "payment_terms" => 160,
                "buyer_order_number" => 160,
                "dispatch_doc_number" => 160,
                "dispatch_through" => 160,
                "destination" => 160,
                "terms_of_delivery" => 500,
            ]
            as $k => $max
        ) {
            $fields[$k] = Validation::text(
                $d[$k] ?? ($k === "notes" ? $settings["terms"] : ""),
                $k,
                $max,
            );
        }
        $fields["invoice_date"] = Validation::date(
            $d["invoice_date"] ?? date("Y-m-d"),
        );
        $fields["customer_gstin"] = Validation::gstin(
            $d["customer_gstin"] ?? "",
        );
        foreach (["customer_email", "shipping_email"] as $emailField) {
            if (
                $fields[$emailField] !== "" &&
                !filter_var($fields[$emailField], FILTER_VALIDATE_EMAIL)
            ) {
                throw new HttpError(422, "Enter a valid e-mail address.");
            }
        }
        $fields["customer_state"] = empty($d["customer_state"])
            ? ""
            : Validation::state($d["customer_state"]);
        if (
            $fields["customer_gstin"] !== "" &&
            $fields["customer_state"] !== "" &&
            substr($fields["customer_gstin"], 0, 2) !==
                $fields["customer_state"]
        ) {
            throw new HttpError(
                422,
                "Customer GSTIN and customer state do not match.",
            );
        }
        $fields["customer_type"] = Validation::choice(
            $d["customer_type"] ?? "Unregistered",
            ["Registered", "Unregistered"],
            "customer type",
        );
        $fields["place_of_supply"] = Validation::state(
            $d["place_of_supply"] ?? $business["state"],
        );
        $fields["shipping_state"] = empty($d["shipping_state"])
            ? ""
            : Validation::state($d["shipping_state"]);
        foreach (["buyer_order_date", "delivery_note_date"] as $dateField) {
            $fields[$dateField] = empty($d[$dateField])
                ? null
                : Validation::date($d[$dateField]);
        }
        if (!is_array($d["items"] ?? null)) {
            throw new HttpError(422, "Add invoice items.");
        }
        $calculated = (new GSTCalculationService())->calculate(
            $d["items"],
            $business["state"],
            $fields["place_of_supply"],
            $settings,
            (string) ($d["shipping_charges"] ?? "0"),
        );
        $allocations = $this->allocations(
            $d,
            $settings,
            $calculated["totals"]["grand_total"],
        );
        $paid = "0.00";
        foreach ($allocations as $allocation) {
            $paid = bcadd($paid, $allocation["amount"], 2);
        }
        if (bccomp($paid, $calculated["totals"]["grand_total"], 2) > 0) {
            throw new HttpError(422, "Total payment cannot exceed the grand total.");
        }
        $due = bcsub($calculated["totals"]["grand_total"], $paid, 2);
        $creditBill = ($d["credit_bill"] ?? false) === true || bccomp($due, "0", 2) > 0;
        if (
            $creditBill &&
            ($fields["customer_name"] === "" ||
                $fields["customer_mobile"] === "" ||
                $fields["billing_address"] === "")
        ) {
            throw new HttpError(
                422,
                "Credit bills require customer name, mobile number and billing address.",
            );
        }
        $fields["amount_paid"] = $paid;
        $fields["amount_due"] = $due;
        $fields["credit_bill"] = (int) $creditBill;
        $fields["payment_status"] = bccomp($due, "0", 2) === 0
            ? "Paid"
            : (bccomp($paid, "0", 2) > 0
                ? "Partially Paid"
                : "Due");
        $fields["payment_method"] = $allocations[0]["method"] ?? "Credit";
        $fields["payment_reference"] = $allocations[0]["reference"] ?? "";
        return [
            "fields" => array_merge($fields, $calculated["totals"], [
                "business_snapshot" => json_encode(
                    $business,
                    JSON_THROW_ON_ERROR,
                ),
            ]),
            "items" => $calculated["items"],
            "totals" => $calculated["totals"],
            "payment_allocations" => $allocations,
        ];
    }
    private function allocations(array $d, array $settings, string $grand): array
    {
        if (!array_key_exists("payment_allocations", $d)) {
            $method = Validation::choice(
                $d["payment_method"] ?? "Cash",
                $settings["payments"],
                "payment method",
            );
            return $method === "Credit" || bccomp($grand, "0", 2) === 0
                ? []
                : [[
                    "method" => $method,
                    "amount" => $grand,
                    "reference" => Validation::text(
                        $d["payment_reference"] ?? "",
                        "payment reference",
                        100,
                    ),
                ]];
        }
        if (!is_array($d["payment_allocations"]) || count($d["payment_allocations"]) > 20) {
            throw new HttpError(422, "Invalid payment allocations.");
        }
        if (!$d["payment_allocations"] && ($d["credit_bill"] ?? false) !== true && bccomp($grand, "0", 2) > 0) {
            $allowed = array_values(array_filter($settings["payments"], fn($v) => $v !== "Credit"));
            if (!$allowed) throw new HttpError(422, "Configure a non-credit payment method.");
            return [["method" => $allowed[0], "amount" => $grand, "reference" => ""]];
        }
        $rows = [];
        foreach ($d["payment_allocations"] as $allocation) {
            if (!is_array($allocation)) {
                throw new HttpError(422, "Invalid payment allocation.");
            }
            $method = Validation::choice(
                $allocation["method"] ?? "",
                array_values(array_filter($settings["payments"], fn($v) => $v !== "Credit")),
                "payment method",
            );
            $amount = Money::number($allocation["amount"] ?? "0", "payment amount");
            if (bccomp($amount, "0", 2) <= 0) {
                throw new HttpError(422, "Payment allocation amounts must be greater than zero.");
            }
            $rows[] = [
                "method" => $method,
                "amount" => $amount,
                "reference" => Validation::text($allocation["reference"] ?? "", "payment reference", 100),
            ];
        }
        return $rows;
    }
    public function save(
        array $data,
        array $user,
        ?int $id = null,
        ?int $source = null,
    ): array {
        return DB::transaction(function () use ($data, $user, $id, $source) {
            $before = $id ? $this->get($id, true) : null;
            if ($before) {
                if ($before["status"] !== "Active") {
                    throw new HttpError(
                        409,
                        "Cancelled invoices cannot be edited.",
                    );
                }
                if (
                    (int) ($data["version"] ?? 0) !== (int) $before["version"]
                ) {
                    throw new HttpError(
                        409,
                        "This invoice has changed. Reload it before editing.",
                    );
                }
                $this->assertNoCredits($id);
                $data["shipping_gst_rate"] ??= $before["shipping_gst_rate"];
            }
            $prepared = $this->prepare($data, $before["business"] ?? null);
            $f = $prepared["fields"];
            $f["updated_by"] = $user["id"];
            if ($id) {
                DB::run(
                    "UPDATE invoices SET " .
                        implode(
                            ",",
                            array_map(fn($k) => "$k=?", array_keys($f)),
                        ) .
                        ",version=version+1 WHERE id=?",
                    [...array_values($f), $id],
                );
                DB::run("DELETE FROM invoice_items WHERE invoice_id=?", [$id]);
                DB::run("DELETE FROM payment_allocations WHERE invoice_id=?", [$id]);
            } else {
                $n = DB::one(
                    "SELECT * FROM invoice_number_settings WHERE id=1 FOR UPDATE",
                );
                if (!DB::one("SELECT id FROM invoices LIMIT 1")) {
                    DB::run("UPDATE invoice_number_settings SET next_number=1 WHERE id=1");
                    $n["next_number"] = 1;
                }
                $f["invoice_number"] =
                    $n["prefix"] .
                    str_pad(
                        (string) $n["next_number"],
                        (int) $n["padding"],
                        "0",
                        STR_PAD_LEFT,
                    );
                if (
                    DB::one("SELECT id FROM invoices WHERE invoice_number=?", [
                        $f["invoice_number"],
                    ])
                ) {
                    throw new HttpError(
                        409,
                        "The number series overlaps existing invoices. Ask the administrator to update it.",
                    );
                }
                DB::run(
                    "UPDATE invoice_number_settings SET next_number=next_number+1 WHERE id=1",
                );
                $f["created_by"] = $user["id"];
                $f["duplicated_from"] = $source;
                DB::run(
                    "INSERT INTO invoices (" .
                        implode(",", array_keys($f)) .
                        ") VALUES (" .
                        implode(",", array_fill(0, count($f), "?")) .
                        ")",
                    array_values($f),
                );
                $id = (int) DB::connection()->lastInsertId();
            }
            foreach ($prepared["items"] as $pos => $row) {
                $row["invoice_id"] = $id;
                $row["position"] = $pos + 1;
                DB::run(
                    "INSERT INTO invoice_items (" .
                        implode(",", array_keys($row)) .
                        ") VALUES (" .
                        implode(",", array_fill(0, count($row), "?")) .
                        ")",
                    array_values($row),
                );
            }
            foreach ($prepared["payment_allocations"] as $pos => $row) {
                DB::run(
                    "INSERT INTO payment_allocations(invoice_id,position,method,amount,reference,created_by) VALUES(?,?,?,?,?,?)",
                    [$id, $pos + 1, $row["method"], $row["amount"], $row["reference"], $user["id"]],
                );
            }
            $after = $this->get($id);
            $this->syncCredit($after, $user);
            $after = $this->get($id);
            $changes = [];
            if ($before) {
                foreach ($after as $k => $v) {
                    if (($before[$k] ?? null) !== $v) {
                        $changes[] = $k;
                    }
                }
                DB::run(
                    "INSERT INTO invoice_edit_history(invoice_id,user_id,previous_values,new_values,changed_fields,final_amount,final_gst) VALUES(?,?,?,?,?,?,?)",
                    [
                        $id,
                        $user["id"],
                        json_encode($before),
                        json_encode($after),
                        json_encode($changes),
                        $after["grand_total"],
                        $after["gst"],
                    ],
                );
            }
            AuditLogService::record(
                $user,
                $before
                    ? "Invoice Edited"
                    : ($source
                        ? "Invoice Duplicated"
                        : "Invoice Created"),
                $id,
                [
                    "invoice_number" => $after["invoice_number"],
                    "changed_fields" => $changes,
                    "final_amount" => $after["grand_total"],
                    "final_gst" => $after["gst"],
                    "payment_status" => $after["payment_status"],
                    "amount_paid" => $after["amount_paid"],
                    "amount_due" => $after["amount_due"],
                    "source" => $source,
                ],
            );
            AuditLogService::record($user, "Payment Recorded", $id, [
                "allocations" => $after["payment_allocations"],
                "total_paid" => $after["amount_paid"],
                "amount_due" => $after["amount_due"],
                "payment_status" => $after["payment_status"],
            ]);
            return $after;
        });
    }
    private function assertNoCredits(int $id): void
    {
        $account = DB::one("SELECT a.id,a.total_cleared FROM credit_accounts a JOIN credit_transactions t ON t.credit_account_id=a.id WHERE t.invoice_id=? AND t.kind='Credit' FOR UPDATE", [$id]);
        if ($account && bccomp($account["total_cleared"], "0", 2) > 0) {
            throw new HttpError(409, "This credit account has clearance receipts. Its credit invoices cannot be edited or cancelled because that would change settled history.");
        }
        if (
            DB::one("SELECT id FROM credit_notes WHERE invoice_id=? LIMIT 1", [
                $id,
            ])
        ) {
            throw new HttpError(
                409,
                "Invoices with credit notes cannot be edited or cancelled.",
            );
        }
        if (
            DB::one(
                "SELECT id FROM integration_requests WHERE invoice_id=? AND status='accepted' LIMIT 1",
                [$id],
            )
        ) {
            throw new HttpError(
                409,
                "This invoice has been accepted by the GST provider. Complete the provider-specific amendment or cancellation workflow before changing it.",
            );
        }
    }
    public function cancel(int $id, array $data, array $user): array
    {
        $reason = Validation::text(
            $data["reason"] ?? "",
            "cancellation reason",
            500,
            true,
        );
        return DB::transaction(function () use ($id, $reason, $user, $data) {
            $old = $this->get($id, true);
            if ($old["status"] !== "Active") {
                throw new HttpError(409, "Invoice is already cancelled.");
            }
            if ((int) ($data["version"] ?? 0) !== (int) $old["version"]) {
                throw new HttpError(
                    409,
                    "Invoice changed. Reload and try again.",
                );
            }
            $this->assertNoCredits($id);
            DB::run(
                "UPDATE invoices SET status='Cancelled',payment_status='Cancelled',cancellation_reason=?,updated_by=?,version=version+1 WHERE id=?",
                [$reason, $user["id"], $id],
            );
            DB::run("UPDATE credit_transactions SET kind='Cancellation' WHERE invoice_id=? AND kind='Credit'", [$id]);
            $credit = DB::one("SELECT credit_account_id FROM credit_transactions WHERE invoice_id=? LIMIT 1", [$id]);
            if ($credit) {
                $this->recalculateCreditAccount((int) $credit["credit_account_id"]);
            }
            AuditLogService::record($user, "Invoice Cancelled", $id, [
                "reason" => $reason,
                "final_amount" => $old["grand_total"],
                "final_gst" => $old["gst"],
            ]);
            return $this->get($id);
        });
    }
    public function duplicate(int $id, array $user): array
    {
        $data = $this->get($id);
        $data["invoice_date"] = date("Y-m-d");
        $data["credit_bill"] = (bool) $data["credit_bill"];
        unset($data["shipping_gst_rate"]);
        return $this->save($data, $user, null, $id);
    }
    private function syncCredit(array $invoice, array $user): void
    {
        $existing = DB::one(
            "SELECT * FROM credit_transactions WHERE invoice_id=? AND kind='Credit' FOR UPDATE",
            [$invoice["id"]],
        );
        $affected = $existing ? [(int) $existing["credit_account_id"]] : [];
        if (bccomp($invoice["amount_due"], "0", 2) > 0) {
            $account = DB::one("SELECT * FROM credit_accounts WHERE customer_mobile=? FOR UPDATE", [$invoice["customer_mobile"]]);
            if (!$account) {
                DB::run(
                    "INSERT INTO credit_accounts(customer_name,customer_mobile,customer_address) VALUES(?,?,?)",
                    [$invoice["customer_name"], $invoice["customer_mobile"], $invoice["billing_address"]],
                );
                $account = DB::one("SELECT * FROM credit_accounts WHERE id=? FOR UPDATE", [DB::connection()->lastInsertId()]);
            } else {
                DB::run("UPDATE credit_accounts SET customer_name=?,customer_address=? WHERE id=?", [$invoice["customer_name"], $invoice["billing_address"], $account["id"]]);
            }
            $affected[] = (int) $account["id"];
            $lastClearance = DB::one("SELECT MAX(transaction_date) d FROM credit_transactions WHERE credit_account_id=? AND kind='Clearance'", [$account["id"]]);
            if ($lastClearance["d"] && $invoice["invoice_date"] < $lastClearance["d"]) {
                throw new HttpError(409, "Credit invoices cannot be dated before the account's latest clearance receipt.");
            }
            if ($existing) {
                DB::run(
                    "UPDATE credit_transactions SET credit_account_id=?,transaction_date=?,amount=?,created_by=? WHERE id=?",
                    [$account["id"], $invoice["invoice_date"], $invoice["amount_due"], $user["id"], $existing["id"]],
                );
            } else {
                DB::run(
                    "INSERT INTO credit_transactions(credit_account_id,invoice_id,kind,transaction_date,amount,balance_after,created_by) VALUES(?,?,'Credit',?,?,0,?)",
                    [$account["id"], $invoice["id"], $invoice["invoice_date"], $invoice["amount_due"], $user["id"]],
                );
                AuditLogService::record($user, "Credit Created", (int) $invoice["id"], ["customer" => $invoice["customer_name"], "amount" => $invoice["amount_due"]]);
            }
        } elseif ($existing) {
            DB::run("UPDATE credit_transactions SET kind='Cancellation' WHERE id=?", [$existing["id"]]);
        }
        foreach (array_unique($affected) as $accountId) {
            $this->recalculateCreditAccount($accountId);
        }
    }
    private function recalculateCreditAccount(int $accountId): void
    {
        $rows = DB::all("SELECT id,kind,amount,invoice_id FROM credit_transactions WHERE credit_account_id=? ORDER BY transaction_date,id FOR UPDATE", [$accountId]);
        $credit = $cleared = $balance = "0.00";
        foreach ($rows as $row) {
            if ($row["kind"] === "Credit") {
                $credit = bcadd($credit, $row["amount"], 2);
                $balance = bcadd($balance, $row["amount"], 2);
            } elseif ($row["kind"] === "Clearance") {
                $cleared = bcadd($cleared, $row["amount"], 2);
                $balance = bcsub($balance, $row["amount"], 2);
            }
            if (bccomp($balance, "0", 2) < 0) {
                throw new HttpError(409, "Existing credit clearances exceed the revised invoice balance.");
            }
            DB::run("UPDATE credit_transactions SET balance_after=? WHERE id=?", [$balance, $row["id"]]);
        }
        DB::run("UPDATE credit_accounts SET total_credit=?,total_cleared=?,balance=? WHERE id=?", [$credit, $cleared, $balance, $accountId]);
        $remainingCleared = $cleared;
        foreach ($rows as $row) {
            if ($row["kind"] !== "Credit" || !$row["invoice_id"]) continue;
            $applied = bccomp($remainingCleared, $row["amount"], 2) >= 0 ? $row["amount"] : $remainingCleared;
            $due = bcsub($row["amount"], $applied, 2);
            $remainingCleared = bcsub($remainingCleared, $applied, 2);
            $invoice = DB::one("SELECT amount_paid,status FROM invoices WHERE id=? FOR UPDATE", [$row["invoice_id"]]);
            if ($invoice && $invoice["status"] === "Active") {
                $paymentStatus = $due === "0.00" ? "Credit Cleared" : (bccomp($invoice["amount_paid"], "0", 2) > 0 || bccomp($applied, "0", 2) > 0 ? "Partially Paid" : "Due");
                DB::run("UPDATE invoices SET amount_due=?,payment_status=? WHERE id=?", [$due, $paymentStatus, $row["invoice_id"]]);
            }
        }
    }
    public function filters(array $q): array
    {
        $where = ["1=1"];
        $args = [];
        if (!empty($q["from"]) || !empty($q["to"])) {
            [$from, $to] = Validation::range($q);
            $where[] = "i.invoice_date BETWEEN ? AND ?";
            array_push($args, $from, $to);
        }
        foreach (
            [
                "invoice_number",
                "invoice_date",
                "customer_name",
                "customer_mobile",
                "customer_gstin",
                "payment_method",
                "payment_status",
                "status",
            ]
            as $key
        ) {
            if (isset($q[$key]) && $q[$key] !== "") {
                $where[] = "i.$key=?";
                $args[] = match ($key) {
                    "invoice_date" => Validation::date($q[$key]),
                    "status" => Validation::choice(
                        $q[$key],
                        ["Active", "Cancelled"],
                        "status",
                    ),
                    "payment_method" => Validation::choice(
                        $q[$key],
                        ["Cash", "UPI", "Card", "Bank Transfer", "Credit", "Other"],
                        "payment",
                    ),
                    "payment_status" => Validation::choice(
                        $q[$key],
                        ["Paid", "Partially Paid", "Due", "Credit Cleared", "Cancelled"],
                        "payment status",
                    ),
                    default => Validation::text($q[$key], $key, 190),
                };
            }
        }
        if (!empty($q["search"])) {
            $s =
                "%" .
                str_replace(
                    ["!", "%", "_"],
                    ["!!", "!%", "!_"],
                    Validation::text($q["search"], "search", 190),
                ) .
                "%";
            $where[] =
                "(i.invoice_number LIKE ? ESCAPE '!' OR i.customer_name LIKE ? ESCAPE '!' OR i.customer_mobile LIKE ? ESCAPE '!' OR i.customer_gstin LIKE ? ESCAPE '!')";
            array_push($args, $s, $s, $s, $s);
        }
        return [implode(" AND ", $where), $args];
    }
    public function list(array $q): array
    {
        [$where, $args] = $this->filters($q);
        $page = max(1, min(1000000, (int) ($q["page"] ?? 1)));
        $offset = ($page - 1) * 25;
        return [
            "rows" => DB::all(
                "SELECT i.*,i.amount_paid initial_amount_paid,(i.grand_total-i.amount_due) amount_paid,c.name created_by_name,u.name updated_by_name FROM invoices i JOIN users c ON c.id=i.created_by JOIN users u ON u.id=i.updated_by WHERE $where ORDER BY i.invoice_date DESC,i.id DESC LIMIT 25 OFFSET $offset",
                $args,
            ),
            "total" => (int) DB::one(
                "SELECT COUNT(*) n FROM invoices i WHERE $where",
                $args,
            )["n"],
            "page" => $page,
            "page_size" => 25,
        ];
    }
}
