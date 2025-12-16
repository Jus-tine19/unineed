<?php
// Checkout - Place Order & Deduct Stock
require_once '../config/database.php';
requireStudent();

// Redirect if cart empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // Sum cart total
    $total_amount = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total_amount += $item['price'] * $item['quantity'];
    }
    
    // Begin atomic transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Create order record
        $query = "INSERT INTO orders (user_id, total_amount, payment_method, order_status) 
                 VALUES ($user_id, $total_amount, 'cash', 'pending')";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to create order");
        }
        
        $order_id = mysqli_insert_id($conn);
        
        // Process each cart item
        foreach ($_SESSION['cart'] as $item) {
            $product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            $price = floatval($item['price']);
            $variants = isset($item['variants']) ? $item['variants'] : [];

            // Find variant_id if product has variants
            $variant_id = null;
            $variant_value_stored = null;
            if (!empty($variants)) {
                $variant_where = "product_id = $product_id";
                foreach ($variants as $v_type => $v_val) {
                    $variant_value_stored = $v_val;
                    $v_type_esc = mysqli_real_escape_string($conn, $v_type);
                    $v_val_esc = mysqli_real_escape_string($conn, $v_val);
                    $variant_where .= " AND variant_type = '$v_type_esc' AND variant_value = '$v_val_esc'";
                }
                $variant_query = "SELECT variant_id FROM product_variants WHERE $variant_where LIMIT 1";
                $var_result = mysqli_query($conn, $variant_query);
                if ($var_result && $var_row = mysqli_fetch_assoc($var_result)) {
                    $variant_id = intval($var_row['variant_id']);
                }
            }

            // Insert order item with variant info
            $variant_id_str = $variant_id !== null ? $variant_id : 'NULL';
            $variant_value_str = $variant_value_stored !== null ? "'" . mysqli_real_escape_string($conn, $variant_value_stored) . "'" : 'NULL';
            
            $query = "INSERT INTO order_items (order_id, product_id, quantity, price, variant_id, variant_value) 
                     VALUES ($order_id, $product_id, $quantity, $price, $variant_id_str, $variant_value_str)";
            
            if (!mysqli_query($conn, $query)) {
                throw new Exception("Failed to insert order item: " . mysqli_error($conn));
            }

            // Decrement product base stock
            $upd = "UPDATE products SET stock_quantity = stock_quantity - $quantity WHERE product_id = $product_id";
            $res = mysqli_query($conn, $upd);
            if (!$res) {
                throw new Exception("Failed to update product stock: " . mysqli_error($conn));
            }

            // Decrement variant stock (if applicable)
            if (!empty($variants)) {
                foreach ($variants as $variant_type => $variant_value) {
                    $variant_type = mysqli_real_escape_string($conn, $variant_type);
                    $variant_value = mysqli_real_escape_string($conn, $variant_value);
                    
                    $var_upd = "UPDATE product_variants SET stock_quantity = stock_quantity - $quantity 
                               WHERE product_id = $product_id AND variant_type = '$variant_type' AND variant_value = '$variant_value'";
                    
                    $var_res = mysqli_query($conn, $var_upd);
                    if (!$var_res) {
                        throw new Exception("Failed to update variant stock: " . mysqli_error($conn));
                    }
                }
            }
        }
        
        // Create invoice record
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        $query = "INSERT INTO invoices (order_id, invoice_number, payment_status) 
                 VALUES ($order_id, '$invoice_number', 'unpaid')";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to create invoice");
        }
        
        // Notify admin of new order
        $message = "New order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " from " . $_SESSION['full_name'];
        $query = "INSERT INTO notifications (user_id, message, type) 
                 SELECT user_id, '$message', 'new_order' FROM users WHERE user_type = 'admin'";
        mysqli_query($conn, $query);
        
        // Commit all changes
        mysqli_commit($conn);
        
        // Clear cart & redirect
        $_SESSION['cart'] = [];
        header('Location: orders.php?success=1&order_id=' . $order_id);
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to place order: " . $e->getMessage();
    }
}

// Get user details
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Calculate cart summary
$cart_items = $_SESSION['cart'];
$subtotal = 0;
$total_items = 0;

foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_items += $item['quantity'];
}

$total = $subtotal;
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
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Checkout</h2>
        </div>
        
        <div class="content-area">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="row g-4">
                    <!-- Order Details -->
                    <div class="col-md-8">
                        <!-- Customer Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-person me-2"></i>Customer Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['phone']); ?>" readonly>
                                    </div>
                                    <?php if ($user['student_id']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">Student ID</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['student_id']); ?>" readonly>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($user['college']): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">College</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['college']); ?>" readonly>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Items -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-cart-check me-2"></i>Order Items</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th>Price</th>
                                                <th>Quantity</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cart_items as $item): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if ($item['image_path']): ?>
                                                                <?php
                                                                    $img = $item['image_path'];
                                                                    if (!preg_match('/^(https?:)?\\/\\//i', $img) && strpos($img, '/') !== 0) {
                                                                        $img = '../' . ltrim($img, '/');
                                                                    }
                                                                ?>
                                                                <img src="<?php echo htmlspecialchars($img); ?>" alt="Product" class="rounded me-2" style="width: 50px; height: 50px; object-fit: cover;">
                                                            <?php endif; ?>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                                <?php if (!empty($item['variants'])): ?>
                                                                    <br><small class="text-muted">
                                                                        <?php foreach ($item['variants'] as $type => $value): ?>
                                                                            <?php echo htmlspecialchars(ucfirst($type)); ?>: <?php echo htmlspecialchars($value); ?>
                                                                        <?php endforeach; ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo formatCurrency($item['price']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Summary -->
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Order Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal (<?php echo $total_items; ?> items)</span>
                                    <span><?php echo formatCurrency($subtotal); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shipping</span>
                                    <span class="text-success">FREE</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between mb-3">
                                    <strong>Total</strong>
                                    <strong class="text-primary fs-4"><?php echo formatCurrency($total); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-cash me-2"></i>Payment Method</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Cash on Pickup</strong>
                                    <p class="mb-0 mt-2 small">You will pay when you pick up your order. Please bring the exact amount.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="place_order" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle me-2"></i>Place Order
                            </button>
                            <a href="cart.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Cart
                            </a>
                        </div>
                        
                        <div class="alert alert-warning mt-3 small">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            By placing this order, you agree to our terms and conditions.
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>