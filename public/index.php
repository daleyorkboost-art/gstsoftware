<?php require dirname(__DIR__) . "/config/bootstrap.php";
App\Auth::start();
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self' https://identitytoolkit.googleapis.com https://securetoken.googleapis.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
);
header("X-Frame-Options: DENY");
header("Cache-Control: no-store");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#147d69"><title>Ledger · GST Billing</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/app.css"></head><body>
<div id="app"><div class="initial-loading" role="status">Loading your workspace…</div></div>
<div id="toast" class="toast" role="status" hidden></div><dialog id="dialog"><form method="dialog"><button class="dialog-close secondary" aria-label="Close dialog">×</button></form><div id="dialog-content"></div></dialog>
<script type="module" src="assets/app.js"></script></body></html>
