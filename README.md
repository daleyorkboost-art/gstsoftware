# Ledger — GST Sales Billing

A PHP/MySQL billing application built from the supplied SRS v1.1. Vanilla HTML, CSS and JavaScript; Firebase Authentication; PDO prepared statements; BCMath accounting; server-generated PDF and XLSX exports. One installation serves one business. **There is no product master, inventory or stock database.** Items are saved only inside invoices.

For direct File Manager upload, start with [HOSTINGER-SETUP.md](HOSTINGER-SETUP.md) and use the clean `hostinger-upload/` folder. Then see [configuration](#configuration). See [requirements coverage](docs/REQUIREMENTS.md), [accounting decisions](docs/DECISIONS.md), [test evidence](docs/TESTING.md), and [external integration contracts](docs/INTEGRATIONS.md).

## Included features

- Admin, Owner and Staff access, granular server-side permissions, local account activation, Firebase provisioning, account disabling and last-admin protection.
- Dashboard with sales, GST, invoice counts, cancellations, credit sales, today's sales, dated credit adjustments, net sales and recent invoices.
- Business identity, GSTIN format validation, bank information and securely re-encoded logo uploads.
- Invoice-only item entry, optional customers, explicit place of supply, standard/custom GST rates, discounts, decimal calculations, round-off and all five payment methods.
- Sequential numbers; history/search/date filtering; pagination; create, view, edit, duplicate and cancel; optimistic edit versioning and immutable UI audit history.
- Daily/date-wise, monthly, customer, GST component, HSN and credit note reports derived from current accounting records.
- Quantity-based credit notes and sales returns, original-rate GST adjustments, refund/adjustment references and cumulative rounding reconciliation.
- A4 PDF, A4/80 mm browser printing, complete date-range invoice PDF, tabular audit PDF, CSV and genuine XLSX.
- Consistent export snapshots, progress feedback, bounded rendering batches, separate invoice pages, ownership-checked downloads and expired-file cleanup.
- WhatsApp message preparation, credential-driven SMTP invoice attachments, and an adapter interface for government GST providers.
- AES-256-GCM encrypted backup and restore, pre-restore backup, CLI/cron backup, optional HTTPS cloud upload and retention cleanup.
- Responsive navigation, mobile item cards, empty/error/loading states, confirmations and success feedback.

## Requirements

- PHP **8.2 or newer** (tested with PHP 8.3.33).
- MySQL 8.0+ or MariaDB 10.6+ with InnoDB, utf8mb4 and JSON support (tested with MariaDB 11.4.5).
- PHP extensions: `bcmath`, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `dom`, `fileinfo`, `gd`, `zip`, `zlib`, plus standard PHP JSON/session support.
- Apache/LiteSpeed with `.htaccess` support; HTTPS in production.
- Start with `memory_limit=256M`, `max_execution_time=120`, `upload_max_filesize=100M`, `post_max_size=110M`. Choose tighter upload limits if browser restore is unnecessary. Application logo uploads are limited separately to 2 MB.
- Outbound HTTPS to Firebase/Google endpoints; outbound SMTP only if email is enabled.
- Writable **private** `storage/` directory.

No Node, Python, Docker, build server or persistent worker is required in production. PHP dependencies are bundled in the delivery archive. Composer is only needed when updating those dependencies during development.

## Hostinger deployment

1. Upload the contents of `hostinger-upload/` directly through File Manager; no ZIP or extraction is needed. Its `.env` contains your Firebase web configuration and a production application key. Do not upload `tmp/`, the development root `.env`, database tools or test session files from the working directory.
2. Create a MySQL database and database user in hPanel. Use the exact hPanel-prefixed names and assign the user to this database.
3. In phpMyAdmin, select the empty database and import **`database/schema.sql` followed by `database/seed.sql`**. The seed includes roles, permissions and default settings, but no users, invoices or fake statistics. Do not re-import these initial setup files into a populated database.
4. Edit the existing `.env` supplied in the direct-upload folder and fill in the values below. Preserve the included Firebase settings and generated APP_KEY. For a future fresh installation without a prefilled `.env`, copy `.env.example` and generate an application key with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` or a cryptographically secure password generator. Keep this key for restoring encrypted backups.
5. Configure Firebase Email/Password authentication as described below. Set `BOOTSTRAP_ADMIN_EMAIL` to the exact email of your first administrator.
6. Point the domain/subdomain document root to the package's **`public/`** directory if your hosting plan permits it.
7. If the document root is fixed to `public_html`, upload the complete package under `public_html` with its root `.htaccess`. The domain root redirects to `/public/`; the root rules deny all other package paths. Verify both `.htaccess` files were uploaded. This fallback deliberately uses the `/public/` URL. The preferred layout keeps private directories outside the web root.
8. Select PHP 8.2+ and enable the extensions in hPanel's PHP configuration. Give the hosting PHP user write access to `storage/` and its subdirectories. Typical permissions: private directories `0700`/`0750`, private files `0600`/`0640`, public files `0644`, public directories `0755`, adjusted to the hosting process owner. Do not use `0777`.
9. Enable the domain's TLS certificate. Set `APP_ENV=production` and `APP_URL` to the actual HTTPS application URL (including `/public` for the fallback layout).
10. Open the application. Use **Activate invited account** with your bootstrap email and a strong password, follow the Firebase verification email, and sign in. If you already created that Firebase account, verify its email and sign in directly. The first verified matching identity becomes Admin only when the local users table is empty.
11. Complete Business profile, upload your logo, and review GST/payment settings and invoice numbering. Add Owner/Staff accounts under Team & permissions. These are ordinary business setup actions in the UI; no source edits are needed.
12. Optionally configure cron and SMTP/cloud/GST provider credentials. Run `php bin/check.php` through SSH if available to check runtime configuration without printing secrets. SSH is not required for normal setup.

## Firebase setup

1. Create/select a Firebase project and add a web app. Copy the web configuration values to the corresponding `FIREBASE_*` entries in `.env`.
2. Enable **Authentication → Sign-in method → Email/Password**. Add the production domain under Authorized domains and configure verification/password reset email templates and action URLs in Firebase.
3. Create or activate the bootstrap administrator email and verify it. The local application grants access only to that initial verified bootstrap email or to an administrator-approved local user. A Firebase account alone does not grant business access.
4. For new team members, Admin first adds a local user. The member can use Activate invited account, verify their email and sign in. Local pending users bind to Firebase UID only after matching a verified email.
5. Optionally generate a Firebase service account JSON with appropriate Authentication admin privileges and store it **outside `public/`**. Set `FIREBASE_SERVICE_ACCOUNT_PATH` to its absolute path. This is required only for the Admin's **Provision Firebase account** button, which creates a Firebase user with a temporary password; it is not required for ordinary sign-in and token verification. Never upload this JSON into `public/assets` or commit it.
6. Firebase web API keys identify the web application and are returned to the browser as intended. The service account private key and SMTP/database/provider credentials are never returned by the configuration endpoint.

Authentication flow: browser calls Firebase's REST Email/Password endpoint → passes ID token to PHP over HTTPS with CSRF token → PHP verifies RS256 signature against Google's certificates, project issuer/audience, subject and time claims → performs online Firebase account lookup → maps Firebase UID to an active local user → rotates an HttpOnly/SameSite PHP session. Every protected request re-reads local status and permissions. Firebase refresh tokens stay in browser memory; after a reload the server session lasts until its absolute expiry, and then sign-in is required. Local disabling takes effect on the next request. Remote Firebase account changes are checked at login/refresh; existing PHP sessions expire within one hour.

References: [Firebase ID token verification](https://firebase.google.com/docs/auth/admin/verify-id-tokens), [Firebase Authentication REST API](https://firebase.google.com/docs/reference/rest/auth), [Dompdf packaged releases](https://github.com/dompdf/dompdf/releases).

## Configuration

All variables are declared in `.env.example`. `FIREBASE_MEASUREMENT_ID` stores the supplied optional Analytics identifier; Analytics tracking is not enabled. Operating-system environment variables override `.env`. Values may be quoted. Use one value per line; multiline private keys belong in the external JSON file.

| Variables                                                                                            | Required / meaning                                                                                         |
| ---------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                                                                                            | `production` enables Secure cookies; `development` only for local HTTP testing                             |
| `APP_URL`                                                                                            | HTTPS application base URL; deployment/reference configuration                                             |
| `APP_KEY`                                                                                            | Random secret of at least 32 characters; encrypts/authenticates backups                                    |
| `APP_TIMEZONE`                                                                                       | Default `Asia/Kolkata`; PHP sets the database session offset automatically for consistent audit timestamps |
| `SESSION_SECONDS`                                                                                    | Absolute session lifetime; constrained to 300–3600 seconds                                                 |
| `DB_HOST`, `DB_PORT`                                                                                 | MySQL host and port from hPanel                                                                            |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`                                                          | MySQL database credentials                                                                                 |
| `FIREBASE_API_KEY`, `FIREBASE_PROJECT_ID`                                                            | Required for sign-in and backend validation                                                                |
| `FIREBASE_AUTH_DOMAIN`, `FIREBASE_STORAGE_BUCKET`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_APP_ID` | Web app settings from Firebase; the REST sign-in implementation primarily uses API key and project ID      |
| `FIREBASE_SERVICE_ACCOUNT_PATH`                                                                      | Optional absolute private JSON path for Admin provisioning                                                 |
| `BOOTSTRAP_ADMIN_EMAIL`                                                                              | Initial verified administrator email; no automatic bootstrap occurs after any local user exists            |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM`, `MAIL_ENCRYPTION`           | Optional SMTP; `tls` on 587 or `ssl` on 465; required only to send email                                   |
| `GST_API_URL`, `GST_API_KEY`                                                                         | Optional authorized provider adapter URL and bearer credential; see integration contract                   |
| `BACKUP_CLOUD_URL`, `BACKUP_CLOUD_TOKEN`                                                             | Optional HTTPS PUT storage adapter and bearer token                                                        |
| `BACKUP_RETENTION_DAYS`                                                                              | Local automatic-backup retention, default 30 days                                                          |
| `EXPORT_CHUNK_SIZE`                                                                                  | Maximum invoices rendered in one step, default 20; additionally bounded to 200 items                       |
| `LOGO_MAX_BYTES`                                                                                     | Logo upload limit, default 2097152 bytes                                                                   |

Business state, bank details, default terms, accepted payments, GST options and number series are database settings maintained through the application.

## Roles and permissions

| Role  | Defaults                                                                                                                                         |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| ADMIN | All permissions; administrative rights cannot be removed with per-user overrides                                                                 |
| OWNER | Billing, edits, cancellation, duplication, reports, invoice/audit exports, activity and credit notes; business profile can be explicitly granted |
| STAFF | Create/view/search invoices and print/download individual invoices; further non-admin permissions require an explicit grant                      |

Permissions: `create_invoice`, `view_invoice`, `edit_invoice`, `cancel_invoice`, `duplicate_invoice`, `export_invoice`, `export_audit_report`, `view_reports`, `manage_users`, `manage_business_profile`, `view_activity_logs`, `manage_settings`, `configure_invoice_numbering`, `manage_credit_notes`, `manage_backups`.

`manage_users`, `manage_settings`, `configure_invoice_numbering`, and `manage_backups` are Admin-only even if a crafted request tries to grant them to another role. Per-user Allow/Deny overrides otherwise replace role defaults. All business invoices are visible to users with `view_invoice`; there is no “own invoices only” rule in the supplied SRS. Export files are restricted to the user who generated them.

## PDF, printing and exports

`PDFService` renders the shared escaped invoice template with bundled Dompdf. Remote resources and PHP execution in templates are disabled. Logos are local re-encoded PNG files. A4 includes seller, buyer, item/HSN/description, every tax component, rounding, payment, bank, notes and signature area.

Complete invoice export snapshots every matching invoice in a transaction, writes a private snapshot file, renders small batches through browser-driven POST requests and merges them using FPDI. One ordinary invoice = one page; **long invoices continue on further pages** to preserve all information. This is a documented physical limitation of the SRS's exact single-page wording. Audit exports use landscape tables and continuation rows for long item lists. Cancelled invoices are included in exports and clearly marked.

The final merge holds PDF objects in memory. Chunking reduces rendering pressure but does not make unlimited-size exports possible on shared hosting. The tested 25-invoice PDF had exactly 25 pages; the combined larger-export test peaked at approximately 70 MB. For unusually large volumes, use a narrower date range or increase hosting resources. Never silently omit matching records.

CSV is UTF-8 with a BOM and spreadsheet-formula protection. XLSX is a genuine zipped OpenXML workbook built with inline string cells, preventing formulas from executing. Numeric-looking values are stored as text to preserve identifiers and exact decimal values; use Excel's conversion functions when doing arithmetic there.

The 80 mm print mode provides a compact browser layout. Select the correct printer paper width and disable browser headers/footers. Exact physical printer margins require a test on the user's printer; no printer hardware was available during development.

## Backup and restore

Manual backup and restore are Admin-only and CSRF-protected. Backups include tables and PNG logos, gzip compression and authenticated AES-256-GCM encryption. They do not include `.env`, SMTP passwords or the service account file. Retain `APP_KEY` separately: without the original key, encrypted backups cannot be restored.

Browser restore requires an encrypted backup and the exact phrase `RESTORE ALL DATA`, then a confirmation dialog. The current state is backed up first; data changes occur in a transaction. The restoring administrator's Firebase identity must remain an active Admin in the backup. Normal UI operations cannot edit or delete audit history; full administrative restore intentionally replaces historical data and records a restore event.

Optional hPanel cron commands (replace `/absolute/path/ledger` with your application root and select your host's PHP 8.x CLI binary):

```sh
# Nightly backup; optionally uploads to the configured cloud endpoint
php /absolute/path/ledger/bin/backup.php
# Hourly cleanup of expired exports and rate-limit records
php /absolute/path/ledger/bin/cleanup.php
```

Example schedules: backup `0 2 * * *`; cleanup `0 * * * *`. Check cron output and perform a restore drill periodically. CLI restore: `php bin/restore.php /absolute/backup.gstbackup ADMIN_USER_ID --confirm-replace`.

Application backups are intended for small/medium datasets and load the snapshot into memory. Browser/CLI restore currently enforces a 100 MB encrypted-file limit and 128 MB expanded JSON limit; use sufficient PHP memory. Larger databases require a hosting-native MySQL backup/restore workflow. A cloud upload adapter must support the documented HTTPS PUT contract.

## Project structure

```text
public/                 Only intended web root
  index.php             Application shell and security headers
  api.php               Authenticated API routing / permission boundary
  print.php             Protected A4 / thermal print page
  assets/               Modular JavaScript, responsive CSS, favicon
config/bootstrap.php    Configuration, autoloading, private storage setup
src/                    Authentication, validation, accounting, persistence,
                        reporting, audit, PDF/XLSX, exports and integrations
templates/invoice.php   Shared escaped invoice document
database/               Initial schema and non-demo seed
storage/                Private uploads, logs, sessions, exports and backups
vendor/                 Bundled production PHP dependencies and licenses
bin/                    CLI checks, backup, restore and cleanup
tests/                  Unit, integration, HTTP and large-export checks
docs/                   Requirements, decisions, integration contracts and QA
.env.example            All environment-specific configuration
composer.json/.lock     Reproducible dependency definitions
```

Database tables: `roles`, `permissions`, `role_permissions`, `users`, `user_permissions`, `business_profile`, `invoice_number_settings`, `system_settings`, `invoices`, `invoice_items`, `activity_logs`, `invoice_edit_history`, `credit_notes`, `sales_returns`, `integration_requests`, `rate_limits`. Foreign keys, financial DECIMAL fields, unique invoice numbers, version checks and search/date indexes enforce relationships and efficient lookup.

## Security and operations

- HTTPS, Secure/HttpOnly/SameSite cookies, strict sessions, session regeneration, absolute expiry and CSRF on every state-changing API call.
- Backend authentication/status/role/permission checks; Firebase signature and account checks; login/export/email rate limits.
- PDO prepared statements with emulated prepares disabled; controlled SQL identifiers; request/date/decimal/enum validation.
- Escaped HTML, restrictive CSP, no remote PDF assets, private credentials, sanitized production errors and logs.
- MIME/dimension/size validation and GD re-encoding for images; random filenames; uploads outside the document root.
- Transactional accounting, audit snapshots, optimistic edit versions, locked number generation and unique constraints.
- Encrypted authenticated backups, restricted exports, no audit editing/deletion endpoints, and last-admin protection.

Before real use: confirm HTTPS and cookie behavior, verify private paths return 403/404, create separate Owner/Staff accounts and exercise permissions, check SMTP sender/DNS if enabled, make and restore a backup, and approve an A4/thermal sample with your business details. Use the `.env` inside `hostinger-upload/` for production. Do not upload test tooling or the development root `.env`.

## Testing and remaining external checks

Run `php tests/unit.php` without a database. Database tests **reset a dedicated database ending in `_test`** and additionally require `GST_TEST_ALLOW_RESET=1`. Run `php tests/integration.php`, then optionally `php tests/large-export.php`. Development HTTP tests use Node only as a test runner: generate local sessions with `php tests/http-fixtures.php`, run `php -S 127.0.0.1:8080 -t public`, then `node tests/http.cjs`.

See [TESTING.md](docs/TESTING.md) for actual evidence and remaining checks. Live Firebase sign-in/provisioning, SMTP delivery, cloud upload, government provider submissions and physical printer output were not exercised because external credentials/accounts/hardware were not supplied. These must be validated against your configured services before production use. Government provider-specific integration requires an adapter matching its actual API contract; adding an arbitrary API key alone is not enough.

## Troubleshooting

| Symptom                                    | Check                                                                                                           |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| Workspace setup required                   | Import both SQL files, check database names/user grants and `.env` location                                     |
| Sign-in denied                             | Email/Password enabled, correct Firebase project/API key, verified bootstrap/invited email, active local user   |
| Works locally but login loops online       | HTTPS must be active with production Secure cookies; verify PHP private session directory is writable           |
| 403 after a tab stayed open                | Session/CSRF token changed; reload and sign in again                                                            |
| Invoice cannot save                        | Business profile, place of supply, item values, GSTIN format and permission; refresh stale invoice versions     |
| Invoice with credit notes cannot be edited | Intentional protection for original return accounting; record another appropriate credit adjustment             |
| PDFs fail or lack images                   | PHP extensions, writable storage/font cache, memory/time limits, valid stored PNG logo                          |
| Email fails                                | SMTP host, port, TLS mode, sender authorization, firewall and credentials                                       |
| Export is incomplete                       | Keep export page open; retry errors; increase hosting limits or narrow range; no records are silently discarded |
| Cannot restore backup                      | Correct original APP_KEY, active matching Admin identity, matching schema version, upload/memory limits         |
| 500 before the app loads                   | PHP version/extensions, `.htaccess` support and host PHP logs; app logs intentionally omit secrets/stack traces |

The schema files initialize a new installation. For future upgrades, back up first and apply a reviewed versioned migration; do not rerun initialization over production data.
