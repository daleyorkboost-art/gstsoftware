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
        $i["business"] = json_decode($i["business_snapshot"], true);
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
        $fields = [];
        foreach (
            [
                "customer_name" => 160,
                "customer_mobile" => 30,
                "billing_address" => 1500,
                "shipping_address" => 1500,
                "payment_reference" => 100,
                "notes" => 2000,
                "transporter" => 160,
                "vehicle_number" => 30,
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
        $fields["payment_method"] = Validation::choice(
            $d["payment_method"] ?? "Cash",
            $settings["payments"],
            "payment method",
        );
        if (!is_array($d["items"] ?? null)) {
            throw new HttpError(422, "Add invoice items.");
        }
        $calculated = (new GSTCalculationService())->calculate(
            $d["items"],
            $business["state"],
            $fields["place_of_supply"],
            $settings,
        );
        return [
            "fields" => array_merge($fields, $calculated["totals"], [
                "business_snapshot" => json_encode(
                    $business,
                    JSON_THROW_ON_ERROR,
                ),
            ]),
            "items" => $calculated["items"],
            "totals" => $calculated["totals"],
        ];
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
            } else {
                $n = DB::one(
                    "SELECT * FROM invoice_number_settings WHERE id=1 FOR UPDATE",
                );
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
                    "source" => $source,
                ],
            );
            return $after;
        });
    }
    private function assertNoCredits(int $id): void
    {
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
                "UPDATE invoices SET status='Cancelled',cancellation_reason=?,updated_by=?,version=version+1 WHERE id=?",
                [$reason, $user["id"], $id],
            );
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
        return $this->save($data, $user, null, $id);
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
                        ["Cash", "UPI", "Card", "Bank Transfer", "Credit"],
                        "payment",
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
                "SELECT i.*,c.name created_by_name,u.name updated_by_name FROM invoices i JOIN users c ON c.id=i.created_by JOIN users u ON u.id=i.updated_by WHERE $where ORDER BY i.invoice_date DESC,i.id DESC LIMIT 25 OFFSET $offset",
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
