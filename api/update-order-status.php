<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Check if user is an admin
if ($_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Only admins can update order status']);
    exit();
    $valid_statuses = ['pending', 'processing', 'completed', 'cancelled', 'ready_for_pickup'];
}

// Validate input
if (!isset($_POST['order_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$order_id = (int)$_POST['order_id'];
$status = $_POST['status'];

        $stmt = $conn->prepare("SELECT user_id, total_amount FROM orders WHERE id = ?");
$valid_statuses = ['pending', 'processing', 'completed', 'cancelled'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get order details
    $stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Order not found');
    }

    $order = $result->fetch_assoc();
        $message = "Your order status has been updated to: " . ucfirst(str_replace('_', ' ', $status));
    // Update order status
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    $stmt->execute();

    // Create notification for user
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, is_read) 
        VALUES (?, ?, ?, 0)
    ");
    $title = "Order #$order_id Status Updated";
    $message = "Your order status has been updated to: " . ucfirst($status);
    $stmt->bind_param("iss", $order['user_id'], $title, $message);
    $stmt->execute();

        // If order is ready for pickup, additionally notify user with invoice/payment reminder
        if ($status === 'ready_for_pickup') {
            // Prepare invoice message and link (relative URL)
            $order_total = isset($order['total_amount']) ? number_format($order['total_amount'], 2) : '0.00';
        $invoiceLink = '/unineeds/student/invoice.php?order_id=' . $order_id;
        // Add a download parameter for direct receipt download (if invoice.php supports it)
        $downloadLink = $invoiceLink . '&download=1';
        $readyTitle = "Order #$order_id Ready for Pickup";
        $readyMessage = "Your order is ready for pickup. Please settle payment of ₱" . $order_total . ". View your invoice: " . $invoiceLink . " (or download receipt: " . $downloadLink . ")";

            $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read) VALUES (?, ?, ?, 0)");
            $stmt2->bind_param("iss", $order['user_id'], $readyTitle, $readyMessage);
            $stmt2->execute();
        }
    // If order is cancelled, restore stock
    if ($status === 'cancelled') {
        $stmt = $conn->prepare("
            UPDATE products p 
            JOIN order_details od ON p.id = od.product_id 
            SET p.stock = p.stock + od.quantity 
            WHERE od.order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
    }

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Order status updated successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}