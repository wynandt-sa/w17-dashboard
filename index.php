<?php
require_once __DIR__ . '/auth.php';
header('Location: ' . (BASE_URL ?: '') . (is_logged_in()?'/dashboard.php':'/login.php'));
