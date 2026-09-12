<?php
declare(strict_types=1);
namespace App;
final class UserService
{
    public function list(): array
    {
        return [
            "users" => DB::all(
                "SELECT id,email,name,contact,role_id,active,firebase_uid IS NOT NULL linked,created_at FROM users ORDER BY id",
            ),
            "permissions" => DB::all("SELECT * FROM permissions"),
            "overrides" => DB::all("SELECT * FROM user_permissions"),
        ];
    }
    public function save(array $d, array $u): array
    {
        $name = Validation::text($d["name"] ?? "", "name", 160, true);
        $contact = Validation::text($d["contact"] ?? "", "contact", 30);
        $email = strtolower(
            Validation::text($d["email"] ?? "", "email", 190, true),
        );
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, "Enter a valid email address.");
        }
        $role = Validation::choice(
            $d["role_id"] ?? "",
            ["ADMIN", "OWNER", "STAFF"],
            "role",
        );
        $active = ($d["active"] ?? true) === true;
        $id = (int) ($d["id"] ?? 0);
        $overrides = $d["permissions"] ?? [];
        if (!is_array($overrides)) {
            throw new HttpError(422, "Invalid permissions.");
        }
        $valid = array_column(
            DB::all("SELECT * FROM permissions"),
            "admin_only",
            "id",
        );
        foreach ($overrides as $p => $allowed) {
            if (
                !array_key_exists($p, $valid) ||
                !is_bool($allowed) ||
                ($role !== "ADMIN" && $valid[$p] && $allowed)
            ) {
                throw new HttpError(422, "Invalid permission assignment.");
            }
        }
        return DB::transaction(function () use (
            $id,
            $name,
            $contact,
            $email,
            $role,
            $active,
            $overrides,
            $u,
        ) {
            DB::one("SELECT id FROM system_settings WHERE id=1 FOR UPDATE");
            if ($id) {
                $old = DB::one("SELECT * FROM users WHERE id=? FOR UPDATE", [
                    $id,
                ]);
                if (!$old) {
                    throw new HttpError(404, "User not found.");
                }
                if (
                    $old["role_id"] === "ADMIN" &&
                    $old["active"] &&
                    $old["firebase_uid"] &&
                    (!$active || $role !== "ADMIN") &&
                    (int) DB::one(
                        "SELECT COUNT(*) n FROM users WHERE role_id='ADMIN' AND active=1 AND firebase_uid IS NOT NULL",
                    )["n"] <= 1
                ) {
                    throw new HttpError(
                        409,
                        "Keep at least one active administrator with a linked Firebase account.",
                    );
                }
                if ($old["email"] !== $email) {
                    throw new HttpError(
                        422,
                        "Email is an identity field. Provision a separate account to change it.",
                    );
                }
                DB::run(
                    "UPDATE users SET name=?,contact=?,role_id=?,active=? WHERE id=?",
                    [$name, $contact, $role, (int) $active, $id],
                );
            } else {
                if (DB::one("SELECT id FROM users WHERE email=?", [$email])) {
                    throw new HttpError(409, "Email already has an account.");
                }
                // Provision locally first. Firebase account may be created by the optional explicit provision action.
                DB::run(
                    "INSERT INTO users(email,name,contact,role_id,active) VALUES(?,?,?,?,?)",
                    [$email, $name, $contact, $role, (int) $active],
                );
                $id = (int) DB::connection()->lastInsertId();
            }
            DB::run("DELETE FROM user_permissions WHERE user_id=?", [$id]);
            foreach ($overrides as $p => $allowed) {
                DB::run("INSERT INTO user_permissions VALUES(?,?,?)", [
                    $id,
                    $p,
                    (int) $allowed,
                ]);
            }
            AuditLogService::record($u, "User Updated", null, [
                "user_id" => $id,
                "role" => $role,
                "active" => $active,
                "permissions" => $overrides,
            ]);
            return ["id" => $id];
        });
    }
    public function provision(array $d, array $u): array
    {
        $target = DB::one("SELECT * FROM users WHERE id=?", [
            (int) ($d["id"] ?? 0),
        ]);
        if (!$target) {
            throw new HttpError(404, "User not found.");
        }
        if ($target["firebase_uid"]) {
            throw new HttpError(
                409,
                "This user is already linked to Firebase.",
            );
        }
        $password = Validation::text(
            $d["password"] ?? "",
            "temporary password",
            128,
            true,
        );
        if (strlen($password) < 12) {
            throw new HttpError(422, "Use at least 12 characters.");
        }
        $result = (new FirebaseService())->admin("signUp", [
            "email" => $target["email"],
            "password" => $password,
            "displayName" => $target["name"],
            "emailVerified" => false,
        ]);
        DB::run("UPDATE users SET firebase_uid=? WHERE id=?", [
            $result["localId"],
            $target["id"],
        ]);
        AuditLogService::record($u, "Firebase User Provisioned", null, [
            "user_id" => $target["id"],
        ]);
        return [
            "message" =>
                "Firebase account created. Give the temporary password to the user through your approved secure channel.",
        ];
    }
    public function resetPassword(array $d, array $u): array
    {
        Auth::reauthenticate($u, $d["reauth_token"] ?? "");
        $target = DB::one("SELECT * FROM users WHERE id=?", [(int) ($d["id"] ?? 0)]);
        if (!$target || $target["role_id"] === "ADMIN" || !$target["firebase_uid"]) {
            throw new HttpError(422, "Choose a linked Owner or Staff account.");
        }
        (new FirebaseService())->sendPasswordReset($target["email"]);
        AuditLogService::record($u, "Password Reset Requested", null, [
            "user_id" => $target["id"],
        ]);
        return ["message" => "Password reset email sent to " . $target["email"] . "."];
    }
    public function delete(array $d, array $u): array
    {
        Auth::reauthenticate($u, $d["reauth_token"] ?? "");
        if (($d["confirmation"] ?? "") !== "DELETE USER") {
            throw new HttpError(422, "Type DELETE USER exactly to confirm permanent deletion.");
        }
        $id = (int) ($d["id"] ?? 0);
        if ($id === (int) $u["id"]) {
            throw new HttpError(409, "You cannot permanently delete your own account.");
        }
        $target = DB::one("SELECT * FROM users WHERE id=?", [$id]);
        if (!$target || $target["role_id"] === "ADMIN") {
            throw new HttpError(422, "Only Owner or Staff accounts can be permanently deleted here.");
        }
        if ($target["firebase_uid"]) {
            (new FirebaseService())->admin("delete", ["localId" => $target["firebase_uid"]]);
        }
        DB::transaction(function () use ($id, $target, $u) {
            DB::run("UPDATE invoices SET created_by=? WHERE created_by=?", [$u["id"], $id]);
            DB::run("UPDATE invoices SET updated_by=? WHERE updated_by=?", [$u["id"], $id]);
            DB::run("UPDATE payment_allocations SET created_by=? WHERE created_by=?", [$u["id"], $id]);
            DB::run("UPDATE credit_transactions SET created_by=? WHERE created_by=?", [$u["id"], $id]);
            DB::run("UPDATE credit_notes SET created_by=? WHERE created_by=?", [$u["id"], $id]);
            DB::run("UPDATE invoice_edit_history SET user_id=? WHERE user_id=?", [$u["id"], $id]);
            DB::run("UPDATE activity_logs SET user_id=? WHERE user_id=?", [$u["id"], $id]);
            DB::run("DELETE FROM user_permissions WHERE user_id=?", [$id]);
            DB::run("DELETE FROM users WHERE id=?", [$id]);
            AuditLogService::record($u, "User Deleted", null, ["deleted_user_id" => $id, "name" => $target["name"], "email" => $target["email"]]);
        });
        return ["message" => "The user was permanently deleted."];
    }
}
