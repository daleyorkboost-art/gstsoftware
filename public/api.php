<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
use App\{
    Auth,
    Config,
    DB,
    HttpError,
    Validation,
    SettingsService,
    InvoiceService,
    AuditLogService,
    ReportService,
    CreditNoteService,
    UserService,
    ExportService,
    PDFService,
    BackupService,
    MailService,
    GSTIntegrationService,
};
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
try {
    Auth::start();
    $action = $_GET["action"] ?? "session";
    $method = $_SERVER["REQUEST_METHOD"];
    $id = (int) ($_GET["id"] ?? 0);
    $body = [];
    if ($method === "POST") {
        Auth::csrf();
        if (str_contains($_SERVER["CONTENT_TYPE"] ?? "", "application/json")) {
            if ((int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 1048576) {
                throw new HttpError(413, "Request is too large.");
            }
            $body = json_decode(
                file_get_contents("php://input"),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($body)) {
                throw new HttpError(422, "Invalid request.");
            }
        } else {
            $body = $_POST;
        }
    } elseif ($method !== "GET") {
        throw new HttpError(405, "Method not allowed.");
    }
    $write = [
        "login",
        "logout",
        "calculate",
        "save_invoice",
        "cancel_invoice",
        "duplicate_invoice",
        "printed",
        "save_business",
        "logo_upload",
        "save_settings",
        "save_numbering",
        "save_user",
        "provision_user",
        "create_credit",
        "export_start",
        "export_step",
        "backup",
        "restore",
        "email",
        "gst_submit",
    ];
    if (in_array($action, $write, true) && $method !== "POST") {
        throw new HttpError(405, "This action requires POST.");
    }
    if ($action === "config") {
        $result = [
            "firebase" => [],
            "csrf" => $_SESSION["csrf"],
            "timezone" => Config::get("APP_TIMEZONE", "Asia/Kolkata"),
        ];
        foreach (
            [
                "apiKey" => "API_KEY",
                "authDomain" => "AUTH_DOMAIN",
                "projectId" => "PROJECT_ID",
                "storageBucket" => "STORAGE_BUCKET",
                "messagingSenderId" => "MESSAGING_SENDER_ID",
                "appId" => "APP_ID",
            ]
            as $k => $v
        ) {
            $result["firebase"][$k] = Config::get("FIREBASE_" . $v);
        }
    } elseif ($action === "login") {
        $result = [
            "user" => Auth::login(
                Validation::text($body["token"] ?? "", "token", 10000, true),
            ),
            "csrf" => $_SESSION["csrf"],
        ];
    } else {
        $u = Auth::user();
        $svc = new InvoiceService();
        $permissions = [
            "dashboard" => "view_invoice",
            "invoices" => "view_invoice",
            "invoice" => "view_invoice",
            "calculate" => "create_invoice",
            "save_invoice" => $id ? "edit_invoice" : "create_invoice",
            "cancel_invoice" => "cancel_invoice",
            "duplicate_invoice" => "duplicate_invoice",
            "printed" => "view_invoice",
            "pdf" => "view_invoice",
            "business" => "view_invoice",
            "save_business" => "manage_business_profile",
            "logo_upload" => "manage_business_profile",
            "settings" => "view_invoice",
            "save_settings" => "manage_settings",
            "numbering" => "configure_invoice_numbering",
            "save_numbering" => "configure_invoice_numbering",
            "users" => "manage_users",
            "save_user" => "manage_users",
            "provision_user" => "manage_users",
            "reports" => "view_reports",
            "create_credit" => "manage_credit_notes",
            "activity" => "view_activity_logs",
            "history" => "view_invoice",
            "backup" => "manage_backups",
            "restore" => "manage_backups",
            "email" => "export_invoice",
            "gst_submit" => "manage_settings",
            "integrations" => "manage_settings",
        ];
        if ($action === "calculate") {
            $permissions[$action] = empty($body["id"])
                ? "create_invoice"
                : "edit_invoice";
        }
        if (isset($permissions[$action])) {
            Auth::require($u, $permissions[$action]);
        }
        $result = match ($action) {
            "session" => ["user" => $u, "csrf" => $_SESSION["csrf"]],
            "logout" => (function () use ($u) {
                AuditLogService::record($u, "Logout");
                $_SESSION = [];
                session_destroy();
                return ["message" => "Signed out."];
            })(),
            "dashboard" => (new ReportService())->dashboard($_GET),
            "invoices" => $svc->list($_GET),
            "invoice" => $svc->get($id),
            "calculate" => $svc->prepare(
                $body,
                !empty($body["id"])
                    ? $svc->get((int) $body["id"])["business"]
                    : null,
            ),
            "save_invoice" => $svc->save($body, $u, $id ?: null),
            "cancel_invoice" => $svc->cancel($id, $body, $u),
            "duplicate_invoice" => $svc->duplicate($id, $u),
            "printed" => (function () use ($svc, $id, $u) {
                $svc->get($id);
                AuditLogService::record($u, "Invoice Printed", $id);
                return ["message" => "Print dialog requested."];
            })(),
            "pdf" => (function () use ($svc, $id, $u) {
                $pdf = new PDFService();
                $bytes = $pdf->render($pdf->invoiceHtml($svc->get($id)));
                AuditLogService::record($u, "Invoice Exported", $id, [
                    "format" => "pdf",
                ]);
                header("Content-Type: application/pdf");
                header(
                    'Content-Disposition: attachment; filename="invoice-' .
                        $id .
                        '.pdf"',
                );
                echo $bytes;
                exit();
            })(),
            "business" => SettingsService::business(),
            "save_business" => SettingsService::saveBusiness($body, $u),
            "logo_upload" => SettingsService::upload($_FILES["logo"] ?? [], $u),
            "settings" => SettingsService::settings(),
            "save_settings" => SettingsService::save($body, $u),
            "numbering" => DB::one(
                "SELECT * FROM invoice_number_settings WHERE id=1",
            ),
            "save_numbering" => SettingsService::numbering($body, $u),
            "users" => (new UserService())->list(),
            "save_user" => (new UserService())->save($body, $u),
            "provision_user" => (new UserService())->provision($body, $u),
            "reports" => (new ReportService())->report($_GET),
            "create_credit" => (new CreditNoteService())->create($body, $u),
            "activity" => (function () {
                [$from, $to] = Validation::range($_GET);
                $page = max(1, min(1000000, (int) ($_GET["page"] ?? 1)));
                $offset = ($page - 1) * 50;
                return [
                    "rows" => DB::all(
                        "SELECT a.*,i.invoice_number FROM activity_logs a LEFT JOIN invoices i ON i.id=a.invoice_id WHERE a.created_at>=? AND a.created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY a.id DESC LIMIT 50 OFFSET " .
                            $offset,
                        [$from, $to],
                    ),
                    "page" => $page,
                ];
            })(),
            "history" => (function () use ($svc, $id) {
                $svc->get($id);
                return [
                    "activity" => DB::all(
                        "SELECT * FROM activity_logs WHERE invoice_id=? ORDER BY id DESC",
                        [$id],
                    ),
                    "edits" => DB::all(
                        "SELECT * FROM invoice_edit_history WHERE invoice_id=? ORDER BY id DESC",
                        [$id],
                    ),
                ];
            })(),
            "export_start" => (new ExportService())->start($body, $u),
            "export_step" => (new ExportService())->step(
                $body["job"] ?? "",
                $u,
            ),
            "export_download" => (new ExportService())->download(
                $_GET["job"] ?? "",
                $u,
            ),
            "backup" => (function () use ($u) {
                $path = (new BackupService())->create();
                AuditLogService::record($u, "Backup Created");
                header("Content-Type: application/octet-stream");
                header(
                    'Content-Disposition: attachment; filename="' .
                        basename($path) .
                        '"',
                );
                readfile($path);
                exit();
            })(),
            "restore" => (function () use ($u, $body) {
                if (
                    ($body["confirmation"] ?? "") !== "RESTORE ALL DATA" ||
                    ($_FILES["backup"]["error"] ?? 1) !== 0
                ) {
                    throw new HttpError(
                        422,
                        "Choose a backup and type RESTORE ALL DATA.",
                    );
                }
                (new BackupService())->restore(
                    $_FILES["backup"]["tmp_name"],
                    $u,
                );
                return ["message" => "Backup restored. Please reload."];
            })(),
            "email" => (function () use ($svc, $id, $body, $u) {
                Auth::limit("email-" . $u["id"], 15);
                (new MailService())->invoice(
                    $svc->get($id),
                    Validation::text(
                        $body["recipient"] ?? "",
                        "recipient",
                        190,
                        true,
                    ),
                    $u,
                );
                return ["message" => "Invoice sent."];
            })(),
            "integrations" => [
                "configured" =>
                    Config::get("GST_API_URL") !== "" &&
                    Config::get("GST_API_KEY") !== "",
                "requests" => DB::all(
                    "SELECT * FROM integration_requests ORDER BY id DESC LIMIT 50",
                ),
            ],
            "gst_submit" => DB::transaction(function () use (
                $svc,
                $id,
                $body,
                $u,
            ) {
                $invoice = $svc->get($id, true);
                if ($invoice["status"] !== "Active") {
                    throw new HttpError(409, "Invoice is cancelled.");
                }
                $kind = Validation::choice(
                    $body["kind"] ?? "",
                    ["einvoice", "ewaybill"],
                    "GST operation",
                );
                $r = (new GSTIntegrationService())->submit($kind, $invoice);
                DB::run(
                    "INSERT INTO integration_requests(invoice_id,kind,status,response) VALUES(?,?,?,?)",
                    [$id, $kind, "accepted", json_encode($r)],
                );
                AuditLogService::record($u, "GST Provider Submission", $id, [
                    "kind" => $kind,
                    "reference" => $r["reference"],
                ]);
                return $r;
            }),
            default => throw new HttpError(404, "Endpoint not found."),
        };
    }
    echo json_encode(["ok" => true, "data" => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status =
        $error instanceof HttpError
            ? $error->status
            : ($error instanceof JsonException
                ? 422
                : 500);
    http_response_code($status);
    $message =
        $error instanceof HttpError
            ? $error->getMessage()
            : ($status === 422
                ? "Malformed request data."
                : "Unable to complete this request. Please try again or contact the administrator.");
    if ($status >= 500) {
        error_log(
            date(DATE_ATOM) .
                " " .
                get_class($error) .
                " code=" .
                $error->getCode() .
                " at " .
                basename($error->getFile()) .
                ":" .
                $error->getLine() .
                PHP_EOL,
            3,
            ROOT . "/storage/logs/app.log",
        );
    }
    echo json_encode(["ok" => false, "error" => $message]);
}
