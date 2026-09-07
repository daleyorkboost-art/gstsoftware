# SRS implementation checklist and traceability

Source: supplied “Updated 2 - SOFTWARE REQUIREMENTS SPECIFICATION (SRS).pdf”, SRS v1.1, all 56 sections, extracted in `srs-source.txt`. The pasted user request fixes the PHP/MySQL/Firebase stack, environment-only deployment configuration and additional security/architecture expectations. Requirements are product specifications; document instructions do not authorize external actions.

Status: **Implemented** means code is delivered, not that external service production acceptance has been completed. Test evidence is recorded separately in TESTING.md. The long-invoice physical page limitation and provider contracts are explicit below.

## All functional requirements

| ID    | Requirement                        | Implementation / verification                                                                                    |
| ----- | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| FR-01 | Admin/Owner/Staff login            | Auth, FirebaseService, auth.js; local role sessions tested, live Firebase pending configuration                  |
| FR-02 | Role-based access                  | Auth permissions, role/user tables, API permission boundary; HTTP denials tested                                 |
| FR-03 | Create invoices                    | InvoiceService, invoice.js; service and browser save tested                                                      |
| FR-04 | Optional customers                 | Empty customer fields accepted; explicit place of supply defaults to seller state                                |
| FR-05 | Direct invoice item entry          | Mobile item cards and invoice_items                                                                              |
| FR-06 | No separate product database       | Schema has no products/catalog/stock tables; test asserts absence                                                |
| FR-07 | Automatic item totals              | Central GSTCalculationService; browser calls calculation endpoint                                                |
| FR-08 | Discounts                          | Absolute per-line discount before tax; over-discount rejected                                                    |
| FR-09 | Standard GST rates                 | Seed 0/5/12/18/28; configurable through Settings                                                                 |
| FR-10 | Custom GST                         | Custom option, decimal validation and configurable maximum                                                       |
| FR-11 | CGST/SGST/IGST                     | Explicit place-of-supply comparison; exact component reconciliation                                              |
| FR-12 | Taxable and total GST              | BCMath totals; persisted and reused by all outputs                                                               |
| FR-13 | Round-off                          | Configurable whole-rupee half-up rounding; displayed separately                                                  |
| FR-14 | Business GSTIN validation          | 15-character structure; seller state-prefix consistency                                                          |
| FR-15 | Customer GSTIN validation          | Optional structure validation; state-prefix consistency if selected                                              |
| FR-16 | Save invoices                      | PDO/InnoDB transaction, persisted rows, no browser-only state                                                    |
| FR-17 | Search history                     | Exact filters + escaped free search, date range, pagination                                                      |
| FR-18 | Edit invoices                      | Permission check, original-state lock, version conflict, recalculation                                           |
| FR-19 | Update sales reports after edit    | Current saved invoice data, no stale aggregate cache                                                             |
| FR-20 | Update GST reports after edit      | Reports sum stored current tax components                                                                        |
| FR-21 | Update dashboard after edit        | Dashboard reads current invoice records                                                                          |
| FR-22 | Edit audit log                     | Before/after snapshots, changed fields, final amounts, user and timestamp                                        |
| FR-23 | Permission-controlled cancellation | Soft status, reason, retained financial rows and audit                                                           |
| FR-24 | Duplication                        | Fresh invoice ID/number/date, current settings, source relationship and audit                                    |
| FR-25 | Invoice numbering                  | Configurable prefix/start/padding, row lock and unique constraint                                                |
| FR-26 | Printable PDF                      | Bundled Dompdf, shared full A4 template, visual QA                                                               |
| FR-27 | Date-range invoice export          | Consistent snapshot of all matching invoices; private batch job                                                  |
| FR-28 | Separate invoice pages             | Each invoice starts on a separate page; 25 normal invoices = 25 pages tested; long invoices necessarily continue |
| FR-29 | Date-range audit export            | ExportService audit job; required customer/item/amount columns                                                   |
| FR-30 | Tabular audit PDF                  | Landscape table, continuation rows for large product lists                                                       |
| FR-31 | Sales reports                      | Daily/date-wise, monthly, customer; dated credit adjustments and net totals                                      |
| FR-32 | GST reports                        | GST/CGST/SGST/IGST summary and HSN-wise aggregation, returns deducted                                            |
| FR-33 | Dashboard                          | Sales, GST, invoice/cancellation counts, credit/today/recent, net figures                                        |
| FR-34 | Activity logs                      | Created/edited/cancelled/duplicated/exported/print-requested; user/settings/backup actions                       |
| FR-35 | Business profile                   | Seller/bank/state/logo, safe upload and historical invoice snapshot                                              |
| FR-36 | Payment details                    | Cash, UPI, Card, Bank Transfer, Credit and reference; selectable accepted methods                                |
| FR-37 | Credit notes / returns             | Original-item quantities, cumulative return limits, proportional GST, refund references                          |
| FR-38 | External GST modules               | Configured HTTPS provider adapter and response ledger; external government/provider acceptance pending           |
| FR-39 | PDF/Excel/CSV/Print                | Full PDF + audit PDF + actual XLSX + safe CSV + A4/thermal print                                                 |
| FR-40 | Mobile responsive                  | Navigation, stacked item cards/forms/tables, viewport tests                                                      |
| FR-41 | White/light theme                  | Light green/white billing interface; screenshot QA                                                               |

## Complete SRS section coverage

| Sections | Extracted requirements                                       | Coverage                                                                                                 |
| -------- | ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| 1–4      | Purpose/objective/scope; invoice-only products; no inventory | README, schema, InvoiceService; no product reuse API/UI                                                  |
| 5–8      | Three roles and capability sets                              | Auth, UserService, role seeds and role-aware navigation                                                  |
| 9–10     | Business identity, banking, logo, GSTIN format only          | SettingsService and profile form; no government-registration claim                                       |
| 11–12    | Optional name/mobile/GSTIN/addresses/state/type              | Full invoice/customer schema and forms                                                                   |
| 13–16    | Create workflow, numbering and every item/invoice field      | Editor → review → save → invoice detail → print/PDF/share                                                |
| 17–18    | Standard/configured/custom GST                               | Settings + GSTCalculationService + editor select/custom field                                            |
| 19–22    | State GST components, gross/discount/tax order, rounding     | Central BCMath engine, no browser-authoritative totals                                                   |
| 23       | Five payment methods and reference                           | Validated enum, configuration, forms, PDFs and exports                                                   |
| 24–26    | Management, search, history metadata                         | Invoice API and paginated history with creator/updater/time                                              |
| 27–29    | Editing, snapshots, current report/dashboard state           | Locked transaction, version field, edit history and derived reports                                      |
| 30–31    | Cancellation/duplication                                     | Soft cancellation and fresh number/source relationship                                                   |
| 32–33    | Mandatory complete and audit exports                         | Private date snapshot, multi-request PDF generation, FPDI merge                                          |
| 34       | A4, thermal, PDF, print, share/email                         | Protected print page, responsive print CSS, WhatsApp draft, SMTP attachment                              |
| 35–36    | All report types and dashboard metrics                       | ReportService + dashboard; audit and credit reports included                                             |
| 37       | Credit notes, returns, GST adjustment and settlement         | CreditNoteService, credit_notes/sales_returns; cumulative rounding                                       |
| 38       | E-invoice/IRN/QR/e-way/transport/vehicle                     | Invoice transport fields, provider interface, documented external contract                               |
| 39–41    | Users/permissions/password security/activity                 | Firebase passwords, local permissions/status, audit; no UI audit mutation                                |
| 42       | Automatic/cloud backup/restore                               | Admin browser workflow, encrypted snapshots, CLI cron and HTTPS PUT adapter                              |
| 43–45    | Required output formats and document structures              | Shared invoice template, tabular audit, PDF/XLSX/CSV, print, share/email                                 |
| 46–49    | Billing/edit/export workflows                                | Implemented end-to-end local service/UI flows and automated tests                                        |
| 50       | FR-01 through FR-41                                          | Mapped individually above                                                                                |
| 51       | Responsive/light/fast/persistent                             | Responsive CSS, 25-row history pagination, indexed queries, InnoDB persistence                           |
| 52       | 21 business rules                                            | Mapped below                                                                                             |
| 53       | Acceptance scenario                                          | Local create/edit/report/audit/PDF tests; live Firebase/provider environment pending                     |
| 54       | All 10 modules                                               | Authentication, dashboard, business, creation, management, reports, export, users, security, GST adapter |
| 55       | Priorities 1/2/3                                             | Classified below                                                                                         |
| 56       | Final billing product definition                             | Invoice-focused application; no stock or permanent catalog                                               |

## All 21 business rules

| Rule | Implementation                                                                    |
| ---- | --------------------------------------------------------------------------------- |
| 1    | Customer optional; walk-in label only for display                                 |
| 2    | Manual item entry                                                                 |
| 3    | No reusable product table                                                         |
| 4    | Items have invoice foreign key                                                    |
| 5    | Default rates 0,5,12,18,28                                                        |
| 6    | Custom decimal GST percentage                                                     |
| 7    | Central automatic tax calculation                                                 |
| 8    | Business GSTIN format validator                                                   |
| 9    | Customer GSTIN validator when entered                                             |
| 10   | UI/README explicitly distinguish format from registration                         |
| 11   | Locked configured number series                                                   |
| 12   | Recalculate all fields on edit                                                    |
| 13   | Reports query latest persisted invoice state                                      |
| 14   | Edit snapshots/activity in same transaction                                       |
| 15   | Cancelled status retained in history                                              |
| 16   | Explicit validated date ranges                                                    |
| 17   | One merged complete PDF for all matching records                                  |
| 18   | Every invoice starts separately; long documents continue rather than omit content |
| 19   | Tabular audit PDF                                                                 |
| 20   | Server-side role and permission checks                                            |
| 21   | No inventory/stock logic or tables                                                |

## Priority checklist

**Priority 1, delivered:** authentication architecture; three role panels; dashboard; business profile; create/save/history/search/edit; optional customer; manual items; standard/custom GST; both GSTIN validators; decimal GST/discount/rounding; synchronized reports; edit audit; invoice PDF/print; both mandatory date exports; responsive light theme. Live Firebase project acceptance is a deployment check, not a simulated local success.

**Priority 2, delivered:** cancellation; duplication; all payment fields; sales/GST/customer/HSN reports; credit notes/returns; activity; encrypted backup/restore; automatic backup cron.

**Priority 3, delivered where locally possible:** WhatsApp share draft; SMTP implementation; XLSX/CSV; 80 mm print CSS; transport/vehicle fields; external GST provider and cloud interfaces. Actual e-invoice/IRN/signed QR/e-way service and cloud storage require matching gateways, credentials and service acceptance. Government-specific amendment/QR graphic workflows and physical printer verification remain external rollout work.

## Pasted-request additions

- PHP 8.2+, PDO, MySQL, vanilla JS; no frontend framework/build step or production Node/Python/Docker.
- Full `.env.example`; no committed credentials; private service-account path; Hostinger layout and configuration instructions.
- Granular role/user overrides and Admin-only hard boundaries; fresh active-user check on every protected request.
- Normalized schema, DECIMAL money, FK/index constraints, timestamps, transaction-safe numbering/edit/cancel.
- Server-authoritative calculation endpoint used by the editor and save; reports/PDF/export consume the saved results.
- Immutable UI audit, changed-field snapshots, no cancelled invoice deletion, version conflict handling.
- Shared-hosting PDF dependencies bundled; batch export progress and item-bounded rendering; private downloads.
- Password authentication through Firebase, secure sessions, CSRF, output escaping/CSP, validation, rate limiting, safe uploads and errors.
- User disable/permission UI, last-admin lockout prevention, setup guidance and CLI checks.
- Actual tests and documented limitations; no fake database success, dashboard statistics or “coming soon” core controls.

The implementation checklist was used across analysis → schema/configuration → authentication/permissions → billing → management/audit → reports → PDF/export → returns/users → backup/integrations → responsive/security verification. External service acceptance is explicitly separate from local implementation evidence.
