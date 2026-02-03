<?php
// Checkout - Place Order & Deduct Stock
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

// Redirect if cart empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit();
}

// Get selected items from POST or use all
$selected_keys = isset($_POST['selected_items']) ? $_POST['selected_items'] : array_keys($_SESSION['cart']);
$cart_items = array_intersect_key($_SESSION['cart'], array_flip($selected_keys));

// Redirect if no items selected
if (empty($cart_items)) {
    header('Location: cart.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user details
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Calculate cart summary
$subtotal = 0;
$total_items = 0;

// NEW LOGIC: Check if any item in the cart requires a down payment
$is_down_payment_required_for_cart = false;
foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_items += $item['quantity'];
    
    // Check item requirement
    if (isset($item['requires_down_payment']) && $item['requires_down_payment']) {
        $is_down_payment_required_for_cart = true;
    }
}

$total = $subtotal;
$down_payment_rate = DOWN_PAYMENT_PERCENTAGE; 
$down_payment_amount = round($total * $down_payment_rate, 2);
$remaining_balance = $total - $down_payment_amount;

// Fetch GCash Settings from Database (Assuming column 'gcash_qr' exists in settings)
$settings_res = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'gcash_qr'");
$settings_row = mysqli_fetch_assoc($settings_res);
$gcash_qr_image = $settings_row ? $settings_row['setting_value'] : null;

$gcash_number = GCASH_NUMBER;
$gcash_name = GCASH_NAME;

// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // Ensure inventory movements table exists before logging sales
    ensureInventoryMovementsTableExists($conn);
    
    // SERVER-SIDE VALIDATION: Ensure cart quantities do not exceed current stock
    foreach ($cart_items as $item) {
        $p_id = intval($item['product_id']);
        $qty = intval($item['quantity']);
        $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;

        if ($variant_id) {
            $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id AND product_id = $p_id LIMIT 1");
            if ($v_q && mysqli_num_rows($v_q) > 0) {
                $vrow = mysqli_fetch_assoc($v_q);
                if ($qty > intval($vrow['stock_quantity'])) {
                    $error = "Not enough stock for " . htmlspecialchars($item['product_name']) . ". Please reduce quantity in your cart.";
                    break;
                }
            }
        } else {
            $p_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $p_id LIMIT 1");
            $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
            if ($qty > intval($prow['stock_quantity'])) {
                $error = "Not enough stock for " . htmlspecialchars($item['product_name']) . ". Please reduce quantity in your cart.";
                break;
            }
        }
    }

    if (!empty($error)) {
        // Will display $error in the UI below
    }

    $payment_method = clean($_POST['payment_method'] ?? ($is_down_payment_required_for_cart ? 'gcash' : 'cash_on_pickup'));
    $payment_option = clean($_POST['payment_option'] ?? 'down_payment'); 

    if (empty($error)) {
        // STRICT VALIDATION: If downpayment is required, payment MUST be GCash
        if ($is_down_payment_required_for_cart && $payment_method !== 'gcash') {
            $error = "This order requires a down payment. Please use GCash to proceed.";
        } else {
            // Proceed with Database Transaction
            mysqli_begin_transaction($conn);
            try {
                // Determine statuses
                if ($payment_option === 'full_payment') {
                    $order_status = 'pending';
                    $payment_status = $payment_method === 'gcash' ? 'pending_proof' : 'fully_paid';
                } else {
                    $order_status = $payment_method === 'gcash' ? 'pending_payment' : 'pending';
                    $payment_status = $payment_method === 'gcash' ? 'pending_proof' : 'unpaid';
                }

                // 1. Create Order
                $q = "INSERT INTO orders (user_id, total_amount, payment_method, order_status) VALUES ($user_id, $total, '$payment_method', '$order_status')";
                mysqli_query($conn, $q);
                $order_id = mysqli_insert_id($conn);

                // 2. Process Items and Stock
                foreach ($cart_items as $item) {
                    $p_id = $item['product_id'];
                    $qty = $item['quantity'];
                    $price = $item['price'];
                    
                    // Prepare variants as JSON if they exist
                    $variants_json = null;
                    if (!empty($item['variants']) && is_array($item['variants'])) {
                        $variants_json = json_encode($item['variants']);
                        $variants_json = mysqli_real_escape_string($conn, $variants_json);
                    }
                    
                    // Insert Item with variants
                if (!empty($variants_json)) {
                    mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, quantity, price, variant_value) VALUES ($order_id, $p_id, $qty, $price, '$variants_json')");
                } else {
                    mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES ($order_id, $p_id, $qty, $price)");
                }
                
                // Deduct Stock only for stocked items (skip for pre-order/made-to-order)
                $prod_check = mysqli_query($conn, "SELECT is_preorder, price FROM products WHERE product_id = $p_id LIMIT 1");
                $prod_row = $prod_check ? mysqli_fetch_assoc($prod_check) : null;
                if (empty($prod_row['is_preorder']) || $prod_row['is_preorder'] == 0) {
                    // Handle variant-level items if variant_id is present in the cart item
                    $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
                    if ($variant_id) {
                        $v_q = mysqli_query($conn, "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id AND product_id = $p_id LIMIT 1");
                        if ($v_q && mysqli_num_rows($v_q) > 0) {
                            $vrow = mysqli_fetch_assoc($v_q);
                            $current_stock = intval($vrow['stock_quantity']);
                            $price_at_movement = floatval($vrow['price']);
                            $new_stock = $current_stock - $qty;
                            if ($new_stock < 0) $new_stock = 0;

                            // Update variant stock
                            mysqli_query($conn, "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id");

                            // Ensure inventory_movements columns exist (variant_id, price_at_movement)
                            $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                            if (mysqli_num_rows($col_check) === 0) {
                                mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                            }
                            $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                            if (mysqli_num_rows($col_check2) === 0) {
                                mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                            }

                            $stock_change = $new_stock - $current_stock; // negative
                            $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($user['full_name'] ?? 'Customer'));
                            $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                            $ins_mv .= "VALUES ($p_id, $variant_id, $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', {$_SESSION['user_id']})";
                            mysqli_query($conn, $ins_mv);

                            // Notify admin if this variant just went out of stock
                            if ($current_stock > 0 && $new_stock == 0) {
                                $pname = mysqli_real_escape_string($conn, $item['product_name']);
                                $vv = mysqli_query($conn, "SELECT variant_type, variant_value FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                                $vtext = '';
                                if ($vv && mysqli_num_rows($vv) > 0) {
                                    $vrow = mysqli_fetch_assoc($vv);
                                    $vtext = ' (' . mysqli_real_escape_string($conn, $vrow['variant_type'] . ': ' . $vrow['variant_value']) . ')';
                                }
                                $note = "Variant out of stock: " . $pname . $vtext;
                                mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                            }
                    } else {
                        // Product-level stock update & movement logging
                        $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $p_id LIMIT 1");
                        $prow = $current_q ? mysqli_fetch_assoc($current_q) : null;
                        $current_stock = intval($prow['stock_quantity']);
                        $price_at_movement = floatval($prow['price'] ?? $price);
                        $new_stock = $current_stock - $qty;
                        if ($new_stock < 0) $new_stock = 0;

                        mysqli_query($conn, "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $p_id");

                        $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                        if (mysqli_num_rows($col_check2) === 0) {
                            mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                        }

                        $stock_change = $new_stock - $current_stock; // negative
                        $mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($user['full_name'] ?? 'Customer'));
                        $ins_mv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                        $ins_mv .= "VALUES ($p_id, $price_at_movement, $stock_change, $current_stock, $new_stock, 'sale', '$mv_reason', {$_SESSION['user_id']})";
                        mysqli_query($conn, $ins_mv);

                        // Notify admin if this product just went out of stock
                        if ($current_stock > 0 && $new_stock == 0) {
                            $pname = mysqli_real_escape_string($conn, $item['product_name']);
                            $note = "Product out of stock: " . $pname;
                            mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $note) . "', 'stock', 0)");
                        }
                    }
                }
            }

            // Update other users' carts and the current session cart to reflect new stock
            foreach ($cart_items as $item) {
                $p_id = intval($item['product_id']);
                $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;

                if ($variant_id) {
                    $v_q = mysqli_query($conn, "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id LIMIT 1");
                    $vrow = $v_q ? mysqli_fetch_assoc($v_q) : null;
                    $new_stock = intval($vrow['stock_quantity'] ?? 0);

                    if ($new_stock <= 0) {
                        mysqli_query($conn, "DELETE FROM cart WHERE product_id = $p_id AND variant_id = $variant_id");
                    } else {
                        mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $p_id AND variant_id = $variant_id");
                    }

                    // Adjust current session cart entries
                    foreach ($_SESSION['cart'] as $k => $ci) {
                        if (isset($ci['variant_id']) && intval($ci['variant_id']) === $variant_id && intval($ci['product_id']) === $p_id) {
                            if ($new_stock <= 0) {
                                unset($_SESSION['cart'][$k]);
                            } elseif ($_SESSION['cart'][$k]['quantity'] > $new_stock) {
                                $_SESSION['cart'][$k]['quantity'] = $new_stock;
                            }
                        }
                    }
                } else {
                    $p_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $p_id LIMIT 1");
                    $prow = $p_q ? mysqli_fetch_assoc($p_q) : null;
                    $new_stock = intval($prow['stock_quantity'] ?? 0);

                    if ($new_stock <= 0) {
                        mysqli_query($conn, "DELETE FROM cart WHERE product_id = $p_id AND (variant_id IS NULL OR variant_id = '')");
                    } else {
                        mysqli_query($conn, "UPDATE cart SET quantity = LEAST(quantity, $new_stock) WHERE product_id = $p_id AND (variant_id IS NULL OR variant_id = '')");
                    }

                    foreach ($_SESSION['cart'] as $k => $ci) {
                        if (intval($ci['product_id']) === $p_id && !isset($ci['variant_id'])) {
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
            }

            // 3. Create Invoice
            $inv_num = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
            
            // Calculate amounts based on payment option
            $amount_paid = 0;
            $balance_due = $total;
            
            if ($payment_method === 'gcash') {
                if ($payment_option === 'full_payment') {
                    $amount_paid = $total;
                    $balance_due = 0;
                } else {
                    // Down payment - calculate 20% of total
                    $amount_paid = $down_payment_amount;
                    $balance_due = $remaining_balance;

?>