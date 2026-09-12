import { api, setCsrf, downloadPost } from "./api.js?v=2.1.2";
import { configure, signIn, signOut, resetPassword, activate, reauthenticate } from "./auth.js?v=2.1.2";
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
} from "./ui.js?v=2.1.2";
import { renderEditor } from "./invoice.js?v=2.1.2";
const app = document.querySelector("#app");
const release = "v2.1.2";
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
  ["credits", "Credit invoices", "↗", "manage_credit_invoices"],
  ["credit-notes", "Credit notes", "↶", "manage_credit_notes"],
  ["activity", "Activity log", "◫", "view_activity_logs"],
  ["business", "Business profile", "⌂", "manage_business_profile"],
  ["users", "Team & permissions", "♧", "manage_users"],
  ["settings", "Settings", "⚙", "manage_settings"],
  ["backups", "Backup & restore", "▤", "manage_backups"],
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
    ["payment_status", "Payment", (v) => `<span class="badge ${v === "Due" ? "credit" : ""}">${esc(v)}</span>`],
    ["amount_due", "Due", money],
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
    )}</nav><div class="sidebar-footer"><span class="release-pill">Updated ${release}</span><div class="profile"><span class="avatar">${esc(user.name[0]?.toUpperCase())}</span><div><b>${esc(user.name)}</b><small>${esc(user.role_id)} ACCOUNT</small></div></div><button id="logout" class="text-button">Sign out ↗</button></div></aside><div class="workspace"><header class="topbar"><button class="menu-toggle" aria-label="Toggle navigation">☰</button><div class="breadcrumb">Workspace <span> / </span> <b id="crumb">Overview</b></div><div class="topbar-right"><span class="secure-pill">● Secure workspace</span><span class="release-pill">${release}</span><span class="workspace-name">${esc(business.name || "Your business")}</span><span class="avatar">${esc((business.name || "B")[0])}</span></div></header><main id="content" class="content"></main></div></div>`;
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
        await creditInvoicesPage(root);
        break;
      case "credit-notes":
        await creditNotesPage(root);
        break;
      case "activity":
        await activityPage(root);
        break;
      case "backups":
        backupPage(root);
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
        `${money(d.outstanding_credit)} currently outstanding`,
      ],
      ["Paid invoices", d.paid_invoices, "✓", `${d.due_invoices} partially paid / due`],
      ["Pending credit customers", d.pending_credit_customers, "↗", "Customers with an outstanding balance"],
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
    `<section class="card"><div class="card-body">${dateFilters(field("Search invoice, customer, mobile or GSTIN", "search") + select("Payment status", "payment_status", ["", "Paid", "Partially Paid", "Due", "Credit Cleared", "Cancelled"]) + select("Invoice status", "status", ["", "Active", "Cancelled"]))}</div><div id="invoice-list"></div></section>`;
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
      can("print_invoice") ? `<a class="button secondary" href="print.php?id=${i.id}" target="_blank" rel="noopener">Print / preview</a><a class="button" href="api.php?action=pdf&id=${i.id}">Download PDF</a>` : "",
    ) +
    `<div class="actions card-body"><span class="badge ${i.status === "Cancelled" ? "cancelled" : ""}">${esc(i.status)}</span><span class="badge">${esc(i.payment_status)}</span><span class="version-tag">Version ${i.version}</span>${can("edit_invoice") && i.status === "Active" ? `<a class="button secondary" href="#edit/${i.id}">Edit invoice</a>` : ""}${can("duplicate_invoice") ? '<button id="duplicate" class="secondary">Duplicate</button>' : ""}${can("cancel_invoice") && i.status === "Active" ? '<button id="cancel" class="secondary">Cancel invoice</button>' : ""}<button id="share" class="secondary">Share</button>${can("export_invoice") ? '<button id="email" class="secondary">Email invoice</button>' : ""}<button id="history" class="secondary">Activity & changes</button></div>${i.cancellation_reason ? `<div class="notice">Cancellation reason: ${esc(i.cancellation_reason)}</div>` : ""}<section class="card"><div class="card-head"><h2>${esc(i.business.name)}</h2><b>${money(i.grand_total)}</b></div><div class="card-body form-grid"><div><h3>Bill to</h3><p>${esc(i.customer_name || "Walk-in customer")}<br>${esc(i.customer_mobile)}<br>${esc(i.customer_gstin)}<br>${esc(i.billing_address)}</p></div><div><h3>Invoice details</h3><p>Place of supply: ${esc(states[i.place_of_supply])}<br>Shipping charges: ${money(i.shipping_charges)}<br>Shipping GST (${esc(i.shipping_gst_rate)}%): ${money(i.shipping_gst)} (included in total GST)<br>Paid: ${money(i.amount_paid)}<br>Due: ${money(i.amount_due)}</p><h3>Payment allocations</h3>${i.payment_allocations.length ? i.payment_allocations.map((payment) => `<p>${esc(payment.method)} · ${money(payment.amount)} · ${esc(payment.reference || "No reference")}</p>`).join("") : "<p>Credit / unpaid</p>"}</div></div>${table(
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
      ["Shipping charges", i.shipping_charges],
      ["Shipping GST (included in total GST)", i.shipping_gst],
      ["Taxable amount", i.taxable],
      ["Total GST", i.gst],
      ["Round-off", i.round_off],
      ["Grand total", i.grand_total],
      ["Amount paid", i.amount_paid],
      ["Amount due", i.amount_due],
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
    `<section class="card"><form id="business-form" class="card-body"><div class="form-grid">${field("Business name", "name", business.name, "text", 'required maxlength="160"')}${field("GSTIN · format only", "gstin", business.gstin, "text", 'maxlength="15"')}${field("Business e-mail", "email", business.email, "email", 'maxlength="190"')}${select("State / state code", "state", states, business.state)}${field("A/c holder name", "account_holder", business.account_holder)}${field("Bank name", "bank_name", business.bank_name)}${field("A/c name", "account_name", business.account_name)}${field("Account number", "account_number", business.account_number)}${field("IFSC code", "ifsc", business.ifsc, "text", 'maxlength="11"')}${field("Branch", "branch", business.branch)}${field("Business financial year (documents use their own date)", "financial_year", business.financial_year, "text", 'placeholder="2026-27" pattern="[0-9]{4}-[0-9]{2}"')}${textarea("Business address", "address", business.address, 'maxlength="1500"')}${textarea("Invoice declaration", "declaration", business.declaration, 'maxlength="2000"')}</div><p class="hint">New invoices snapshot these details so historical documents remain stable.</p><button>Save business profile</button></form></section><div class="settings-grid"><section class="card"><div class="card-head"><h2>Business logo</h2></div><form id="logo-form" class="card-body">${business.logo ? '<img class="asset-preview" src="api.php?action=business_asset&type=logo" alt="Current business logo"><p class="asset-warning" hidden>Stored logo could not be loaded.</p>' : '<div class="notice">No business logo configured.</div>'}${field("PNG or JPEG · maximum 2 MB, 3000 × 3000 px", "logo", "", "file", 'accept="image/png,image/jpeg" required')}<p></p><div class="actions"><button>Upload logo</button>${business.logo ? '<button type="button" class="danger delete-asset" data-type="logo">Delete logo</button>' : ""}</div></form></section><section class="card"><div class="card-head"><h2>Stamp / authorised signature</h2></div><form id="signature-form" class="card-body">${business.signature ? '<img class="asset-preview" src="api.php?action=business_asset&type=signature" alt="Current authorised signature"><p class="asset-warning" hidden>Stored signature could not be loaded.</p>' : '<div class="notice">No signature or stamp configured.</div>'}${field("PNG or JPEG", "signature", "", "file", 'accept="image/png,image/jpeg" required')}<p></p><div class="actions"><button>Upload signature</button>${business.signature ? '<button type="button" class="danger delete-asset" data-type="signature">Delete stamp</button>' : ""}</div></form></section></div>`;
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
      businessPage(root);
    }).catch(() => {});
  };
  root.querySelector("#signature-form").onsubmit = async (e) => {
    e.preventDefault();
    await busy(e.target.querySelector("button"), async () => {
      await api("signature_upload", new FormData(e.target));
      business = await api("business");
      toast("Authorised signature uploaded.");
      businessPage(root);
    }).catch(() => {});
  };
  root.querySelectorAll(".asset-preview").forEach((image) => image.onerror = () => { image.hidden = true; image.nextElementSibling.hidden = false; });
  root.querySelectorAll(".delete-asset").forEach((button) => button.onclick = async () => {
    const label = button.dataset.type === "logo" ? "business logo" : "stamp / signature";
    if (!(await confirmAction(`Delete ${label}?`, "It will be removed from the business profile and future invoices. Existing invoice snapshots remain unchanged."))) return;
    await busy(button, async () => {
      const result = await api("business_asset_delete", { type: button.dataset.type });
      business = await api("business");
      toast(result.message);
      businessPage(root);
    }).catch(() => {});
  });
}
async function settingsPage(root) {
  const n = await api("numbering");
  settings = await api("settings");
  root.innerHTML =
    heading("Settings", "A billing workspace that fits your business.") +
    `<div class="settings-grid"><section class="card"><div class="card-head"><h2>Tax & billing preferences</h2></div><form id="settings-form" class="card-body">${field("Standard GST rates (comma separated)", "gst_rates", settings.gst_rates.join(", "))}<p></p>${field("Maximum custom GST rate (%)", "gst_max", settings.gst_max, "number", 'min="0" max="100" step="0.01" required')}<p></p>${field("GST rate on shipping (%)", "shipping_gst_rate", settings.shipping_gst_rate || "0", "number", 'min="0" max="100" step="0.01" required')}<p class="hint">Shipping is shown separately and taxed using this explicit policy.</p><label class="check"><input type="checkbox" name="round_to_rupee" ${settings.round_to_rupee ? "checked" : ""}>Round invoice total to nearest rupee</label><h3>Payment methods</h3><div class="checks">${["Cash", "UPI", "Card", "Bank Transfer", "Credit", "Other"].map((p) => `<label class="check"><input type="checkbox" name="payment" value="${p}" ${settings.payments.includes(p) ? "checked" : ""}>${p}</label>`).join("")}</div><p></p>${textarea("Default invoice terms", "terms", settings.terms, 'maxlength="2000"')}<p></p><button>Save preferences</button></form></section><section class="card"><div class="card-head"><h2>Invoice numbering</h2></div><form id="numbering-form" class="card-body">${field("Prefix", "prefix", n.prefix, "text", 'maxlength="20"')}<p></p>${field("Next invoice number", "next_number", n.next_number, "number", 'required min="1"')}<p></p>${field("Minimum number digits", "padding", n.padding, "number", 'required min="1" max="12"')}<p class="hint">The current series cannot move backwards. Numbers are assigned only when invoices are saved.</p><button>Save numbering</button></form></section></div>`;
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
      ["contact", "Contact"],
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
        (v, row) =>
          `<div class="actions"><button class="secondary edit-user" data-id="${v}">Edit</button>${row.role_id !== "ADMIN" && Number(row.linked) ? `<button class="secondary reset-user" data-id="${v}">Reset password</button>` : ""}${row.role_id !== "ADMIN" ? `<button class="danger delete-user" data-id="${v}">Delete permanently</button>` : ""}</div>`,
      ],
    ])}</section><div class="notice">Create the local account, then use “Activate invited account” on the sign-in page. With a Firebase service account configured, you can also provision a temporary password below.</div>`;
  function editor(target = { role_id: "STAFF", active: 1 }) {
    const overrides = Object.fromEntries(
      data.overrides
        .filter((x) => x.user_id == target.id)
        .map((x) => [x.permission_id, Number(x.allowed) ? "allow" : "deny"]),
    );
    modal(
      `<h2>${target.id ? "Edit team member" : "Add team member"}</h2><form id="user-form"><div class="form-grid">${field("Name", "name", target.name, "text", 'required maxlength="160"')}${field("Contact", "contact", target.contact, "tel", 'maxlength="30"')}${field("Email", "email", target.email, "email", `required ${target.id ? "readonly" : ""}`)}${select("Role", "role_id", ["STAFF", "OWNER", "ADMIN"], target.role_id)}${select("Account status", "active", { 1: "Active", 0: "Disabled" }, String(target.active))}</div><h3>Permission overrides</h3><p class="hint">“Role default” inherits the assigned role. Administrative permissions cannot be granted to Owner or Staff.</p><div class="form-grid">${data.permissions.map((p) => select(p.id.replaceAll("_", " ") + (Number(p.admin_only) ? " (Admin only)" : ""), "perm_" + p.id, { default: "Role default", allow: "Allow", deny: "Deny" }, overrides[p.id] || "default")).join("")}</div><p></p><button>Save team member</button></form>${target.id && !Number(target.linked) ? '<p></p><button id="provision" class="secondary">Provision Firebase account</button>' : ""}`,
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
  root.querySelectorAll(".reset-user").forEach((button) => button.onclick = () => {
    const target = data.users.find((row) => row.id == button.dataset.id);
    modal(`<h2>Reset ${esc(target.name)}'s password</h2><p>A secure Firebase password-reset link will be emailed to <b>${esc(target.email)}</b>. Enter your current Admin password to authorize the request.</p><form id="reset-user-form">${field("Your Admin password", "admin_password", "", "password", 'required autocomplete="current-password"')}<p></p><button>Send reset email</button></form>`);
    document.querySelector("#reset-user-form").onsubmit = async (event) => { event.preventDefault(); await busy(event.target.querySelector("button"), async () => { const token = await reauthenticate(user.email, event.target.admin_password.value); const result = await api("reset_user_password", { id: target.id, reauth_token: token }); closeModal(); toast(result.message); }).catch(() => {}); };
  });
  root.querySelectorAll(".delete-user").forEach((button) => button.onclick = () => {
    const target = data.users.find((row) => row.id == button.dataset.id);
    modal(`<h2>Permanently delete ${esc(target.name)}?</h2><div class="notice">This differs from disabling the account. The Firebase identity and local user record will be removed permanently; historical financial records remain assigned to the acting Admin.</div><form id="delete-user-form">${field("Your Admin password", "admin_password", "", "password", 'required autocomplete="current-password"')}<p></p><button class="danger">Delete user permanently</button></form>`);
    document.querySelector("#delete-user-form").onsubmit = async (event) => { event.preventDefault(); await busy(event.target.querySelector("button"), async () => { const token = await reauthenticate(user.email, event.target.admin_password.value); const result = await api("delete_user", { id: target.id, confirmation: "DELETE USER", reauth_token: token }); closeModal(); toast(result.message); await usersPage(root); }).catch(() => {}); };
  });
}
async function creditInvoicesPage(root) {
  root.innerHTML = heading("Credit invoices", "Customer balances, clearances and receipt history.", '<a class="button secondary" href="api.php?action=credit_history_all">Export all history ↓</a>') +
    `<section class="card"><div class="card-body"><form id="credit-filters" class="filter-bar">${field("Search name, mobile or invoice", "search")}${select("Status", "status", ["All", "Pending", "Partially Cleared", "Cleared"])}<button class="secondary">Apply</button></form></div><div id="credit-list"></div></section><div id="credit-detail"></div>`;
  async function load() {
    const data = await api("credit_accounts", undefined, formData(root.querySelector("#credit-filters")));
    root.querySelector("#credit-list").innerHTML = table(data.rows, [
      ["customer_name", "Customer"], ["customer_mobile", "Mobile"], ["total_credit", "Credit created", money],
      ["total_cleared", "Cleared", money], ["balance", "Outstanding", money], ["credit_status", "Status", (value) => `<span class="badge">${esc(value)}</span>`],
      ["id", "Action", (value) => `<button class="secondary open-credit" data-id="${value}">Open ledger</button>`],
    ]);
    root.querySelectorAll(".open-credit").forEach((button) => (button.onclick = () => detail(button.dataset.id)));
  }
  async function detail(id) {
    const account = await api("credit_account", undefined, { id });
    const slot = root.querySelector("#credit-detail");
    slot.innerHTML = `<section class="card"><div class="card-head"><div><h2>${esc(account.customer_name)}</h2><small>${esc(account.customer_mobile)} · ${esc(account.customer_address)}</small></div><div class="actions"><a class="button secondary" href="api.php?action=credit_history&id=${account.id}">Export history</a>${can("clear_credit") && Number(account.balance) > 0 ? '<button id="clear-credit">Clear credit</button>' : ""}</div></div><div class="card-body"><div class="stats"><div class="stat"><div class="stat-label">Current outstanding</div><div class="stat-value">${money(account.balance)}</div></div><div class="stat"><div class="stat-label">Total cleared</div><div class="stat-value">${money(account.total_cleared)}</div></div></div></div>${table(account.transactions, [
      ["transaction_date", "Date"], ["kind", "Type"], ["invoice_number", "Invoice", (value) => esc(value || "—")], ["receipt_number", "Receipt", (value, row) => value ? `<a href="api.php?action=credit_receipt&id=${row.id}">${esc(value)}</a>` : "—"], ["amount", "Amount", money], ["balance_after", "Running balance", money], ["allocations", "Payment modes", (value) => value.length ? value.map((row) => `${esc(row.method)} ${money(row.amount)}`).join(" + ") : "—"],
    ])}</section>`;
    slot.querySelector("#clear-credit")?.addEventListener("click", () => clearance(account));
  }
  function clearance(account) {
    modal(`<h2>Clear credit for ${esc(account.customer_name)}</h2><p>Outstanding: <b>${money(account.balance)}</b></p><form id="clearance-form">${field("Clearance date", "date", today(), "date", "required")}<div id="clearance-allocations"></div><button type="button" id="add-clearance" class="secondary">＋ Add payment method</button><p></p><button type="submit">Record clearance & create receipt</button></form>`);
    const form = document.querySelector("#clearance-form"), allocations = document.querySelector("#clearance-allocations");
    const add = () => { const row = document.createElement("div"); row.className = "item-card"; row.innerHTML = `<div class="item-grid">${select("Method", "method", settings.payments.filter((p) => p !== "Credit"))}${field("Amount", "amount", "", "number", `required min="0.01" max="${esc(account.balance)}" step="0.01"`)}${field("Reference", "reference", "", "text", 'maxlength="100"')}<button type="button" class="secondary remove">Remove</button></div>`; row.querySelector(".remove").onclick = () => row.remove(); allocations.append(row); };
    add(); form.querySelector("#add-clearance").onclick = add;
    form.onsubmit = async (event) => { event.preventDefault(); const payload = { credit_account_id: account.id, date: form.date.value, allocations: [...allocations.children].map((row) => Object.fromEntries([...row.querySelectorAll("input,select")].map((el) => [el.name, el.value]))) }; await busy(form.querySelector("[type=submit]"), async () => { const receipt = await api("clear_credit", payload); closeModal(); toast("Credit cleared. Receipt " + receipt.receipt_number + " is ready."); window.open(`api.php?action=credit_receipt&id=${receipt.id}`, "_blank", "noopener"); await load(); await detail(account.id); }).catch(() => {}); };
  }
  root.querySelector("#credit-filters").onsubmit = async (event) => { event.preventDefault(); await busy(event.target.querySelector("button"), load).catch(() => {}); };
  await load();
}

async function creditNotesPage(root) {
  root.innerHTML =
    heading(
      "Credit notes & returns",
      "Return invoice items and record the corresponding GST adjustment.",
    ) +
    `<section class="card"><div class="card-head"><h2>Create a credit note</h2></div><form id="find-return" class="card-body filter-bar">${field("Original invoice number", "invoice_number", "", "text", "required")}<button>Find invoice</button></form><div id="return-form-slot"></div></section><section class="card"><div class="card-head"><h2>Credit note report</h2></div><div class="card-body">${dateFilters()}</div><div id="credits-list"></div></section>`;
  async function list(q = {}) {
    const d = await api("reports", undefined, { type: "credit", ...q });
    root.querySelector("#credits-list").innerHTML = table(d.rows, [
      ["credit_note_number", "Credit note", (v, row) => `<a href="api.php?action=credit_note_pdf&id=${row.id}">CN-${esc(v)} · PDF</a>`],
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
          toast("Credit note " + n.number + " created.");
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
  if (can("delete_transaction_data")) root.insertAdjacentHTML("beforeend", `<section class="card danger-zone"><div class="card-head"><h2>Delete transaction data</h2></div><form id="delete-transactions-form" class="card-body"><div class="notice">Permanently removes invoices, invoice items, payments, credit accounts, clearances, credit notes and transactional audit history. Invoice, credit-note and receipt numbering restarts from 1. Business name, GSTIN, bank details, logo, declaration, signature, users and billing preferences are preserved. An encrypted backup is created first.</div>${field("Type DELETE ALL TRANSACTION DATA", "confirmation", "", "text", "required")}${field("Your Admin password", "admin_password", "", "password", 'required autocomplete="current-password"')}<p></p><button class="danger">Delete transaction data permanently</button></form></section>`);
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
  root.querySelector("#delete-transactions-form")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!(await confirmAction("Permanently delete all transaction data?", "The listed billing and credit records will be deleted after an automatic encrypted backup. Business master details will remain."))) return;
    await busy(event.target.querySelector("button"), async () => {
      const token = await reauthenticate(user.email, event.target.admin_password.value);
      const result = await api("delete_transactions", { confirmation: event.target.confirmation.value, reauth_token: token });
      toast(result.message + " Backup: " + result.backup);
      event.target.reset();
    }).catch(() => {});
  });
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
