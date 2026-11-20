<?php
/**
 * User Registration Page (register.php)
 * Validates student account details via the reusable parseAndValidateForm() parser,
 * verifies unique username/email constraints, hashes passwords with password_hash(),
 * and inserts new records using a PDO prepared statement.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect already logged-in users directly to the Dashboard
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$errors = [];
// Preserve safe user inputs on validation failure so the user does not retype everything
$old = [
    'username'   => '',
    'email'      => '',
    'full_name'  => '',
    'reg_number' => '',
    'department' => 'Computer Science',
];

// Process form submission only on HTTP POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate session-based CSRF token before touching form data
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid security token. Please refresh the page and try again.";
    } else {
        // 2. Define declarative validation rules for the reusable form parser
        $rules = [
            'full_name'  => ['required' => true, 'min' => 3, 'max' => 100, 'label' => 'Full Name'],
            'username'   => ['required' => true, 'min' => 4, 'max' => 50,  'label' => 'Username'],
            'email'      => ['required' => true, 'type' => 'email', 'max' => 120, 'label' => 'Email Address'],
            'reg_number' => ['required' => true, 'min' => 5, 'max' => 40,  'label' => 'Registration Number'],
            'department' => ['required' => true, 'min' => 2, 'max' => 80,  'label' => 'Department'],
            'password'   => ['required' => true, 'min' => 6, 'max' => 100, 'label' => 'Password'],
        ];

        // 3. Parse, validate, and sanitize $_POST inputs in a single call
        $parsed = parseAndValidateForm($_POST, $rules);
        $old    = $parsed['data'];
        $errors = array_values($parsed['errors']);

        // 4. Confirm password fields match
        if (($_POST['password'] ?? '') !== ($_POST['confirm_password'] ?? '')) {
            $errors[] = "Password and Confirm Password do not match.";
        }

        // 5. Check database uniqueness and insert new user record if validation passed
        if (empty($errors)) {
            $checkStmt = $pdo->prepare(
                "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1"
            );
            $checkStmt->execute([
                ':username' => $old['username'],
                ':email'    => $old['email'],
            ]);

            if ($checkStmt->fetch()) {
                $errors[] = "That username or email address is already registered.";
            } else {
                // Hash password securely using PHP's default bcrypt/argon2 algorithm
                $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);

                // Insert user record using a parameterized prepared statement
                $insertStmt = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, reg_number, department)
                     VALUES (:username, :email, :password_hash, :full_name, :reg_number, :department)"
                );
                $insertStmt->execute([
                    ':username'      => $old['username'],
                    ':email'         => $old['email'],
                    ':password_hash' => $hashedPassword,
                    ':full_name'     => $old['full_name'],
                    ':reg_number'    => $old['reg_number'],
                    ':department'    => $old['department'],
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                // Record registration event in both the flat file log and MySQL audit table
                logActivity($old['username'], 'REGISTER', 'New student account created.');
                $logStmt = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                     VALUES (:user_id, 'REGISTER', :details, :ip)"
                );
                $logStmt->execute([
                    ':user_id' => $newUserId,
                    ':details' => 'Account registered via web portal',
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                // Redirect to login page with confirmation flag
                redirect(BASE_URL . '/login.php?registered=1');
            }
        }
    }
}

// SEO metadata passed into shared header.php
$pageTitle = 'Create Account';
$pageDescription = 'Register a new Huduma Campus student account to submit and track campus support inquiries.';
include __DIR__ . '/includes/header.php';
?>

<section class="card auth-card">
    <div class="page-header">
        <h1>Create Student Account</h1>
        <p class="muted">Register to submit inquiries and track office responses.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= $err ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php" novalidate>
        <!-- Hidden CSRF token field -->
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

        <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= $old['full_name'] ?>" required>
        </div>

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= $old['username'] ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Campus Email Address</label>
            <input type="email" id="email" name="email" value="<?= $old['email'] ?>" required>
        </div>

        <div class="form-group">
            <label for="reg_number">Registration Number</label>
            <input type="text" id="reg_number" name="reg_number" placeholder="e.g. BCS-03-0111/2025" value="<?= $old['reg_number'] ?>" required>
        </div>

        <div class="form-group">
            <label for="department">Department / School</label>
            <input type="text" id="department" name="department" value="<?= $old['department'] ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password (minimum 6 characters)</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>

        <button type="submit" class="btn" style="width:100%;">Register Account</button>
    </form>

    <p class="muted" style="margin-top:1rem; text-align:center;">
        Already registered? <a href="login.php">Sign in here</a>.
    </p>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>