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

// Fetch latest product images for cart items
foreach ($selected_keys as $key) {
    if (isset($_SESSION['cart'][$key])) {
        $p_id = intval($_SESSION['cart'][$key]['product_id']);
        $p_q = mysqli_query($conn, "SELECT image_path, image_url FROM products WHERE product_id = $p_id LIMIT 1");
        if ($p_q && $prow = mysqli_fetch_assoc($p_q)) {
            // Always use database values as source of truth
            $_SESSION['cart'][$key]['image_path'] = $prow['image_path'] ?: null;
            $_SESSION['cart'][$key]['image_url'] = $prow['image_url'] ?: null;
            // Update cart_items with the latest image
            $cart_items[$key]['image_path'] = $_SESSION['cart'][$key]['image_path'];
            $cart_items[$key]['image_url'] = $_SESSION['cart'][$key]['image_url'];
        }
    }
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
                    
                    // Resolve variant_id from variants array when variant_id is not provided
                    $variant_id = isset($item['variant_id']) ? intval($item['variant_id']) : null;
                    if (empty($variant_id) && !empty($item['variants']) && is_array($item['variants'])) {
                        foreach ($item['variants'] as $vt => $vv) {
                            $vt_esc = mysqli_real_escape_string($conn, $vt);
                            $vv_esc = mysqli_real_escape_string($conn, $vv);
                            $vid_q = mysqli_query($conn, "SELECT variant_id FROM product_variants WHERE product_id = $p_id AND variant_type = '$vt_esc' AND variant_value = '$vv_esc' LIMIT 1");
                            if ($vid_q && mysqli_num_rows($vid_q) > 0) {
                                $variant_id = intval(mysqli_fetch_assoc($vid_q)['variant_id']);
                                // attach to item for subsequent logic
                                $item['variant_id'] = $variant_id;
                                break;
                            }
                        }
                    }

                    // Insert Item with variants (include resolved variant_id when available)
                if (!empty($variants_json)) {
                    if (!empty($variant_id)) {
                        mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, variant_id, quantity, price, variant_value) VALUES ($order_id, $p_id, $variant_id, $qty, $price, '$variants_json')");
                    } else {
                        mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, quantity, price, variant_value) VALUES ($order_id, $p_id, $qty, $price, '$variants_json')");
                    }
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
                            // Also deduct from parent product stock when variant is used
                            $p_cur_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $p_id LIMIT 1");
                            $p_cur = $p_cur_q ? mysqli_fetch_assoc($p_cur_q) : null;
                            $p_current_stock = intval($p_cur['stock_quantity'] ?? 0);
                            $p_new_stock = $p_current_stock - $qty;
                            if ($p_new_stock < 0) $p_new_stock = 0;
                            mysqli_query($conn, "UPDATE products SET stock_quantity = $p_new_stock WHERE product_id = $p_id");

                            // Ensure price_at_movement column exists
                            $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                            if (mysqli_num_rows($col_check2) === 0) {
                                mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                            }

                            $p_stock_change = $p_new_stock - $p_current_stock; // negative
                            $p_mv_reason = mysqli_real_escape_string($conn, "Sale - Order #" . $order_id . " - " . ($user['full_name'] ?? 'Customer'));
                            $ins_pmv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                            $ins_pmv .= "VALUES ($p_id, " . floatval($p_cur['price'] ?? $price) . ", $p_stock_change, $p_current_stock, $p_new_stock, 'sale', '$p_mv_reason', {$_SESSION['user_id']})";
                            mysqli_query($conn, $ins_pmv);

                            // Notify admin if the parent product just went out of stock
                            if ($p_current_stock > 0 && $p_new_stock == 0) {
                                $pname = mysqli_real_escape_string($conn, $item['product_name']);
                                $notep = "Product out of stock: " . $pname;
                                mysqli_query($conn, "INSERT INTO notifications (user_id, message, type, is_read) VALUES (1, '" . mysqli_real_escape_string($conn, $notep) . "', 'stock', 0)");
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
                }
            } else {
                // Cash on Pickup - no payment yet
                $amount_paid = 0;
                $balance_due = $total;
            }
            
            $q_inv = "INSERT INTO invoices (order_id, invoice_number, payment_status, amount_paid, balance_due, down_payment_due, remaining_balance) 
                      VALUES ($order_id, '$inv_num', '$payment_status', $amount_paid, $balance_due, $down_payment_amount, $remaining_balance)";
            mysqli_query($conn, $q_inv);

            // 4. Handle GCash Receipt
            if ($payment_method === 'gcash' && isset($_FILES['receipt_image'])) {
                $uploadDir = '../assets/uploads/gcash_receipts/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $ext = pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION);
                $newFile = 'receipt_' . $order_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $uploadDir . $newFile)) {
                    $dbPath = 'assets/uploads/gcash_receipts/' . $newFile;
                    mysqli_query($conn, "UPDATE invoices SET payment_proof_path = '$dbPath' WHERE order_id = $order_id");
                }
            }

            mysqli_commit($conn);
            foreach ($selected_keys as $key) unset($_SESSION['cart'][$key]);
            header('Location: orders.php?success=1&order_id=' . $order_id);
            exit();

            if (!empty($error)) {
                mysqli_rollback($conn);
            }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .qr-display-box { max-width: 220px; margin: 0 auto; border: 3px solid #28a745; border-radius: 12px; padding: 10px; background: #fff; }
        .product-img-checkout { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; }
        .payment-card { border-top: 5px solid #28a745; border-radius: 15px !important; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar"><h2>Checkout</h2></div>
        
        <div class="content-area">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="checkoutForm">
                <div class="row g-4">
                    <div class="col-lg-8">
                        
                        <div class="card shadow-sm border-0 mb-4" style="border-radius: 15px;">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0"><i class="bi bi-person me-2 text-success"></i>Customer Information</h5></div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small text-muted">Full Name</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Student ID</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['student_id'] ?? 'N/A'); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Email Address</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted">Phone Number</label>
                                        <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow-sm border-0" style="border-radius: 15px;">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0"><i class="bi bi-cart-check me-2 text-success"></i>Order Items</h5></div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead class="bg-light text-muted small">
                                            <tr>
                                                <th class="ps-3">Product</th>
                                                <th>Price</th>
                                                <th>Qty</th>
                                                <th class="text-end pe-3">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cart_items as $item): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="d-flex align-items-center">
                                                        <?php
                                                            $img = '';
                                                            if (!empty($item['image_path'])) {
                                                                $img = $item['image_path'];
                                                            } elseif (!empty($item['image_url'])) {
                                                                $img = $item['image_url'];
                                                            }

                                                            if (!empty($img)) {
                                                                // Ensure proper path for student context
                                                                if (preg_match('/^(https?:)?\\/\\//i', $img)) {
                                                                    // External URL - use as is
                                                                } elseif (strpos($img, '/assets/') === 0) {
                                                                    // Absolute path from web root - add ../ prefix for student directory
                                                                    $img = '..' . $img;
                                                                } elseif (strpos($img, '../') !== 0 && strpos($img, '/') !== 0) {
                                                                    // Relative path - add ../ prefix
                                                                    $img = '../' . ltrim($img, '/');
                                                                }
                                                            } else {
                                                                $img = '../assets/images/avatar.png';
                                                            }
                                                        ?>
                                                        <img src="<?php echo htmlspecialchars($img); ?>" class="product-img-checkout me-3 border" alt="<?php echo htmlspecialchars($item['product_name']); ?>" onerror="this.src='../assets/images/avatar.png'">
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                            <?php if (!empty($item['variants'])): ?>
                                                                <small class="text-success bg-light px-2 rounded">
                                                                    <?php foreach ($item['variants'] as $type => $val) echo ucfirst($type).": ".$val." "; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo formatCurrency($item['price']); ?></td>
                                                <td><?php echo $item['quantity']; ?></td>
                                                <td class="text-end pe-3 fw-bold"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card shadow-sm payment-card border-0 mb-4">
                            <div class="card-header bg-white pt-3"><h5 class="mb-0">Payment Summary</h5></div>
                            <div class="card-body">
                                
                                <?php if (!$is_down_payment_required_for_cart): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="payment_method" id="pay_cash" value="cash_on_pickup" checked onchange="togglePayment(this.value)">
                                    <label class="form-check-label fw-bold" for="pay_cash">Cash on Pickup</label>
                                </div>
                                <?php endif; ?>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="payment_method" id="pay_gcash" value="gcash" <?php echo $is_down_payment_required_for_cart ? 'checked' : ''; ?> onchange="togglePayment(this.value)">
                                    <label class="form-check-label fw-bold" for="pay_gcash">GCash (QR Scan)</label>
                                </div>

                                <div id="gcash_box" class="<?php echo $is_down_payment_required_for_cart ? '' : 'd-none'; ?> mt-3">
                                    <div class="p-3 bg-light rounded border mb-3">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="payment_option" id="opt_full" value="full_payment" checked>
                                            <label class="form-check-label small fw-bold" for="opt_full text-dark">Full Payment (<?php echo formatCurrency($total); ?>)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="payment_option" id="opt_down" value="down_payment">
                                            <label class="form-check-label small fw-bold text-danger" for="opt_down">Downpayment (<?php echo formatCurrency($down_payment_amount); ?>)</label>
                                        </div>
                                    </div>

                                    <h6 class="text-center text-success mb-2 small fw-bold">SCAN TO PAY VIA GCASH</h6>
                                    <div class="qr-display-box shadow-sm mb-3" id="qrDisplayBox">
                                        <?php if ($gcash_qr_image): ?>
                                            <img src="../<?php echo $gcash_qr_image; ?>?t=<?php echo time(); ?>" class="img-fluid rounded" alt="GCash QR" id="qrImage">
                                        <?php else: ?>
                                            <div class="text-center py-4"><i class="bi bi-qr-code fs-1 text-muted"></i><p class="small text-muted">No QR Available</p></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($gcash_qr_image): ?>
                                    <button type="button" class="btn btn-outline-success btn-sm w-100 mb-3" onclick="downloadQRCode()">
                                        <i class="bi bi-download me-2"></i>Download QR Code
                                    </button>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3 small">
                                        <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                        <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                    </div>

                                    <label class="form-label small fw-bold">Upload GCash Receipt</label>
                                    <input type="file" name="receipt_image" id="receipt_image" class="form-control form-control-sm border-success" accept="image/*" onchange="validateForm()">
                                </div>

                                <hr>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="small">Total Amount:</span> 
                                        <span class="small text-muted"><?php echo formatCurrency($total); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-3">
                                        <span><strong>Amount Due Today:</strong></span> 
                                        <h4 class="text-success mb-0" id="amountDueDisplay"><?php echo formatCurrency($total); ?></h4>
                                    </div>
                                    <div id="paymentSummaryInfo" class="alert alert-info p-2 small mb-3">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <span id="paymentInfoText"></span>
                                    </div>
                                </div>
                                <button type="submit" name="place_order" id="submitBtn" class="btn btn-success w-100 py-3 font-weight-bold shadow-sm" <?php echo $is_down_payment_required_for_cart ? 'disabled' : ''; ?> style="border-radius: 12px;">
                                    PLACE ORDER NOW
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Non-Refundable Downpayment Modal -->
    <div class="modal fade" id="downpaymentWarningModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-warning">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle-fill me-2"></i>Important Notice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning mb-3" role="alert">
                        <strong>Downpayment is Non-Refundable</strong>
                    </div>
                    <p class="mb-3">
                        By selecting the downpayment option, you acknowledge that:
                    </p>
                    <ul class="mb-3">
                        <li>The downpayment of <strong id="downpaymentWarningAmount">₱0.00</strong> is <strong class="text-danger">NOT refundable</strong></li>
                        <li>The remaining balance of <strong id="remainingBalanceWarningAmount">₱0.00</strong> must be paid upon claiming your order</li>
                        <li>If you cancel your order, the downpayment will be forfeited</li>
                    </ul>
                    <p class="small text-muted mb-0">
                        Please ensure you have read and understood these terms before proceeding with the downpayment.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>
                    <button type="button" class="btn btn-warning text-white" data-bs-dismiss="modal" id="acknowledgeDownpayment">I Understand & Accept</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    const totalAmount = <?php echo $total; ?>;
    const downPaymentAmount = <?php echo $down_payment_amount; ?>;
    const remainingBalance = <?php echo $remaining_balance; ?>;

    function downloadQRCode() {
        const qrImage = document.getElementById('qrImage');
        if (!qrImage) {
            alert('QR Code not available');
            return;
        }
        
        const link = document.createElement('a');
        link.href = qrImage.src;
        link.download = 'gcash-qr-code-' + new Date().getTime() + '.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function updatePaymentSummary() {
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
        const paymentOption = document.querySelector('input[name="payment_option"]:checked')?.value;
        const amountDueDisplay = document.getElementById('amountDueDisplay');
        const paymentSummaryInfo = document.getElementById('paymentSummaryInfo');
        const paymentInfoText = document.getElementById('paymentInfoText');
        
        let amountDue = totalAmount;
        let infoText = '';
        let shouldShow = false;
        
        if (paymentMethod === 'gcash') {
            shouldShow = true;
            if (paymentOption === 'down_payment') {
                amountDue = downPaymentAmount;
                infoText = `You are paying ₱${downPaymentAmount.toFixed(2)} as downpayment now. The remaining balance of ₱${remainingBalance.toFixed(2)} must be paid upon claiming your order.`;
            } else if (paymentOption === 'full_payment') {
                amountDue = totalAmount;
                infoText = `You are paying the full amount of ₱${totalAmount.toFixed(2)}. No remaining balance.`;
            }
        }
        
        if (shouldShow) {
            paymentSummaryInfo.style.display = 'block';
            paymentInfoText.textContent = infoText;
        } else {
            paymentSummaryInfo.style.display = 'none';
        }
        
        amountDueDisplay.textContent = '₱' + amountDue.toFixed(2);
    }

    function togglePayment(method) {
        const box = document.getElementById('gcash_box');
        const receipt = document.getElementById('receipt_image');
        if (method === 'gcash') {
            box.classList.remove('d-none');
            receipt.required = true;
        } else {
            box.classList.add('d-none');
            receipt.required = false;
        }
        updatePaymentSummary();
        validateForm();
    }

    // Add event listeners to payment option radio buttons
    document.addEventListener('DOMContentLoaded', function() {
        // Show downpayment warning modal when downpayment is selected
        document.querySelectorAll('input[name="payment_option"]').forEach(option => {
            option.addEventListener('change', function() {
                if (this.value === 'down_payment') {
                    document.getElementById('downpaymentWarningAmount').textContent = '₱' + downPaymentAmount.toFixed(2);
                    document.getElementById('remainingBalanceWarningAmount').textContent = '₱' + remainingBalance.toFixed(2);
                    
                    // Show modal using Bootstrap 5
                    const modalElement = document.getElementById('downpaymentWarningModal');
                    if (modalElement) {
                        const warningModal = new bootstrap.Modal(modalElement);
                        warningModal.show();
                    }
                }
                updatePaymentSummary();
            });
        });
        
        const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
        paymentMethods.forEach(method => {
            method.addEventListener('change', updatePaymentSummary);
        });
        updatePaymentSummary();
    });


    function validateForm() {
        const method = document.querySelector('input[name="payment_method"]:checked').value;
        const receipt = document.getElementById('receipt_image');
        const btn = document.getElementById('submitBtn');
        if (method === 'gcash') {
            btn.disabled = receipt.files.length === 0;
        } else {
            btn.disabled = false;
        }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>