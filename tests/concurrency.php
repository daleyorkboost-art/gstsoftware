<?php
declare(strict_types=1);
require dirname(__DIR__) . "/config/bootstrap.php";
if (
    PHP_SAPI !== "cli" ||
    !str_ends_with(App\Config::get("DB_DATABASE"), "_test")
) {
    exit(1);
}
$children = [];
for ($n = 0; $n < 10; $n++) {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . "/concurrency-worker.php"],
        [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes,
        ROOT,
    );
    if (!is_resource($process)) {
        throw new RuntimeException("Unable to launch test worker.");
    }
    $children[] = [$process, $pipes];
}
$numbers = [];
foreach ($children as [$process, $pipes]) {
    $numbers[] = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Worker failed: " . $error);
    }
}
if (count(array_unique($numbers)) !== 10 || in_array("", $numbers, true)) {
    throw new RuntimeException("Duplicate or missing invoice numbers.");
}
echo "PASS 10 concurrent saves produce 10 distinct invoice numbers\n";
