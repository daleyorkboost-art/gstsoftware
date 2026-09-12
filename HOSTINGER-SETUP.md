# Updating your existing site?

You already migrated to v2.0: follow [the v2.1 update steps](docs/DEPLOY-V2.1-HOSTINGER.md). This `gstsoftware` project root is the complete deployment folder; upload its application contents together. Its complete `.env` and storage structure are retained. The fresh-install instructions below are for a NEW EMPTY database only.

# Direct upload to Hostinger — no ZIP needed

Upload this project root's application contents into your website's `public_html` using File Manager. The same root includes production dependencies, the complete `.env`, Firebase configuration and private storage structure. There is no second deployment folder or ZIP.

## 1. Create the database

1. Open hPanel → Websites → your website's Dashboard.
2. Open Databases → Management (or MySQL Databases).
3. Create a database and database user, choose a strong database password, and assign the user to the database if prompted.
4. Save the **full names including the Hostinger prefix**, for example `u123456789_gst` and `u123456789_gstuser`, plus the database host shown by Hostinger. Usually the host is `localhost`.
5. Open this database in phpMyAdmin. Select **Import**, upload `database/schema.sql` and run the import. Then import `database/seed.sql` into the same database.
6. Use these imports only on a new empty database. They create roles/settings but no administrator, invoices or demo customers.

Hostinger instructions: [database creation and import](https://www.hostinger.com/support/1864324-how-to-upload-and-set-up-your-database-at-hostinger/).

## 2. Upload the files

Open Files → File Manager → `public_html`. Upload the application files from this project root, preserving folders. Ensure hidden files `.env` and both `.htaccess` files are included. The result must look like:

```text
public_html/
  .env
  .htaccess
  public/
    index.php
    api.php
    .htaccess
    assets/
  config/
  src/
  database/
  templates/
  vendor/
  storage/
  bin/
```

The root `.htaccess` redirects the website to `/public/` and denies access to the private application files. Do not move `.env` into `public/`. Use an empty site/subdomain for this installation so it does not overwrite another site's files.

Select PHP 8.2+ in hPanel and enable `bcmath`, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `dom`, `fileinfo`, `gd`, `zip` and `zlib`. Make `storage/` writable by PHP. Enable HTTPS. No Composer, Node, Docker or build command is required on Hostinger.

## 3. Update the uploaded .env

In File Manager, edit `public_html/.env`. Fill these values:

```dotenv
APP_ENV=production
APP_URL=https://YOUR-DOMAIN.com/public
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=YOUR_FULL_HOSTINGER_DATABASE_NAME
DB_USERNAME=YOUR_FULL_HOSTINGER_DATABASE_USERNAME
DB_PASSWORD="YOUR_DATABASE_PASSWORD"
BOOTSTRAP_ADMIN_EMAIL=YOUR_ADMIN_EMAIL_ADDRESS
```

Use the database host provided by Hostinger if it differs. `BOOTSTRAP_ADMIN_EMAIL` must be an email address you control and can verify.

Your supplied Firebase API key, auth domain, project ID, storage bucket, sender ID, app ID and measurement ID are already present. A random production `APP_KEY` has also been generated in this upload folder; keep it unchanged and backed up because encrypted backups depend on it. SMTP, cloud and government GST integration variables may stay blank until you configure those services.

The measurement ID is stored for reference; Analytics tracking is **not enabled**. Billing authentication uses Firebase's REST API, so you do not need to paste JavaScript SDK imports or initialize Analytics.

## 4. Enable Firebase login

1. Open Firebase Console and select **gstbilling-fdb43**.
2. Open Authentication → Get started, if it has not been initialized.
3. Under Sign-in method, enable **Email/Password** and save.
4. Under Authentication → Settings → Authorized domains, add your actual website hostname, without `https://` or `/public` (for example `billing.example.com`).
5. Keep Firebase's default verification-email action handler unless you have deliberately configured a custom handler. No service-account JSON is needed for the normal account activation/login workflow.

Reference: [Firebase Email/Password account setup](https://support.google.com/firebase/answer/6400802?hl=en).

## 5. Create the first Admin

1. Set `BOOTSTRAP_ADMIN_EMAIL` in the uploaded `.env` to your desired admin email.
2. Open `https://YOUR-DOMAIN.com/public/`.
3. Click **Activate invited account**. This also handles the initial administrator.
4. Enter the exact same email and a strong password of at least 12 characters.
5. Click **Send verification email**, open the Firebase email and complete verification.
6. Return to the application and sign in with that email/password.
7. If the local users table is empty, the verified email matching `BOOTSTRAP_ADMIN_EMAIL` is automatically created as **ADMIN**. No manual SQL insertion or role changes are needed.

If that email already has a Firebase account, use its existing password or Forgot password rather than activating it again. It must have a verified email before initial Admin sign-in. If verification is still needed, send a verification email from that account's existing Firebase workflow or use a new bootstrap email that you control.

After signing in, enter your Business profile and bank/GST details, configure numbering, then use **Team & permissions** to add Owner/Staff users. Each new member activates and verifies the administrator-approved email through the same login-page workflow. The bootstrap email setting does not create another Admin once local users already exist.

## 6. Verify before billing

Create and reopen a test invoice, download a PDF, and check the seller details and GST totals. Confirm `https://YOUR-DOMAIN.com/.env` returns 403/404 and that Staff cannot open administrative screens. Then create an encrypted backup. The supplied Firebase configuration has been written locally; live sign-in and Hostinger connectivity still need to be checked after your deployment.
