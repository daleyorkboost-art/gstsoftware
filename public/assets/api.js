let csrf = "";
export function setCsrf(value) {
  csrf = value;
}
export async function api(action, data, query = {}) {
  const opts = { credentials: "same-origin", headers: {} };
  if (data !== undefined) {
    opts.method = "POST";
    opts.headers["X-CSRF-Token"] = csrf;
    if (data instanceof FormData) opts.body = data;
    else {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(data);
    }
  }
  const response = await fetch(
    "api.php?" + new URLSearchParams({ action, ...query }),
    opts,
  );
  const result = await response.json();
  if (!response.ok || !result.ok) {
    const e = new Error(result.error || "Unable to complete request.");
    e.status = response.status;
    throw e;
  }
  return result.data;
}
export async function downloadPost(action) {
  const r = await fetch("api.php?action=" + action, {
    method: "POST",
    headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
    body: "{}",
  });
  if (!r.ok) {
    const e = await r.json();
    throw new Error(e.error);
  }
  const blob = await r.blob();
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download =
    (r.headers.get("Content-Disposition")?.match(/filename="([^"]+)/) ||
      [])[1] || "backup.gstbackup";
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 30000);
}
