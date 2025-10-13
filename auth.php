<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php'; // Need db() for role calculation

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function user(): ?array {
    $u = $_SESSION['user'] ?? null;
    if ($u) {
        // Dynamically determine 'manager' role: if they manage any active users.
        try {
            $pdo = db();
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE manager_id = ? AND active = 1");
            $st->execute([$u['id']]);
            $is_manager_of_subs = (int)$st->fetchColumn() > 0;
            
            $db_role = $_SESSION['user']['role'] ?? 'user';
            
            // Admins always remain admins.
            if ($db_role === 'admin') {
                $u['role'] = 'admin';
            } 
            // If the user manages subordinates, they are a 'manager' for this session.
            elseif ($is_manager_of_subs) {
                $u['role'] = 'manager';
            } 
            // Otherwise, they are a plain 'user'/'agent'.
            else {
                $u['role'] = 'user';
            }

            // Overwrite the session role for the current request cycle.
            $_SESSION['user'] = $u;

        } catch (Throwable $e) {
            // Handle DB error gracefully by falling back to session role
        }
    }
    return $u;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . (BASE_URL ?: '') . '/login.php');
        exit;
    }
}

function is_manager(): bool {
    $u = user();
    return ($u['role'] ?? 'user') === 'manager' || ($u['role'] ?? 'user') === 'admin';
}

function is_admin(): bool {
    $u = user();
    return ($u['role'] ?? 'user') === 'admin';
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        echo '<div style="padding:2rem;font-family:system-ui">Access denied.</div>';
        exit;
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// DEFINITIVE FIX: Define h() as a global alias for e()
function h(string $s): string {
    return e($s);
}
