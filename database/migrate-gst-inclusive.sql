-- Run once on an existing v2.1 database after taking a backup.
-- Historical invoices remain tax-exclusive and retain their entered rates.
ALTER TABLE invoices ADD gst_mode ENUM('Include','Exclude') NOT NULL DEFAULT 'Exclude' AFTER notes;
ALTER TABLE invoice_items ADD entered_rate DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER rate;
UPDATE invoice_items SET entered_rate = rate;
