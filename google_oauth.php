<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---- Config sanity ---- */
if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET') || !defined('GOOGLE_REDIRECT_URI')) {
  http_response_code(500);
  echo "Google OAuth is not configured. Add GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI in config.php";
  exit;
}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
  // Strongly encourage HTTPS (Google requires it in production)
  // You can remove this block if you terminate TLS in a proxy.
}

/* ---- Generate PKCE + state/nonce ---- */
function base64url($data){ return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
$code_verifier  = base64url(random_bytes(64));
$code_challenge = base64url(hash('sha256', $code_verifier, true));
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

$_SESSION['g_pkce_verifier'] = $code_verifier;
$_SESSION['g_oauth_state']   = $state;
$_SESSION['g_oauth_nonce']   = $nonce;

/* ---- Build auth URL ---- */
$params = [
  'client_id' => GOOGLE_CLIENT_ID,
  'redirect_uri' => GOOGLE_REDIRECT_URI, // should point to google_callback.php
  'response_type' => 'code',
  'scope' => 'openid email profile',
  'include_granted_scopes' => 'true',
  'access_type' => 'offline',
  'state' => $state,
  'nonce' => $nonce,
  'code_challenge' => $code_challenge,
  'code_challenge_method' => 'S256',
  // 'hd' => 'workshop17.com' // optional hint; we’ll enforce domain in callback anyway
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $auth_url);
exit;
