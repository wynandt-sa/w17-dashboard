<?php
require_once __DIR__ . '/config.php';

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . (BASE_URL ?: '') . '/login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo '<div style="padding:2rem;font-family:system-ui">Access denied.</div>';
        exit;
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
