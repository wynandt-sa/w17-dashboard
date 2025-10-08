<?php
// ==== CONFIG ====
define('DB_HOST', 'localhost');
define('DB_NAME', 'w17_ticketing');
define('DB_USER', 'root');
define('DB_PASS', 'root@W172025');

// Base URL (no trailing slash). Adjust if app is in a subfolder.
// Example: define('BASE_URL', 'https://tickets.workshop17.com');
define('BASE_URL', '');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_name('W17TKTSESS');
session_start();


// Zulip bot configuration
define('ZULIP_SITE',       'https://workshop17.zulipchat.com'); // or self-hosted URL
define('ZULIP_BOT_EMAIL',  'ticketing-bot@workshop17.zulipchat.com');
define('ZULIP_BOT_APIKEY', '8VyIz4zmromOysCF40peJrwXUIDlfWbG');
// Where tickets live (for links in messages)
define('APP_BASE_URL',     'https://dashboard.workshop17.com'); // no trailing slash


// Google OAuth
define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_OAUTH_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  (BASE_URL ?: '') . '/google_callback.php'); // must exactly match console config
