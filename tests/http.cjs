// Development-only test. Run http-fixtures.php first against a dedicated *_test DB.
const fs = require("fs");
const assert = require("assert/strict");
const f = JSON.parse(fs.readFileSync("tmp/http-fixtures.json"));
const base = process.env.GST_TEST_URL || "http://127.0.0.1:8080";
let count = 0;
async function request(action, role, body, csrf = true, extra = {}) {
  const headers = {};
  if (role) headers.Cookie = "gst_session=" + f[role].session;
  const options = { headers };
  if (body !== undefined) {
    options.method = "POST";
    headers["Content-Type"] = "application/json";
    if (csrf && role) headers["X-CSRF-Token"] = f[role].csrf;
    options.body = JSON.stringify(body);
  }
  const r = await fetch(
    base + "/api.php?" + new URLSearchParams({ action, ...extra }),
    options,
  );
  return { status: r.status, body: await r.json() };
}
function check(value, message) {
  assert.ok(value, message);
  count++;
  console.log("PASS " + message);
}
(async () => {
  for (const action of [
    "session",
    "invoices",
    "users",
    "reports",
    "business",
    "settings",
    "activity",
    "export_download",
  ]) {
    const r = await request(action);
    check(r.status === 401, "unauthenticated " + action + " rejected");
  }
  for (const [action, body] of [
    ["save_invoice", {}],
    ["cancel_invoice", {}],
    ["save_user", {}],
    ["save_business", {}],
    ["export_start", {}],
  ]) {
    const r = await request(action, "admin", body, false);
    check(r.status === 403, "CSRF " + action + " rejected");
  }
  for (const [action, body] of [
    ["users", undefined],
    ["save_user", {}],
    ["save_settings", {}],
    ["save_numbering", {}],
    ["backup", {}],
    ["restore", {}],
    ["cancel_invoice", {}],
    ["save_invoice", {}],
  ]) {
    const r = await request(action, "staff", body, true, { id: "1" });
    check(r.status === 403, "staff " + action + " denied");
  }
  check(
    (await request("users", "owner")).status === 403,
    "owner admin endpoint denied",
  );
  check(
    (await request("save_invoice", "admin")).status === 405,
    "GET cannot mutate",
  );
  check(
    (await request("invoice", "admin", undefined, true, { id: "999999" }))
      .status === 404,
    "missing invoice 404",
  );
  check(
    (
      await request("export_start", "staff", {
        from: "2026-01-01",
        to: "2026-12-31",
        kind: "audit",
      })
    ).status === 403,
    "staff audit export denied",
  );
  check(
    (
      await request("reports", "owner", undefined, true, {
        from: "2026-10-01",
        to: "2026-01-01",
      })
    ).status === 422,
    "invalid report range rejected",
  );
  check(
    (
      await request("calculate", "staff", {
        items: [
          { product_name: "x", quantity: "1", rate: "1", gst_rate: "-1" },
        ],
      })
    ).status === 422,
    "invalid tax rejected over HTTP",
  );
  const badLogo = new FormData();
  badLogo.append(
    "logo",
    new Blob(['<?php echo "exploit"; ?>'], { type: "image/png" }),
    "logo.png",
  );
  const upload = await fetch(base + "/api.php?action=logo_upload", {
    method: "POST",
    headers: {
      Cookie: "gst_session=" + f.admin.session,
      "X-CSRF-Token": f.admin.csrf,
    },
    body: badLogo,
  });
  check(upload.status === 422, "disguised executable upload rejected");
  const valid = await request("session", "admin");
  check(
    valid.status === 200 && valid.body.data.user.role_id === "ADMIN",
    "admin session works",
  );
  const logout = await request("logout", "staff", {});
  check(logout.status === 200, "logout succeeds");
  check(
    (await request("session", "staff")).status === 401,
    "logout invalidates session",
  );
  for (const path of [
    "/.env",
    "/database/schema.sql",
    "/src/Auth.php",
    "/storage/logs/app.log",
  ]) {
    const r = await fetch(base + path);
    check(
      r.status === 404 || r.status === 403,
      "private file unavailable " + path,
    );
  }
  console.log(count + " HTTP security checks passed.");
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
