<?php
/**
 * Session Authentication Guard (includes/auth_guard.php)
 * Included at the top of every protected internal page to ensure only
 * authenticated users with an active $_SESSION['user_id'] can view the content.
 */

// Resume existing session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect unauthenticated visitors back to the login page
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php?auth=required");
    exit();
}