<?php
/**
 * Semantic Primary Navigation Bar (includes/nav.php)
 * Detects the active script via $_SERVER['PHP_SELF'] to highlight the current page
 * and renders role-appropriate links based on session login status.
 */

// Extract current script filename (e.g., 'dashboard.php')
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="site-nav" aria-label="Primary Navigation">
    <div class="container nav-inner">
        <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Links visible only to authenticated users -->
            <a href="<?= BASE_URL ?>/pages/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="<?= BASE_URL ?>/pages/profile.php" class="<?= $currentPage === 'profile.php' ? 'active' : '' ?>">My Profile</a>
            <a href="<?= BASE_URL ?>/pages/contact.php" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Contact Us</a>
            <a href="<?= BASE_URL ?>/pages/submissions.php" class="<?= $currentPage === 'submissions.php' ? 'active' : '' ?>">Submissions Directory</a>
            <a href="<?= BASE_URL ?>/pages/info_hub.php" class="<?= $currentPage === 'info_hub.php' ? 'active' : '' ?>">Information Hub</a>
            <a href="<?= BASE_URL ?>/logout.php" class="nav-logout">Logout</a>
        <?php else: ?>
            <!-- Links visible to unauthenticated visitors -->
            <a href="<?= BASE_URL ?>/login.php" class="<?= $currentPage === 'login.php' ? 'active' : '' ?>">Login</a>
            <a href="<?= BASE_URL ?>/register.php" class="<?= $currentPage === 'register.php' ? 'active' : '' ?>">Create Account</a>
        <?php endif; ?>
    </div>
</nav>