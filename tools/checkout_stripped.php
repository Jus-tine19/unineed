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
            /* TRY_BLOCK_REMOVED */
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
                                                                // Add ../ prefix if not already absolute or relative with ../
                                                                if (!preg_match('/^(https?:)?\\/\\//i', $img) && strpos($img, '/') !== 0 && strpos($img, '../') !== 0) {
                                                                    $img = '../' . ltrim($img, '/');
                                                                }
                                                            } elseif (!empty($item['image_url'])) {
                                                                $img = $item['image_url'];
                                                            } else {
                                                                $img = '../assets/images/no-image.png';
                                                            }
                                                        ?>
                                                        <img src="<?php echo htmlspecialchars($img); ?>" class="product-img-checkout me-3 border" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
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
                infoText = `You are paying ₱${downPaymentAmount.toFixed(2)} as downpayment now. The remaining balance of ₱${remainingBalance.toFixed(2)} will be due when claiming your order.`;
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
        const paymentOptions = document.querySelectorAll('input[name="payment_option"]');
        paymentOptions.forEach(option => {
            option.addEventListener('change', updatePaymentSummary);
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
</body>
</html>