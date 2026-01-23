<?php

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
        // Don't show success message for quantity updates - it's too frequent
        // Just redirect to avoid form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
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
                <form method="POST" action="checkout.php" id="checkout-form">
                <div class="row g-4">
                    <!-- Cart Items -->
                    <div class="col-md-8">
                        <div class="card" style="border: none; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border-radius: 12px;">
                            <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; border: none; padding: 1.5rem;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fw-bold" style="font-size: 1.2rem;">
                                        <i class="bi bi-bag-check me-2"></i>Cart Items (<?php echo $total_items; ?>)
                                    </h5>
                                    <form method="POST" style="display: inline;">
                                        <button type="submit" name="clear_cart" class="btn btn-sm" style="background-color: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); font-weight: 600; transition: all 0.3s ease;" onclick="return confirm('Clear all items from cart?')" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.3)'" onmouseout="this.style.backgroundColor='rgba(255,255,255,0.2)'">
                                            <i class="bi bi-trash me-1"></i>Clear Cart
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($cart_items as $key => $item): ?>
                                    <div class="cart-item" style="border-bottom: 1px solid #f0f0f0; padding: 1.5rem; transition: all 0.3s ease;" onmouseover="this.style.backgroundColor='#f9f9f9'" onmouseout="this.style.backgroundColor='white'">
                                        <div class="row align-items-center">
                                            <div class="col-auto">
                                                <input type="checkbox" name="selected_items[]" value="<?php echo htmlspecialchars($key); ?>" class="form-check-input cart-checkbox" checked data-price="<?php echo $item['price']; ?>" data-quantity="<?php echo $item['quantity']; ?>" style="width: 20px; height: 20px; cursor: pointer;">
                                            </div>
                                            <div class="col-md-2">
                                                <?php if ($item['image_path']): ?>
                                                    <?php
                                                        $cartImg = $item['image_path'];
                                                        if (!preg_match('/^(https?:)?\\/\\//i', $cartImg) && strpos($cartImg, '/') !== 0) {
                                                            $cartImg = '../' . ltrim($cartImg, '/');
                                                        }
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($cartImg); ?>" alt="Product" class="img-fluid rounded" style="max-height: 90px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                                <?php else: ?>
                                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 90px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                                        <i class="bi bi-image text-muted fs-2"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4">
                                                <h6 class="mb-2 fw-bold" style="color: #2c3e50; font-size: 1rem;"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                                <?php if (!empty($item['variants'])): ?>
                                                    <small class="text-muted d-block mb-2" style="line-height: 1.6;">
                                                        <?php foreach ($item['variants'] as $type => $value): ?>
                                                            <span style="background: #f0f0f0; padding: 0.25rem 0.75rem; border-radius: 4px; display: inline-block; margin-right: 0.5rem; margin-bottom: 0.25rem;">
                                                                <strong><?php echo htmlspecialchars(ucfirst($type)); ?></strong>: <?php echo htmlspecialchars($value); ?>
                                                            </span><br>
                                                        <?php endforeach; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <p class="text-success fw-bold mb-0" style="font-size: 1.1rem;">₱<?php echo number_format($item['price'], 2); ?></p>
                                            </div>
                                            <div class="col-md-3">
                                                <form method="POST" class="d-flex align-items-center gap-2">
                                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                                    <button type="button" class="btn btn-outline-secondary" style="width: 38px; height: 38px; padding: 0; border-radius: 6px;" onclick="this.nextElementSibling.stepDown(); this.form.submit();">
                                                        <i class="bi bi-dash"></i>
                                                    </button>
                                                    <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" max="99" class="form-control form-control-sm text-center fw-bold" style="width: 60px; border-radius: 6px; height: 38px;" readonly>
                                                    <button type="button" class="btn btn-outline-secondary" style="width: 38px; height: 38px; padding: 0; border-radius: 6px;" onclick="this.previousElementSibling.stepUp(); this.form.submit();">
                                                        <i class="bi bi-plus"></i>
                                                    </button>
                                                    <button type="submit" name="update_quantity" class="btn btn-sm btn-primary d-none">Update</button>
                                                </form>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <p class="fw-bold mb-2" style="font-size: 1.1rem; color: #2c3e50;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></p>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="cart_key" value="<?php echo htmlspecialchars($key); ?>">
                                                    <button type="submit" name="remove_item" class="btn btn-sm" style="background-color: #FF6B6B; color: white; border: none; border-radius: 6px; padding: 0.5rem 0.75rem; font-weight: 600; transition: all 0.3s ease;" onclick="return confirm('Remove this item?')" onmouseover="this.style.backgroundColor='#FF5252'" onmouseout="this.style.backgroundColor='#FF6B6B'">
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
                                
                                <hr>
                                <div class="d-flex justify-content-between mb-3">
                                    <strong>Total</strong>
                                    <strong class="text-primary fs-4" id="total-amount"><?php echo formatCurrency($total); ?></strong>
                                </div>
                                
                                <button type="submit" class="btn btn-success btn-lg w-100 mb-2">
                                    <i class="bi bi-cart-check me-2"></i>Proceed to Checkout
                                </button>
                                
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
                </form>
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

        // Update total when checkboxes change
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.cart-checkbox:checked').forEach(cb => {
                const price = parseFloat(cb.getAttribute('data-price'));
                const quantity = parseInt(cb.getAttribute('data-quantity'));
                total += price * quantity;
            });
            document.getElementById('total-amount').textContent = '₱' + total.toFixed(2);
        }

        document.querySelectorAll('.cart-checkbox').forEach(cb => {
            cb.addEventListener('change', updateTotal);
        });

        // Cart quantity +/- buttons - prevent double submission
        let isSubmitting = false;

        document.querySelectorAll('.qty-decrease-cart').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (isSubmitting) return; // Prevent double submission
                
                const form = this.closest('.quantity-form');
                const input = form.querySelector('.quantity-input');
                let val = parseInt(input.value) || 1;
                
                if (val > 1) {
                    val = val - 1;
                    input.value = val;
                    isSubmitting = true;
                    btn.disabled = true;
                    form.submit();
                }
            });
        });

        document.querySelectorAll('.qty-increase-cart').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (isSubmitting) return; // Prevent double submission
                
                const form = this.closest('.quantity-form');
                const input = form.querySelector('.quantity-input');
                let val = parseInt(input.value) || 1;
                const max = parseInt(input.max) || 99;
                
                if (val < max) {
                    val = val + 1;
                    input.value = val;
                    isSubmitting = true;
                    btn.disabled = true;
                    form.submit();
                }
            });
        });

        // Initial update
        updateTotal();
    </script>
</body>
</html>