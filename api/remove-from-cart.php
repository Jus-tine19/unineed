<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Validate input
if (!isset($_POST['cart_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing cart item ID']);
    exit();
}

$cart_id = (int)$_POST['cart_id'];
$user_id = $_SESSION['user_id'];

// Delete item from cart
$stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $cart_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error removing item from cart']);
}