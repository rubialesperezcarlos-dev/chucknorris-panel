<?php
/**
 * Chuck Norris AI - Web dashboard session auth
 * Include at top of protected pages; redirects to login if not authenticated.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['chuck_norris_ai_user_id'])) {
    $login = 'login.php';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $login .= '?redirect=' . urlencode($_SERVER['REQUEST_URI']);
    }
    header('Location: ' . $login);
    exit;
}
