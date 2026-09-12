# SRS v2.0 review and shipping-tax update — 10 September 2026

Reviewed `GST_Billing_Software_SRS_v2.0.pdf`, including FR-01–FR-32 and acceptance scenarios A–N. The PDF supplies product requirements, not operational authorization. Shipping tax was already calculated by the v2 engine with a configurable default of 0%; this patch completes its visibility and report treatment.

| SRS requirements | Implementation/review result |
| --- | --- |
| FR-01–02 authentication/permissions | Existing Firebase/session flow and API permissions retained; 35 HTTP security checks passed. Real Firebase acceptance remains a deployment check. |
| FR-03–05 user management/password/delete | Account/contact/role/permission editing, fresh Firebase reauthentication, password update and Owner/Staff deletion present. Explicit deletion confirmation enforced. No live password update or Firebase deletion performed. |
| FR-06 protected transaction deletion | Business/users/settings retained; backup now taken inside the same serialized transaction as deletion. Security/admin events retained and old exports invalidated. Real Admin reauthentication and destructive end-to-end action remain deployment checks. |
| FR-07 logo/signature | Upload validation, authenticated asset preview and embedded image rendering present in invoice/audit/credit-note paths. Invalid executable upload rejected over HTTP. Verify the actual hosted logo and printer after upload. |
| FR-08–09 invoice fields/table | Buyer/consignee/dispatch/delivery fields present and round-trip tested; formal goods columns reviewed. Fixed state-name display alongside code. |
| FR-10–11 discounts | Single flat/percentage mode, bounds and server calculation verified. |
| FR-12 shipping | Separate shipping charge and per-invoice GST rate; saved rate retained on edits; tax visible in editor, invoice, PDF and CSV/XLSX. Fixed missing shipping in HSN report and zero-tax PDF summary. Intra/interstate, odd paise and invalid values tested. |
| FR-13–17 payments/credit creation | Full/split/partial/due payments, mandatory credit identity, stored allocations and credit account tested. Added configurable Other method. Credit duplication no longer invents a payment. |
| FR-18–21 credit management | Owner/Admin screens, filters, customer history, split clearances, overclear protection, receipt PDF and CSV history present. Full/partial clearance tested. Receipt snapshots, chronological safeguards and settled-history edit protection added. |
| FR-22–26 audit/invoice output | Audit header/range/columns reviewed. State names, grouped GST, words, bank/declaration and date-based financial year present. A4 invoice layout corrected to avoid a near-empty second page for the tested one-item case. |
| FR-27–29 credit note | Original invoice references/party/business, goods-only returns, visible borders, words and signature area present. Full goods return excludes shipping and shipping tax, including round-off allocation. |
| FR-30 audit | Invoice/payment/credit/admin events present; password text omitted from audit data. Security events survive transaction cleanup. |
| FR-31 synchronization | Saved invoice tax, GST/HSN reports and credit statuses verified. Paid amounts now include credit clearances while initial payment allocations remain unchanged; outstanding credit sales and paid counts corrected. |
| FR-32 exports/mobile | CSV/XLSX and PDF regression checks passed; desktop create/edit, all main screens and 390px mobile form/add/remove tested. Long invoices use continuation pages to avoid loss of information. |

Verification performed against a separate `gst_v2_test` database; the supplied live database credentials/data were not used. Results: **33 unit checks; 68 integration/v2 checks; 35 HTTP security checks; 10 concurrent saves with unique invoice numbers; browser desktop/mobile workflow pass**. V2.1 migration executed twice against populated test data and preserved invoices/settings. Invoice, interstate invoice, credit note, receipt and audit PDFs rendered and inspected locally.

Reproduce with the existing `docs/TESTING.md` setup, explicitly pointing environment variables at a disposable `*_test` database. `php tests/v2.php` runs the original integration suite then the v2 additions, and resets that test database only when `GST_TEST_ALLOW_RESET=1`. `php tests/unit.php` tests calculation/validation. Never enable the reset flag on the deployed application.

Not claimed as verified: live Firebase login/reset/delete, the fully reauthenticated destructive Admin workflow, actual Hostinger server configuration, SMTP/cloud/GST-provider integrations or physical printing. Those need the deployed environment/credentials. Review this table together with `docs/DEPLOY-V2.1-HOSTINGER.md`; implemented and locally tested is not the same as live operational acceptance.
