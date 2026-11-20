<?php
/**
 * Campus Submissions Directory & Management Page (pages/submissions.php)
 * Implements full relational READ, UPDATE, and DELETE operations over an
 * INNER JOIN query, paired with parameterized search/filtering, whitelisted
 * column sorting, LIMIT/OFFSET pagination, and CSV data export.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_guard.php'; // Enforce active login session

$userId  = (int)$_SESSION['user_id'];
$errors  = [];
$success = '';

// Allowed ticket statuses for strict whitelist validation
$allowedStatuses = ['Open', 'In Progress', 'Resolved'];

// ============================================================================
// 1. HANDLE POST ACTIONS (UPDATE STATUS & DELETE RECORD)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token before modifying database records
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid or expired security token. Please refresh and try again.";
    } else {
        $action       = $_POST['action'] ?? '';
        $submissionId = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;

        if ($submissionId <= 0) {
            $errors[] = "Invalid inquiry reference ID provided.";
        } elseif ($action === 'update_status') {
            $newStatus = $_POST['new_status'] ?? '';

            // Validate status against whitelist using in_array()
            if (!in_array($newStatus, $allowedStatuses, true)) {
                $errors[] = "Invalid status selection.";
            } else {
                // Execute parameterized SQL UPDATE
                $updateStmt = $pdo->prepare(
                    "UPDATE submissions SET status = :status WHERE id = :id"
                );
                $updateStmt->execute([
                    ':status' => $newStatus,
                    ':id'     => $submissionId,
                ]);

                // Record update action in both flat-file log and MySQL audit table
                logActivity(
                    $_SESSION['username'],
                    'UPDATE_STATUS',
                    "Updated Ticket #$submissionId status to '$newStatus'"
                );
                $logStmt = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                     VALUES (:user_id, 'UPDATE_STATUS', :details, :ip)"
                );
                $logStmt->execute([
                    ':user_id' => $userId,
                    ':details' => "Changed Ticket #$submissionId to $newStatus",
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                $success = "Ticket #$submissionId status updated to \"$newStatus\".";
            }
        } elseif ($action === 'delete') {
            // Execute parameterized SQL DELETE
            $deleteStmt = $pdo->prepare("DELETE FROM submissions WHERE id = :id");
            $deleteStmt->execute([':id' => $submissionId]);

            // Record deletion event in both flat-file log and MySQL audit table
            logActivity(
                $_SESSION['username'],
                'DELETE_INQUIRY',
                "Deleted Ticket #$submissionId from submissions table"
            );
            $logStmt = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                 VALUES (:user_id, 'DELETE_INQUIRY', :details, :ip)"
            );
            $logStmt->execute([
                ':user_id' => $userId,
                ':details' => "Deleted support ticket #$submissionId",
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            $success = "Ticket #$submissionId has been permanently deleted.";
        }
    }
}

// ============================================================================
// 2. PARSE SEARCH, FILTER, AND WHITELISTED SORTING PARAMETERS ($_GET)
// ============================================================================
$search         = trim($_GET['q'] ?? '');
$categoryFilter = (isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id']))
    ? (int)$_GET['category_id']
    : 0;
$statusFilter   = (isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses, true))
    ? $_GET['status']
    : '';

// Strict column whitelist prevents SQL injection inside ORDER BY clauses
$allowedSortColumns = [
    'id'         => 's.id',
    'subject'    => 's.subject',
    'category'   => 'c.name',
    'status'     => 's.status',
    'created_at' => 's.created_at',
];

$sortKey = (isset($_GET['sort']) && array_key_exists($_GET['sort'], $allowedSortColumns))
    ? $_GET['sort']
    : 'created_at';
$sortCol = $allowedSortColumns[$sortKey];
$sortDir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';

// Build dynamic WHERE clauses with unique named placeholders
$whereClauses = [];
$boundParams  = [];

if ($search !== '') {
    $whereClauses[] = "(s.subject LIKE :search_subj OR s.sender_name LIKE :search_name OR s.message LIKE :search_msg)";
    $wildcard = '%' . $search . '%';
    $boundParams[':search_subj'] = [$wildcard, PDO::PARAM_STR];
    $boundParams[':search_name'] = [$wildcard, PDO::PARAM_STR];
    $boundParams[':search_msg']  = [$wildcard, PDO::PARAM_STR];
}

if ($categoryFilter > 0) {
    $whereClauses[] = "s.category_id = :category_id";
    $boundParams[':category_id'] = [$categoryFilter, PDO::PARAM_INT];
}

if ($statusFilter !== '') {
    $whereClauses[] = "s.status = :status";
    $boundParams[':status'] = [$statusFilter, PDO::PARAM_STR];
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// ============================================================================
// 3. HANDLE CSV EXPORT REQUEST (?export=csv)
// ============================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = "SELECT s.id, s.subject, c.name AS category_name, s.sender_name,
                         s.sender_email, s.status, s.created_at
                  FROM submissions s
                  INNER JOIN categories c ON s.category_id = c.id
                  {$whereSql}
                  ORDER BY {$sortCol} {$sortDir}";

    $exportStmt = $pdo->prepare($exportSql);
    foreach ($boundParams as $param => [$val, $type]) {
        $exportStmt->bindValue($param, $val, $type);
    }
    $exportStmt->execute();
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_NUM);

    logActivity($_SESSION['username'], 'EXPORT_CSV', 'Exported filtered submissions to CSV.');
    exportCSV(
        'huduma_campus_submissions_' . date('Ymd_His') . '.csv',
        ['Ticket ID', 'Subject', 'Department', 'Sender Name', 'Sender Email', 'Status', 'Created At'],
        $exportRows
    );
}

// ============================================================================
// 4. CALCULATE PAGINATION & FETCH FILTERED RECORDS VIA INNER JOIN
// ============================================================================
$countSql = "SELECT COUNT(s.id)
             FROM submissions s
             INNER JOIN categories c ON s.category_id = c.id
             {$whereSql}";

$countStmt = $pdo->prepare($countSql);
foreach ($boundParams as $param => [$val, $type]) {
    $countStmt->bindValue($param, $val, $type);
}
$countStmt->execute();

$totalRecords = (int)$countStmt->fetchColumn();
$limit        = RECORDS_PER_PAGE;
$totalPages   = max(1, (int)ceil($totalRecords / $limit));
$currentPage  = (isset($_GET['page']) && ctype_digit((string)$_GET['page']))
    ? max(1, min($totalPages, (int)$_GET['page']))
    : 1;
$offset       = ($currentPage - 1) * $limit;

// Fetch paginated records joining submissions, categories, and users
$dataSql = "SELECT s.id, s.sender_name, s.sender_email, s.subject, s.message,
                   s.status, s.created_at, c.name AS category_name,
                   c.office_in_charge, u.username
            FROM submissions s
            INNER JOIN categories c ON s.category_id = c.id
            INNER JOIN users u ON s.user_id = u.id
            {$whereSql}
            ORDER BY {$sortCol} {$sortDir}
            LIMIT :limit OFFSET :offset";

$dataStmt = $pdo->prepare($dataSql);
foreach ($boundParams as $param => [$val, $type]) {
    $dataStmt->bindValue($param, $val, $type);
}
$dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$submissions = $dataStmt->fetchAll();

// Fetch categories list to populate the filter dropdown
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY id ASC")->fetchAll();

/**
 * Helper closure to build query strings while preserving active filters and sort state.
 */
$buildQueryUrl = function (array $overrides = []) use ($search, $categoryFilter, $statusFilter, $sortKey, $sortDir, $currentPage): string {
    $query = array_merge([
        'q'           => $search,
        'category_id' => $categoryFilter > 0 ? $categoryFilter : '',
        'status'      => $statusFilter,
        'sort'        => $sortKey,
        'dir'         => $sortDir,
        'page'        => $currentPage,
    ], $overrides);

    // Strip empty values so URLs stay clean
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return 'submissions.php?' . http_build_query($query);
};

// SEO metadata passed into shared header.php
$pageTitle = 'Submissions Directory';
$pageDescription = 'Search, filter, update status, and export student support inquiries in the Huduma Campus directory.';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <h1>Campus Submissions Directory</h1>
    <p class="muted">
        Showing <strong><?= count($submissions) ?></strong> of <strong><?= $totalRecords ?></strong> total inquiry records
        (Page <?= $currentPage ?> of <?= $totalPages ?>).
    </p>
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

<!-- Search, Filter & CSV Export Toolbar -->
<section class="card">
    <form method="GET" action="submissions.php" class="inline-form">
        <div style="flex:2; min-width:200px;">
            <label for="q" class="muted">Keyword Search</label>
            <input type="text" id="q" name="q" placeholder="Search subject, sender, or details..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div style="flex:1; min-width:170px;">
            <label for="category_id" class="muted">Department</label>
            <select id="category_id" name="category_id">
                <option value="">All Departments</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1; min-width:140px;">
            <label for="status" class="muted">Ticket Status</label>
            <select id="status" name="status">
                <option value="">All Statuses</option>
                <?php foreach ($allowedStatuses as $st): ?>
                    <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                        <?= $st ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:0.45rem; align-self:flex-end; flex-wrap:wrap;">
            <button type="submit" class="btn">Filter</button>
            <a href="submissions.php" class="btn btn-secondary">Reset</a>
            <a href="<?= htmlspecialchars($buildQueryUrl(['export' => 'csv', 'page' => null]), ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-secondary">Export CSV</a>
        </div>
    </form>
</section>

<!-- Main Relational Records Table -->
<section class="card">
    <?php if (empty($submissions)): ?>
        <p class="muted">No inquiries matched your current search or filter criteria.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php
                        $sortableHeaders = [
                            'id'         => 'ID',
                            'subject'    => 'Subject & Message',
                            'category'   => 'Department',
                            'status'     => 'Status',
                            'created_at' => 'Submitted',
                        ];
                        foreach ($sortableHeaders as $colKey => $colLabel):
                            $nextDir = 'ASC';
                            $arrow   = '';
                            if ($sortKey === $colKey) {
                                if ($sortDir === 'ASC') {
                                    $nextDir = 'DESC';
                                    $arrow   = ' &#9650;';
                                } else {
                                    $nextDir = 'ASC';
                                    $arrow   = ' &#9660;';
                                }
                            }
                        ?>
                            <th>
                                <a href="<?= htmlspecialchars($buildQueryUrl(['sort' => $colKey, 'dir' => $nextDir, 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                                   style="color:inherit; text-decoration:none;">
                                    <?= htmlspecialchars($colLabel, ENT_QUOTES, 'UTF-8') . $arrow ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                        <th>Actions (Update / Delete)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <span class="muted"><?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') ?></span><br>
                                <small class="muted">
                                    By: <?= htmlspecialchars($row['sender_name'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= htmlspecialchars($row['sender_email'], ENT_QUOTES, 'UTF-8') ?>)
                                </small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small class="muted"><?= htmlspecialchars($row['office_in_charge'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($row['status']) ?>">
                                    <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <!-- Inline Status Update Form (POST + CSRF) -->
                                <form method="POST" action="<?= htmlspecialchars($buildQueryUrl(), ENT_QUOTES, 'UTF-8') ?>"
                                      class="inline-form" style="margin-bottom:0.4rem;">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="submission_id" value="<?= (int)$row['id'] ?>">

                                    <select name="new_status" aria-label="Change status for ticket #<?= (int)$row['id'] ?>"
                                            style="width:auto; padding:0.28rem 0.5rem; font-size:0.82rem;">
                                        <?php foreach ($allowedStatuses as $st): ?>
                                            <option value="<?= $st ?>" <?= $row['status'] === $st ? 'selected' : '' ?>>
                                                <?= $st ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm">Update</button>
                                </form>

                                <!-- Inline Delete Form (POST + CSRF + JS Confirmation) -->
                                <form method="POST" action="<?= htmlspecialchars($buildQueryUrl(), ENT_QUOTES, 'UTF-8') ?>"
                                      onsubmit="return confirm('Permanently delete Ticket #<?= (int)$row['id'] ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="submission_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Submissions Pagination">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">&laquo; Prev</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"
                       class="<?= $p === $currentPage ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= htmlspecialchars($buildQueryUrl(['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Next &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
