# Verification record

Tested locally on 7 September 2026 with PHP 8.3.33, MariaDB 11.4.5, Chrome desktop/headless browser automation, Dompdf 3.1.6 and FPDI 2.6.8. Test data and session fixtures are isolated in a database named `ledger_test`; they are not included in the direct-upload folder.

The table below records the v1.1 baseline run. On 10 September 2026, the v2.0 changes passed JavaScript syntax checks, a full PHP grammar parse of all 41 PHP/template/test files, and `git diff --check`. The current workstation did not expose PHP, MySQL, a running container engine or Poppler, so the updated unit/integration/browser suite and rendered v2.0 PDF visual review remain to be rerun in the configured test environment; they are not claimed as completed here.

## Automated results

| Area | Evidence |
| --- | --- |
| Accounting and validation | 27 unit checks: standard/custom GST, intra/interstate, discounts, multiple items, fractional quantities, odd-paise reconciliation, positive/negative half-up rounding, invalid values, GSTIN, dates and CSV formula protection |
| Database integration | 38 checks: no product catalog, role defaults/denials, forged totals ignored, numbering, save/edit/dashboard/GST synchronization, audit snapshots, stale edit conflicts, duplication, soft cancellation, rollback, proportional returns, over-return rejection, return-protected invoices, SQL injection search, last Admin, permission grants, PDF/CSV/XLSX, export ownership, encrypted restore and tamper rejection |
| HTTP security | 35 checks: unauthenticated endpoints, missing CSRF, Staff/Owner administrative denials, GET mutation prevention, missing record, unauthorized audit export, invalid date/tax, disguised executable upload, session/logout and private file denial |
| Concurrent numbering | 10 parallel PHP processes saved invoices with 10 different invoice numbers |
| Complete PDF export | 25 invoices exported into one PDF containing exactly 25 pages |
| Large invoice | 50 detailed items retained across 7 pages; final item and final totals verified in extracted text and rendered image |
| Large audit | Same 50 items retained in tabular continuation rows across 3 pages |
| XLSX | Workbook opened as ZIP/XML; header plus all 25 data rows present |
| Browser workflow | Authenticated test session → dashboard → create/review/save → view → edit/review/save → activity; all nine additional management screens loaded without runtime errors |
| Responsive | Desktop 1440 px and mobile 390 px; mobile page had no horizontal document overflow; item add/remove worked |
| PDF visual review | Rendered A4 invoice, complete batch, audit and long invoice/audit pages inspected for clipping and missing content |
| Dependency audit | Composer audit reported no known security advisories for bundled dependencies at verification time |
| Syntax | All application PHP files passed `php -l`; browser imported the production JS modules and completed the workflows without runtime errors |

The browser used server-side **test sessions written from CLI outside public/**, not a production authentication bypass. There is no demo login or test-login endpoint. This verifies the authenticated billing UI and API, not a live Firebase login.

## Fixes made during verification

- PHP permission-expression syntax error and initial empty-hash routing.
- Backup restore ordering for duplicated invoices with self-referencing foreign keys.
- Credit-note round-off allocation so complete returns reconcile to the original rounded invoice total.
- Cumulative item tax rounding so repeated small returns cannot produce a negative remaining tax component.
- UTF-8 UI symbols damaged by a development shell rewrite, and hidden custom-rate styling.
- Item-bounded PDF batches, audit continuation rows and real XLSX output.
- Invoice edit calculation permission independent of create permission.
- Session identity binding, usable-Admin protection, database timezone initialization and transactional provider submission.

## Reproduction

1. Use a private test environment, PHP extensions from README, and a dedicated database ending `_test`.
2. Configure a development-only `.env`. Do not point the test scripts at production.
3. `php tests/unit.php`
4. Set `GST_TEST_ALLOW_RESET=1`, then `php tests/integration.php`. This intentionally resets the test database.
5. `php tests/large-export.php` on that fresh test database; `php tests/concurrency.php`.
6. `php -S 127.0.0.1:8080 -t public`, then `php tests/http-fixtures.php` and `node tests/http.cjs`. Node is a development test runner only.
7. Browser workflow checks can be repeated manually using the acceptance checklist below with configured real Firebase accounts.

## Deployment acceptance still required

- [ ] Real Firebase Admin/Owner/Staff login, verification and password reset emails.
- [ ] Firebase service-account user provisioning, disabled/deleted account behavior and session expiry against the configured project.
- [ ] Hostinger Apache/LiteSpeed `.htaccess`, HTTPS/Secure cookies, database grants and cron paths.
- [ ] SMTP attachment delivery with the actual sender/domain configuration.
- [ ] Cloud backup upload and download through the configured storage gateway.
- [ ] GST provider applicability/schema/authentication, authoritative IRN/signed QR/e-way response and provider-specific cancellation/amendment workflows.
- [ ] Physical A4 and 80 mm printer output, margins and browser print headers.
- [ ] Load testing at the actual business volume and hosting limits; final PDF merge and backup still consume memory proportional to output/data size.

## Business acceptance walkthrough

1. Configure business name, state, GSTIN, bank details and logo; choose numbering and GST settings.
2. Add Owner and Staff. Confirm Staff cannot edit/cancel/export/administer unless explicitly granted the relevant permission.
3. Create a walk-in invoice, then a multi-item customer invoice with HSN, description, fractional quantity, both discount modes, standard/custom GST, shipping, and split payment allocations.
4. Create a partial/due invoice with name, mobile and address; verify the credit ledger, partial clearance, multi-method clearance receipt and customer-history export.
5. Verify intra-state CGST/SGST and inter-state IGST, total GST and round-off; save, reopen, print and download PDF.
6. Edit an invoice and confirm totals, dashboard, sales/GST/HSN reports and before/after audit snapshots change together.
7. Duplicate and cancel invoices; verify the duplicate has a new number and cancellation retains its history while reducing sales totals.
8. Return some and then all items; verify quantity limits, GST adjustments, settlement references and net sales reconciliation.
9. Export a date range in both mandatory PDFs, CSV and XLSX; confirm counts and complete information.
10. Create an encrypted backup, change a test record, restore, and verify recovery and audit entry.
11. Repeat creation/search/edit on mobile and check the actual print/export layout with business data.

## Explicit limitations

No live external credentials/accounts or physical printers were supplied. Their operations were not simulated or reported as verified. Each invoice starts on a new PDF page, but long invoices use continuation pages to avoid truncation. This application does not validate current GST registration, legal eligibility for e-invoicing, statutory amendment rules or GSTR filing. Its calculation rules follow the supplied SRS and documented rounding decisions. Government features require an authorized provider gateway and its specific acceptance process.
