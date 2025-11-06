<?php
// config/database.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'unineeds');

//define('DB_HOST', 'db5018930086.hosting-data.io');
//define('DB_USER', 'dbu1862993');
//define('DB_PASS', 'bpcunineedspass.');
//define('DB_NAME', 'dbs14922247');
        
// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to utf8
mysqli_set_charset($conn, "utf8");

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to check user type
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

function isStudent() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /unineeds/index.php');
        exit();
    }
}

// Redirect if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /unineeds/student/dashboard.php');
        exit();
    }
}

// Redirect if not student
function requireStudent() {
    requireLogin();
    if (!isStudent()) {
        header('Location: /unineeds/admin/dashboard.php');
        exit();
    }
}

// Sanitize input
function clean($string) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($string));
}

// Format currency
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}
?>