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
    CreditLedgerService,
    AdminService,
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
        "password_reset",
        "calculate",
        "save_invoice",
        "cancel_invoice",
        "duplicate_invoice",
        "printed",
        "save_business",
        "logo_upload",
        "signature_upload",
        "business_asset_delete",
        "save_settings",
        "save_numbering",
        "save_user",
        "provision_user",
        "reset_user_password",
        "delete_user",
        "delete_transactions",
        "clear_credit",
        "create_credit",
        "export_start",
        "export_step",
        "backup",
        "restore",
        "email",
    ];
    if (in_array($action, $write, true) && $method !== "POST") {
        throw new HttpError(405, "This action requires POST.");
    }
    if ($action === "config") {
        $result = [
            "firebase" => [],
            "csrf" => $_SESSION["csrf"],
            "timezone" => Config::get("APP_TIMEZONE", "Asia/Kolkata"),
            "release" => "2.1.2",
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
    } elseif ($action === "password_reset") {
        Auth::limit("password-reset", 5);
        $email = strtolower(Validation::text($body["email"] ?? "", "email", 190, true));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, "Enter a valid email address.");
        }
        (new App\FirebaseService())->sendPasswordReset($email);
        $result = ["message" => "If the account exists, a password reset email has been sent."];
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
            "printed" => "print_invoice",
            "pdf" => "print_invoice",
            "business" => "view_invoice",
            "business_asset" => "view_invoice",
            "save_business" => "manage_business_profile",
            "logo_upload" => "manage_business_profile",
            "signature_upload" => "manage_business_profile",
            "business_asset_delete" => "manage_business_profile",
            "settings" => "view_invoice",
            "save_settings" => "manage_settings",
            "numbering" => "configure_invoice_numbering",
            "save_numbering" => "configure_invoice_numbering",
            "users" => "manage_users",
            "save_user" => "manage_users",
            "provision_user" => "manage_users",
            "reset_user_password" => "reset_user_password",
            "delete_user" => "delete_users",
            "delete_transactions" => "delete_transaction_data",
            "credit_accounts" => "manage_credit_invoices",
            "credit_account" => "manage_credit_invoices",
            "clear_credit" => "clear_credit",
            "credit_receipt" => "manage_credit_invoices",
            "credit_history" => "manage_credit_invoices",
            "credit_history_all" => "manage_credit_invoices",
            "reports" => "view_reports",
            "create_credit" => "manage_credit_notes",
            "credit_note_pdf" => "manage_credit_notes",
            "activity" => "view_activity_logs",
            "history" => "view_invoice",
            "backup" => "manage_backups",
            "restore" => "manage_backups",
            "email" => "export_invoice",
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
            "business_asset" => (function () {
                $type = Validation::choice($_GET["type"] ?? "logo", ["logo", "signature"], "asset type");
                $business = SettingsService::business();
                $name = $business[$type] ?? "";
                if (!preg_match('/^[a-f0-9]{40}\.png$/D', $name) || !is_file(ROOT . "/storage/uploads/" . $name)) throw new HttpError(404, "Business asset not configured.");
                header("Content-Type: image/png");
                header("Content-Length: " . filesize(ROOT . "/storage/uploads/" . $name));
                readfile(ROOT . "/storage/uploads/" . $name);
                exit();
            })(),
            "save_business" => SettingsService::saveBusiness($body, $u),
            "logo_upload" => SettingsService::upload($_FILES["logo"] ?? [], $u),
            "signature_upload" => SettingsService::uploadSignature($_FILES["signature"] ?? [], $u),
            "business_asset_delete" => SettingsService::deleteAsset($body["type"] ?? "", $u),
            "settings" => SettingsService::settings(),
            "save_settings" => SettingsService::save($body, $u),
            "numbering" => DB::one(
                "SELECT * FROM invoice_number_settings WHERE id=1",
            ),
            "save_numbering" => SettingsService::numbering($body, $u),
            "users" => (new UserService())->list(),
            "save_user" => (new UserService())->save($body, $u),
            "provision_user" => (new UserService())->provision($body, $u),
            "reset_user_password" => (new UserService())->resetPassword($body, $u),
            "delete_user" => (new UserService())->delete($body, $u),
            "delete_transactions" => (new AdminService())->deleteTransactions($body, $u),
            "credit_accounts" => (new CreditLedgerService())->list($_GET),
            "credit_account" => (new CreditLedgerService())->get($id),
            "clear_credit" => (new CreditLedgerService())->clear($body, $u),
            "credit_receipt" => (function () use ($id, $u) {
                $ledger = new CreditLedgerService();
                $receipt = $ledger->receipt($id);
                $pdf = new PDFService();
                AuditLogService::record($u, "Credit Receipt Exported", null, ["receipt" => $receipt["receipt_number"]]);
                header("Content-Type: application/pdf");
                header('Content-Disposition: attachment; filename="' . $receipt["receipt_number"] . '.pdf"');
                echo $pdf->render($pdf->receiptHtml($receipt));
                exit();
            })(),
            "credit_history" => (function () use ($id, $u) {
                $account = (new CreditLedgerService())->get($id);
                AuditLogService::record($u, "Credit History Exported", null, ["credit_account_id" => $id]);
                header("Content-Type: text/csv; charset=utf-8");
                header('Content-Disposition: attachment; filename="credit-history-' . $id . '.csv"');
                $out = fopen("php://output", "wb");
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ["Date", "Type", "Invoice", "Receipt", "Amount", "Payment modes", "Balance"]);
                foreach ($account["transactions"] as $row) fputcsv($out, array_map([ExportService::class, "csvSafe"], [$row["transaction_date"], $row["kind"], $row["invoice_number"] ?? "", $row["receipt_number"] ?? "", $row["amount"], implode(" + ", array_column($row["allocations"], "method")), $row["balance_after"]]));
                fclose($out);
                exit();
            })(),
            "credit_history_all" => (function () use ($u) {
                $rows = (new CreditLedgerService())->historyRows();
                AuditLogService::record($u, "All Credit History Exported", null, ["rows" => count($rows)]);
                header("Content-Type: text/csv; charset=utf-8");
                header('Content-Disposition: attachment; filename="all-credit-invoice-history-' . date("Y-m-d") . '.csv"');
                $out = fopen("php://output", "wb");
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ["Date", "Customer", "Mobile", "Address", "Type", "Invoice", "Receipt", "Amount", "Payment modes", "Balance", "Recorded by"]);
                foreach ($rows as $row) {
                    fputcsv($out, array_map([ExportService::class, "csvSafe"], [$row["transaction_date"], $row["customer_name"], $row["customer_mobile"], $row["customer_address"], $row["kind"], $row["invoice_number"] ?? "", $row["receipt_number"] ?? "", $row["amount"], $row["payment_modes"], $row["balance_after"], $row["created_by_name"]]));
                }
                fclose($out);
                exit();
            })(),
            "reports" => (new ReportService())->report($_GET),
            "create_credit" => (new CreditNoteService())->create($body, $u),
            "credit_note_pdf" => (function () use ($id, $u) {
                $credit = (new CreditNoteService())->get($id);
                $pdf = new PDFService();
                AuditLogService::record($u, "Credit Note Exported", (int) $credit["invoice_id"], ["credit_note" => $id]);
                header("Content-Type: application/pdf");
                header('Content-Disposition: attachment; filename="credit-note-' . $id . '.pdf"');
                echo $pdf->render($pdf->creditNoteHtml($credit));
                exit();
            })(),
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
