<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---- Quick helpers ---- */
function bad($msg='Bad request'){ http_response_code(400); echo htmlspecialchars($msg); exit; }
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---- CSRF (state) ---- */
if (!isset($_GET['state'], $_SESSION['g_oauth_state']) || !hash_equals($_SESSION['g_oauth_state'], $_GET['state'])) {
  bad('Invalid state');
}
unset($_SESSION['g_oauth_state']); // single-use

/* ---- Exchange code for tokens ---- */
if (!isset($_GET['code'])) bad('Missing code');

$code = $_GET['code'];
$verifier = $_SESSION['g_pkce_verifier'] ?? null;
if (!$verifier) bad('Missing PKCE verifier');

$post = [
  'code' => $code,
  'client_id' => GOOGLE_CLIENT_ID,
  'client_secret' => GOOGLE_CLIENT_SECRET,
  'redirect_uri' => GOOGLE_REDIRECT_URI,
  'grant_type' => 'authorization_code',
  'code_verifier' => $verifier,
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query($post),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER => false,
]);
$resp = curl_exec($ch);
if ($resp === false) bad('Token exchange failed');
curl_close($ch);

$data = json_decode($resp, true);
$id_token = $data['id_token'] ?? null;
if (!$id_token) bad('Missing id_token');

/* ---- Validate id_token (compact JWT) ----
   Minimal checks: header.payload.signature (we rely on Google’s TLS + audience/issuer/nonce checks)
   For full verification, fetch Google certs & verify signature; often libraries handle it.
*/
$parts = explode('.', $id_token);
if (count($parts) !== 3) bad('Invalid id_token format');

$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
if (!$payload) bad('Invalid token payload');

$iss_ok = in_array($payload['iss'] ?? '', ['https://accounts.google.com','accounts.google.com'], true);
$aud_ok = ($payload['aud'] ?? '') === GOOGLE_CLIENT_ID;
$exp_ok = isset($payload['exp']) && time() < (int)$payload['exp'];
$nonce_ok = isset($_SESSION['g_oauth_nonce']) && hash_equals($_SESSION['g_oauth_nonce'], (string)($payload['nonce'] ?? ''));

unset($_SESSION['g_oauth_nonce'], $_SESSION['g_pkce_verifier']); // single-use

if (!$iss_ok || !$aud_ok || !$exp_ok || !$nonce_ok) {
  bad('Token validation failed');
}

/* ---- Enforce verified email & allowed domain ---- */
$email = strtolower(trim($payload['email'] ?? ''));
$email_verified = (bool)($payload['email_verified'] ?? false);

if (!$email || !$email_verified) bad('Email not verified with Google');

$allowed = (str_ends_with($email, '@workshop17.com') || str_ends_with($email, '@workshop17.mu'));
if (!$allowed) {
  bad('Only Workshop17 accounts are allowed.');
}

/* ---- Log in existing active user ---- */
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e AND active = 1 LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  // Optional: auto-provision here if you want. For now, block:
  bad('Your account is not enabled in this system. Please contact an administrator.');
}

session_regenerate_id(true);
$_SESSION['user'] = $user;

header('Location: ' . (BASE_URL ?: '') . '/dashboard.php');
exit;

/* Utility for PHP <8.0 */
function str_ends_with($haystack, $needle) {
  $len = strlen($needle);
  if ($len === 0) { return true; }
  return (substr($haystack, -$len) === $needle);
}
