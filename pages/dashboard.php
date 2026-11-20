<?php
/**
 * Portal Dashboard Page (pages/dashboard.php)
 * Protected home screen for authenticated users. Displays personalized session
 * greeting, current server date via date(), last login cookie info, aggregate
 * MySQL statistics (COUNT, MAX), and the 5 most recent inquiries via INNER JOIN.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_guard.php'; // Blocks unauthenticated access

// 1. Run aggregate MySQL queries for dashboard summary cards (avoiding SELECT *)
$totalSubmissions = (int)$pdo->query("SELECT COUNT(id) FROM submissions")->fetchColumn();
$pendingCount     = (int)$pdo->query("SELECT COUNT(id) FROM submissions WHERE status != 'Resolved'")->fetchColumn();
$totalUsers       = (int)$pdo->query("SELECT COUNT(id) FROM users")->fetchColumn();
$latestDateRaw    = $pdo->query("SELECT MAX(created_at) FROM submissions")->fetchColumn();

// Format latest submission timestamp into a readable date string
$latestSubmissionDate = $latestDateRaw
    ? date('M j, Y g:i A', strtotime($latestDateRaw))
    : 'No submissions recorded';

// 2. Retrieve the 5 most recent submissions using INNER JOIN, ORDER BY, and LIMIT
$recentStmt = $pdo->prepare(
    "SELECT s.id, s.subject, s.status, s.created_at, c.name AS category_name, u.username
     FROM submissions s
     INNER JOIN categories c ON s.category_id = c.id
     INNER JOIN users u ON s.user_id = u.id
     ORDER BY s.created_at DESC
     LIMIT 5"
);
$recentStmt->execute();
$recentSubmissions = $recentStmt->fetchAll();

// 3. Read non-sensitive 'hc_last_login' cookie set during login
$lastLoginDisplay = isset($_COOKIE['hc_last_login'])
    ? htmlspecialchars($_COOKIE['hc_last_login'], ENT_QUOTES, 'UTF-8')
    : 'Current active session';

// SEO metadata passed to shared header.php
$pageTitle = 'Dashboard';
$pageDescription = 'Monitor campus support statistics, recent inquiry tickets, and service updates on Huduma Campus.';
include __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Banner & PHP date() Output -->
<section class="page-header">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted">
        Logged in as: <strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong> |
        Date: <strong><?= date('l, F j, Y') ?></strong> |
        Last Login (Cookie): <strong><?= $lastLoginDisplay ?></strong>
    </p>
</section>

<!-- Aggregate Statistics Cards -->
<section class="grid-4" aria-label="System Summary Statistics">
    <div class="stat-card">
        <span class="muted">Total Inquiries</span>
        <div class="stat-value"><?= $totalSubmissions ?></div>
        <span class="muted">All submitted tickets</span>
    </div>

    <div class="stat-card">
        <span class="muted">Active / Pending</span>
        <div class="stat-value"><?= $pendingCount ?></div>
        <span class="muted">Open or In Progress</span>
    </div>

    <div class="stat-card">
        <span class="muted">Registered Users</span>
        <div class="stat-value"><?= $totalUsers ?></div>
        <span class="muted">Active student accounts</span>
    </div>

    <div class="stat-card">
        <span class="muted">Latest Submission</span>
        <div class="stat-value" style="font-size:1.1rem; margin-top:0.55rem;">
            <?= htmlspecialchars($latestSubmissionDate, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <span class="muted">Most recent activity</span>
    </div>
</section>

<!-- Recent Submissions Overview Table -->
<section class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
        <h2 style="margin:0;">Latest Campus Inquiries</h2>
        <div>
            <a href="<?= BASE_URL ?>/pages/contact.php" class="btn btn-sm">+ Submit New Inquiry</a>
            <a href="<?= BASE_URL ?>/pages/submissions.php" class="btn btn-secondary btn-sm">Open Directory</a>
        </div>
    </div>

    <?php if (empty($recentSubmissions)): ?>
        <p class="muted">No inquiries found in the database.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Subject</th>
                        <th>Department / Category</th>
                        <th>Submitted By</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSubmissions as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($row['status']) ?>">
                                    <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>