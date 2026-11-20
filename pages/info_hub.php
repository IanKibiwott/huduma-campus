<?php
/**
 * Campus Information Hub, System Diagnostics & Audit Logs (pages/info_hub.php)
 * Demonstrates:
 *  1. Non-sensitive $_COOKIE preference management (Light / Dark theme toggle)
 *  2. Aggregate SQL reporting (LEFT JOIN + GROUP BY + COUNT) rendered via generateTable()
 *  3. Flat-file log reading from logs/activity.log via readActivityLog() (fopen/fgets)
 *  4. Relational audit log querying from MySQL activity_logs table
 *  5. Runtime environment inspection using PHP superglobals ($_SERVER)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_guard.php'; // Enforce active login session

$errors  = [];
$success = '';

// ============================================================================
// 1. HANDLE THEME PREFERENCE COOKIE TOGGLE ($_COOKIE)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_theme') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid or expired. Please refresh and try again.";
    } else {
        $chosenTheme = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';

        // Store non-sensitive UI theme preference in a 30-day browser cookie
        setcookie('hc_theme', $chosenTheme, time() + (86400 * 30), '/');

        logActivity(
            $_SESSION['username'],
            'THEME_CHANGE',
            "Switched portal theme preference to '$chosenTheme' mode"
        );

        // Redirect so header.php reads the newly set $_COOKIE['hc_theme'] immediately
        redirect(BASE_URL . '/pages/info_hub.php?theme_saved=1');
    }
}

if (isset($_GET['theme_saved'])) {
    $success = "Display theme preference saved in browser cookie (hc_theme).";
}

// Determine active theme from $_COOKIE for UI display
$activeTheme = (isset($_COOKIE['hc_theme']) && $_COOKIE['hc_theme'] === 'dark') ? 'dark' : 'light';

// ============================================================================
// 2. DEPARTMENTAL SLA & TICKET SUMMARY (LEFT JOIN + GROUP BY + generateTable)
// ============================================================================
$deptStmt = $pdo->query(
    "SELECT c.name AS department_name,
            c.office_in_charge,
            c.sla_hours,
            COUNT(s.id) AS total_tickets,
            SUM(CASE WHEN s.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_tickets
     FROM categories c
     LEFT JOIN submissions s ON c.id = s.category_id
     GROUP BY c.id, c.name, c.office_in_charge, c.sla_hours
     ORDER BY c.id ASC"
);
$deptStatsRaw = $deptStmt->fetchAll();

// Format rows into a clean 2D array for our reusable generateTable() helper
$deptTableRows = [];
foreach ($deptStatsRaw as $dept) {
    $deptTableRows[] = [
        $dept['department_name'],
        $dept['office_in_charge'],
        $dept['sla_hours'] . ' Hours',
        (int)$dept['total_tickets'],
        (int)$dept['resolved_tickets'],
    ];
}

// ============================================================================
// 3. READ FLAT-FILE & RELATIONAL AUDIT LOGS
// ============================================================================
// Read newest 8 lines from logs/activity.log using fopen() & fgets()
$fileLogEntries = readActivityLog(8);

// Query newest 6 audit entries from MySQL activity_logs table via LEFT JOIN
$dbLogStmt = $pdo->query(
    "SELECT a.action_type, a.details, a.ip_address, a.created_at, u.username
     FROM activity_logs a
     LEFT JOIN users u ON a.user_id = u.id
     ORDER BY a.created_at DESC
     LIMIT 6"
);
$dbLogEntries = $dbLogStmt->fetchAll();

// SEO metadata passed into shared header.php
$pageTitle = 'Information Hub';
$pageDescription = 'Explore campus support SLA metrics, customize display theme cookies, and inspect system audit logs.';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <h1>Campus Information Hub &amp; System Logs</h1>
    <p class="muted">Departmental service metrics, cookie-based theme settings, and dual-layer audit trails.</p>
</section>

<!-- Feedback Alerts -->
<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Top Grid: Cookie Theme Switcher & Server Environment Info -->
<section class="grid-2">
    <!-- Cookie Theme Switcher Card -->
    <div class="card">
        <h2>Portal Display Preference (Cookie Demo)</h2>
        <p class="muted" style="margin-bottom:1rem;">
            Current active theme stored in <code>$_COOKIE['hc_theme']</code>:
            <strong><?= strtoupper($activeTheme) ?> MODE</strong>
        </p>

        <form method="POST" action="info_hub.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_theme">

            <button type="submit" name="theme" value="light"
                    class="btn <?= $activeTheme === 'light' ? '' : 'btn-secondary' ?>">
                Light Theme
            </button>
            <button type="submit" name="theme" value="dark"
                    class="btn <?= $activeTheme === 'dark' ? '' : 'btn-secondary' ?>">
                Dark Theme
            </button>
        </form>
    </div>

    <!-- Server & Session Runtime Diagnostics -->
    <div class="card">
        <h2>Runtime Environment &amp; Session Info</h2>
        <table class="data-table">
            <tbody>
                <tr>
                    <th>PHP Version</th>
                    <td><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Server Software</th>
                    <td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Apache / XAMPP', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Active Session ID</th>
                    <td><code><?= htmlspecialchars(session_id(), ENT_QUOTES, 'UTF-8') ?></code></td>
                </tr>
                <tr>
                    <th>Server Timestamp</th>
                    <td><?= date('Y-m-d H:i:s T') ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- Departmental Performance Report (Rendered using reusable generateTable() helper) -->
<section class="card">
    <h2>Departmental SLA &amp; Resolution Summary</h2>
    <p class="muted" style="margin-bottom:1rem;">
        Aggregated dynamically via SQL <code>LEFT JOIN</code> and <code>GROUP BY</code> across normalized tables:
    </p>

    <?= generateTable(
        ['Department', 'Office In Charge', 'Target SLA', 'Total Inquiries', 'Resolved'],
        $deptTableRows
    ) ?>
</section>

<!-- Bottom Grid: Flat-File Log (activity.log) vs MySQL Audit Table (activity_logs) -->
<section class="grid-2">
    <!-- Flat-File Audit Log (fopen / fgets) -->
    <div class="card">
        <h2>Flat-File Activity Log (<code>logs/activity.log</code>)</h2>
        <p class="muted" style="margin-bottom:0.75rem;">
            Written with <code>fopen()</code> / <code>fwrite()</code> and read via <code>fgets()</code>:
        </p>

        <?php if (empty($fileLogEntries)): ?>
            <p class="muted">No flat-file log entries recorded yet.</p>
        <?php else: ?>
            <ul class="log-box">
                <?php foreach ($fileLogEntries as $line): ?>
                    <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Relational MySQL Audit Log -->
    <div class="card">
        <h2>Database Audit Trail (<code>activity_logs</code>)</h2>
        <p class="muted" style="margin-bottom:0.75rem;">
            Latest events stored in MySQL with foreign key reference to <code>users</code>:
        </p>

        <?php if (empty($dbLogEntries)): ?>
            <p class="muted">No database audit entries recorded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>User</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dbLogEntries as $entry): ?>
                            <tr>
                                <td><span class="badge badge-progress"><?= htmlspecialchars($entry['action_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($entry['username'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($entry['details'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M j, H:i', strtotime($entry['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>