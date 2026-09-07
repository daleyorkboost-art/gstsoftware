import { api, setCsrf } from "./api.js";
let firebase = {};
let refreshTimer;
export async function configure() {
  const c = await api("config");
  firebase = c.firebase;
  globalThis.billingTimezone = c.timezone;
  setCsrf(c.csrf);
  return firebase;
}
async function firebaseRequest(operation, payload) {
  if (!firebase.apiKey)
    throw new Error(
      "Firebase is not configured. Follow the deployment guide and add the Firebase values to .env.",
    );
  const r = await fetch(
    "https://identitytoolkit.googleapis.com/v1/accounts:" +
      operation +
      "?key=" +
      encodeURIComponent(firebase.apiKey),
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    },
  );
  const result = await r.json();
  if (!r.ok)
    throw new Error(
      "Authentication failed. Check your email and password, or reset your password.",
    );
  return result;
}
export async function signIn(email, password) {
  const f = await firebaseRequest("signInWithPassword", {
    email,
    password,
    returnSecureToken: true,
  });
  const session = await api("login", { token: f.idToken });
  setCsrf(session.csrf);
  scheduleRefresh(f.refreshToken);
  return session.user;
}
function scheduleRefresh(token) {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(
    async () => {
      try {
        const r = await fetch(
          "https://securetoken.googleapis.com/v1/token?key=" +
            encodeURIComponent(firebase.apiKey),
          {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
              grant_type: "refresh_token",
              refresh_token: token,
            }),
          },
        );
        if (!r.ok) return;
        const t = await r.json();
        const session = await api("login", { token: t.id_token });
        setCsrf(session.csrf);
        scheduleRefresh(t.refresh_token);
      } catch {
        /* Expired sessions require sign-in. */
      }
    },
    45 * 60 * 1000,
  );
}
export async function resetPassword(email) {
  await firebaseRequest("sendOobCode", {
    requestType: "PASSWORD_RESET",
    email,
  });
}
export async function activate(email, password) {
  const f = await firebaseRequest("signUp", {
    email,
    password,
    returnSecureToken: true,
  });
  await firebaseRequest("sendOobCode", {
    requestType: "VERIFY_EMAIL",
    idToken: f.idToken,
  });
}
export async function signOut() {
  await api("logout", {});
  clearTimeout(refreshTimer);
}
