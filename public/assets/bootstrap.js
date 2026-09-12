const app = document.querySelector("#app");

try {
  await import("./app.js?v=2.1.2");
} catch (error) {
  console.error("Application startup failed", error);
  app.innerHTML = `<main class="login-form-wrap"><section class="login-form">
    <div class="brand"><span class="brand-mark">≋</span><div>ledger<small>GST billing</small></div></div>
    <h2>Update files are incomplete</h2>
    <p>The browser received files from different application versions. Upload everything inside the prepared <b>public_html</b> folder again, replace matching files, clear the Hostinger cache, then retry.</p>
    <p class="hint">Expected release: v2.1.2</p>
    <button id="startup-retry">Retry connection</button>
  </section></main>`;
  app.querySelector("#startup-retry").onclick = () => location.reload();
}
