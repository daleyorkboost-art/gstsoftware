<?php
declare(strict_types=1);
namespace App;
final class MailService
{
    public function invoice(
        array $invoice,
        string $recipient,
        array $user,
    ): void {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, "Enter a valid recipient email.");
        }
        require_once ROOT . "/vendor/autoload.php";
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = Config::required("MAIL_HOST");
        $mail->Port = (int) Config::get("MAIL_PORT", "587");
        $mail->SMTPAuth = true;
        $mail->Username = Config::required("MAIL_USERNAME");
        $mail->Password = Config::required("MAIL_PASSWORD");
        $mail->SMTPSecure = Validation::choice(
            Config::get("MAIL_ENCRYPTION", "tls"),
            ["tls", "ssl"],
            "mail encryption",
        );
        $mail->Timeout = 30;
        $mail->CharSet = "UTF-8";
        $mail->setFrom(
            Config::required("MAIL_FROM"),
            $invoice["business"]["name"],
        );
        $mail->addAddress($recipient);
        $mail->Subject = "Invoice " . $invoice["invoice_number"];
        $mail->Body =
            "Please find your invoice attached. Total: INR " .
            $invoice["grand_total"] .
            ".";
        $pdf = new PDFService();
        $mail->addStringAttachment(
            $pdf->render($pdf->invoiceHtml($invoice)),
            "invoice-" . $invoice["id"] . ".pdf",
        );
        $mail->send();
        AuditLogService::record(
            $user,
            "Invoice Emailed",
            (int) $invoice["id"],
            ["recipient" => $recipient],
        );
    }
}
