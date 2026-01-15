<?php
header('Content-Type: application/json');
require_once '../config/database.php';
requireStudent();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$cart = $_SESSION['cart'] ?? [];
$payment_method = $_POST['payment_method'] ?? 'Cash';
$receipt_path = null;

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit;
}

// 1. Validate GCash Receipt if applicable
if ($payment_method === 'GCash') {
    if (!isset($_FILES['payment_receipt']) || $_FILES['payment_receipt']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Proof of payment is required for GCash orders.']);
        exit;
    }

    $target_dir = "../uploads/receipts/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $file_ext = pathinfo($_FILES["payment_receipt"]["name"], PATHINFO_EXTENSION);
    $file_name = "receipt_" . time() . "_" . $user_id . "." . $file_ext;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["payment_receipt"]["tmp_name"], $target_file)) {
        $receipt_path = "uploads/receipts/" . $file_name;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload receipt.']);
        exit;
    }
}

// Calculate Total
$total_amount = 0;
foreach($cart as $item) {
    $total_amount += ($item['price'] * $item['quantity']);
}

$conn->begin_transaction();

try {
    // 2. Create Order
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, payment_method, receipt_proof, status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("idss", $user_id, $total_amount, $payment_method, $receipt_path);
    $stmt->execute();
    $order_id = $conn->insert_id;

    // 3. Move items to order_items and deduct stock
    foreach($cart as $id => $item) {
        $product_id = $item['product_id'];
        $qty = $item['quantity'];
        $price = $item['price'];

        $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $item_stmt->bind_param("iiid", $order_id, $product_id, $qty, $price);
        $item_stmt->execute();

        // Update Stock
        $stock_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stock_stmt->bind_param("ii", $qty, $product_id);
        $stock_stmt->execute();
    }

    $conn->commit();
    unset($_SESSION['cart']); // Clear cart after success
    echo json_encode(['success' => true, 'order_id' => $order_id]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Order failed: ' . $e->getMessage()]);
}