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

// Validate input
if (!isset($_POST['product_id']) || !isset($_POST['quantity'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$product_id = (int)$_POST['product_id'];
$quantity = (int)$_POST['quantity'];
$user_id = $_SESSION['user_id'];

// Validate quantity
if ($quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid quantity']);
    exit();
}

// Check if product exists and has enough stock
$stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit();
}

$product = $result->fetch_assoc();
if ($product['stock'] < $quantity) {
    echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
    exit();
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
    
    if ($new_quantity > $product['stock']) {
        echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
        exit();
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