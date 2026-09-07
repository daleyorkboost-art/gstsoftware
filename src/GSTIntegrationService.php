<?php
declare(strict_types=1);
namespace App;
/** Provider contract is documented in docs/INTEGRATIONS.md. No government result is fabricated. */
final class GSTIntegrationService implements GSTProvider
{
    public function submit(string $kind, array $invoice): array
    {
        Validation::choice($kind, ["einvoice", "ewaybill"], "GST integration");
        $result = HttpClient::request(
            rtrim(Config::required("GST_API_URL"), "/") . "/" . $kind,
            [
                "invoice" => $invoice,
                "idempotency_key" =>
                    $kind . "-" . $invoice["id"] . "-v" . $invoice["version"],
            ],
            ["Authorization: Bearer " . Config::required("GST_API_KEY")],
        );
        if (
            ($result["status"] ?? "") !== "accepted" ||
            empty($result["reference"]) ||
            ($kind === "einvoice" &&
                (empty($result["irn"]) || empty($result["signed_qr"])))
        ) {
            throw new HttpError(
                502,
                "GST provider response was incomplete. Check provider status before retrying.",
            );
        }
        return $result;
    }
}
