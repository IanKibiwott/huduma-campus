<?php
/**
 * Secure Session Logout Script (logout.php)
 * Logs the logout action, clears all $_SESSION variables, expires the PHP
 * session cookie in the browser, destroys the server session, and redirects
 * back to login.php.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// 1. Record logout event before destroying session identity
if (isset($_SESSION['username'])) {
    logActivity($_SESSION['username'], 'LOGOUT', 'User ended active session.');
}

// 2. Clear all variables stored in the $_SESSION superglobal
$_SESSION = [];

// 3. Delete the session cookie from the client's browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Destroy the session storage on the server and redirect to Login
session_destroy();
redirect(BASE_URL . '/login.php?logged_out=1');