<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // ensure e() is available

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---- Zulip helper (needed here for pre-login admin notification) ---- */
function zulip_send_pm(string $toEmail, string $content): bool {
  // Uses constants from config.php
  if (!defined('ZULIP_SITE') || !defined('ZULIP_BOT_EMAIL') || !defined('ZULIP_BOT_APIKEY') || !ZULIP_SITE || !ZULIP_BOT_EMAIL || !ZULIP_BOT_APIKEY) return false;
  $payload = [
    'type'    => 'private',
    'to'      => json_encode([$toEmail]),
    'content' => $content,
  ];
  $ch = curl_init(rtrim(ZULIP_SITE,'/').'/api/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => ZULIP_BOT_EMAIL.':'.ZULIP_BOT_APIKEY,
    CURLOPT_TIMEOUT        => 7,
  ]);
  curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($http >= 200 && $http < 300);
}


/* ---- Quick helpers ---- */
function bad($msg='Bad request'){ http_response_code(400); echo htmlspecialchars($msg); exit; }
function log_err($x){ try { @file_put_contents('/tmp/ticketing_error.log', "[".date('c')."] ".$x."\n", FILE_APPEND); } catch(Throwable $t){} }


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

/* ---- Validate id_token (compact JWT) ---- */
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

/* ---- Enforce verified email & allowed domain (workshop17.com / workshop17.mu) ---- */
$email = strtolower(trim($payload['email'] ?? ''));
$email_verified = (bool)($payload['email_verified'] ?? false);
$name = $payload['name'] ?? null;
$first_name = $payload['given_name'] ?? ($name ? explode(' ', $name)[0] : null);
$last_name = $payload['family_name'] ?? ($name ? end(explode(' ', $name)) : null);
$username = $payload['email'] ?? null;
$avatar = $payload['picture'] ?? null;

if (!$email || !$email_verified) bad('Email not verified with Google');

$allowed = (str_ends_with($email, '@workshop17.com') || str_ends_with($email, '@workshop17.mu'));
if (!$allowed) {
  bad('Only Workshop17 accounts are allowed.');
}

/* ---- Log in / Provision User ---- */
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // 1. Auto-provision new user (INACTIVE by default)
    $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, username, role, active, avatar)
        VALUES (?, ?, ?, ?, 'user', 0, ?)
    ")->execute([$first_name, $last_name, $email, $username, $avatar]);
    
    // 2. Notify Admins via Zulip
    $adminEmails = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($adminEmails)) {
        $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/users.php';
        $msg = "🔔 New User Signup (INACTIVE):\n".
               "Email: **{$email}**\n".
               "Name: {$first_name} {$last_name}\n".
               "An Admin must activate this user to grant access.\n".
               ($link ? "[View Users]($link)" : "");
        foreach ($adminEmails as $adminEmail) {
            @zulip_send_pm($adminEmail, $msg);
        }
    }
    log_err("New user created (INACTIVE): ".$email);
    bad('Your account has been created but is currently **inactive**. Please contact an administrator to gain access to Dashboard.');
}

// 3. Block inactive users
if ((int)($user['active'] ?? 0) !== 1) {
    bad('Your account is not currently active. Please contact an administrator.');
}

// 4. Log in existing active user
session_regenerate_id(true);
$_SESSION['user'] = $user;

header('Location: ' . (BASE_URL ?: '') . '/dashboard.php');
exit;

/* Utility for PHP <8.0 */
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
      $len = strlen($needle);
      if ($len === 0) { return true; }
      return (substr($haystack, -$len) === $needle);
    }
}
