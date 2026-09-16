import { api } from "./api.js?v=2.1.2";
import { esc, money, today, field, select, textarea, states, formData, toast, busy, modal, closeModal } from "./ui.js?v=2.1.2";

export async function renderEditor(root, id) {
  const [business, settings, invoice] = await Promise.all([
    api("business"), api("settings"), id ? api("invoice", undefined, { id }) : Promise.resolve(null),
  ]);
  const data = invoice || {
    invoice_date: today(), place_of_supply: business.state, customer_type: "Unregistered",
    gst_mode: "Include", credit_bill: false, shipping_charges: "0", shipping_gst_rate: settings.shipping_gst_rate || "0", payment_allocations: [], notes: settings.terms,
    items: [{ quantity: "1", rate: "0", discount_mode: "Flat", discount_value: "0", gst_rate: "18", unit: "pcs" }],
  };
  root.innerHTML = `<div class="page-heading"><div><h1>${id ? "Edit invoice" : "Create invoice"}</h1><p>${id ? esc(invoice.invoice_number) + " · Changes are recorded." : "Create a complete GST invoice with payment and credit tracking."}</p></div><a href="#invoices" class="button secondary">Back to invoices</a></div>
  ${!business.name ? '<div class="notice">Complete your business profile before saving your first invoice.</div>' : ""}
  <form id="invoice-form"><div class="invoice-layout"><div>
  <section class="card"><div class="card-head"><h2>GST price mode</h2></div><div class="card-body"><div class="gst-mode-options"><label class="check"><input type="radio" name="gst_mode" value="Include" ${data.gst_mode === "Include" ? "checked" : ""}> Include GST</label><label class="check"><input type="radio" name="gst_mode" value="Exclude" ${data.gst_mode !== "Include" ? "checked" : ""}> Exclude GST</label></div><p class="hint" id="gst-mode-hint"></p></div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">01</span>Business / invoice</h2><span class="badge">${id ? esc(invoice.invoice_number) : "Number assigned on save"}</span></div><div class="card-body form-grid three">${field("Invoice date", "invoice_date", data.invoice_date, "date", "required")}${select("Place of supply", "place_of_supply", states, data.place_of_supply)}${field("Delivery note", "delivery_note", data.delivery_note, "text", 'maxlength="160"')}${field("Payment terms", "payment_terms", data.payment_terms, "text", 'maxlength="160"')}${field("Buyer's order number", "buyer_order_number", data.buyer_order_number, "text", 'maxlength="160"')}${field("Buyer's order date", "buyer_order_date", data.buyer_order_date, "date")}</div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">02</span>Dispatch</h2></div><div class="card-body form-grid">${field("Dispatch document number", "dispatch_doc_number", data.dispatch_doc_number, "text", 'maxlength="160"')}${field("Delivery note date", "delivery_note_date", data.delivery_note_date, "date")}${field("Dispatch through", "dispatch_through", data.dispatch_through || data.transporter, "text", 'maxlength="160"')}${field("Destination", "destination", data.destination, "text", 'maxlength="160"')}${field("Transporter", "transporter", data.transporter, "text", 'maxlength="160"')}${field("Vehicle number", "vehicle_number", data.vehicle_number, "text", 'maxlength="30"')}${textarea("Terms of delivery", "terms_of_delivery", data.terms_of_delivery, 'maxlength="500"')}</div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">03</span>Buyer (Bill To)</h2><small>Required when an amount is due</small></div><div class="card-body form-grid">${field("Buyer name", "customer_name", data.customer_name, "text", 'maxlength="160"')}${field("Mobile number", "customer_mobile", data.customer_mobile, "tel", 'maxlength="30"')}${field("E-mail", "customer_email", data.customer_email, "email", 'maxlength="190"')}${field("GSTIN · format validation only", "customer_gstin", data.customer_gstin, "text", 'maxlength="15"')}${select("Buyer state", "customer_state", { "": "Not specified", ...states }, data.customer_state)}${select("Customer type", "customer_type", ["Unregistered", "Registered"], data.customer_type)}${textarea("Billing address", "billing_address", data.billing_address, 'maxlength="1500"')}</div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">04</span>Consignee (Ship To)</h2><small>Leave blank to use buyer details</small></div><div class="card-body form-grid">${field("Consignee name", "shipping_name", data.shipping_name, "text", 'maxlength="160"')}${field("Mobile", "shipping_mobile", data.shipping_mobile, "tel", 'maxlength="30"')}${field("E-mail", "shipping_email", data.shipping_email, "email", 'maxlength="190"')}${select("State", "shipping_state", { "": "Use buyer state", ...states }, data.shipping_state)}${textarea("Shipping address", "shipping_address", data.shipping_address, 'maxlength="1500"')}</div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">05</span>Goods / services</h2><small>Invoice-only items</small></div><div class="card-body"><div id="items"></div><button type="button" id="add-item" class="secondary">＋ Add another item</button><p class="hint">Choose either flat/cash or percentage discount for each line.</p></div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">06</span>Additional charges</h2></div><div class="card-body form-grid">${field("Shipping charges (INR)", "shipping_charges", data.shipping_charges, "number", 'min="0" step="0.01" required')}${field("Shipping GST (%)", "shipping_gst_rate", data.shipping_gst_rate ?? settings.shipping_gst_rate ?? "0", "number", `min="0" max="${esc(settings.gst_max)}" step="0.01" required`)}<p class="hint full">Shipping is entered before GST. Its tax is included in total GST below. New invoices start with the rate configured in Settings.</p><div class="full">${textarea("Terms & notes", "notes", data.notes, 'maxlength="2000"')}</div></div></section>
  <section class="card"><div class="card-head"><h2><span class="section-number">07</span>Payment</h2><button type="button" id="add-payment" class="secondary">＋ Add payment allocation</button></div><div class="card-body"><label class="check"><input type="checkbox" name="credit_bill" ${Number(data.credit_bill) ? "checked" : ""}> Credit / due bill</label><div id="payments"></div><p class="hint">Add multiple rows for split payment. The unpaid balance enters the customer credit ledger.</p></div></section>
  </div><aside class="card summary-panel"><div class="card-head"><h2>Invoice summary</h2></div><div class="card-body"><div id="calculation"><p>Add your items to see totals.</p></div><button type="submit">Review ${id ? "changes" : "invoice"} →</button><p class="hint">Totals and allocations are verified by the server.</p><p id="calculation-error" class="hint" role="status"></p></div></aside></div></form>`;

  const form = root.querySelector("#invoice-form");
  const container = root.querySelector("#items");
  const payments = root.querySelector("#payments");
  let timer, revision = 0;
  let placeOfSupplyManuallyChanged = false;
  const paymentMethods = settings.payments.filter((method) => method !== "Credit");

  function addItem(item = { quantity: "1", rate: "0", discount_mode: "Flat", discount_value: "0", gst_rate: "18", unit: "pcs" }) {
    const node = document.createElement("section");
    node.className = "item-card";
    const standard = settings.gst_rates.some((rate) => Number(rate) === Number(item.gst_rate));
    node.innerHTML = `<div class="item-top"><b class="item-label">Item</b><button type="button" class="remove-item">Remove item</button></div><div class="item-grid"><div class="wide">${field("Product / item name", "product_name", item.product_name, "text", 'required maxlength="160"')}</div>${field("HSN / SAC", "hsn_sac", item.hsn_sac, "text", 'maxlength="12"')}${field("Unit", "unit", item.unit, "text", 'maxlength="20"')}${field("Quantity", "quantity", item.quantity, "number", 'required min="0.001" step="0.001"')}${field("Entered price (INR)", "rate", item.entered_rate ?? item.rate, "number", 'required min="0" step="0.01"')}${select("Discount mode", "discount_mode", { Flat: "Flat / cash", Percent: "Percentage" }, item.discount_mode || "Flat")}${field("Discount value", "discount_value", item.discount_value ?? item.discount ?? "0", "number", 'required min="0" step="0.01"')}${select("GST rate", "gst_option", [...settings.gst_rates.map((rate) => [rate, rate + "%"]), ["custom", "Custom rate"]].reduce((all, [key, value]) => ((all[key] = value), all), {}), standard ? settings.gst_rates.find((rate) => Number(rate) === Number(item.gst_rate)) : "custom")}<label class="custom-rate" ${standard ? "hidden" : ""}>Custom GST (%)<input name="gst_rate" type="number" step="0.01" min="0" max="${esc(settings.gst_max)}" value="${esc(item.gst_rate)}"></label><div class="wide">${field("Description", "description", item.description, "text", 'maxlength="500"')}</div></div>`;
    node.querySelector(".remove-item").onclick = () => { if (container.children.length === 1) return toast("Keep at least one item.", true); node.remove(); renumber(); schedule(); };
    node.querySelector("[name=gst_option]").onchange = (event) => { node.querySelector(".custom-rate").hidden = event.target.value !== "custom"; if (event.target.value !== "custom") node.querySelector("[name=gst_rate]").value = event.target.value; schedule(); };
    container.append(node); renumber();
  }
  function renumber() { container.querySelectorAll(".item-label").forEach((node, index) => (node.textContent = "Item " + (index + 1))); }
  function addPayment(payment = { method: paymentMethods[0], amount: "", reference: "" }) {
    const node = document.createElement("div"); node.className = "item-card payment-row";
    node.innerHTML = `<div class="item-grid">${select("Payment method", "method", paymentMethods, payment.method)}${field("Amount (INR)", "amount", payment.amount, "number", 'required min="0.01" step="0.01"')}${field("Reference (optional)", "reference", payment.reference, "text", 'maxlength="100"')}<button type="button" class="remove-payment secondary">Remove</button></div>`;
    node.querySelector(".remove-payment").onclick = () => { node.remove(); schedule(); }; payments.append(node);
  }
  function payload() {
    const result = formData(form);
    result.items = [...container.children].map((node) => Object.fromEntries([...node.querySelectorAll("input,select")].filter((el) => el.name !== "gst_option").map((el) => [el.name, el.value])));
    result.payment_allocations = [...payments.children].map((node) => Object.fromEntries([...node.querySelectorAll("input,select")].map((el) => [el.name, el.value])));
    result.credit_bill = form.elements.credit_bill.checked; result.version = data.version; result.id = id;
    return result;
  }
  function totals(t, fields = {}) {
    const interstate = form.elements.place_of_supply.value !== business.state;
    const taxHeading = `<div class="notice tax-mode">${interstate ? "Inter-state supply · IGST applies" : "Intra-state supply · CGST + SGST apply"}</div>`;
    return taxHeading + Object.entries({ subtotal: "Subtotal", discount: "Discount", shipping_charges: "Shipping charges", taxable: "Taxable amount", cgst: "CGST", sgst: "SGST", igst: "IGST", gst: "Total GST", shipping_gst: `Of which shipping GST (${t.shipping_gst_rate}%)`, round_off: "Round-off", grand_total: "Grand total", amount_paid: "Amount paid", amount_due: "Amount due" })
      .map(([key, label]) => `<div class="summary-line ${key === "grand_total" ? "summary-total" : ""}"><span>${label}</span><b>${money(t[key] ?? fields[key])}</b></div>`).join("");
  }
  async function calculate() {
    const current = ++revision;
    try {
      const result = await api("calculate", payload()); if (current !== revision) return;
      root.querySelector("#calculation").innerHTML = `<p class="hint">Calculated Rate (before GST)</p>${result.items.map((item) => `<div class="summary-line"><span>${esc(item.product_name)} · ${esc(item.gst_rate)}%</span><b>${money(item.rate)}</b></div>`).join("")}` + totals(result.totals, result.fields) + `<p class="badge">${esc(result.fields.payment_status)}</p>`;
      root.querySelector("#calculation-error").textContent = ""; return result;
    } catch (error) { if (current === revision) root.querySelector("#calculation-error").textContent = error.message; throw error; }
  }
  function schedule() { revision++; clearTimeout(timer); timer = setTimeout(() => calculate().catch(() => {}), 250); }
  function updateModeHint() {
    const inclusive = form.elements.gst_mode.value === "Include";
    container.querySelectorAll('[name="rate"]').forEach((input) => {
      input.parentElement.firstChild.textContent = inclusive ? "Price including GST (INR)" : "Rate before GST (INR)";
    });
    root.querySelector("#gst-mode-hint").textContent = inclusive
      ? "Enter the final per-unit price including GST. The invoice Rate is calculated before GST; discounts reduce the entered price."
      : "Enter the per-unit Rate before GST. GST is added after discounts.";
  }
  data.items.forEach(addItem); (data.payment_allocations || []).forEach(addPayment);
  updateModeHint();
  root.querySelector("#add-item").onclick = () => { addItem(); updateModeHint(); container.lastChild.querySelector("input").focus(); };
  root.querySelector("#add-payment").onclick = () => { addPayment(); payments.lastChild.querySelector("input").focus(); };
  form.elements.place_of_supply.addEventListener("change", () => { placeOfSupplyManuallyChanged = true; });
  form.elements.customer_state.addEventListener("change", (event) => {
    if (!placeOfSupplyManuallyChanged && event.target.value) {
      form.elements.place_of_supply.value = event.target.value;
    }
    schedule();
  });
  form.addEventListener("input", schedule); form.addEventListener("change", schedule);
  form.addEventListener("change", updateModeHint);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await busy(form.querySelector("[type=submit]"), async () => {
      clearTimeout(timer); const result = await calculate();
      modal(`<h2>Review ${id ? "invoice changes" : "your invoice"}</h2><p>${esc(data.invoice_number || "New invoice")} · ${esc(form.elements.invoice_date.value)}<br>${esc(form.elements.customer_name.value || "Walk-in customer")} · ${result.items.length} item(s)</p>${totals(result.totals, result.fields)}<p class="hint">Status: ${esc(result.fields.payment_status)} · Place of supply: ${esc(states[form.elements.place_of_supply.value])}</p><button id="confirm-save">${id ? "Save changes" : "Save & generate invoice"}</button>`);
      document.querySelector("#confirm-save").onclick = async (buttonEvent) => { await busy(buttonEvent.target, async () => { const saved = await api("save_invoice", payload(), id ? { id } : {}); closeModal(); toast("Invoice saved successfully."); location.hash = "invoice/" + saved.id; }).catch(() => {}); };
    }).catch(() => {});
  });
  schedule();
}
