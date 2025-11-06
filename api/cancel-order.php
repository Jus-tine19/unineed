<?php
require_once '../config/database.php';
requireStudent();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';

// Expect POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Support JSON body as well as form-data
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

// Get order details
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

// Already verified: order exists, belongs to user, and is pending

// Start transaction
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

    // Get order items with product details
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
    
    // Restore stock for each item
    while ($item = mysqli_fetch_assoc($items)) {
        $update_stock = "UPDATE products 
                        SET stock_quantity = stock_quantity + ? 
                        WHERE product_id = ?";
        
        $stmt = mysqli_prepare($conn, $update_stock);
        if (!$stmt) {
            throw new Exception('Failed to prepare stock update: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "ii", $item['quantity'], $item['product_id']);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to restore stock for product ' . $item['product_id'] . ': ' . mysqli_stmt_error($stmt));
        }
    }

    // Create different messages for admin and user
    $adminMsg = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been cancelled by the customer.";
    $userMsg = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been cancelled by you.";
    $adminId = 1; // default admin
    
    // Insert notification for admin
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

