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

// IONOS credentials 
// define('DB_HOST', 'db5018930086.hosting-data.io');
// define('DB_USER', 'dbu1862993');
// define('DB_PASS', 'bpcunineedspass.');
// define('DB_NAME', 'dbs14922247');

// Connect to DB
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set UTF-8 charset
mysqli_set_charset($conn, "utf8");

// Set MySQL session timezone to +08:00 (Philippines)
@mysqli_query($conn, "SET time_zone = '+08:00'");

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
        header('Location: /unineeds/student/dashboard.php');
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