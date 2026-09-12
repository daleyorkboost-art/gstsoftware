# Accounting and scope decisions

The supplied SRS v2.0 supersedes the v1.1 baseline captured in srs-source.txt. It retains PHP, MySQL, Firebase, shared hosting, and no permanent product catalog while adding the payment, credit-ledger, destructive-admin and formal-output rules traced in V2-UPGRADE.md.

This installation serves one business. All active authorized users operate within that business; this is not a multi-tenant SaaS. Exactly ADMIN, OWNER and STAFF roles exist.

Customer details are optional. Place of supply is an explicit required field defaulting to the business state; it controls interstate tax even when customer information is absent. GSTIN validation checks format and matching state prefix, not registration status.

Amounts use decimal strings and BCMath. Quantity has three decimal places; rate, flat discount, percentage discount, shipping and tax percentage have two. Each line stores its selected discount mode/value and the resulting cash discount; only one mode is active. Shipping is separately displayed and uses the configured shipping GST rate. Gross, total GST and each displayed accounting amount round half up to two decimals. For odd tax paise, CGST receives the extra paise and SGST the remainder so the displayed components reconcile exactly. Optional whole-rupee rounding defaults on. Reports aggregate stored, server-calculated amounts without calculating tax again.

Payment allocations are immutable invoice evidence for the initial bill payment. Their sum determines initial Amount Paid; the remaining amount creates a credit-ledger transaction. Later clearances are separate transactions and receipts, are applied oldest-credit-first for current invoice due statuses, and never rewrite the original allocation rows.

Cancelled invoices remain intact and are excluded from sales/tax totals. Credit notes record returned quantities and proportional original taxable/tax amounts; cumulative rounding apportions tax paise and the last return receives any remainder; invoice round-off is apportioned so a full return exactly matches the original grand total. Reports distinguish gross invoiced sales, dated credit adjustments and net sales. Editing/cancelling an invoice after a credit note is blocked to preserve return accounting.

Each invoice begins on its own PDF page. Long invoices necessarily continue onto additional pages to retain complete information and readable type; the SRS's exact one-page-per-invoice example applies to invoices fitting A4. No items are silently truncated. Batch exports use disk-backed chunks and merge all pages into one PDF.

Users create Firebase accounts through administrator provisioning. Credentials and service account remain outside public/. No demo login or production authentication bypass is included. External GST provider-specific adapters require a documented provider contract as well as credentials; no government success or IRN is fabricated.
