<?php
/**
 * Shared HTML Document Head & Semantic Header (includes/header.php)
 * Renders dynamic SEO meta tags, Open Graph social sharing metadata,
 * browser tab favicon, theme cookie classes, and the top site branding header.
 */

// 1. Set page-specific SEO title and description with sensible fallbacks
$pageTitle = isset($pageTitle)
    ? $pageTitle . ' | ' . APP_NAME
    : APP_NAME . ' — ' . APP_SUBTITLE;

$pageDescription = $pageDescription
    ?? 'Huduma Campus is a centralized student support and inquiry management portal for ICT, finance, examinations, and welfare services.';

// 2. Read non-sensitive theme preference from $_COOKIE (defaults to light mode)
$currentTheme = (isset($_COOKIE['hc_theme']) && $_COOKIE['hc_theme'] === 'dark')
    ? 'theme-dark'
    : 'theme-light';

// 3. Build current canonical URL and logo URLs for Open Graph & favicon
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$ogUrl    = $protocol . $host . ($_SERVER['REQUEST_URI'] ?? BASE_URL);
$logoPath = BASE_URL . '/assets/logo.svg';
$ogImage  = $protocol . $host . $logoPath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- On-Page SEO Title & Meta Description -->
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Browser Tab Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Open Graph / Social Media Sharing Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= APP_NAME ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Main Responsive Stylesheet -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="<?= $currentTheme ?>">
    <!-- Semantic HTML5 Site Header -->
    <header class="site-header">
        <div class="container header-inner">
            <a href="<?= BASE_URL ?>/index.php" class="brand">
                <img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>"
                     alt="Huduma Campus Logo" width="42" height="42">
                <div>
                    <span class="brand-title"><?= APP_NAME ?></span>
                    <span class="brand-sub"><?= APP_SUBTITLE ?></span>
                </div>
            </a>

            <?php if (isset($_SESSION['username'])): ?>
                <div class="user-pill">
                    <span>Signed in as <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php
    // Include primary navigation bar directly below the site header
    include __DIR__ . '/nav.php';
    ?>

    <!-- Semantic HTML5 Main Content Container (closed in footer.php) -->
    <main class="container main-content">