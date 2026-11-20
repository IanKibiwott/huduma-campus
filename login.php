<?php
/**
 * User Authentication Page (login.php)
 * Authenticates credentials against MySQL using password_verify(),
 * initializes $_SESSION state on success, and manages non-sensitive
 * $_COOKIE entries for "Remember Me" and "Last Login".
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect users who are already signed in directly to the Dashboard
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$errors = [];

// Pre-fill username input if the non-sensitive 'hc_remember_user' cookie exists
$rememberedUser = isset($_COOKIE['hc_remember_user'])
    ? sanitizeInput($_COOKIE['hc_remember_user'])
    : '';

// Handle login form submission via HTTP POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify session-based CSRF token
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token expired or invalid. Please try again.";
    } else {
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);

        if ($username === '' || $password === '') {
            $errors[] = "Both username and password are required.";
        } else {
            // 2. Fetch user record using a parameterized PDO prepared statement
            $stmt = $pdo->prepare(
                "SELECT id, username, full_name, password_hash
                 FROM users
                 WHERE username = :username
                 LIMIT 1"
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            // 3. Verify submitted plain password against the stored bcrypt hash
            if ($user && password_verify($password, $user['password_hash'])) {
                // Regenerate session ID to prevent session fixation attacks
                session_regenerate_id(true);

                // Store authenticated user identity in server-side $_SESSION
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];

                // 4. Manage non-sensitive browser cookies (never store passwords in cookies)
                if ($rememberMe) {
                    setcookie('hc_remember_user', $user['username'], time() + (86400 * 30), '/');
                } else {
                    setcookie('hc_remember_user', '', time() - 3600, '/');
                }
                setcookie('hc_last_login', date('Y-m-d H:i:s'), time() + (86400 * 30), '/');

                // 5. Record login event in flat-file log and relational activity_logs table
                logActivity($user['username'], 'LOGIN', 'User authenticated successfully.');
                $logStmt = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, action_type, details, ip_address)
                     VALUES (:user_id, 'LOGIN', :details, :ip)"
                );
                $logStmt->execute([
                    ':user_id' => $user['id'],
                    ':details' => 'Successful portal login',
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                // Redirect to protected Dashboard
                redirect(BASE_URL . '/pages/dashboard.php');
            } else {
                // Log failed login attempt without revealing whether username or password failed
                logActivity($username, 'LOGIN_FAILED', 'Invalid login credentials submitted.');
                $errors[] = "Invalid username or password.";
            }
        }
    }
}

// SEO metadata passed to shared header.php
$pageTitle = 'Sign In';
$pageDescription = 'Sign in to Huduma Campus to manage support inquiries, profile details, and campus records.';
include __DIR__ . '/includes/header.php';
?>

<section class="card auth-card">
    <div class="page-header">
        <h1>Portal Sign In</h1>
        <p class="muted">Sign in with your student account to access campus support services.</p>
    </div>

    <!-- Contextual status alerts passed via $_GET query parameters -->
    <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success">Account created successfully. You may now sign in.</div>
    <?php endif; ?>

    <?php if (isset($_GET['logged_out'])): ?>
        <div class="alert alert-success">You have been securely logged out.</div>
    <?php endif; ?>

    <?php if (isset($_GET['auth'])): ?>
        <div class="alert alert-error">Please sign in to access protected portal pages.</div>
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

    <form method="POST" action="login.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($rememberedUser, ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="checkbox-row">
            <input type="checkbox" id="remember_me" name="remember_me" <?= $rememberedUser !== '' ? 'checked' : '' ?>>
            <label for="remember_me" style="margin:0; font-weight:normal;">Remember my username on this browser</label>
        </div>

        <button type="submit" class="btn" style="width:100%;">Sign In</button>
    </form>

    <p class="muted" style="margin-top:1rem; text-align:center;">
        Don't have an account? <a href="register.php">Create one here</a>.
    </p>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>