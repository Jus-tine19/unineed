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
    echo json_encode(['success' => false, 'message' => 'Only students can place orders']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Get cart items
    $stmt = $conn->prepare("
        SELECT c.*, p.price, p.stock_quantity 
        FROM cart c 
        JOIN products p ON c.product_id = p.product_id 
        WHERE c.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_items = $stmt->get_result();

    if ($cart_items->num_rows === 0) {
        throw new Exception('Cart is empty');
    }

    // Calculate total and validate stock
    $total_amount = 0;
    $items = [];
    while ($item = $cart_items->fetch_assoc()) {
        if ($item['quantity'] > $item['stock_quantity']) {
            throw new Exception("Not enough stock available for some items");
        }
        $total_amount += $item['price'] * $item['quantity'];
        $items[] = $item;
    }

    // Create order
    $stmt = $conn->prepare("
        INSERT INTO orders (user_id, total_amount, order_status, payment_status, payment_method) 
        VALUES (?, ?, 'pending', 'pending', 'cod')
    ");
    $stmt->bind_param("id", $user_id, $total_amount);
    $stmt->execute();
    $order_id = $conn->insert_id;

    // Create order items and update stock
    $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    $stmt_stock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?");

    foreach ($items as $item) {
        // Add to order items
        $stmt->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        $stmt->execute();

        // Update stock
        $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
        $stmt_stock->execute();
    }

    // Clear cart
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    // Create notification for admin
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, message, type, is_read) 
        VALUES (?, ?, 'order', 0)
    ");
    $message = "New order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been placed for ₱" . number_format($total_amount, 2);
    $admin_id = 1; // Assuming admin user ID is 1
    $stmt->bind_param("is", $admin_id, $message);
    $stmt->execute();

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Order processed successfully',
        'order_id' => $order_id
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}