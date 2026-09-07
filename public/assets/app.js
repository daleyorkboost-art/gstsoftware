import { api, setCsrf, downloadPost } from "./api.js";
import { configure, signIn, signOut, resetPassword, activate } from "./auth.js";
import {
  esc,
  money,
  today,
  monthStart,
  field,
  select,
  textarea,
  states,
  formData,
  toast,
  busy,
  modal,
  closeModal,
  confirmAction,
  dateFilters,
  table,
} from "./ui.js";
import { renderEditor } from "./invoice.js";
const app = document.querySelector("#app");
let user,
  settings,
  business,
  routeRevision = 0;
const can = (p) => user?.permissions.includes(p);
const nav = [
  ["dashboard", "Overview", "◫", "view_invoice"],
  ["invoices", "Invoices", "▤", "view_invoice"],
  ["create", "Create invoice", "＋", "create_invoice"],
  ["reports", "Reports", "▤", "view_reports"],
  ["exports", "Exports", "↗", "export_invoice"],
  ["credits", "Credit notes", "↶", "manage_credit_notes"],
  ["activity", "Activity log", "◫", "view_activity_logs"],
  ["business", "Business profile", "⌂", "manage_business_profile"],
  ["users", "Team & permissions", "♧", "manage_users"],
  ["settings", "Settings", "⚙", "manage_settings"],
  ["backups", "Backup & restore", "▤", "manage_backups"],
  ["integrations", "GST integrations", "◇", "manage_settings"],
];
function heading(title, subtitle, actions = "") {
  return `<div class="page-heading"><div><h1>${esc(title)}</h1><p>${esc(subtitle)}</p></div><div class="actions">${actions}</div></div>`;
}
function invoiceColumns() {
  return [
    [
      "invoice_number",
      "Invoice",
      (_, r) =>
        `<a href="#invoice/${r.id}"><b>${esc(r.invoice_number)}</b></a>`,
    ],
    ["invoice_date", "Date"],
    ["customer_name", "Customer", (v) => esc(v || "Walk-in customer")],
    ["grand_total", "Amount", (v) => `<b>${money(v)}</b>`],
    [
      "payment_method",
      "Payment",
      (v) =>
        `<span class="badge ${v === "Credit" ? "credit" : ""}">${esc(v)}</span>`,
    ],
    [
      "status",
      "Status",
      (v) =>
        `<span class="badge ${v === "Cancelled" ? "cancelled" : ""}">${esc(v)}</span>`,
    ],
  ];
}
async function loginScreen() {
  user = null;
  await configure();
  app.innerHTML = `<main class="login-layout"><section class="login-story"><div class="brand"><span class="brand-mark">≋</span><div>ledger<small>GST billing, simplified</small></div></div><h1>Less paperwork.<br>More business.</h1><p>A thoughtful workspace for your invoices, GST reports, and everyday billing.</p><div class="login-feature">✓ Clear invoices, accurate calculations<br>✓ Your entire billing history, in one place<br>✓ Built for the way your team works</div></section><section class="login-form-wrap"><div class="login-form"><h2>Welcome back</h2><p>Sign in to your business workspace.</p><form id="login-form">${field("Email address", "email", "", "email", 'required autocomplete="username"')}${field("Password", "password", "", "password", 'required autocomplete="current-password" minlength="6"')}<button type="submit">Sign in →</button><div id="login-error" role="alert"></div></form><div class="login-links"><button id="reset" class="text-button">Forgot password?</button><button id="activate" class="text-button">Activate invited account</button></div><p class="login-foot">Secure authentication · Admin, Owner & Staff access</p></div></section></main>`;
  app.querySelector("#login-form").onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    await busy(f.querySelector("button"), async () => {
      try {
        user = await signIn(f.email.value, f.password.value);
        await startWorkspace();
      } catch (error) {
        app.querySelector("#login-error").textContent = error.message;
        throw error;
      }
    }).catch(() => {});
  };
  app.querySelector("#reset").onclick = async (e) => {
    const email = app.querySelector("[name=email]").value;
    if (!email) {
      toast("Enter your email address first.", true);
      return;
    }
    await busy(e.target, async () => {
      await resetPassword(email);
      toast(
        "If the account exists, a password reset email has been requested.",
      );
    }).catch(() => {});
  };
  app.querySelector("#activate").onclick = () => {
    modal(
      `<h2>Activate an invited account</h2><p>Use the email your administrator granted access to. Verify your email before signing in.</p><form id="activation">${field("Email", "email", "", "email", "required")}${field("New password", "password", "", "password", 'required minlength="12" autocomplete="new-password"')}<p class="hint">At least 12 characters.</p><button>Send verification email</button></form>`,
    );
    document.querySelector("#activation").onsubmit = async (e) => {
      e.preventDefault();
      await busy(e.target.querySelector("button"), async () => {
        await activate(e.target.email.value, e.target.password.value);
        closeModal();
        toast("Account created. Check your verification email, then sign in.");
      }).catch(() => {});
    };
  };
}
async function startWorkspace() {
  [business, settings] = await Promise.all([api("business"), api("settings")]);
  app.innerHTML = `<div class="shell"><aside class="sidebar"><a href="#dashboard" class="brand"><span class="brand-mark">≋</span><div>ledger<small>GST billing</small></div></a><div class="nav-label">WORKSPACE</div><nav>${nav
    .filter(
      (n) => can(n[3]) || (n[0] === "exports" && can("export_audit_report")),
    )
    .map(
      ([href, label, icon]) =>
        `<a class="nav-link" href="#${href}"><span class="nav-icon" aria-hidden="true">${icon}</span>${label}</a>`,
    )
    .join(
      "",
    )}</nav><div class="sidebar-footer"><div class="profile"><span class="avatar">${esc(user.name[0]?.toUpperCase())}</span><div><b>${esc(user.name)}</b><small>${esc(user.role_id)} ACCOUNT</small></div></div><button id="logout" class="text-button">Sign out ↗</button></div></aside><div class="workspace"><header class="topbar"><button class="menu-toggle" aria-label="Toggle navigation">☰</button><div class="breadcrumb">Workspace <span> / </span> <b id="crumb">Overview</b></div><div class="topbar-right"><span class="secure-pill">● Secure workspace</span><span class="workspace-name">${esc(business.name || "Your business")}</span><span class="avatar">${esc((business.name || "B")[0])}</span></div></header><main id="content" class="content"></main></div></div>`;
  app.querySelector(".menu-toggle").onclick = () =>
    app.querySelector(".shell").classList.toggle("menu-open");
  app.querySelector("#logout").onclick = async () => {
    await signOut();
    await loginScreen();
  };
  await route();
}
async function route() {
  if (!user) return;
  const revision = ++routeRevision;
  const [page = "dashboard", id] = (
    location.hash.slice(1) || "dashboard"
  ).split("/");
  const host = document.querySelector("#content");
  if (!host) return;
  host.replaceChildren(document.createElement("div"));
  const root = host.firstElementChild;
  root.innerHTML =
    '<div class="loading" role="status">Loading your workspace…</div>';
  app.querySelector(".shell").classList.remove("menu-open");
  app
    .querySelectorAll(".nav-link")
    .forEach((a) => a.classList.toggle("active", a.hash === "#" + page));
  app.querySelector("#crumb").textContent =
    nav.find((n) => n[0] === page)?.[1] || "Invoice";
  try {
    const required = nav.find((n) => n[0] === page)?.[3];
    if (
      required &&
      !can(required) &&
      !(page === "exports" && can("export_audit_report"))
    )
      throw new Error("You do not have access to this page.");
    switch (page) {
      case "dashboard":
        await dashboard(root);
        break;
      case "invoices":
        await invoices(root);
        break;
      case "create":
        await renderEditor(root, null, user);
        break;
      case "edit":
        if (!can("edit_invoice")) throw new Error("You cannot edit invoices.");
        await renderEditor(root, id, user);
        break;
      case "invoice":
        await invoiceView(root, id);
        break;
      case "reports":
        await reports(root);
        break;
      case "exports":
        exportsPage(root);
        break;
      case "business":
        businessPage(root);
        break;
      case "settings":
        await settingsPage(root);
        break;
      case "users":
        await usersPage(root);
        break;
      case "credits":
        await creditsPage(root);
        break;
      case "activity":
        await activityPage(root);
        break;
      case "backups":
        backupPage(root);
        break;
      case "integrations":
        await integrationsPage(root);
        break;
      default:
        throw new Error("Page not found.");
    }
  } catch (error) {
    if (revision !== routeRevision) return;
    if (error.status === 401) {
      await loginScreen();
      return;
    }
    root.innerHTML = `<div class="error-state"><h2>Unable to open this page</h2><p>${esc(error.message)}</p><button id="retry">Try again</button> <a href="#dashboard">Return to overview</a></div>`;
    root.querySelector("#retry").onclick = route;
  }
}
async function dashboard(root) {
  root.innerHTML =
    heading(
      "Business overview",
      "A clear picture of your billing, all in one place.",
      can("create_invoice")
        ? '<a href="#create" class="button">＋ Create invoice</a>'
        : "",
    ) +
    dateFilters() +
    '<div id="dashboard-data"></div>';
  async function load(q = {}) {
    const d = await api("dashboard", undefined, q);
    root.querySelector("#dashboard-data").innerHTML = `<div class="stats">${[
      ["Total sales", money(d.sales), "₹", "Active invoices in this period"],
      ["GST collected", money(d.gst), "%", "Before credit note adjustments"],
      [
        "Invoices",
        d.invoice_count,
        "▤",
        d.cancelled + " cancelled in this period",
      ],
      [
        "Credit sales",
        money(d.credit_sales),
        "◫",
        "Invoices with credit payment",
      ],
    ]
      .map(
        ([l, v, i, f]) =>
          `<div class="stat"><div class="stat-label">${l}</div><span class="stat-icon">${i}</span><div class="stat-value">${v}</div><div class="stat-foot">${f}</div></div>`,
      )
      .join(
        "",
      )}</div><div class="dashboard-grid"><section class="card"><div class="card-head"><h2>Your sales at a glance</h2><span class="badge">Live records</span></div><div class="card-body"><p>Saved invoices and dated credit notes keep these figures current.</p><div class="mini-metrics"><div><small>Today's sales</small><b>${money(d.today_sales)}</b></div><div><small>Credit adjustments</small><b>${money(d.returns)}</b></div><div><small>Net sales</small><b>${money(d.net_sales)}</b></div></div></div></section><section class="welcome-card"><h2>Good records. Better days.</h2><p>Keep your billing moving with a fresh invoice. Add items as you go.</p>${can("create_invoice") ? '<a href="#create" class="button">Start a new invoice ↗</a>' : '<a href="#invoices" class="button">View invoices ↗</a>'}</section></div><section class="card"><div class="card-head"><h2>Recent invoices</h2><a href="#invoices">View all invoices →</a></div>${table(d.recent.slice(0, 6), invoiceColumns())}<div class="table-footer"><span>Showing the latest ${Math.min(d.recent.length, 6)} invoices in this period</span><span>All amounts in INR</span></div></section><p class="footer-note">Your records stay connected. Invoice edits update your reports automatically.</p>`;
  }
  root.querySelector("#filters").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), () =>
      load(formData(e.target)),
    ).catch(() => {});
  };
  await load();
}
async function invoices(root) {
  root.innerHTML =
    heading(
      "Invoices",
      "Your complete billing history, ready when you need it.",
      can("create_invoice")
        ? '<a href="#create" class="button">＋ Create invoice</a>'
        : "",
    ) +
    `<section class="card"><div class="card-body">${dateFilters(field("Search invoice, customer, mobile or GSTIN", "search") + select("Payment", "payment_method", ["", "Cash", "UPI", "Card", "Bank Transfer", "Credit"]) + select("Status", "status", ["", "Active", "Cancelled"]))}</div><div id="invoice-list"></div></section>`;
  let q = {},
    page = 1;
  async function load() {
    const d = await api("invoices", undefined, { ...q, page });
    root.querySelector("#invoice-list").innerHTML =
      table(d.rows, [
        ...invoiceColumns(),
        ["created_by_name", "Created by"],
        ["updated_by_name", "Updated by"],
        ["updated_at", "Last updated"],
      ]) +
      `<div class="table-footer"><span>${d.total} invoices · Page ${d.page} of ${Math.max(1, Math.ceil(d.total / 25))}</span><div class="actions"><button class="secondary" id="prev" ${page === 1 ? "disabled" : ""}>↶ Previous</button><button class="secondary" id="next" ${page * 25 >= d.total ? "disabled" : ""}>Next →</button></div></div>`;
    root.querySelector("#prev").onclick = () => {
      page--;
      load().catch((e) => toast(e.message, true));
    };
    root.querySelector("#next").onclick = () => {
      page++;
      load().catch((e) => toast(e.message, true));
    };
  }
  root.querySelector("#filters").onsubmit = async (e) => {
    e.preventDefault();
    q = formData(e.target);
    page = 1;
    await busy(e.target.querySelector("button"), load).catch(() => {});
  };
  q = formData(root.querySelector("#filters"));
  await load();
}
async function invoiceView(root, id) {
  const i = await api("invoice", undefined, { id });
  root.innerHTML =
    heading(
      i.invoice_number,
      (i.customer_name || "Walk-in customer") + " · " + i.invoice_date,
      `<a class="button secondary" href="print.php?id=${i.id}" target="_blank" rel="noopener">Print / preview</a><a class="button" href="api.php?action=pdf&id=${i.id}">Download PDF</a>`,
    ) +
    `<div class="actions card-body"><span class="badge ${i.status === "Cancelled" ? "cancelled" : ""}">${esc(i.status)}</span><span class="version-tag">Version ${i.version}</span>${can("edit_invoice") && i.status === "Active" ? `<a class="button secondary" href="#edit/${i.id}">Edit invoice</a>` : ""}${can("duplicate_invoice") ? '<button id="duplicate" class="secondary">Duplicate</button>' : ""}${can("cancel_invoice") && i.status === "Active" ? '<button id="cancel" class="secondary">Cancel invoice</button>' : ""}<button id="share" class="secondary">Share</button>${can("export_invoice") ? '<button id="email" class="secondary">Email invoice</button>' : ""}<button id="history" class="secondary">Activity & changes</button></div>${i.cancellation_reason ? `<div class="notice">Cancellation reason: ${esc(i.cancellation_reason)}</div>` : ""}<section class="card"><div class="card-head"><h2>${esc(i.business.name)}</h2><b>${money(i.grand_total)}</b></div><div class="card-body form-grid"><div><h3>Bill to</h3><p>${esc(i.customer_name || "Walk-in customer")}<br>${esc(i.customer_mobile)}<br>${esc(i.customer_gstin)}<br>${esc(i.billing_address)}</p></div><div><h3>Invoice details</h3><p>Place of supply: ${esc(states[i.place_of_supply])}<br>Payment: ${esc(i.payment_method)}<br>Reference: ${esc(i.payment_reference || "—")}<br>Shipping: ${esc(i.shipping_address || "—")}</p></div></div>${table(
      i.items,
      [
        ["product_name", "Item"],
        ["hsn_sac", "HSN/SAC"],
        ["description", "Description"],
        ["quantity", "Quantity"],
        ["unit", "Unit"],
        ["rate", "Rate", money],
        ["discount", "Discount", money],
        ["taxable", "Taxable", money],
        ["gst_rate", "GST %"],
        ["cgst", "CGST", money],
        ["sgst", "SGST", money],
        ["igst", "IGST", money],
        ["total", "Total", money],
      ],
    )}<div class="card-body"><div class="form-grid">${[
      ["Subtotal", i.subtotal],
      ["Discount", i.discount],
      ["Taxable amount", i.taxable],
      ["Total GST", i.gst],
      ["Round-off", i.round_off],
      ["Grand total", i.grand_total],
    ]
      .map(
        ([l, v]) =>
          `<div class="summary-line"><span>${l}</span><b>${money(v)}</b></div>`,
      )
      .join("")}</div><p class="hint">${esc(i.notes)}</p></div></section>`;
  root.querySelector("#duplicate")?.addEventListener("click", async (e) => {
    if (
      !(await confirmAction(
        "Duplicate this invoice?",
        "A new invoice will be saved using the current date and tax settings.",
      ))
    )
      return;
    await busy(e.target, async () => {
      const n = await api("duplicate_invoice", {}, { id });
      toast("Invoice duplicated.");
      location.hash = "invoice/" + n.id;
    }).catch(() => {});
  });
  root.querySelector("#cancel")?.addEventListener("click", () => {
    modal(
      `<h2>Cancel ${esc(i.invoice_number)}</h2><p>The invoice stays in history and is excluded from sales totals.</p><form id="cancel-form">${textarea("Reason", "reason", "", 'required maxlength="500"')}<p></p><button class="danger">Confirm cancellation</button></form>`,
    );
    document.querySelector("#cancel-form").onsubmit = async (e) => {
      e.preventDefault();
      await busy(e.target.querySelector("button"), async () => {
        await api(
          "cancel_invoice",
          { reason: e.target.reason.value, version: i.version },
          { id },
        );
        closeModal();
        toast("Invoice cancelled.");
        await invoiceView(root, id);
      }).catch(() => {});
    };
  });
  root.querySelector("#history").onclick = async (e) => {
    await busy(e.target, async () => {
      const h = await api("history", undefined, { id });
      modal(
        `<h2>Invoice activity</h2>${h.activity.map((a) => `<div class="activity-entry"><b>${esc(a.action)}</b><p>${esc(a.user_name)} · ${esc(a.created_at)}</p><details><summary>Details</summary><pre>${esc(JSON.stringify(JSON.parse(a.details), null, 2))}</pre></details></div>`).join("")}<h3>Recorded edits</h3>${h.edits.map((a) => `<div class="activity-entry"><p>${esc(a.created_at)} · Final ${money(a.final_amount)}</p><details><summary>Previous and updated values</summary><pre>${esc(JSON.stringify({ previous: JSON.parse(a.previous_values), updated: JSON.parse(a.new_values) }, null, 2))}</pre></details></div>`).join("") || "<p>No edits recorded.</p>"}`,
      );
    }).catch(() => {});
  };
  root.querySelector("#share").onclick = () => {
    const text = `Invoice ${i.invoice_number} from ${i.business.name}, dated ${i.invoice_date}. Total INR ${i.grand_total}.`;
    modal(
      `<h2>Share invoice summary</h2><p>Review the message below. The full invoice PDF can be downloaded and attached in your messaging app.</p>${textarea("Message", "share_message", text, "readonly")}<p></p><a class="button" href="https://wa.me/?text=${encodeURIComponent(text)}" target="_blank" rel="noopener noreferrer">Open WhatsApp</a>`,
    );
  };
  root.querySelector("#email")?.addEventListener("click", () => {
    modal(
      `<h2>Email this invoice</h2><p>The full invoice PDF will be sent to the recipient you enter.</p><form id="email-form">${field("Recipient email", "recipient", "", "email", "required")}<p></p><button>Send invoice</button></form>`,
    );
    document.querySelector("#email-form").onsubmit = async (e) => {
      e.preventDefault();
      await busy(e.target.querySelector("button"), async () => {
        await api("email", formData(e.target), { id });
        closeModal();
        toast("Invoice email sent.");
      }).catch(() => {});
    };
  });
}
async function reports(root) {
  root.innerHTML =
    heading(
      "Reports",
      "Sales and GST, based on the latest saved invoice state.",
    ) +
    `<section class="card"><div class="card-body">${dateFilters(select("Report", "type", { daily: "Daily / date-wise sales", monthly: "Monthly sales", customer: "Customer-wise sales", gst: "GST / CGST / SGST / IGST summary", hsn: "HSN-wise sales", credit: "Credit note report" }, "daily"))}<p class="hint">Cancelled invoices are excluded. Credit adjustments appear on the credit note date. Customer grouping uses GSTIN, then mobile, then name.</p></div><div id="report-data"></div></section>`;
  async function load(q) {
    const r = await api("reports", undefined, q);
    const columns = r.rows.length
      ? Object.keys(r.rows[0]).map((k) => [
          k,
          k.replaceAll("_", " "),
          [
            "taxable",
            "cgst",
            "sgst",
            "igst",
            "gst",
            "total",
            "net_sales",
            "gross_sales",
            "credit_adjustments",
          ].includes(k)
            ? money
            : undefined,
        ])
      : [];
    root.querySelector("#report-data").innerHTML = table(r.rows, columns);
  }
  root.querySelector("#filters").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), () =>
      load(formData(e.target)),
    ).catch(() => {});
  };
  await load(formData(root.querySelector("#filters")));
}
function exportsPage(root) {
  root.innerHTML =
    heading(
      "Export your records",
      "Complete invoices or a clear audit report. One date range, one download.",
    ) +
    `<section class="card"><div class="card-head"><h2>Prepare an export</h2><span class="badge">PDF · Excel · CSV</span></div><form id="export-form" class="card-body"><div class="form-grid">${field("From date", "from", monthStart(), "date", "required")}${field("To date", "to", today(), "date", "required")}</div><h3>Choose your format</h3><div class="export-options">${[
      [
        "invoices",
        "Complete invoice PDF",
        "Every invoice starts on a new page, with all item and tax details.",
        "export_invoice",
      ],
      [
        "audit",
        "Invoice audit PDF",
        "A tabular record of invoices, customers, products and totals.",
        "export_audit_report",
      ],
      [
        "csv",
        "CSV spreadsheet",
        "Invoice data ready for analysis or import.",
        "export_invoice",
      ],
      [
        "excel",
        "Excel-compatible spreadsheet",
        "Open directly in Microsoft Excel.",
        "export_invoice",
      ],
    ]
      .filter((x) => can(x[3]))
      .map(
        ([v, l, d], n) =>
          `<div class="export-option"><label><input type="radio" name="kind" value="${v}" ${n === 0 ? "checked" : ""}>${l}</label><p>${d}</p></div>`,
      )
      .join(
        "",
      )}</div><p class="hint">All matching invoices, including cancelled records, are included. Large exports are processed in batches; keep this page open.</p><button type="submit">Generate export ↓</button><div id="export-progress" role="status"></div></form></section>`;
  root.querySelector("#export-form").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("[type=submit]"), async () => {
      let job = await api("export_start", formData(e.target));
      const progress = root.querySelector("#export-progress");
      while (!job.ready) {
        progress.innerHTML = `<p>Preparing ${job.done} of ${job.total} invoices…</p><progress class="progress" max="${job.total}" value="${job.done}"></progress>`;
        job = await api("export_step", { job: job.id });
      }
      progress.innerHTML = `<div class="notice">Your export is ready. ${job.total} invoices included.</div><a class="button" href="api.php?action=export_download&job=${job.id}">Download ${job.kind === "csv" || job.kind === "excel" ? "spreadsheet" : "PDF"}</a>`;
      toast("Export generated successfully.");
    }).catch(() => {});
  };
}
function businessPage(root) {
  root.innerHTML =
    heading(
      "Business profile",
      "The details your customers see on every invoice.",
    ) +
    `<section class="card"><form id="business-form" class="card-body"><div class="form-grid">${field("Business name", "name", business.name, "text", 'required maxlength="160"')}${field("GSTIN · format only", "gstin", business.gstin, "text", 'maxlength="15"')}${select("State", "state", states, business.state)}${field("Bank name", "bank_name", business.bank_name)}${field("Account number", "account_number", business.account_number)}${field("IFSC code", "ifsc", business.ifsc, "text", 'maxlength="11"')}${field("Branch", "branch", business.branch)}${textarea("Business address", "address", business.address, 'maxlength="1500"')}</div><p class="hint">New invoices use these details. Saved invoices retain the original business profile for historical accuracy.</p><button>Save business profile</button></form></section><section class="card"><div class="card-head"><h2>Business logo</h2></div><form id="logo-form" class="card-body">${field("PNG or JPEG · maximum 2 MB, 3000 × 3000 px", "logo", "", "file", 'accept="image/png,image/jpeg" required')}<p></p><button>Upload logo</button></form></section>`;
  root.querySelector("#business-form").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), async () => {
      business = await api("save_business", formData(e.target));
      toast("Business profile saved.");
    }).catch(() => {});
  };
  root.querySelector("#logo-form").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), async () => {
      await api("logo_upload", new FormData(e.target));
      business = await api("business");
      toast("Logo uploaded.");
    }).catch(() => {});
  };
}
async function settingsPage(root) {
  const n = await api("numbering");
  settings = await api("settings");
  root.innerHTML =
    heading("Settings", "A billing workspace that fits your business.") +
    `<div class="settings-grid"><section class="card"><div class="card-head"><h2>Tax & billing preferences</h2></div><form id="settings-form" class="card-body">${field("Standard GST rates (comma separated)", "gst_rates", settings.gst_rates.join(", "))}<p></p>${field("Maximum custom GST rate (%)", "gst_max", settings.gst_max, "number", 'min="0" max="100" step="0.01" required')}<p></p><label class="check"><input type="checkbox" name="round_to_rupee" ${settings.round_to_rupee ? "checked" : ""}>Round invoice total to nearest rupee</label><h3>Payment methods</h3><div class="checks">${["Cash", "UPI", "Card", "Bank Transfer", "Credit"].map((p) => `<label class="check"><input type="checkbox" name="payment" value="${p}" ${settings.payments.includes(p) ? "checked" : ""}>${p}</label>`).join("")}</div><p></p>${textarea("Default invoice terms", "terms", settings.terms, 'maxlength="2000"')}<p></p><button>Save preferences</button></form></section><section class="card"><div class="card-head"><h2>Invoice numbering</h2></div><form id="numbering-form" class="card-body">${field("Prefix", "prefix", n.prefix, "text", 'maxlength="20"')}<p></p>${field("Next invoice number", "next_number", n.next_number, "number", 'required min="1"')}<p></p>${field("Minimum number digits", "padding", n.padding, "number", 'required min="1" max="12"')}<p class="hint">The current series cannot move backwards. Numbers are assigned only when invoices are saved.</p><button>Save numbering</button></form></section></div>`;
  root.querySelector("#settings-form").onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    await busy(f.querySelector("button"), async () => {
      const d = formData(f);
      d.gst_rates = d.gst_rates
        .split(",")
        .map((x) => x.trim())
        .filter(Boolean);
      d.round_to_rupee = f.round_to_rupee.checked;
      d.payments = [...f.querySelectorAll("[name=payment]:checked")].map(
        (x) => x.value,
      );
      settings = await api("save_settings", d);
      toast("Preferences saved.");
    }).catch(() => {});
  };
  root.querySelector("#numbering-form").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), async () => {
      await api("save_numbering", formData(e.target));
      toast("Numbering updated.");
    }).catch(() => {});
  };
}
async function usersPage(root) {
  const data = await api("users");
  root.innerHTML =
    heading(
      "Your team",
      "Give each person the access they need.",
      '<button id="new-user">＋ Add team member</button>',
    ) +
    `<section class="card">${table(data.users, [
      ["name", "Name"],
      ["email", "Email"],
      ["role_id", "Role"],
      [
        "active",
        "Status",
        (v) =>
          `<span class="badge ${!Number(v) ? "cancelled" : ""}">${Number(v) ? "Active" : "Disabled"}</span>`,
      ],
      [
        "id",
        "Actions",
        (v) =>
          `<button class="secondary edit-user" data-id="${v}">Edit permissions</button>`,
      ],
    ])}</section><div class="notice">Create the local account, then use “Activate invited account” on the sign-in page. With a Firebase service account configured, you can also provision a temporary password below.</div>`;
  function editor(target = { role_id: "STAFF", active: 1 }) {
    const overrides = Object.fromEntries(
      data.overrides
        .filter((x) => x.user_id == target.id)
        .map((x) => [x.permission_id, Number(x.allowed) ? "allow" : "deny"]),
    );
    modal(
      `<h2>${target.id ? "Edit team member" : "Add team member"}</h2><form id="user-form"><div class="form-grid">${field("Name", "name", target.name, "text", 'required maxlength="160"')}${field("Email", "email", target.email, "email", `required ${target.id ? "readonly" : ""}`)}${select("Role", "role_id", ["STAFF", "OWNER", "ADMIN"], target.role_id)}${select("Account status", "active", { 1: "Active", 0: "Disabled" }, String(target.active))}</div><h3>Permission overrides</h3><p class="hint">“Role default” inherits the assigned role. Administrative permissions cannot be granted to Owner or Staff.</p><div class="form-grid">${data.permissions.map((p) => select(p.id.replaceAll("_", " ") + (Number(p.admin_only) ? " (Admin only)" : ""), "perm_" + p.id, { default: "Role default", allow: "Allow", deny: "Deny" }, overrides[p.id] || "default")).join("")}</div><p></p><button>Save team member</button></form>${target.id ? '<p></p><button id="provision" class="secondary">Provision Firebase account</button>' : ""}`,
    );
    document.querySelector("#user-form").onsubmit = async (e) => {
      e.preventDefault();
      await busy(e.target.querySelector("button"), async () => {
        const d = formData(e.target);
        d.id = target.id;
        d.active = d.active === "1";
        d.permissions = {};
        for (const p of data.permissions) {
          const value = d["perm_" + p.id];
          if (value !== "default") d.permissions[p.id] = value === "allow";
          delete d["perm_" + p.id];
        }
        await api("save_user", d);
        closeModal();
        toast("Team member saved.");
        await usersPage(root);
      }).catch(() => {});
    };
    document.querySelector("#provision")?.addEventListener("click", () => {
      modal(
        `<h2>Provision Firebase account</h2><p>Create Firebase credentials for ${esc(target.email)}. Use a unique temporary password.</p><form id="provision-form">${field("Temporary password", "password", "", "password", 'minlength="12" required autocomplete="new-password"')}<p></p><button>Create Firebase account</button></form>`,
      );
      document.querySelector("#provision-form").onsubmit = async (e) => {
        e.preventDefault();
        await busy(e.target.querySelector("button"), async () => {
          const r = await api("provision_user", {
            id: target.id,
            password: e.target.password.value,
          });
          closeModal();
          toast(r.message);
        }).catch(() => {});
      };
    });
  }
  root.querySelector("#new-user").onclick = () => editor();
  root
    .querySelectorAll(".edit-user")
    .forEach(
      (b) =>
        (b.onclick = () =>
          editor(data.users.find((u) => u.id == b.dataset.id))),
    );
}
async function creditsPage(root) {
  root.innerHTML =
    heading(
      "Credit notes & returns",
      "Return invoice items and record the corresponding GST adjustment.",
    ) +
    `<section class="card"><div class="card-head"><h2>Create a credit note</h2></div><form id="find-return" class="card-body filter-bar">${field("Original invoice number", "invoice_number", "", "text", "required")}<button>Find invoice</button></form><div id="return-form-slot"></div></section><section class="card"><div class="card-head"><h2>Credit note report</h2></div><div class="card-body">${dateFilters()}</div><div id="credits-list"></div></section>`;
  async function list(q = {}) {
    const d = await api("reports", undefined, { type: "credit", ...q });
    root.querySelector("#credits-list").innerHTML = table(d.rows, [
      ["id", "Credit note", (v) => "CN-" + esc(v)],
      ["note_date", "Date"],
      ["invoice_number", "Invoice"],
      ["reason", "Reason"],
      ["settlement", "Settlement"],
      ["taxable", "Taxable", money],
      ["gst", "GST adjustment", money],
      ["total", "Total", money],
    ]);
  }
  root.querySelector("#filters").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), () =>
      list(formData(e.target)),
    ).catch(() => {});
  };
  root.querySelector("#find-return").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), async () => {
      const r = await api("invoices", undefined, {
        invoice_number: e.target.invoice_number.value,
      });
      if (!r.rows.length) throw new Error("Invoice not found.");
      const i = await api("invoice", undefined, { id: r.rows[0].id });
      const slot = root.querySelector("#return-form-slot");
      slot.innerHTML = `<form id="return-form" class="card-body"><h3>${esc(i.invoice_number)} · ${esc(i.customer_name || "Walk-in customer")}</h3><div class="form-grid">${field("Credit note date", "note_date", today(), "date", "required")}${select("Settlement", "settlement", ["Adjustment", "Refund"])}${field("Reference", "reference", "", "text", 'maxlength="100"')}${textarea("Reason", "reason", "", 'required maxlength="500"')}</div><h3>Return quantities</h3>${i.items.map((item) => `<div class="item-card">${field(item.product_name + " · Invoiced " + item.quantity + " " + item.unit, "return_" + item.id, "0", "number", `min="0" max="${item.quantity}" step="0.001"`)}<p class="hint">Original taxable ${money(item.taxable)} · GST ${money(item.gst)}</p></div>`).join("")}<button>Create credit note</button></form>`;
      slot.querySelector("form").onsubmit = async (event) => {
        event.preventDefault();
        const f = event.target;
        const d = formData(f);
        d.invoice_id = i.id;
        d.items = i.items
          .filter((item) => Number(d["return_" + item.id]) > 0)
          .map((item) => ({
            invoice_item_id: item.id,
            quantity: d["return_" + item.id],
          }));
        if (
          !(await confirmAction(
            "Create this credit note?",
            "The returned quantities and GST adjustment will be recorded permanently.",
          ))
        )
          return;
        await busy(f.querySelector("button"), async () => {
          const n = await api("create_credit", d);
          toast("Credit note CN-" + n.id + " created.");
          slot.innerHTML = "";
          await list();
        }).catch(() => {});
      };
    }).catch(() => {});
  };
  if (can("view_reports")) await list();
  else
    root.querySelector("#credits-list").innerHTML =
      '<div class="notice">Report permission is required to view the credit note report.</div>';
}
async function activityPage(root) {
  root.innerHTML =
    heading(
      "Activity log",
      "A traceable record of important actions in your workspace.",
    ) +
    `<section class="card"><div class="card-body">${dateFilters()}</div><div id="activity-list"></div></section>`;
  let page = 1,
    q = {};
  async function load() {
    const d = await api("activity", undefined, { ...q, page });
    root.querySelector("#activity-list").innerHTML =
      table(d.rows, [
        ["created_at", "Date & time"],
        ["user_name", "Team member"],
        ["action", "Action"],
        ["invoice_number", "Invoice"],
        [
          "details",
          "Details",
          (v) =>
            `<details><summary>View details</summary><pre>${esc(JSON.stringify(JSON.parse(v), null, 2))}</pre></details>`,
        ],
      ]) +
      `<div class="table-footer"><span>Page ${page}</span><div class="actions"><button id="prev-activity" class="secondary" ${page === 1 ? "disabled" : ""}>Previous</button><button id="next-activity" class="secondary" ${d.rows.length < 50 ? "disabled" : ""}>Next</button></div></div>`;
    root.querySelector("#prev-activity").onclick = () => {
      page--;
      load().catch((e) => toast(e.message, true));
    };
    root.querySelector("#next-activity").onclick = () => {
      page++;
      load().catch((e) => toast(e.message, true));
    };
  }
  root.querySelector("#filters").onsubmit = async (e) => {
    e.preventDefault();
    page = 1;
    q = formData(e.target);
    await busy(e.target.querySelector("button"), load).catch(() => {});
  };
  await load();
}
function backupPage(root) {
  root.innerHTML =
    heading(
      "Backup & restore",
      "Keep a recoverable copy of your business records.",
    ) +
    `<div class="settings-grid"><section class="card"><div class="card-head"><h2>Download encrypted backup</h2></div><div class="card-body"><p>Includes users, invoices, returns, settings, audit history and logos. Store the backup and your application key securely.</p><button id="download-backup">Create & download backup</button><p class="hint">Automatic and cloud backups are available through the documented Hostinger cron configuration.</p></div></section><section class="card"><div class="card-head"><h2>Restore your records</h2></div><form id="restore-form" class="card-body"><p>Restoring replaces the current database with the selected backup. A backup of the current state is saved first. Your active admin identity must exist in the backup.</p>${field("Encrypted backup", "backup", "", "file", 'accept=".gstbackup" required')}<p></p>${field("Type RESTORE ALL DATA to confirm", "confirmation", "", "text", "required")}<p></p><button class="danger">Restore backup</button></form></section></div>`;
  root.querySelector("#download-backup").onclick = async (e) => {
    await busy(e.target, () => downloadPost("backup")).catch(() => {});
  };
  root.querySelector("#restore-form").onsubmit = async (e) => {
    e.preventDefault();
    if (
      !(await confirmAction(
        "Replace the current records?",
        "This restores users, settings, invoices and audit history from the selected backup.",
      ))
    )
      return;
    await busy(e.target.querySelector("button"), async () => {
      await api("restore", new FormData(e.target));
      toast("Backup restored. Reloading workspace.");
      const s = await api("session");
      user = s.user;
      await startWorkspace();
    }).catch(() => {});
  };
}
async function integrationsPage(root) {
  const d = await api("integrations");
  root.innerHTML =
    heading(
      "GST integrations",
      "Connect your authorized provider for e-invoice and e-way bill requests.",
    ) +
    `<div class="notice">${d.configured ? "Provider endpoint configured. Ensure your provider implements the documented adapter contract before submission." : "Requires external API access: configure your authorized GST provider adapter and credentials. No government submission is simulated."}</div><section class="card"><form id="gst-form" class="card-body form-grid">${field("Invoice number", "invoice_number", "", "text", "required")}${select("Operation", "kind", { einvoice: "E-invoice / IRN / signed QR", ewaybill: "E-way bill" })}<button ${!d.configured ? "disabled" : ""}>Submit to provider</button></form></section><section class="card"><div class="card-head"><h2>Provider responses</h2></div>${table(
      d.requests,
      [
        ["created_at", "Date"],
        ["invoice_id", "Invoice ID"],
        ["kind", "Operation"],
        ["status", "Status"],
        [
          "response",
          "Response",
          (v) =>
            `<details><summary>IRN / QR / reference details</summary><pre>${esc(JSON.stringify(JSON.parse(v), null, 2))}</pre></details>`,
        ],
      ],
    )}</section>`;
  root.querySelector("#gst-form").onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    if (
      !(await confirmAction(
        "Submit invoice to GST provider?",
        "The full invoice and customer details will be transmitted to your configured provider.",
      ))
    )
      return;
    await busy(f.querySelector("button"), async () => {
      const r = await api("invoices", undefined, {
        invoice_number: f.invoice_number.value,
      });
      if (!r.rows.length) throw new Error("Invoice not found.");
      await api("gst_submit", { kind: f.kind.value }, { id: r.rows[0].id });
      toast("Provider accepted the request.");
      await integrationsPage(root);
    }).catch(() => {});
  };
}
window.addEventListener("hashchange", route);
try {
  await configure();
  const s = await api("session");
  user = s.user;
  setCsrf(s.csrf);
  await startWorkspace();
} catch (e) {
  if (e.status === 401) await loginScreen();
  else {
    app.innerHTML = `<div class="login-form-wrap"><div class="login-form"><div class="brand"><span class="brand-mark">≋</span>ledger</div><h2>Workspace setup required</h2><p>${esc(e.message)}</p><p>Configure your database and Firebase values in .env, import the schema and seed data, then reload.</p><button id="setup-retry">Retry connection</button></div></div>`;
    app.querySelector("#setup-retry").onclick = () => location.reload();
  }
}
