<?php
declare(strict_types=1);
namespace App;
final class AdminService
{
    public function deleteTransactions(array $data, array $user): array
    {
        Auth::reauthenticate($user, $data["reauth_token"] ?? "");
        if (($data["confirmation"] ?? "") !== "DELETE ALL TRANSACTION DATA") {
            throw new HttpError(422, "Type DELETE ALL TRANSACTION DATA exactly.");
        }
        $backup = DB::transaction(function () use ($user) {
            DB::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            $backup = (new BackupService())->create();
            DB::run("DELETE FROM integration_requests");
            DB::run("DELETE FROM sales_returns");
            DB::run("DELETE FROM credit_notes");
            DB::run("DELETE FROM credit_transaction_allocations");
            DB::run("DELETE FROM credit_transactions");
            DB::run("DELETE FROM credit_accounts");
            DB::run("DELETE FROM payment_allocations");
            DB::run("DELETE FROM invoice_edit_history");
            DB::run("DELETE FROM activity_logs WHERE invoice_id IS NOT NULL OR action IN ('Credit Created','Credit Cleared','Credit Receipt Exported','Credit History Exported','All Credit History Exported','Export Requested','Invoice Exported')");
            DB::run("UPDATE invoices SET duplicated_from=NULL");
            DB::run("DELETE FROM invoice_items");
            DB::run("DELETE FROM invoices");
            DB::run("UPDATE invoice_number_settings SET next_number=1 WHERE id=1");
            AuditLogService::record($user, "Bulk Transaction Deletion", null, [
                "categories" => ["invoices", "payments", "credits", "credit_notes", "transaction_audit"],
                "business_profile_preserved" => true,
                "backup_created" => basename($backup),
            ]);
            return $backup;
        });
        // DELETE does not reset MySQL AUTO_INCREMENT counters. These tables are
        // empty now, so all user-visible document series can start at 1 again.
        foreach (["invoices", "invoice_items", "payment_allocations", "credit_accounts", "credit_transactions", "credit_transaction_allocations", "credit_notes", "sales_returns", "invoice_edit_history", "integration_requests"] as $table) {
            DB::run("ALTER TABLE $table AUTO_INCREMENT=1");
        }
        // Repeat outside the deletion transaction and verify the user-visible
        // invoice sequence, so a complete reset cannot leave the old counter.
        DB::run("UPDATE invoice_number_settings SET next_number=1 WHERE id=1");
        $numbering = DB::one("SELECT next_number FROM invoice_number_settings WHERE id=1");
        if ((int) ($numbering["next_number"] ?? 0) !== 1) {
            throw new HttpError(500, "Transaction data was deleted, but invoice numbering could not be reset. Restore the automatic backup before billing.");
        }
        return ["message" => "Transaction data deleted. Invoice, credit invoice, credit note and receipt numbering will restart from 1. Business details and configuration were preserved.", "backup" => basename($backup)];
    }
}
