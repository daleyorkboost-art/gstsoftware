-- Existing v2.0 databases only. Back up the database and storage first.
-- Re-running this patch is safe. It does not change saved invoice totals or tax settings.
SET @needs_receipt_snapshot = (SELECT COUNT(*)=0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_transactions' AND COLUMN_NAME='receipt_snapshot');
SET @receipt_sql = IF(@needs_receipt_snapshot, 'ALTER TABLE credit_transactions ADD receipt_snapshot JSON NULL', 'SET @receipt_patch_noop=1');
PREPARE receipt_patch FROM @receipt_sql;
EXECUTE receipt_patch;
DEALLOCATE PREPARE receipt_patch;
ALTER TABLE invoices MODIFY payment_method ENUM('Cash','UPI','Card','Bank Transfer','Credit','Other') NOT NULL;
-- Existing receipts can only snapshot the details available at upgrade time.
UPDATE credit_transactions t JOIN credit_accounts a ON a.id=t.credit_account_id JOIN users u ON u.id=t.created_by CROSS JOIN business_profile b
SET t.receipt_snapshot=JSON_OBJECT('customer_name',a.customer_name,'customer_mobile',a.customer_mobile,'customer_address',a.customer_address,'created_by_name',u.name,'business',JSON_OBJECT('name',b.name,'address',b.address,'gstin',b.gstin))
WHERE t.kind='Clearance' AND t.receipt_snapshot IS NULL AND b.id=1;
