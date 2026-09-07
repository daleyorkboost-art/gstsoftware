INSERT INTO roles VALUES ('ADMIN'),('OWNER'),('STAFF');
INSERT INTO permissions VALUES ('create_invoice',0),('view_invoice',0),('edit_invoice',0),('cancel_invoice',0),('duplicate_invoice',0),('export_invoice',0),('export_audit_report',0),('view_reports',0),('manage_users',1),('manage_business_profile',0),('view_activity_logs',0),('manage_settings',1),('configure_invoice_numbering',1),('manage_credit_notes',0),('manage_backups',1);
INSERT INTO role_permissions SELECT 'ADMIN',id FROM permissions;
INSERT INTO role_permissions SELECT 'OWNER',id FROM permissions WHERE admin_only=0 AND id <> 'manage_business_profile';
INSERT INTO role_permissions VALUES ('STAFF','create_invoice'),('STAFF','view_invoice');
INSERT INTO business_profile(id) VALUES(1);
INSERT INTO invoice_number_settings(id) VALUES(1);
INSERT INTO system_settings(id,settings) VALUES(1,'{"gst_rates":["0","5","12","18","28"],"gst_max":"100","round_to_rupee":true,"payments":["Cash","UPI","Card","Bank Transfer","Credit"],"terms":"Thank you for your business."}');
