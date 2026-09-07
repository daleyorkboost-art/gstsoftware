# Accounting and scope decisions

The supplied SRS v1.1 is captured in srs-source.txt. The pasted user request additionally fixes PHP, MySQL, Firebase, shared hosting, and no permanent product catalog.

This installation serves one business. All active authorized users operate within that business; this is not a multi-tenant SaaS. Exactly ADMIN, OWNER and STAFF roles exist.

Customer details are optional. Place of supply is an explicit required field defaulting to the business state; it controls interstate tax even when customer information is absent. GSTIN validation checks format and matching state prefix, not registration status.

Amounts use decimal strings and BCMath. Quantity has three decimal places; rate, item-level absolute discount and tax percentage have two. Gross, total GST and each displayed accounting amount round half up to two decimals. For odd tax paise, CGST receives the extra paise and SGST the remainder so the displayed components reconcile exactly. Optional whole-rupee rounding defaults on. Reports aggregate stored, server-calculated amounts without calculating tax again.

Cancelled invoices remain intact and are excluded from sales/tax totals. Credit notes record returned quantities and proportional original taxable/tax amounts; cumulative rounding apportions tax paise and the last return receives any remainder; invoice round-off is apportioned so a full return exactly matches the original grand total. Reports distinguish gross invoiced sales, dated credit adjustments and net sales. Editing/cancelling an invoice after a credit note is blocked to preserve return accounting.

Each invoice begins on its own PDF page. Long invoices necessarily continue onto additional pages to retain complete information and readable type; the SRS's exact one-page-per-invoice example applies to invoices fitting A4. No items are silently truncated. Batch exports use disk-backed chunks and merge all pages into one PDF.

Users create Firebase accounts through administrator provisioning. Credentials and service account remain outside public/. No demo login or production authentication bypass is included. External GST provider-specific adapters require a documented provider contract as well as credentials; no government success or IRN is fabricated.
