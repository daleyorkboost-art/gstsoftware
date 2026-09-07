import { api } from "./api.js";
import {
  esc,
  money,
  today,
  field,
  select,
  textarea,
  states,
  formData,
  toast,
  busy,
  modal,
  closeModal,
} from "./ui.js";
export async function renderEditor(root, id, user) {
  const [business, settings, invoice] = await Promise.all([
    api("business"),
    api("settings"),
    id ? api("invoice", undefined, { id }) : Promise.resolve(null),
  ]);
  const data = invoice || {
    invoice_date: today(),
    place_of_supply: business.state,
    customer_type: "Unregistered",
    payment_method: settings.payments[0],
    notes: settings.terms,
    items: [
      { quantity: "1", rate: "0", discount: "0", gst_rate: "18", unit: "pcs" },
    ],
  };
  root.innerHTML = `<div class="page-heading"><div><h1>${id ? "Edit invoice" : "Create invoice"}</h1><p>${id ? esc(invoice.invoice_number) + " · Changes are recorded in the audit trail." : "A few details. A clear invoice. Ready for business."}</p></div><a href="#invoices" class="button secondary">Back to invoices</a></div>${!business.name ? '<div class="notice">Complete your business profile before saving your first invoice.</div>' : ""}<form id="invoice-form"><div class="invoice-layout"><div><section class="card"><div class="card-head"><h2><span class="section-number">01</span>Invoice details</h2><span class="badge">${id ? esc(invoice.invoice_number) : "Number assigned on save"}</span></div><div class="card-body form-grid three">${field("Invoice date", "invoice_date", data.invoice_date, "date", "required")}${select("Place of supply", "place_of_supply", states, data.place_of_supply)}${select("Payment method", "payment_method", settings.payments, data.payment_method)}</div></section><section class="card"><div class="card-head"><h2><span class="section-number">02</span>Customer details</h2><small>Optional</small></div><div class="card-body form-grid">${field("Customer name", "customer_name", data.customer_name, "text", 'maxlength="160"')}${field("Mobile number", "customer_mobile", data.customer_mobile, "tel", 'maxlength="30"')}${field("GSTIN · format validation only", "customer_gstin", data.customer_gstin, "text", 'maxlength="15"')}${select("Customer state", "customer_state", { "": "Not specified", ...states }, data.customer_state)}${select("Customer type", "customer_type", ["Unregistered", "Registered"], data.customer_type)}${field("Payment reference", "payment_reference", data.payment_reference, "text", 'maxlength="100"')}${textarea("Billing address", "billing_address", data.billing_address, 'maxlength="1500"')}${textarea("Shipping address", "shipping_address", data.shipping_address, 'maxlength="1500"')}</div></section><section class="card"><div class="card-head"><h2><span class="section-number">03</span>Invoice items</h2><small>Entered for this invoice</small></div><div class="card-body"><div id="items"></div><button type="button" id="add-item" class="secondary">＋ Add another item</button><p class="hint">Discount is an absolute amount for the entire item row, applied before GST.</p></div></section><section class="card"><div class="card-head"><h2><span class="section-number">04</span>Additional details</h2></div><div class="card-body form-grid">${field("Transporter", "transporter", data.transporter, "text", 'maxlength="160"')}${field("Vehicle number", "vehicle_number", data.vehicle_number, "text", 'maxlength="30"')}<div class="full">${textarea("Terms & notes", "notes", data.notes, 'maxlength="2000"')}</div></div></section></div><aside class="card summary-panel"><div class="card-head"><h2>Invoice summary</h2></div><div class="card-body"><div id="calculation"><p>Add your items to see totals.</p></div><button type="submit">Review ${id ? "changes" : "invoice"} →</button><p class="hint">All totals are calculated and verified securely before saving.</p><p id="calculation-error" class="hint" role="status"></p></div></aside></div></form>`;
  const form = root.querySelector("#invoice-form"),
    container = root.querySelector("#items");
  let timer,
    revision = 0,
    lastCalculation = null;
  function addItem(
    item = {
      quantity: "1",
      rate: "0",
      discount: "0",
      gst_rate: "18",
      unit: "pcs",
    },
  ) {
    const node = document.createElement("section");
    node.className = "item-card";
    const standard = settings.gst_rates.some(
      (r) => Number(r) === Number(item.gst_rate),
    );
    node.innerHTML = `<div class="item-top"><b class="item-label">Item</b><button type="button" class="remove-item">Remove item</button></div><div class="item-grid"><div class="wide">${field("Product / item name", "product_name", item.product_name, "text", 'required maxlength="160"')}</div>${field("HSN / SAC", "hsn_sac", item.hsn_sac, "text", 'maxlength="12"')}${field("Unit", "unit", item.unit, "text", 'maxlength="20"')}${field("Quantity", "quantity", item.quantity, "number", 'required min="0.001" step="0.001"')}${field("Rate (INR)", "rate", item.rate, "number", 'required min="0" step="0.01"')}${field("Discount (INR)", "discount", item.discount, "number", 'required min="0" step="0.01"')}${select(
      "GST rate",
      "gst_option",
      [
        ...settings.gst_rates.map((r) => [r, r + "%"]),
        ["custom", "Custom rate"],
      ].reduce((a, [k, v]) => ((a[k] = v), a), {}),
      standard
        ? settings.gst_rates.find((r) => Number(r) === Number(item.gst_rate))
        : "custom",
    )}<label class="custom-rate" ${standard ? "hidden" : ""}>Custom GST (%)<input name="gst_rate" type="number" step="0.01" min="0" max="${esc(settings.gst_max)}" value="${esc(item.gst_rate)}"></label><div class="wide">${field("Description", "description", item.description, "text", 'maxlength="500"')}</div></div>`;
    node.querySelector(".remove-item").onclick = () => {
      if (container.children.length === 1) {
        toast("Keep at least one item.", true);
        return;
      }
      node.remove();
      renumber();
      schedule();
    };
    node.querySelector("[name=gst_option]").onchange = (e) => {
      node.querySelector(".custom-rate").hidden = e.target.value !== "custom";
      if (e.target.value !== "custom")
        node.querySelector("[name=gst_rate]").value = e.target.value;
      schedule();
    };
    container.append(node);
    renumber();
  }
  function renumber() {
    container
      .querySelectorAll(".item-label")
      .forEach((n, i) => (n.textContent = "Item " + (i + 1)));
  }
  function payload() {
    const d = formData(form);
    d.items = [...container.children].map((node) =>
      Object.fromEntries(
        [...node.querySelectorAll("input")].map((el) => [el.name, el.value]),
      ),
    );
    d.version = data.version;
    d.id = id;
    return d;
  }
  function totals(t) {
    return Object.entries({
      subtotal: "Subtotal",
      discount: "Discount",
      taxable: "Taxable amount",
      cgst: "CGST",
      sgst: "SGST",
      igst: "IGST",
      gst: "Total GST",
      round_off: "Round-off",
      grand_total: "Grand total",
    })
      .map(
        ([k, l]) =>
          `<div class="summary-line ${k === "grand_total" ? "summary-total" : ""}"><span>${l}</span><b>${money(t[k])}</b></div>`,
      )
      .join("");
  }
  async function calculate() {
    const current = ++revision;
    try {
      const result = await api("calculate", payload());
      if (current !== revision) return;
      lastCalculation = result;
      root.querySelector("#calculation").innerHTML = totals(result.totals);
      root.querySelector("#calculation-error").textContent = "";
      return result;
    } catch (e) {
      if (current === revision) {
        lastCalculation = null;
        root.querySelector("#calculation-error").textContent = e.message;
      }
      throw e;
    }
  }
  function schedule() {
    revision++;
    lastCalculation = null;
    clearTimeout(timer);
    timer = setTimeout(() => calculate().catch(() => {}), 250);
  }
  data.items.forEach(addItem);
  root.querySelector("#add-item").onclick = () => {
    addItem();
    container.lastChild.querySelector("input").focus();
  };
  form.addEventListener("input", schedule);
  form.addEventListener("change", schedule);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await busy(form.querySelector("[type=submit]"), async () => {
      clearTimeout(timer);
      const result = await calculate();
      modal(
        `<h2>Review ${id ? "invoice changes" : "your invoice"}</h2><p>${esc(data.invoice_number || "New invoice")} · ${esc(form.elements.invoice_date.value)}<br>${esc(form.elements.customer_name.value || "Walk-in customer")} · ${result.items.length} item(s)</p>${totals(result.totals)}<p class="hint">Payment: ${esc(form.elements.payment_method.value)}. Place of supply: ${esc(states[form.elements.place_of_supply.value])}.</p><button id="confirm-save">${id ? "Save changes" : "Save & generate invoice"}</button>`,
      );
      document.querySelector("#confirm-save").onclick = async (e) => {
        await busy(e.target, async () => {
          const saved = await api("save_invoice", payload(), id ? { id } : {});
          closeModal();
          toast("Invoice saved successfully.");
          location.hash = "invoice/" + saved.id;
        }).catch(() => {});
      };
    }).catch(() => {});
  });
  if (id) calculate().catch(() => {});
}
