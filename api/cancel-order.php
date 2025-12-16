<?php
// Cancel Order API - Restore Stock
require_once '../config/database.php';
requireStudent();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Extract order_id from form or JSON
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);
$order_id = 0;
if (!empty($_POST)) {
    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
} elseif (is_array($jsonInput) && isset($jsonInput['order_id'])) {
    $order_id = (int)$jsonInput['order_id'];
} else {
    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
}
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing order id']);
    exit();
}

// Verify order exists, is pending, & belongs to user
$order_query = "SELECT o.*, u.user_id 
                FROM orders o 
                JOIN users u ON o.user_id = u.user_id 
                WHERE o.order_id = ? AND o.user_id = ? 
                AND o.order_status = 'pending'";

$stmt = mysqli_prepare($conn, $order_query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare order query: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $order_id, $user_id);
if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => 'Failed to execute order query: ' . mysqli_stmt_error($stmt)]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
if (mysqli_num_rows($result) === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found or cannot be cancelled']);
    exit;
}

$order = mysqli_fetch_assoc($result);

// Begin atomic transaction
$conn->begin_transaction();
try {
    // Update order status to cancelled
    $update_query = "UPDATE orders SET order_status = 'cancelled' WHERE order_id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare update query: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to update order status: ' . mysqli_stmt_error($stmt));
    }

    // Fetch order items & restore stock
    $items_query = "SELECT oi.*, p.product_id, p.stock_quantity 
                    FROM order_items oi 
                    JOIN products p ON oi.product_id = p.product_id 
                    WHERE oi.order_id = ?";
    
    $stmt = mysqli_prepare($conn, $items_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare items query: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to get order items: ' . mysqli_stmt_error($stmt));
    }
    
    $items = mysqli_stmt_get_result($stmt);
    
    // Process each item & restore stock
    while ($item = mysqli_fetch_assoc($items)) {
        $qty = intval($item['quantity']);
        $product_id = intval($item['product_id']);
        $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
        
        // Restore base product stock
        $update_stock = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?";
        $stmt = mysqli_prepare($conn, $update_stock);
        if (!$stmt) {
            throw new Exception('Failed to prepare stock update: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "ii", $qty, $product_id);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to restore stock for product ' . $product_id . ': ' . mysqli_stmt_error($stmt));
        }
        
        // Restore variant stock (if applicable)
        if ($variant_id) {
            $variant_stock = "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE variant_id = ?";
            $stmt = mysqli_prepare($conn, $variant_stock);
            if (!$stmt) {
                throw new Exception('Failed to prepare variant stock update: ' . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($stmt, "ii", $qty, $variant_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to restore stock for variant ' . $variant_id . ': ' . mysqli_stmt_error($stmt));
            }
        }
    }

    // Send cancellation notifications
    $adminMsg = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " cancelled by customer.";
    $userMsg = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " cancelled.";
    $adminId = 1;
    
    // Notify admin
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (?, ?, 'order', 0)");
    if (!$stmt) {
        throw new Exception('Failed to prepare admin notification: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "is", $adminId, $adminMsg);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to create admin notification: ' . mysqli_stmt_error($stmt));
    }
    
    // Insert notification for user
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (?, ?, 'order', 0)");
    if (!$stmt) {
        throw new Exception('Failed to prepare user notification: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "is", $user_id, $userMsg);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to create user notification: ' . mysqli_stmt_error($stmt));
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Order cancelled successfully']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to cancel order: ' . $e->getMessage()]);
}

