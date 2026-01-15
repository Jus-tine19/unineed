<?php
// Checkout - Place Order & Deduct Stock
require_once '../config/database.php';
requireStudent();

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
    
    $payment_method = clean($_POST['payment_method'] ?? ($is_down_payment_required_for_cart ? 'gcash' : 'cash_on_pickup'));
    $payment_option = clean($_POST['payment_option'] ?? 'down_payment'); 

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
                
                // Insert Item
                mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES ($order_id, $p_id, $qty, $price)");
                
                // Deduct Stock
                mysqli_query($conn, "UPDATE products SET stock_quantity = stock_quantity - $qty WHERE product_id = $p_id");
            }

            // 3. Create Invoice
            $inv_num = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
            $q_inv = "INSERT INTO invoices (order_id, invoice_number, payment_status, down_payment_due, remaining_balance) 
                      VALUES ($order_id, '$inv_num', '$payment_status', $down_payment_amount, $remaining_balance)";
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

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Transaction failed: " . $e->getMessage();
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
                                                            $img = !empty($item['image_path']) ? '../' . $item['image_path'] : '../assets/images/no-image.png';
                                                        ?>
                                                        <img src="<?php echo $img; ?>" class="product-img-checkout me-3 border">
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
                                    <div class="qr-display-box shadow-sm mb-3">
                                        <?php if ($gcash_qr_image): ?>
                                            <img src="../<?php echo $gcash_qr_image; ?>?t=<?php echo time(); ?>" class="img-fluid rounded" alt="GCash QR">
                                        <?php else: ?>
                                            <div class="text-center py-4"><i class="bi bi-qr-code fs-1 text-muted"></i><p class="small text-muted">No QR Available</p></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3 small">
                                        <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                        <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                    </div>

                                    <label class="form-label small fw-bold">Upload GCash Receipt</label>
                                    <input type="file" name="receipt_image" id="receipt_image" class="form-control form-control-sm border-success" accept="image/*" onchange="validateForm()">
                                </div>

                                <hr>
                                <div class="d-flex justify-content-between mb-3"><span>Total Due:</span> <h4 class="text-success mb-0"><?php echo formatCurrency($total); ?></h4></div>
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

    <script>
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
        validateForm();
    }

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
</body>
</html>