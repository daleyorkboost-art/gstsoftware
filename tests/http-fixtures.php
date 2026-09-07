<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
if (
    PHP_SAPI !== "cli" ||
    !str_ends_with(App\Config::get("DB_DATABASE"), "_test")
) {
    exit(1);
}
$fixtures = [];
foreach (["admin" => 1, "owner" => 2, "staff" => 3] as $role => $id) {
    App\Auth::start();
    session_regenerate_id(true);
    $u = App\DB::one("SELECT * FROM users WHERE id=?", [$id]);
    $_SESSION["uid"] = $id;
    $_SESSION["firebase_uid"] = $u["firebase_uid"];
    $_SESSION["expires"] = time() + 3600;
    $fixtures[$role] = ["session" => session_id(), "csrf" => $_SESSION["csrf"]];
    session_write_close();
    session_id("");
}
App\DB::run("DELETE FROM user_permissions WHERE user_id=3");
file_put_contents(ROOT . "/tmp/http-fixtures.json", json_encode($fixtures));
echo "Local test sessions prepared.\n";
