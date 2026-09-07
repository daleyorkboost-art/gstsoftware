# Direct-folder delivery

Use the contents of `hostinger-upload/` for Hostinger File Manager upload into `public_html`. No ZIP is required. See the root HOSTINGER-SETUP.md for database creation, configuration and the first administrator.

The upload folder includes production PHP dependencies, your Firebase configuration, a random production APP_KEY, and empty private storage. Fill in APP_URL, Hostinger database credentials and BOOTSTRAP_ADMIN_EMAIL in its .env. Firebase Analytics is not enabled; the measurement ID is stored for reference.

Local development tools, test fixtures and the development .env remain outside this folder. No live Hostinger deployment or Firebase account creation has been performed.
