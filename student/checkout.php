// unineed/student/checkout.php - Full Content

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

// NEW LOGIC: Check if any item in the cart requires a down payment
$is_down_payment_required_for_cart = false;
foreach ($cart_items as $item) {
    if (isset($item['requires_down_payment']) && $item['requires_down_payment']) {
        $is_down_payment_required_for_cart = true;
        break;
    }
}


// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    
    // Determine payment method and option based on cart requirement
    if ($is_down_payment_required_for_cart) {
        $payment_method = 'gcash'; // Forced to GCash
        $payment_option = clean($_POST['payment_option'] ?? 'down_payment'); // Will be down_payment or full_payment
    } else {
        $payment_method = clean($_POST['payment_method'] ?? 'cash_on_pickup');
        // If not required, GCash payment means full payment now. Cash means full payment on claim.
        $payment_option = $payment_method === 'gcash' ? clean($_POST['payment_option'] ?? 'full_payment') : 'full_payment';
    }


    // *** VALIDATION LOGIC ***
    if ($is_down_payment_required_for_cart && $payment_method === 'cash_on_pickup') {
         $error = "Cash on Claim is not allowed for this order as it contains item(s) requiring an upfront down payment. Please use GCash.";
         goto skip_db_transaction;
    }
    // *** END VALIDATION LOGIC ***

    // Determine order status and payment status based on payment option and method
    if ($payment_option === 'full_payment') {
        $amount_paid = $total;
        $order_status_for_db = 'pending'; 
        // If GCash, wait for proof. If cash, it's paid on claim (treated as fully paid for status).
        $payment_status_for_db = $payment_method === 'gcash' ? 'pending_proof' : 'fully_paid'; 
        $is_downpayment = false;
        
    } else {
        // Down payment selected (only possible if payment_method is gcash AND down payment is required for cart)
        $amount_paid = $down_payment_amount;
        $is_downpayment = true;

        if ($payment_method === 'gcash') {
            $order_status_for_db = 'pending_payment'; // Wait for GCash proof
            $payment_status_for_db = 'pending_proof';
        } else {
            // Should not happen due to validation/UI, but defaults to standard cash payment flow
            $order_status_for_db = 'pending'; 
            $payment_status_for_db = 'unpaid'; 
        }
    }

    // Begin atomic transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Create order record
        $query = "INSERT INTO orders (user_id, total_amount, payment_method, order_status) 
                 VALUES ($user_id, $total, '$payment_method', '$order_status_for_db')";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to create order: " . mysqli_error($conn));
        }
        
        $order_id = mysqli_insert_id($conn);
        
        // Process each cart item and deduct stock
        foreach ($_SESSION['cart'] as $item) {
            $product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            $price = floatval($item['price']);
            $variants = isset($item['variants']) ? $item['variants'] : [];

            // Find variant_id if product has variants (Logic block unchanged)
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
        
        // Create invoice record. 
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
        
        // Clear cart
        $_SESSION['cart'] = [];

        // REDIRECTION: Redirect to a dedicated Gcash confirmation page if GCash payment is selected
        if ($payment_method === 'gcash') {
             // Directs to order details with instruction flag
             header('Location: orders.php?id=' . $order_id . '&payment_needed=gcash');
        } else {
             // Standard redirect for cash/paid orders
             header('Location: orders.php?success=1&order_id=' . $order_id);
        }
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to place order: " . $e->getMessage();
    }
// --- Label to jump to for validation failure
skip_db_transaction:
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
                                    <?php if ($is_down_payment_required_for_cart): ?>
                                        <p class="small text-danger mt-2 mb-1">
                                            **NOTE:** This order requires a minimum **<?php echo $down_payment_rate * 100; ?>% (<?php echo formatCurrency($down_payment_amount); ?>)** payment upfront via **GCash**.
                                        </p>
                                    <?php endif; ?>
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
                                
                                <?php $gcash_default_checked = $is_down_payment_required_for_cart ? 'checked' : ''; ?>
                                
                                <?php if (!$is_down_payment_required_for_cart): ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="radio" name="payment_method" id="cashOnPickup" value="cash_on_pickup" checked onchange="togglePaymentDetails(this.value)">
                                        <label class="form-check-label fw-bold" for="cashOnPickup">
                                            Cash (Pay Full Amount on Claim)
                                        </label>
                                    </div>
                                    <?php $gcash_default_checked = ''; // Ensure cash is checked if available ?>
                                <?php else: ?>
                                     <div class="alert alert-danger small mb-3">
                                         <i class="bi bi-exclamation-triangle me-2"></i>
                                         Only **GCash** is available. This order requires a down payment.
                                     </div>
                                <?php endif; ?>

                                <div class="form-check mb-3 <?php echo $is_down_payment_required_for_cart ? '' : 'mt-3'; ?>">
                                    <input class="form-check-input" type="radio" name="payment_method" id="gcash" value="gcash" <?php echo $is_down_payment_required_for_cart ? 'checked' : $gcash_default_checked; ?> onchange="togglePaymentDetails(this.value)">
                                    <label class="form-check-label fw-bold" for="gcash">
                                        GCash (Pay Now)
                                    </label>
                                </div>
                                
                                <hr>
                                
                                <div id="cashDetails" class="payment-details-box <?php echo $is_down_payment_required_for_cart || $gcash_default_checked ? 'd-none' : ''; ?>">
                                    <h6 class="text-primary">Cash Payment Details (Full Amount Due on Claim)</h6>
                                    <p class="small mb-1">
                                        You will pay the **full amount** of **<?php echo formatCurrency($total); ?>** when you pick up your order.
                                    </p>
                                </div>

                                <div id="gcashDetails" class="payment-details-box <?php echo $is_down_payment_required_for_cart || $gcash_default_checked ? '' : 'd-none'; ?>">
                                    <h6 class="text-success">GCash Payment Details</h6>
                                    
                                    <div class="alert alert-success p-2 small">
                                        <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                        <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                    </div>

                                    <?php if ($is_down_payment_required_for_cart): ?>
                                        <p class="small mb-1">Please select your payment option below:</p>
                                        <p class="small text-danger mb-2">
                                            **IMPORTANT:** Your order status will be 'Pending Payment' until an admin verifies your GCash transaction proof.
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
                                    <?php else: ?>
                                        <p class="small mb-1">
                                            You will pay the **full amount** of **<?php echo formatCurrency($total); ?>** via GCash before your order is processed.
                                        </p>
                                        <p class="small text-danger mb-2">
                                            **IMPORTANT:** Your order status will be 'Pending Payment' until an admin verifies your GCash transaction proof.
                                        </p>
                                        <input type="hidden" name="payment_option" value="full_payment">
                                    <?php endif; ?>
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
        const isDownPaymentRequired = <?php echo $is_down_payment_required_for_cart ? 'true' : 'false'; ?>;
        
        function togglePaymentDetails(method) {
            const cashDetails = document.getElementById('cashDetails');
            const gcashDetails = document.getElementById('gcashDetails');
            
            if (method === 'cash_on_pickup') {
                if(cashDetails) cashDetails.classList.remove('d-none');
                if (gcashDetails) gcashDetails.classList.add('d-none'); 

            } else if (method === 'gcash') {
                if (cashDetails) cashDetails.classList.add('d-none');
                if(gcashDetails) gcashDetails.classList.remove('d-none');
            }
        }
        
        // Initial call to set state correctly
        document.addEventListener('DOMContentLoaded', () => {
             // Re-check selected method on load
             const selectedMethod = document.querySelector('input[name="payment_method"]:checked').value;
             togglePaymentDetails(selectedMethod);
        });
    </script>
</body>
</html>