<?php
/**
 * Core Helper & Utility Library (includes/functions.php)
 * Implements reusable input sanitization, server-side form parsing, CSRF protection,
 * flat-file activity logging, CSV exporting, and array/string utilities.
 */

// Start PHP session automatically if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 1. Sanitizes user string input to prevent XSS and strip control characters.
 *
 * @param string $input Raw input string from user
 * @return string Cleaned and HTML-escaped string
 */
function sanitizeInput(string $input): string {
    $trimmed = trim($input);
    $cleaned = str_replace(["\r", "\0"], '', $trimmed);
    return htmlspecialchars($cleaned, ENT_QUOTES, 'UTF-8');
}

/**
 * 2. Validates an email address using PHP's built-in filter_var() function.
 *
 * @param string $email Email string to check
 * @return bool True if valid email format, false otherwise
 */
function validateEmail(string $email): bool {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 3. Sends a Location HTTP header and halts script execution safely.
 *
 * @param string $url Target URL or path
 * @return void
 */
function redirect(string $url): void {
    header("Location: " . $url);
    exit();
}

/**
 * 4. Reusable Form Parser & Server-Side Validator
 * Loops through a declarative validation ruleset, checks required fields,
 * string lengths, email format, and allowed values (in_array), then sanitizes data.
 *
 * @param array $source Typically $_POST
 * @param array $rules  Associative array of field rules
 * @return array ['data' => sanitized_array, 'errors' => error_messages_array]
 */
function parseAndValidateForm(array $source, array $rules): array {
    $cleanData = [];
    $errors = [];

    foreach ($rules as $field => $config) {
        $raw = isset($source[$field]) ? trim((string)$source[$field]) : '';
        $label = $config['label'] ?? ucfirst(str_replace('_', ' ', $field));

        // Check required constraint
        if (!empty($config['required']) && $raw === '') {
            $errors[$field] = "$label is required.";
            continue;
        }

        // Check minimum character length using strlen()
        if (isset($config['min']) && strlen($raw) < $config['min']) {
            $errors[$field] = "$label must be at least {$config['min']} characters long.";
        }

        // Check maximum character length using strlen()
        if (isset($config['max']) && strlen($raw) > $config['max']) {
            $errors[$field] = "$label must not exceed {$config['max']} characters.";
        }

        // Validate email structure when type is set to 'email'
        if (($config['type'] ?? '') === 'email' && $raw !== '' && !validateEmail($raw)) {
            $errors[$field] = "Please enter a valid email address for $label.";
        }

        // Verify value against whitelist array using in_array()
        if (isset($config['allowed']) && !in_array($raw, $config['allowed'], true)) {
            $errors[$field] = "Invalid selection provided for $label.";
        }

        // Store sanitized value
        $cleanData[$field] = sanitizeInput($raw);
    }

    return ['data' => $cleanData, 'errors' => $errors];
}

/**
 * 5. Appends authentication and CRUD events to logs/activity.log using fopen() & fwrite().
 *
 * @param string $username Active user's username
 * @param string $action   Event category (e.g., LOGIN, REGISTER, CREATE, UPDATE, DELETE)
 * @param string $details  Description of the action performed
 * @return bool True on successful write, false on failure
 */
function logActivity(string $username, string $action, string $details = ''): bool {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $filePath = $logDir . '/activity.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Combine log fields into a pipe-delimited line using implode()
    $record = implode(' | ', [
        $timestamp,
        strtoupper($action),
        "User: $username",
        "IP: $ip",
        $details
    ]) . PHP_EOL;

    $fp = fopen($filePath, 'a');
    if ($fp === false) {
        return false;
    }

    fwrite($fp, $record);
    fclose($fp);
    return true;
}

/**
 * 6. Reads the latest audit entries from logs/activity.log using fopen() & fgets().
 *
 * @param int $limit Maximum number of recent lines to return
 * @return array Indexed array of formatted log lines
 */
function readActivityLog(int $limit = 8): array {
    $filePath = __DIR__ . '/../logs/activity.log';
    if (!file_exists($filePath)) {
        return [];
    }

    $lines = [];
    $fp = fopen($filePath, 'r');
    if ($fp !== false) {
        while (($line = fgets($fp)) !== false) {
            $lines[] = trim($line);
        }
        fclose($fp);
    }

    // Filter out empty lines and return newest entries first
    $lines = array_filter($lines, fn($item) => $item !== '');
    return array_slice(array_reverse($lines), 0, $limit);
}

/**
 * 7. Streams an array of database records as a downloadable CSV file using fputcsv().
 *
 * @param string $filename Download filename (e.g., huduma_submissions.csv)
 * @param array  $headers  Column header names
 * @param array  $rows     2D array of data rows
 * @return void
 */
function exportCSV(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();
}

/**
 * 8. Dynamically builds an XSS-safe HTML table from headers and row arrays.
 *
 * @param array $headers Column titles
 * @param array $rows    2D array of cell values
 * @return string Rendered HTML markup
 */
function generateTable(array $headers, array $rows): string {
    if (empty($rows)) {
        return '<p class="muted">No records available to display.</p>';
    }

    $html = '<div class="table-responsive"><table class="data-table"><thead><tr>';
    foreach ($headers as $heading) {
        $html .= '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $value) {
            $html .= '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
}

/**
 * 9. Multidimensional Array of Inquiry Subject Presets for the Contact Us dropdown.
 * Links each preset subject code and label to its corresponding category_id.
 *
 * @return array Multidimensional array of subject options
 */
function getSubjectOptions(): array {
    return [
        ['category_id' => 1, 'code' => 'ICT-01', 'label' => 'Student Portal / LMS Login Issue'],
        ['category_id' => 1, 'code' => 'ICT-02', 'label' => 'Campus Wi-Fi & Network Connectivity'],
        ['category_id' => 2, 'code' => 'FIN-01', 'label' => 'Fee Statement & Balance Verification'],
        ['category_id' => 2, 'code' => 'FIN-02', 'label' => 'Exam Card Clearance Request'],
        ['category_id' => 3, 'code' => 'EXM-01', 'label' => 'Missing Marks / CAT Score Query'],
        ['category_id' => 3, 'code' => 'EXM-02', 'label' => 'Unit Registration & Timetable Clash'],
        ['category_id' => 4, 'code' => 'WEL-01', 'label' => 'Hostel Maintenance & Welfare Support'],
    ];
}

/**
 * 10. Maps an inquiry status string to its corresponding CSS badge class using switch.
 *
 * @param string $status Ticket status ('Open', 'In Progress', 'Resolved')
 * @return string CSS class name
 */
function getStatusBadgeClass(string $status): string {
    switch ($status) {
        case 'Resolved':
            return 'badge-resolved';
        case 'In Progress':
            return 'badge-progress';
        case 'Open':
        default:
            return 'badge-open';
    }
}

/**
 * 11. Generates and caches a cryptographically secure CSRF token in $_SESSION.
 *
 * @return string 64-character hex token
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 12. Verifies a submitted form CSRF token against the active session token.
 *
 * @param string|null $token Token received from $_POST
 * @return bool True if valid, false otherwise
 */
function verifyCsrfToken(?string $token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}