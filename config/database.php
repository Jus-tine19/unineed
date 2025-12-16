<?php
// Database & Auth Configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set Philippine timezone for PHP
date_default_timezone_set('Asia/Manila');

// DB Config
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'unineeds');

// Connect to DB
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set UTF-8 charset
mysqli_set_charset($conn, "utf8");

// Set MySQL session timezone to +08:00 (Philippines)
@mysqli_query($conn, "SET time_zone = '+08:00'");


// --- APPLICATION CONSTANTS AND DYNAMIC SETTINGS ---

// Application-wide Constants
define('DOWN_PAYMENT_PERCENTAGE', 0.20); // 20% down payment

// Dynamic GCash Settings Fetch
if ($conn) {
    $check_settings = mysqli_query($conn, "SHOW TABLES LIKE 'settings'");
    if ($check_settings && mysqli_num_rows($check_settings) > 0) {
        $gcash_settings_query = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gcash_number', 'gcash_name')";
        $gcash_settings_result = mysqli_query($conn, $gcash_settings_query);
        
        $settings = [];
        if ($gcash_settings_result) {
            while ($row = mysqli_fetch_assoc($gcash_settings_result)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        // Define the GCash Constants dynamically (using fallbacks if not found in DB)
        define('GCASH_NUMBER', $settings['gcash_number'] ?? '09171234567');
        define('GCASH_NAME', $settings['gcash_name'] ?? 'UniNeeds Treasurer');
        
    } else {
        // Fallback constants if 'settings' table does not exist
        define('GCASH_NUMBER', '09171234567');
        define('GCASH_NAME', 'UniNeeds Treasurer');
    }
} else {
    // Fallback constants if DB connection failed
    define('GCASH_NUMBER', '09171234567');
    define('GCASH_NAME', 'UniNeeds Treasurer');
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Check if user is student
function isStudent() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student';
}

// Enforce login (redirect if not logged in)
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /unineeds/index.php');
        exit();
    }
}

// Enforce admin access (redirect if not admin)
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        // Redirect to student product page
        header('Location: /unineeds/student/products.php'); 
        exit();
    }
}

// Enforce student access (redirect if not student)
function requireStudent() {
    requireLogin();
    if (!isStudent()) {
        header('Location: /unineeds/admin/dashboard.php');
        exit();
    }
}

// Sanitize input string
function clean($string) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($string));
}

// Format amount as Philippine currency
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}
?>