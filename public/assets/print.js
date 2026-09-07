async function printInvoice(thermal) {
  const r = await fetch(
    "api.php?action=printed&id=" + document.body.dataset.invoice,
    {
      method: "POST",
      headers: {
        "X-CSRF-Token": document.body.dataset.csrf,
        "Content-Type": "application/json",
      },
      body: "{}",
    },
  );
  const result = await r.json();
  if (!r.ok) {
    document.querySelector("#print-error").textContent = result.error;
    return;
  }
  document.body.classList.toggle("thermal", thermal);
  window.print();
}
document
  .querySelector("#print")
  .addEventListener("click", () => printInvoice(false));
document
  .querySelector("#thermal")
  .addEventListener("click", () => printInvoice(true));
