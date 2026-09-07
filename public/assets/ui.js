export const esc = (v) =>
  String(v ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
export const money = (v) =>
  new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR" }).format(
    v || 0,
  );
export const today = () =>
  new Date().toLocaleDateString("en-CA", {
    timeZone: globalThis.billingTimezone || "Asia/Kolkata",
  });
export const monthStart = () => today().slice(0, 7) + "-01";
export function toast(message, error = false) {
  const el = document.querySelector("#toast");
  el.textContent = message;
  el.classList.toggle("error", error);
  el.hidden = false;
  clearTimeout(window.toastTimer);
  window.toastTimer = setTimeout(() => (el.hidden = true), 6000);
}
export const states = {
  "01": "Jammu & Kashmir",
  "02": "Himachal Pradesh",
  "03": "Punjab",
  "04": "Chandigarh",
  "05": "Uttarakhand",
  "06": "Haryana",
  "07": "Delhi",
  "08": "Rajasthan",
  "09": "Uttar Pradesh",
  10: "Bihar",
  11: "Sikkim",
  12: "Arunachal Pradesh",
  13: "Nagaland",
  14: "Manipur",
  15: "Mizoram",
  16: "Tripura",
  17: "Meghalaya",
  18: "Assam",
  19: "West Bengal",
  20: "Jharkhand",
  21: "Odisha",
  22: "Chhattisgarh",
  23: "Madhya Pradesh",
  24: "Gujarat",
  25: "Daman & Diu (legacy)",
  26: "Dadra & Nagar Haveli and Daman & Diu",
  27: "Maharashtra",
  28: "Andhra Pradesh (legacy)",
  29: "Karnataka",
  30: "Goa",
  31: "Lakshadweep",
  32: "Kerala",
  33: "Tamil Nadu",
  34: "Puducherry",
  35: "Andaman & Nicobar",
  36: "Telangana",
  37: "Andhra Pradesh",
  38: "Ladakh",
  97: "Other territory",
};
export function field(label, name, value = "", type = "text", attrs = "") {
  return `<label>${esc(label)}<input name="${esc(name)}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
}
export function select(label, name, options, value = "", attrs = "") {
  return `<label>${esc(label)}<select name="${esc(name)}" ${attrs}>${(Array.isArray(options) ? options.map((v) => [v, v]) : Object.entries(options)).map(([v, l]) => `<option value="${esc(v)}" ${String(value) === String(v) ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></label>`;
}
export function textarea(label, name, value = "", attrs = "") {
  return `<label>${esc(label)}<textarea name="${esc(name)}" ${attrs}>${esc(value)}</textarea></label>`;
}
export function formData(form) {
  return Object.fromEntries(new FormData(form));
}
export function dateFilters(extra = "") {
  return `<form id="filters" class="filter-bar">${field("From date", "from", monthStart(), "date", "required")}${field("To date", "to", today(), "date", "required")}${extra}<button type="submit" class="secondary">Apply filters</button></form>`;
}
export function table(rows, columns) {
  if (!rows.length)
    return '<div class="empty"><span class="empty-icon">▤</span><h3>No records yet</h3><p>Records will appear here as you use your billing workspace. Try a different date range if needed.</p></div>';
  return `<div class="table-wrap"><table><thead><tr>${columns.map((c) => `<th>${esc(c[1])}</th>`).join("")}</tr></thead><tbody>${rows.map((row) => `<tr>${columns.map((c) => `<td data-label="${esc(c[1])}">${c[2] ? c[2](row[c[0]], row) : esc(row[c[0]])}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`;
}
export async function busy(button, fn) {
  if (button.disabled) return;
  button.disabled = true;
  const text = button.textContent;
  button.textContent = "Working…";
  try {
    return await fn();
  } catch (e) {
    toast(e.message, true);
    throw e;
  } finally {
    button.disabled = false;
    button.textContent = text;
  }
}
export function modal(html) {
  document.querySelector("#dialog-content").innerHTML = html;
  document.querySelector("#dialog").showModal();
}
export function closeModal() {
  document.querySelector("#dialog").close();
}
export function confirmAction(title, message) {
  return new Promise((resolve) => {
    modal(
      `<h2>${esc(title)}</h2><p>${esc(message)}</p><div class="actions"><button id="confirm-action" class="danger">Confirm</button><button id="cancel-action" class="secondary">Keep unchanged</button></div>`,
    );
    const d = document.querySelector("#dialog");
    let done = false;
    const finish = (v) => {
      if (done) return;
      done = true;
      closeModal();
      resolve(v);
    };
    document.querySelector("#confirm-action").onclick = () => finish(true);
    document.querySelector("#cancel-action").onclick = () => finish(false);
    d.addEventListener("close", () => finish(false), { once: true });
  });
}
