# Integration contracts and credentials

## Firebase Authentication — implemented, requires project configuration

The browser uses the official Firebase REST operations `accounts:signInWithPassword`, `accounts:signUp`, `accounts:sendOobCode` and Secure Token refresh. PHP validates project-specific RS256 ID tokens and calls `accounts:lookup` to check current Firebase account status at login/refresh. Service-account OAuth signs an RS256 assertion and exchanges it as form-urlencoded data at Google's token endpoint. Admin provisioning uses the project-scoped `accounts:signUp` endpoint. No service-account credentials are exposed in JavaScript.

Configure Email/Password, authorized domain, email verification and password reset settings. The local database always determines business authorization. Firebase accounts not approved locally cannot access invoices. No live Firebase project was provided for end-to-end service testing.

## SMTP — implemented, requires credentials

`MailService` uses bundled PHPMailer with TLS/SSL and sends an actual PDF attachment. Configure all `MAIL_*` variables. It reports a failure if configuration or delivery fails; it does not simulate success. The UI requires the recipient address and an explicit Send invoice action. No email was sent during development.

## WhatsApp — implemented share workflow

The user reviews an invoice summary and explicitly opens `https://wa.me/?text=...`. Sending remains under the user's control inside WhatsApp. There is no public invoice link or unprotected PDF download. Download the PDF and attach it in WhatsApp when the full invoice is required. This workflow requires no WhatsApp Business API credentials. Automated WhatsApp Business messaging is not implemented.

## GST e-invoice / IRN / signed QR / e-way bill — provider adapter implemented

Government integration is explicitly external in the SRS. This application does not claim legal eligibility, government verification or government connectivity without an authorized provider.

Implement/configure a provider gateway with:

```http
POST {GST_API_URL}/einvoice
Authorization: Bearer {GST_API_KEY}
Content-Type: application/json

{
  "invoice": {"id": 123, "version": 1, "invoice_number": "INV-00123", "items": []},
  "idempotency_key": "einvoice-123-v1"
}
```

`invoice` is the complete server-calculated invoice including customer/business snapshots, item HSN/SAC and GST components, payment and transport fields. The gateway must map it to the provider's required schema, check applicability/mandatory tax fields, handle provider authentication, preserve idempotency and return authoritative results.

Successful response contract:

```json
{
  "status": "accepted",
  "reference": "provider-reference",
  "irn": "actual-IRN",
  "signed_qr": "actual-provider-signed-QR-payload"
}
```

For `POST {GST_API_URL}/ewaybill`, `status` and `reference` are required; provider-specific bill/transport results may be included. The app records the returned response and exposes it to Admin under GST integrations. A signed QR payload is recorded as provider data; rendering a government QR graphic requires the gateway to supply/encode its prescribed representation. This is not a fabricated generic QR code.

Non-2xx, malformed or incomplete responses are failures. In ambiguous timeout cases, check the provider for the idempotency key before retrying. Government cancellation/amendment workflows depend on the provider and are not simulated. Accepted provider invoices are locked against local editing and cancellation. A real government-connected rollout must implement the provider's permitted amendment/cancellation workflow before enabling those changes.

No universal GST gateway URL or key exists. An arbitrary GSP endpoint will not necessarily match this adapter. The provider contract and credentials are genuinely external dependencies.

## Cloud backup — implemented transport, requires storage gateway

The backup cron creates an encrypted `.gstbackup` locally, then sends:

```http
PUT {BACKUP_CLOUD_URL}/{generated-backup-filename}
Authorization: Bearer {BACKUP_CLOUD_TOKEN}
Content-Type: application/octet-stream
```

The gateway must persist the bytes and return 2xx only after storage succeeds. A non-2xx or failed request is reported as failure; the local encrypted file remains. This contract can be backed by a private object-storage bucket through your gateway. It is not a direct implementation of AWS request signing, Google Drive OAuth, Dropbox or another proprietary storage API. Configure lifecycle/retention and encryption policies on the cloud service independently.

No external service credentials or customer data were sent during implementation testing.
