<?php
declare(strict_types=1);
namespace App;
final class AuditLogService
{
    public static function record(
        array $user,
        string $action,
        ?int $invoice = null,
        array $details = [],
    ): void {
        DB::run(
            "INSERT INTO activity_logs(user_id,user_name,invoice_id,action,details) VALUES(?,?,?,?,?)",
            [
                $user["id"],
                $user["name"],
                $invoice,
                $action,
                json_encode($details, JSON_THROW_ON_ERROR),
            ],
        );
    }
}
