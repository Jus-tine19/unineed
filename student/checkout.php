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
$down_payment_rate = DOWN_PAYMENT_PERCENTAGE; // Use constant from database.php
$down_payment_amount = round($total * $down_payment_rate, 2);
$remaining_balance = $total - $down_payment_amount;

$gcash_number = GCASH_NUMBER;
$gcash_name = GCASH_NAME;

// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    
    $payment_method = clean($_POST['payment_method'] ?? 'cash_on_pickup');
    $payment_option = clean($_POST['payment_option'] ?? 'down_payment'); // 'down_payment' or 'full_payment'

    // Determine total amount due now
    if ($payment_option === 'full_payment') {
        $amount_paid = $total;
        $order_status_for_db = 'pending'; // Start processing immediately (full payment handled on pickup or gcash proof)
        $payment_status_for_db = $payment_method === 'gcash' ? 'pending_proof' : 'paid'; // 'paid' is best we can do for cash on pickup/full payment
        $is_downpayment = false;
    } else {
        $amount_paid = $down_payment_amount;
        $order_status_for_db = 'pending'; // Order starts processing (requires only 20% down)
        $payment_status_for_db = $payment_method === 'gcash' ? 'pending_proof' : 'unpaid'; // 'unpaid' means down payment required on pickup, 'pending_proof' means required now for gcash
        $is_downpayment = true;
    }

    // Begin atomic transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Validation for GCash Down Payment: Require Proof Upload (Simulated Check)
        if ($payment_method === 'gcash') {
            // For GCash, we change the order status to awaiting payment verification
            $order_status_for_db = 'pending_payment'; // New status to halt processing until admin confirms payment
        }


        // Create order record
        // The total_amount column still stores the full order total.
        $query = "INSERT INTO orders (user_id, total_amount, payment_method, order_status) 
                 VALUES ($user_id, $total, '$payment_method', '$order_status_for_db')";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to create order");
        }
        
        $order_id = mysqli_insert_id($conn);
        
        // Process each cart item and deduct stock
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

            // Decrement product base stock immediately after order creation
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
        
        // Create invoice record. We will use `payment_status` to store the partial payment state.
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        $query = "INSERT INTO invoices (order_id, invoice_number, payment_status, down_payment_due, remaining_balance) 
                 VALUES ($order_id, '$invoice_number', '$payment_status_for_db', $down_payment_amount, $remaining_balance)";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to create invoice: " . mysqli_error($conn));
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
                    <div class="col-md-8">
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
                                    <strong>Total Amount Due</strong>
                                    <strong class="text-primary fs-4"><?php echo formatCurrency($total); ?></strong>
                                </div>

                                <div class="alert alert-warning mb-3">
                                    <h6 class="mb-1"><i class="bi bi-wallet2 me-2"></i>Payment Requirement</h6>
                                    <div class="d-flex justify-content-between mt-2">
                                        <small>20% Down Payment (Min.)</small>
                                        <strong class="text-danger"><?php echo formatCurrency($down_payment_amount); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <small>Remaining Balance</small>
                                        <strong class="text-muted"><?php echo formatCurrency($remaining_balance); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-wallet me-2"></i>Select Payment</h5>
                            </div>
                            <div class="card-body">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="payment_method" id="cashOnPickup" value="cash_on_pickup" checked onchange="togglePaymentDetails(this.value)">
                                    <label class="form-check-label fw-bold" for="cashOnPickup">
                                        Cash on Pickup (Pay Down Payment on Claim)
                                    </label>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="payment_method" id="gcash" value="gcash" onchange="togglePaymentDetails(this.value)">
                                    <label class="form-check-label fw-bold" for="gcash">
                                        GCash (Pay Down Payment Now)
                                    </label>
                                </div>
                                
                                <hr>
                                
                                <div id="cashDetails" class="payment-details-box">
                                    <h6 class="text-primary">Cash on Pickup</h6>
                                    <p class="small mb-1">
                                        You are required to pay the **<?php echo formatCurrency($down_payment_amount); ?>** down payment when you claim your order.
                                    </p>
                                    <p class="small text-muted mb-0">
                                        The remaining balance of **<?php echo formatCurrency($remaining_balance); ?>** is also paid upon claiming.
                                    </p>
                                </div>

                                <div id="gcashDetails" class="payment-details-box d-none">
                                    <h6 class="text-success">GCash Payment Details (20% Down Payment: <?php echo formatCurrency($down_payment_amount); ?>)</h6>
                                    <p class="small mb-1">Please pay at least the 20% down payment (<?php echo formatCurrency($down_payment_amount); ?>) via GCash.</p>
                                    <div class="alert alert-success p-2 small">
                                        <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                        <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                    </div>
                                    <p class="small text-danger mb-2">
                                        **IMPORTANT:** Your order will only be processed after an admin verifies your GCash payment proof.
                                    </p>

                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_option" id="downPaymentOption" value="down_payment" checked>
                                        <label class="form-check-label small" for="downPaymentOption">
                                            Pay Down Payment (<?php echo formatCurrency($down_payment_amount); ?>)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_option" id="fullPaymentOption" value="full_payment">
                                        <label class="form-check-label small" for="fullPaymentOption">
                                            Pay Full Amount (<?php echo formatCurrency($total); ?>)
                                        </label>
                                    </div>
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
    <script>
        function togglePaymentDetails(method) {
            const cashDetails = document.getElementById('cashDetails');
            const gcashDetails = document.getElementById('gcashDetails');
            
            if (method === 'cash_on_pickup') {
                cashDetails.classList.remove('d-none');
                gcashDetails.classList.add('d-none');
                // Ensure Cash on Pickup defaults to down payment logic
                document.getElementById('downPaymentOption').checked = true;
                document.getElementById('fullPaymentOption').disabled = true;
            } else if (method === 'gcash') {
                cashDetails.classList.add('d-none');
                gcashDetails.classList.remove('d-none');
                document.getElementById('fullPaymentOption').disabled = false;
            }
        }
        
        // Initial call to set state correctly
        document.addEventListener('DOMContentLoaded', () => {
             // For Cash on Pickup, force selection to down payment
             document.getElementById('fullPaymentOption').disabled = true;
             togglePaymentDetails(document.querySelector('input[name="payment_method"]:checked').value);
        });
    </script>
</body>
</html>