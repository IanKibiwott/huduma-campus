<?php
/**
 * Student Support & Contact Us Page (pages/contact.php)
 * Allows authenticated users to submit campus support inquiries linked to a
 * service department (category). Validates inputs via parseAndValidateForm(),
 * enforces CSRF protection, inserts records via PDO prepared statements, and
 * records audit entries in both activity.log and the activity_logs MySQL table.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_guard.php'; // Restrict access to logged-in users

$userId  = (int)$_SESSION['user_id'];
$errors  = [];
$success = '';

// 1. Fetch active user's name and email to pre-fill the inquiry form
$userStmt = $pdo->prepare(
    "SELECT full_name, email FROM users WHERE id = :id LIMIT 1"
);
$userStmt->execute([':id' => $userId]);
$currentUser = $userStmt->fetch();

// 2. Fetch all campus support departments from the normalized categories table
$catStmt = $pdo->query(
    "SELECT id, name, office_in_charge, sla_hours, description
     FROM categories
     ORDER BY id ASC"
);
$categories = $catStmt->fetchAll();

// Build a whitelist of valid category IDs (as strings for strict in_array checking)
$validCategoryIds = array_map(fn($cat) => (string)$cat['id'], $categories);

// Load multidimensional array of common inquiry topics from includes/functions.php
$subjectPresets = getSubjectOptions();

// Initialize form state with sanitized user defaults
$old = [
    'sender_name'  => sanitizeInput($currentUser['full_name'] ?? ''),
    'sender_email' => sanitizeInput($currentUser['email'] ?? ''),
    'category_id'  => '',
    'subject'      => '',
    'message'      => '',
];

// 3. Process inquiry submission on HTTP POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify session CSRF token first
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid or expired. Please refresh the page and try again.";
    } else {
        // Define declarative validation rules for parseAndValidateForm()
        $rules = [
            'sender_name'  => ['required' => true, 'min' => 3, 'max' => 100, 'label' => 'Your Name'],
            'sender_email' => ['required' => true, 'type' => 'email', 'max' => 120, 'label' => 'Email Address'],
            'category_id'  => ['required' => true, 'allowed' => $validCategoryIds, 'label' => 'Support Department'],
            'subject'      => ['required' => true, 'min' => 5, 'max' => 150, 'label' => 'Inquiry Subject'],
            'message'      => ['required' => true, 'min' => 15, 'max' => 2000, 'label' => 'Detailed Message'],
        ];

        // Parse, validate, and sanitize submitted inputs
        $parsed = parseAndValidateForm($_POST, $rules);
        $old    = $parsed['data'];
        $errors = array_values($parsed['errors']);

        // Insert validated inquiry into MySQL using a prepared statement
        if (empty($errors)) {
            $insertStmt = $pdo->prepare(
                "INSERT INTO submissions
                    (user_id, category_id, sender_name, sender_email, subject, message, status)
                 VALUES
                    (:user_id, :category_id, :sender_name, :sender_email, :subject, :message, 'Open')"
            );
            $insertStmt->execute([
                ':user_id'      => $userId,
                ':category_id'  => (int)$old['category_id'],
                ':sender_name'  => $old['sender_name'],
                ':sender_email' => $old['sender_email'],
                ':subject'      => $old['subject'],
                ':message'      => $old['message'],
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            // Record creation event in both flat-file activity.log and MySQL activity_logs
            logActivity(
                $_SESSION['username'],
                'CREATE_INQUIRY',
                "Submitted Ticket #$ticketId: {$old['subject']}"
            );

            $logStmt = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                 VALUES (:user_id, 'CREATE_INQUIRY', :details, :ip)"
            );
            $logStmt->execute([
                ':user_id' => $userId,
                ':details' => "Created support ticket #$ticketId",
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            $success = "Inquiry submitted successfully! Your tracking reference is Ticket #$ticketId.";

            // Clear subject, category, and message inputs after successful submission
            $old['category_id'] = '';
            $old['subject']     = '';
            $old['message']     = '';
        }
    }
}

// SEO metadata passed into shared header.php
$pageTitle = 'Contact Us';
$pageDescription = 'Submit a campus support inquiry to ICT, Finance, Examinations, or Student Welfare on Huduma Campus.';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <h1>Submit a Campus Support Inquiry</h1>
    <p class="muted">Select the responsible campus office, describe your issue, and track resolution progress.</p>
</section>

<!-- Success & Error Feedback Alerts -->
<?php if ($success !== ''): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        <a href="<?= BASE_URL ?>/pages/submissions.php" style="margin-left:0.5rem; font-weight:600;">View in Directory &rarr;</a>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= $err ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="grid-2">
    <!-- Left Column: Validated Contact Us Submission Form -->
    <div class="card">
        <h2>New Support Ticket</h2>
        <form method="POST" action="contact.php" novalidate>
            <!-- Hidden CSRF protection token -->
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label for="sender_name">Full Name</label>
                <input type="text" id="sender_name" name="sender_name" value="<?= $old['sender_name'] ?>" required>
            </div>

            <div class="form-group">
                <label for="sender_email">Reply Email Address</label>
                <input type="email" id="sender_email" name="sender_email" value="<?= $old['sender_email'] ?>" required>
            </div>

            <div class="form-group">
                <label for="category_id">Target Department / Office</label>
                <select id="category_id" name="category_id" required>
                    <option value="">-- Select Support Department --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"
                            <?= $old['category_id'] === (string)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name'] . ' (' . $cat['office_in_charge'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="subject">Subject (pick a preset or type your own)</label>
                <input type="text" id="subject" name="subject" list="subject_presets"
                       placeholder="e.g. Exam Card Clearance Request" value="<?= $old['subject'] ?>" required>
                <!-- Datalist populated from multidimensional getSubjectOptions() array -->
                <datalist id="subject_presets">
                    <?php foreach ($subjectPresets as $preset): ?>
                        <option value="<?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?>">
                            [<?= htmlspecialchars($preset['code'], ENT_QUOTES, 'UTF-8') ?>]
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="form-group">
                <label for="message">Detailed Description</label>
                <textarea id="message" name="message" rows="5"
                          placeholder="Include relevant details such as unit codes, dates, or error messages..."
                          required><?= $old['message'] ?></textarea>
            </div>

            <button type="submit" class="btn">Submit Inquiry</button>
        </form>
    </div>

    <!-- Right Column: Department SLA Guide & Common Inquiry Codes -->
    <div class="card">
        <h2>Campus Service Desk Directory</h2>
        <p class="muted" style="margin-bottom:1rem;">
            Each department operates under a target Service Level Agreement (SLA) response window:
        </p>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Office In Charge</th>
                        <th>Target SLA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <span class="muted"><?= htmlspecialchars($cat['description'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= htmlspecialchars($cat['office_in_charge'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge badge-progress"><?= (int)$cat['sla_hours'] ?> hrs</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>