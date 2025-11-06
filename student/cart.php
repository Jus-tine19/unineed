<?php
// student/cart.php
require_once '../config/database.php';
requireStudent();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle Remove from Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $cart_key = clean($_POST['cart_key']);
    if (isset($_SESSION['cart'][$cart_key])) {
        unset($_SESSION['cart'][$cart_key]);
        $success = "Item removed from cart!";
    }
}

// Handle Update Quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantity'])) {
    $cart_key = clean($_POST['cart_key']);
    $quantity = intval($_POST['quantity']);
    
    if (isset($_SESSION['cart'][$cart_key]) && $quantity > 0) {
        $_SESSION['cart'][$cart_key]['quantity'] = $quantity;
        $success = "Quantity updated!";
    }
}

// Handle Clear Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    $success = "Cart cleared!";
}

// Calculate totals
$cart_items = $_SESSION['cart'];
$subtotal = 0;
$total_items = 0;

foreach ($cart_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_items += $item['quantity'];
}

$total = $subtotal; // No tax or shipping for now
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/cart.css">
</head>
<body class="cart-page">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Shopping Cart</h2>
            <div class="ms-auto">
                <a href="products.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                </a>
            </div>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($cart_items)): ?>
                <div class="row g-4">
                    <!-- Cart Items -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Cart Items (<?php echo $total_items; ?>)</h5>
                                <form method="POST" style="display: inline;">
                                    <button type="submit" name="clear_cart" class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear all items from cart?')">
                                        <i class="bi bi-trash me-1"></i>Clear Cart
                                    </button>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($cart_items as $key => $item): ?>
                                    <div class="cart-item border-bottom p-3">
                                        <div class="row align-items-center">
                                            <div class="col-md-2">
                                                <?php if ($item['image_path']): ?>
                                                    <?php
                                                        $cartImg = $item['image_path'];
                                                        if (!preg_match('/^(https?:)?\\/\\//i', $cartImg) && strpos($cartImg, '/') !== 0) {
                                                            $cartImg = '../' . ltrim($cartImg, '/');
                                                        }
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($cartImg); ?>" alt="Product" class="img-fluid rounded" style="max-height: 80px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 80px;">
                                                        <i class="bi bi-image text-muted fs-2"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                                <?php if (!empty($item['variants'])): ?>
                                                    <small class="text-muted">
                                                        <?php foreach ($item['variants'] as $type => $value): ?>
                                                            <?php echo htmlspecialchars(ucfirst($type)); ?>: <?php echo htmlspecialchars($value); ?><br>
                                                        <?php endforeach; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <p class="text-success fw-bold mb-0"><?php echo formatCurrency($item['price']); ?></p>
                                            </div>
                                            <div class="col-md-3">
                                                <form method="POST" class="d-flex align-items-center gap-2">
                                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.nextElementSibling.stepDown(); this.form.submit();">
                                                        <i class="bi bi-dash"></i>
                                                    </button>
                                                    <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" max="99" class="form-control form-control-sm text-center" style="width: 60px;" readonly>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.previousElementSibling.stepUp(); this.form.submit();">
                                                        <i class="bi bi-plus"></i>
                                                    </button>
                                                    <button type="submit" name="update_quantity" class="btn btn-sm btn-primary d-none">Update</button>
                                                </form>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <p class="fw-bold mb-2"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></p>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                                    <button type="submit" name="remove_item" class="btn btn-sm btn-danger" onclick="return confirm('Remove this item?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Summary -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Order Summary</h5>
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
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <small><strong>Payment Method:</strong> Cash on Pickup</small>
                                </div>
                                
                                <form method="POST" action="checkout.php">
                                    <button type="submit" class="btn btn-success btn-lg w-100 mb-2">
                                        <i class="bi bi-cart-check me-2"></i>Proceed to Checkout
                                    </button>
                                </form>
                                
                                <a href="products.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                                </a>
                            </div>
                        </div>
                        
                        <!-- Info Card -->
                        <div class="card mt-3">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-shield-check me-2 text-success"></i>Safe & Secure</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Pay cash on pickup</li>
                                    <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Order tracking</li>
                                    <li class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>Customer support</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-cart-x"></i>
                    <h5>Your Cart is Empty</h5>
                    <p>Looks like you haven't added anything to your cart yet.</p>
                    <a href="products.php" class="btn btn-primary">
                        <i class="bi bi-shop me-2"></i>Start Shopping
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        // Handle quantity updates
        document.querySelectorAll('.btn-outline-secondary').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const form = this.closest('form');
                const input = form.querySelector('input[name="quantity"]');
                const currentVal = parseInt(input.value);
                
                if (this.querySelector('.bi-plus')) {
                    input.value = currentVal + 1;
                } else if (this.querySelector('.bi-dash') && currentVal > 1) {
                    input.value = currentVal - 1;
                }
                
                // Remove readonly to allow form submission
                input.removeAttribute('readonly');
                form.querySelector('[name="update_quantity"]').click();
                input.setAttribute('readonly', 'readonly');
            });
        });
    </script>
</body>
</html>