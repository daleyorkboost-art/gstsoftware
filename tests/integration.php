<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
use App\{
    DB,
    Config,
    Auth,
    SettingsService,
    InvoiceService,
    ReportService,
    AuditLogService,
    CreditNoteService,
    UserService,
    ExportService,
    PDFService,
    BackupService,
    HttpError,
};
if (
    PHP_SAPI !== "cli" ||
    getenv("GST_TEST_ALLOW_RESET") !== "1" ||
    !str_ends_with(Config::get("DB_DATABASE"), "_test")
) {
    throw new RuntimeException(
        "Use a dedicated *_test database and GST_TEST_ALLOW_RESET=1. This test resets test data.",
    );
}
$db = DB::connection();
$db->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (DB::all("SHOW TABLES") as $r) {
    $db->exec("DROP TABLE `" . array_values($r)[0] . "`");
}
$db->exec("SET FOREIGN_KEY_CHECKS=1");
foreach (["schema", "seed"] as $file) {
    $sql = file_get_contents(ROOT . "/database/" . $file . ".sql");
    foreach (explode(";", $sql) as $statement) {
        if (trim($statement) !== "") {
            $db->exec($statement);
        }
    }
}
Auth::start();
$count = 0;
function check(bool $c, string $name): void
{
    global $count;
    if (!$c) {
        throw new RuntimeException("FAIL " . $name);
    }
    $count++;
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
    throw new RuntimeException("FAIL " . $name);
}
DB::run(
    "INSERT INTO users(firebase_uid,email,name,role_id) VALUES('fixture-admin','admin@example.test','Test Administrator','ADMIN'),('fixture-owner','owner@example.test','Test Owner','OWNER'),('fixture-staff','staff@example.test','Test Staff','STAFF')",
);
$admin = DB::one("SELECT * FROM users WHERE id=1");
$admin["permissions"] = Auth::permissions($admin);
$owner = DB::one("SELECT * FROM users WHERE id=2");
$owner["permissions"] = Auth::permissions($owner);
$staff = DB::one("SELECT * FROM users WHERE id=3");
$staff["permissions"] = Auth::permissions($staff);
check(count(DB::all('SHOW TABLES LIKE "products"')) === 0, "no product master");
check(in_array("create_invoice", $staff["permissions"]), "staff can create");
rejects(fn() => Auth::require($staff, "edit_invoice"), "staff edit denied");
rejects(fn() => Auth::require($staff, "cancel_invoice"), "staff cancel denied");
rejects(
    fn() => Auth::require($owner, "manage_users"),
    "owner admin access denied",
);
SettingsService::saveBusiness(
    [
        "name" => "Ledger Test Business",
        "address" => "Bengaluru, Karnataka",
        "gstin" => "29ABCDE1234F1Z5",
        "state" => "29",
        "bank_name" => "Test Bank",
        "account_number" => "1234567890",
        "ifsc" => "TEST0123456",
        "branch" => "Central",
    ],
    $admin,
);
$service = new InvoiceService();
$report = new ReportService();
$date = date("Y-m-d");
$range = ["from" => $date, "to" => $date];
$input = [
    "invoice_date" => $date,
    "place_of_supply" => "29",
    "payment_method" => "UPI",
    "customer_name" => "Test Customer",
    "customer_mobile" => "9876543210",
    "customer_gstin" => "29ABCDE1234F1Z5",
    "customer_state" => "29",
    "items" => [
        [
            "product_name" => "Consulting",
            "quantity" => "2",
            "rate" => "100",
            "discount" => "10",
            "gst_rate" => "18",
            "hsn_sac" => "9983",
            "unit" => "hours",
            "description" => "Test service",
        ],
    ],
    "grand_total" => "0",
];
$one = $service->save($input, $owner);
check($one["grand_total"] === "224.00", "save ignores forged browser totals");
check(
    $one["invoice_number"] === "INV-00001",
    "numbering starts at configured sequence",
);
check($one["cgst"] === "17.10", "stored GST");
$beforeDashboard = $report->dashboard($range);
check($beforeDashboard["sales"] === "224.00", "dashboard includes save");
$edit = $input;
$edit["items"][0]["rate"] = "200";
$edit["version"] = $one["version"];
$two = $service->save($edit, $owner, (int) $one["id"]);
check($two["grand_total"] === "460.00", "edit recalculates");
check(
    $report->dashboard($range)["sales"] === "460.00",
    "edit synchronizes dashboard",
);
check(
    $report->report($range + ["type" => "gst"])["rows"][0]["gst"] === "70.20",
    "edit synchronizes GST report",
);
check(
    (int) DB::one("SELECT COUNT(*) n FROM invoice_edit_history")["n"] === 1,
    "edit snapshots",
);
rejects(
    fn() => $service->save($edit, $owner, (int) $one["id"]),
    "stale edit conflict",
);
$dup = $service->duplicate((int) $one["id"], $owner);
check(
    $dup["id"] !== $one["id"] && $dup["invoice_number"] === "INV-00002",
    "duplicate new ID and number",
);
$service->cancel(
    (int) $dup["id"],
    ["reason" => "Test cancellation", "version" => $dup["version"]],
    $owner,
);
check(
    $service->get((int) $dup["id"])["status"] === "Cancelled",
    "cancel preserves invoice",
);
check(
    $report->dashboard($range)["sales"] === "460.00",
    "cancel excluded from dashboard",
);
$snapshot = DB::one("SELECT next_number FROM invoice_number_settings")[
    "next_number"
];
$bad = $input;
$bad["items"][0]["discount"] = "99999";
rejects(fn() => $service->save($bad, $owner), "invalid invoice rolls back");
check(
    DB::one("SELECT next_number FROM invoice_number_settings")[
        "next_number"
    ] === $snapshot,
    "failed save does not consume number",
);
$notes = new CreditNoteService();
$return = [
    "invoice_id" => $one["id"],
    "note_date" => $date,
    "reason" => "Partial return",
    "settlement" => "Refund",
    "reference" => "Refund test",
    "items" => [
        ["invoice_item_id" => $two["items"][0]["id"], "quantity" => "1"],
    ],
];
$credit = $notes->create($return, $owner);
check(
    $credit["totals"]["total"] === "230.00",
    "partial credit proportional original GST",
);
check(
    $report->dashboard($range)["net_sales"] === "230.00",
    "credit adjusts net sales",
);
rejects(
    fn() => $service->save($two, $owner, (int) $one["id"]),
    "credit protects invoice edit",
);
rejects(
    fn() => $service->cancel(
        (int) $one["id"],
        ["reason" => "x", "version" => $two["version"]],
        $owner,
    ),
    "credit protects cancellation",
);
$notes->create($return, $owner);
rejects(fn() => $notes->create($return, $owner), "over-return rejected");
check(
    $report->report($range + ["type" => "gst"])["rows"][0]["gst"] === "0.00",
    "full return GST zero",
);
check(
    $service->list(["search" => "%' OR 1=1 --"])["total"] === 0,
    "search SQL injection inert",
);
check(
    $service->list(["invoice_number" => $one["invoice_number"]])["total"] === 1,
    "exact invoice search",
);
$users = new UserService();
rejects(
    fn() => $users->save(
        [
            "id" => 1,
            "email" => $admin["email"],
            "name" => $admin["name"],
            "role_id" => "STAFF",
            "active" => false,
        ],
        $admin,
    ),
    "last admin protected",
);
rejects(
    fn() => $users->save(
        [
            "email" => "x@example.test",
            "name" => "X",
            "role_id" => "STAFF",
            "permissions" => ["manage_users" => true],
        ],
        $admin,
    ),
    "staff admin grant rejected",
);
$users->save(
    [
        "id" => 3,
        "email" => $staff["email"],
        "name" => $staff["name"],
        "role_id" => "STAFF",
        "active" => true,
        "permissions" => ["edit_invoice" => true],
    ],
    $admin,
);
check(
    in_array("edit_invoice", Auth::permissions($staff)),
    "explicit staff grant",
);
$pdf = new PDFService();
file_put_contents(
    ROOT . "/tmp/invoice-test.pdf",
    $pdf->render($pdf->invoiceHtml($two)),
);
check(
    str_starts_with(file_get_contents(ROOT . "/tmp/invoice-test.pdf"), "%PDF-"),
    "single PDF generated",
);
$exports = new ExportService();
$job = $exports->start($range + ["kind" => "invoices"], $admin);
while (!$job["ready"]) {
    $job = $exports->step($job["id"], $admin);
}
check(
    $job["done"] === 2,
    "batch includes all matching cancelled and active invoices",
);
copy(
    ROOT . "/storage/generated/" . $job["id"] . ".pdf",
    ROOT . "/tmp/batch-test.pdf",
);
rejects(
    fn() => $exports->job($job["id"], $owner),
    "other user export access denied",
);
$audit = $exports->start($range + ["kind" => "audit"], $admin);
while (!$audit["ready"]) {
    $audit = $exports->step($audit["id"], $admin);
}
copy(
    ROOT . "/storage/generated/" . $audit["id"] . ".pdf",
    ROOT . "/tmp/audit-test.pdf",
);
check($audit["ready"], "audit PDF generated");
foreach (["csv", "excel"] as $kind) {
    $j = $exports->start($range + ["kind" => $kind], $admin);
    while (!$j["ready"]) {
        $j = $exports->step($j["id"], $admin);
    }
    check($j["ready"], $kind . " generated");
}
$backup = new BackupService();
$path = $backup->create();
DB::run("UPDATE business_profile SET name='Changed' WHERE id=1");
$backup->restore($path, $admin);
check(
    SettingsService::business()["name"] === "Ledger Test Business",
    "encrypted backup restore",
);
$tampered = ROOT . "/tmp/tampered.gstbackup";
$bytes = file_get_contents($path);
$bytes[50] = chr(ord($bytes[50]) ^ 1);
file_put_contents($tampered, $bytes);
rejects(
    fn() => $backup->restore($tampered, $admin),
    "tampered backup rejected",
);
Auth::start();
$_SESSION["uid"] = 1;
$_SESSION["firebase_uid"] = "fixture-admin";
$_SESSION["expires"] = time() + 3600;
$fixture = [
    "session" => session_id(),
    "csrf" => $_SESSION["csrf"],
    "invoice_id" => $one["id"],
    "count" => $count,
];
session_write_close();
file_put_contents(ROOT . "/tmp/test-session.json", json_encode($fixture));
echo "\n$count integration checks passed. Test-only session saved outside public/.\n";
