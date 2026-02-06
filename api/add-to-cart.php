<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Check if user is a student
if ($_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Only students can add items to cart']);
    exit();
}

// Support JSON payloads
$input = null;
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Validate input (from JSON or form)
$product_id = isset($input['product_id']) ? (int)$input['product_id'] : (isset($_POST['product_id']) ? (int)$_POST['product_id'] : null);
$quantity = isset($input['quantity']) ? (int)$input['quantity'] : (isset($_POST['quantity']) ? (int)$_POST['quantity'] : null);
$user_id = $_SESSION['user_id'];

if ($product_id === null || $quantity === null) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Validate quantity
if ($quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid quantity']);
    exit();
}

// Check if product exists and fetch stock/is_preorder
$stmt = $conn->prepare("SELECT product_id, stock_quantity, is_preorder FROM products WHERE product_id = ? LIMIT 1");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit();
}

$product = $result->fetch_assoc();

// If product is not preorder, enforce stock checks (use correct fields)
if (empty($product['is_preorder']) || $product['is_preorder'] == 0) {
    $available = intval($product['stock_quantity'] ?? 0);
    if ($quantity > $available) {
        echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
        exit();
    }
}

// Check if product is already in cart
$stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
$stmt->bind_param("ii", $user_id, $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Update quantity in cart
    $current_quantity = $result->fetch_assoc()['quantity'];
    $new_quantity = $current_quantity + $quantity;
    
    // If product is not preorder, ensure new quantity doesn't exceed stock
    if (empty($product['is_preorder']) || $product['is_preorder'] == 0) {
        $available = intval($product['stock_quantity'] ?? 0);
        if ($new_quantity > $available) {
            echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
            exit();
        }
    }

    $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("iii", $new_quantity, $user_id, $product_id);
} else {
    // Add new item to cart
    $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmt->bind_param("iii", $user_id, $product_id, $quantity);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Product added to cart successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error adding product to cart']);
}