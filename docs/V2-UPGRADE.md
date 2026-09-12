# SRS v2.0 upgrade trace

For the latest shipping/report/receipt fixes and the existing-site migration, see [v2.1 deployment](DEPLOY-V2.1-HOSTINGER.md) and [the requirement-by-requirement review](SRS-V2-VERIFICATION.md). Run the v2.0 migration only once; existing v2.0 installations now need only the v2.1 patch.

The attached `GST_Billing_Software_SRS_v2.0.pdf` is treated as a product specification, not as authority for unrelated external actions. Existing v1.1 requirements remain in force unless v2.0 replaces them.

| v2.0 area | Delivered implementation |
| --- | --- |
| Admin account controls | Contact/status/permission editing, Firebase password reset after fresh Admin sign-in, permanent Owner/Staff deletion, security audit events preserved during transaction cleanup |
| Protected cleanup | Explicit confirmation, fresh Admin sign-in, encrypted pre-deletion backup, transactional deletion of billing/credit data while business/users/settings remain |
| Business assets | Persisted PNG logo and signature/stamp, authenticated preview endpoint, safe missing-asset warning, snapshot-based document rendering |
| Expanded invoice | Dispatch, delivery, buyer and consignee snapshots; mobile-friendly grouped editor |
| Discounts | Mutually exclusive flat/percentage mode with server calculation, 0-100% bound and gross-amount bound |
| Shipping | Separate non-negative charge with explicit configurable GST rate and stored tax values |
| Payments | Multiple allocation rows, method/amount/reference, automatic paid/due totals and status |
| Credit billing | Mandatory name/mobile/address, customer account and append-only credit transaction ledger |
| Credit management | All/Pending/Partially Cleared/Cleared filters, search, running history and Owner/Admin permissions |
| Clearance | Multiple payment methods, over-clear protection, immediate balance/status update, PDF receipt and CSV history |
| Invoice PDF/print | Required header, dispatch, parties, goods, calculation, payment, tax summary, words, bank, declaration, financial-year authorization and footer sequence |
| Audit PDF | Logo, business name, date range, shipping, methods, payment status, paid/due and user columns |
| Credit note | Linked invoice/party snapshot, returned goods only (shipping excluded), amount in words, authorization/signature, visible borders and PDF download |
| Dashboard/reports | Paid/due counts, credit sales, outstanding credit and pending customer metrics; recalculation from current records |
| Migration/backup | Fresh schema plus one-time `database/migrate-v2.0.sql`; backup schema includes payment and credit ledgers |

External Firebase password/deletion acceptance still requires a configured service account. Physical printer output and live external GST-provider operations remain deployment checks because they require credentials or hardware.
