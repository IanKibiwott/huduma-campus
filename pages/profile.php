<?php
/**
 * Student Profile Management Page (pages/profile.php)
 * Displays the authenticated user's account details pulled from MySQL and
 * provides a CSRF-protected, server-validated Update Profile form that executes
 * a parameterized SQL UPDATE statement.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_guard.php'; // Enforce active login session

$userId  = (int)$_SESSION['user_id'];
$errors  = [];
$success = '';

// 1. Handle Update Profile form submission via HTTP POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify session CSRF token before processing input
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid or expired. Please refresh and try again.";
    } else {
        // Define validation rules for the reusable form parser
        $rules = [
            'full_name'  => ['required' => true, 'min' => 3, 'max' => 100, 'label' => 'Full Name'],
            'email'      => ['required' => true, 'type' => 'email', 'max' => 120, 'label' => 'Email Address'],
            'reg_number' => ['required' => true, 'min' => 5, 'max' => 40,  'label' => 'Registration Number'],
            'department' => ['required' => true, 'min' => 2, 'max' => 80,  'label' => 'Department'],
        ];

        // Parse, validate, and sanitize submitted $_POST fields
        $parsed  = parseAndValidateForm($_POST, $rules);
        $form    = $parsed['data'];
        $errors  = array_values($parsed['errors']);

        // Ensure the updated email is not already owned by another account
        if (empty($errors)) {
            $emailCheck = $pdo->prepare(
                "SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1"
            );
            $emailCheck->execute([
                ':email' => $form['email'],
                ':id'    => $userId,
            ]);

            if ($emailCheck->fetch()) {
                $errors[] = "That email address is already linked to another student account.";
            }
        }

        // Perform conditional SQL UPDATE using a prepared statement
        if (empty($errors)) {
            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET full_name = :full_name,
                     email = :email,
                     reg_number = :reg_number,
                     department = :department
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':full_name'  => $form['full_name'],
                ':email'      => $form['email'],
                ':reg_number' => $form['reg_number'],
                ':department' => $form['department'],
                ':id'         => $userId,
            ]);

            // Sync updated full name inside active $_SESSION
            $_SESSION['full_name'] = $form['full_name'];

            // Record profile update in flat-file log and relational activity_logs table
            logActivity($_SESSION['username'], 'PROFILE_UPDATE', 'Updated personal account details.');
            $logStmt = $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                 VALUES (:user_id, 'PROFILE_UPDATE', :details, :ip)"
            );
            $logStmt->execute([
                ':user_id' => $userId,
                ':details' => 'Updated profile information',
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            $success = "Your profile details have been updated successfully.";
        }
    }
}

// 2. Fetch fresh user details from MySQL (avoiding SELECT *)
$userStmt = $pdo->prepare(
    "SELECT id, username, email, full_name, reg_number, department, created_at
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$userStmt->execute([':id' => $userId]);
$user = $userStmt->fetch();

// 3. Count total inquiries submitted by this specific user
$countStmt = $pdo->prepare("SELECT COUNT(id) FROM submissions WHERE user_id = :user_id");
$countStmt->execute([':user_id' => $userId]);
$myInquiryCount = (int)$countStmt->fetchColumn();

// SEO metadata passed to shared header.php
$pageTitle = 'My Profile';
$pageDescription = 'View and update your Huduma Campus student account details, department, and registration information.';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <h1>Student Profile &amp; Account Settings</h1>
    <p class="muted">Review your registered campus record and keep your contact information up to date.</p>
</section>

<!-- Feedback Alerts -->
<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
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
    <!-- Left Column: Read-Only Database Account Summary -->
    <div class="card">
        <h2>Account Summary</h2>
        <table class="data-table">
            <tbody>
                <tr>
                    <th>Account ID</th>
                    <td>#<?= (int)$user['id'] ?></td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td><strong><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                </tr>
                <tr>
                    <th>Full Name</th>
                    <td><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Registration No.</th>
                    <td><?= htmlspecialchars($user['reg_number'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Department</th>
                    <td><?= htmlspecialchars($user['department'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Email Address</th>
                    <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Member Since</th>
                    <td><?= htmlspecialchars(date('F j, Y', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>My Inquiries</th>
                    <td><span class="badge badge-progress"><?= $myInquiryCount ?> submitted</span></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Right Column: Validated SQL UPDATE Form -->
    <div class="card">
        <h2>Update Profile Details</h2>
        <form method="POST" action="profile.php" novalidate>
            <!-- CSRF protection token -->
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Campus Email Address</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-group">
                <label for="reg_number">Registration Number</label>
                <input type="text" id="reg_number" name="reg_number"
                       value="<?= htmlspecialchars($user['reg_number'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-group">
                <label for="department">Department / Faculty</label>
                <input type="text" id="department" name="department"
                       value="<?= htmlspecialchars($user['department'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <button type="submit" class="btn">Save Profile Changes</button>
        </form>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>