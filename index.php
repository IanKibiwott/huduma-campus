<?php
/**
 * Public Application Entry Point (index.php)
 * Checks whether the visitor already holds an active $_SESSION['user_id']
 * and routes them to either the protected Dashboard or the Login page.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Route authenticated users to Dashboard; send unauthenticated visitors to Login
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . '/pages/dashboard.php');
} else {
    redirect(BASE_URL . '/login.php');
}