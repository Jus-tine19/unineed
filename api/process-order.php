<?php
header('Content-Type: application/json');
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

// Ensure inventory movements table exists before processing order
ensureInventoryMovementsTableExists($conn);
$conn->begin_transaction();

try {
    // 2. Create Order
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, payment_method, receipt_proof, status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("idss", $user_id, $total_amount, $payment_method, $receipt_path);
    $stmt->execute();
    $order_id = $conn->insert_id;

// 3. Move items to order_items and deduct stock (log inventory movements)
    foreach($cart as $id => $item) {
        $product_id = $item['product_id'];
        $qty = $item['quantity'];
        $price = $item['price'];

        // Resolve variant_id if variants are provided but variant_id isn't set
        $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
        if (empty($variant_id) && !empty($item['variants']) && is_array($item['variants'])) {
            foreach ($item['variants'] as $vt => $vv) {
                $vt_esc = mysqli_real_escape_string($conn, $vt);
                $vv_esc = mysqli_real_escape_string($conn, $vv);
                $vid_q = mysqli_query($conn, "SELECT variant_id FROM product_variants WHERE product_id = $product_id AND variant_type = '$vt_esc' AND variant_value = '$vv_esc' LIMIT 1");
                if ($vid_q && mysqli_num_rows($vid_q) > 0) {
                    $variant_id = intval(mysqli_fetch_assoc($vid_q)['variant_id']);
                    $item['variant_id'] = $variant_id;
                    break;
                }
            }
        }

        if (!empty($variant_id)) {
            $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
            $item_stmt->bind_param("iiiid", $order_id, $product_id, $variant_id, $qty, $price);
        } else {
            $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $item_stmt->bind_param("iiid", $order_id, $product_id, $qty, $price);
        }
        $item_stmt->execute();

        // Handle stock update with variant support
        $is_preorder_q = mysqli_query($conn, "SELECT is_preorder FROM products WHERE product_id = $product_id LIMIT 1");
        $is_preorder_row = $is_preorder_q ? mysqli_fetch_assoc($is_preorder_q) : null;
        if (empty($is_preorder_row['is_preorder']) || $is_preorder_row['is_preorder'] == 0) {
            // Variant support if provided
            $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
            if ($variant_id) {
                $v_q = mysqli_query($conn, "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                if ($v_q && mysqli_num_rows($v_q) > 0) {
                    $vrow = mysqli_fetch_assoc($v_q);
                    $current_stock = intval($vrow['stock_quantity']);
                    if ($qty > $current_stock) throw new Exception("Stock ran out for variant of " . ($item['name'] ?? 'item'));
                    $new_stock = $current_stock - $qty;
                    if ($new_stock < 0) $new_stock = 0;

                    mysqli_query($conn, "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id");

                    // Ensure columns
                    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                    if (mysqli_num_rows($col_check) === 0) {
                        mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                    }
                    $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                    if (mysqli_num_rows($col_check2) === 0) {
                        mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                    }

                    $stock_change = $new_stock - $current_stock; // negative
                    $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($_SESSION['full_name'] ?? 'customer'));
                    $price_at_movement = floatval($vrow['price']);
                    $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $variant_id, $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', $user_id)";
                    mysqli_query($conn, $ins_mv);

                    if ($current_stock > 0 && $new_stock == 0) {
                        $vv = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                        $vtext = '';
                        if ($vv && mysqli_num_rows($vv) > 0) {
                            $vrow = mysqli_fetch_assoc($vv);
                            $vtext = ' (' . mysqli_real_escape_string($conn, $vrow['variant_type'] . ': ' . $vrow['variant_value']) . ')';
                        }
                        $pname = mysqli_real_escape_string($conn, ($item['name'] ?? 'Product'));
                        $note = "Variant out of stock: " . $pname . $vtext;
                        mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                    }
                }
            } else {
                $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
                $prow = $current_q ? mysqli_fetch_assoc($current_q) : null;
                $current_stock = intval($prow['stock_quantity']);
                if ($qty > $current_stock) throw new Exception("Stock ran out for " . ($item['name'] ?? 'item'));
                $new_stock = $current_stock - $qty;
                if ($new_stock < 0) $new_stock = 0;

                mysqli_query($conn, "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $product_id");

                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                }

                $stock_change = $new_stock - $current_stock; // negative
                $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($_SESSION['full_name'] ?? 'customer'));
                $price_at_movement = floatval($prow['price'] ?? $price);
                $ins_mv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', $user_id)";
                mysqli_query($conn, $ins_mv);

                if ($current_stock > 0 && $new_stock == 0) {
                    $pname = mysqli_real_escape_string($conn, ($item['name'] ?? 'Product'));
                    $note = "Product out of stock: " . $pname;
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                }
            }
        }
    }

    // Update carts for other users and the current session cart
    foreach($cart as $item) {
        $product_id = intval($item['product_id']);
        $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;

        if ($variant_id) {
            $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
            $vrow = $v_q ? mysqli_fetch_assoc($v_q) : null;
            $new_stock = intval($vrow['stock_quantity'] ?? 0);

            if ($new_stock <= 0) {
                mysqli_query($conn, "DELETE FROM cart WHERE product_id = $product_id AND variant_id = $variant_id");
            } else {
                mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $product_id AND variant_id = $variant_id");
            }

            // Update session cart for current user if present
            if (isset($_SESSION['cart'])) {
                foreach($_SESSION['cart'] as $k => $ci) {
                    if (isset($ci['variant_id']) && intval($ci['variant_id']) === $variant_id && intval($ci['product_id']) === $product_id) {
                        if ($new_stock <= 0) {
                            unset($_SESSION['cart'][$k]);
                        } elseif ($_SESSION['cart'][$k]['quantity'] > $new_stock) {
                            $_SESSION['cart'][$k]['quantity'] = $new_stock;
                        }
                    }
                }
            }
        } else {
            $p_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $product_id LIMIT 1");
            $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
            $new_stock = intval($prow['stock_quantity'] ?? 0);

            if ($new_stock <= 0) {
                mysqli_query($conn, "DELETE FROM cart WHERE product_id = $product_id AND (variant_id IS NULL OR variant_id = '')");
            } else {
                mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $product_id AND (variant_id IS NULL OR variant_id = '')");
            }

            if (isset($_SESSION['cart'])) {
                foreach($_SESSION['cart'] as $k => $ci) {
                    if (intval($ci['product_id']) === $product_id && !isset($ci['variant_id'])) {
                        if ($new_stock <= 0) {
                            unset($_SESSION['cart'][$k]);
                        } elseif ($_SESSION['cart'][$k]['quantity'] > $new_stock) {
                            $_SESSION['cart'][$k]['quantity'] = $new_stock;
                        }
                    }
                }
            }
        }
    }

    $conn->commit();
    unset($_SESSION['cart']); // Clear cart after success
    echo json_encode(['success' => true, 'order_id' => $order_id]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Order failed: ' . $e->getMessage()]);
}