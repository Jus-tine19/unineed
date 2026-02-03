<?php
// Cancel Order API - Restore Stock
require_once '../config/database.php';
requireStudent();

function ensureInventoryMovementsTableExists($conn) {
    $table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
    if (mysqli_num_rows($table_exists) === 0) {
        $create = "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            variant_id INT NULL,
            price_at_movement DECIMAL(10,2) NULL,
            quantity_change INT NOT NULL,
            previous_quantity INT NOT NULL,
            new_quantity INT NOT NULL,
            movement_type VARCHAR(32) NOT NULL,
            reason TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        mysqli_query($conn, $create);
    }
} 

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

// Ensure inventory movements table exists before restoration
ensureInventoryMovementsTableExists($conn);
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
        
        // Restore base product stock (and log as inventory movement)
        $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
        $prow = $current_q ? mysqli_fetch_assoc($current_q) : null;
        $prev_stock = intval($prow['stock_quantity']);
        $new_stock = $prev_stock + $qty;

        $update_stock = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?";
        $stmt = mysqli_prepare($conn, $update_stock);
        if (!$stmt) {
            throw new Exception('Failed to prepare stock update: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "ii", $qty, $product_id);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to restore stock for product ' . $product_id . ': ' . mysqli_stmt_error($stmt));
        }

        // Ensure price_at_movement column exists
        $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
        if (mysqli_num_rows($col_check2) === 0) {
            mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
        }

        // Log movement for product restoration
        $price_at_movement = floatval($prow['price'] ?? 0);
        $mv_reason = mysqli_real_escape_string($conn, "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " cancelled by customer");
        $ins_mv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
        $ins_mv .= "VALUES ($product_id, $price_at_movement, $qty, $prev_stock, $new_stock, 'add', '$mv_reason', {$_SESSION['user_id']})";
        mysqli_query($conn, $ins_mv);

        // Notify admin if product was restocked (from 0 to >0)
        if ($prev_stock == 0 && $new_stock > 0) {
            $pname = mysqli_real_escape_string($conn, $item['product_name'] ?? 'Product');
            $note = "Product restocked: " . $pname;
            mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
        }
        
        // Restore variant stock (if applicable) and log it
        if ($variant_id) {
            $v_q = mysqli_query($conn, "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
            $vrow = $v_q ? mysqli_fetch_assoc($v_q) : null;
            $v_prev = intval($vrow['stock_quantity']);
            $v_new = $v_prev + $qty;

            $variant_stock = "UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE variant_id = ?";
            $stmt = mysqli_prepare($conn, $variant_stock);
            if (!$stmt) {
                throw new Exception('Failed to prepare variant stock update: ' . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($stmt, "ii", $qty, $variant_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to restore stock for variant ' . $variant_id . ': ' . mysqli_stmt_error($stmt));
            }

            // Ensure variant_id column exists in inventory_movements
            $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
            if (mysqli_num_rows($col_check) === 0) {
                mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
            }

            $price_at_movement = floatval($vrow['price'] ?? 0);
            $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
            $ins_mv .= "VALUES ($product_id, $variant_id, $price_at_movement, $qty, $v_prev, $v_new, 'add', '$mv_reason', {$_SESSION['user_id']})";
            mysqli_query($conn, $ins_mv);

            if ($v_prev == 0 && $v_new > 0) {
                $vv = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                $vtext = '';
                if ($vv && mysqli_num_rows($vv) > 0) {
                    $vrow = mysqli_fetch_assoc($vv);
                    $vtext = ' (' . mysqli_real_escape_string($conn, $vrow['variant_type'] . ': ' . $vrow['variant_value']) . ')';
                }
                $pname = mysqli_real_escape_string($conn, $item['product_name'] ?? 'Product');
                $note = "Variant restocked: " . $pname . $vtext;
                mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
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

