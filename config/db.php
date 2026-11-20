<?php
// Application & Database Constants
define('APP_NAME', 'Huduma Campus');
define('APP_SUBTITLE', 'Campus Support & Inquiry Management System');
define('BASE_URL', '/huduma_campus');
define('RECORDS_PER_PAGE', 5);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'huduma_campus_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed. Make sure MySQL is running in XAMPP.");
}