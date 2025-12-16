<?php

require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];

// Check for success message
$success = isset($_GET['success']) && $_GET['success'] == 1;
$new_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;

// Get active orders (not completed or cancelled)
$query = "SELECT * FROM orders 
          WHERE user_id = $user_id 
          AND order_status NOT IN ('completed', 'cancelled')
          ORDER BY order_date DESC";
$orders = mysqli_query($conn, $query);

// If specific order ID is provided, get order details
$order_details = null;
if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    $query = "SELECT o.*, i.invoice_number, i.payment_status 
              FROM orders o 
              LEFT JOIN invoices i ON o.order_id = i.order_id
              WHERE o.order_id = $order_id AND o.user_id = $user_id";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $order_details = mysqli_fetch_assoc($result);
        
        // Get order items
        $items_query = "SELECT oi.*, p.product_name, p.image_url, p.image_path 
                       FROM order_items oi 
                       JOIN products p ON oi.product_id = p.product_id 
                       WHERE oi.order_id = $order_id";
        $order_items = mysqli_query($conn, $items_query);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $order_details ? 'Order Details' : 'My Orders'; ?> - UniNeeds</title>
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
            <h2><?php echo $order_details ? 'Order Details' : 'My Orders'; ?></h2>
            <?php if ($order_details): ?>
                <div class="ms-auto">
                    <a href="orders.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Orders
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="content-area">
            <?php if ($success && $new_order_id): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <h5 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Order Placed Successfully!</h5>
                    <p>Your order #<?php echo str_pad($new_order_id, 6, '0', STR_PAD_LEFT); ?> has been placed successfully. We'll notify you when it's ready for pickup.</p>
                    <hr>
                    <a href="orders.php?id=<?php echo $new_order_id; ?>" class="btn btn-sm btn-success">View Order Details</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($order_details): ?>
                <!-- Order Details View -->
                <div class="row g-4">
                    <div class="col-md-8">
                        <!-- Order Info Card -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Order #<?php echo str_pad($order_details['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                    <?php
                                    $badge_class = [
                                        'pending' => 'warning',
                                        'ready for pickup' => 'info',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class[$order_details['order_status']]; ?> fs-6">
                                        <?php echo ucfirst($order_details['order_status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Order Date:</strong> <?php echo date('F j, Y g:i A', strtotime($order_details['order_date'])); ?></p>
                                        <p class="mb-2"><strong>Payment Method:</strong> <?php echo ucfirst($order_details['payment_method']); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Total Amount:</strong> <span class="text-success fs-5"><?php echo formatCurrency($order_details['total_amount']); ?></span></p>
                                        <?php if ($order_details['invoice_number']): ?>
                                            <p class="mb-2"><strong>Invoice:</strong> <?php echo htmlspecialchars($order_details['invoice_number']); ?></p>
                                            <p class="mb-2"><strong>Payment Status:</strong> 
                                                <span class="badge bg-<?php echo $order_details['payment_status'] === 'paid' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($order_details['payment_status']); ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Order Status Timeline -->
                                <div class="mt-4">
                                    <h6 class="mb-3">Order Status</h6>
                                    <div class="progress" style="height: 30px;">
                                        <?php
                                        $status_progress = [
                                            'pending' => 33,
                                            'ready for pickup' => 66,
                                            'completed' => 100,
                                            'cancelled' => 100
                                        ];
                                        $progress = $status_progress[$order_details['order_status']];
                                        ?>
                                        <div class="progress-bar bg-<?php echo $badge_class[$order_details['order_status']]; ?>" 
                                             style="width: <?php echo $progress; ?>%">
                                            <?php echo ucfirst($order_details['order_status']); ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2 small text-muted">
                                        <span>Pending</span>
                                        <span>Ready for Pickup</span>
                                        <span>Completed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Items -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Order Items</h5>
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
                                            <?php while ($item = mysqli_fetch_assoc($order_items)): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php 
                                                            $img = $item['image_path'] ?? $item['image_url'];
                                                            if ($img):
                                                                if (!preg_match('/^(https?:)?\\/\\//i', $img) && strpos($img, '/') !== 0) {
                                                                    $img = '../' . ltrim($img, '/');
                                                                }
                                                            ?>
                                                                <img src="<?php echo htmlspecialchars($img); ?>" alt="Product" class="rounded me-2" style="width: 50px; height: 50px; object-fit: cover;">
                                                            <?php endif; ?>
                                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td><?php echo formatCurrency($item['price']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="3" class="text-end">Total:</th>
                                                <th class="text-end"><?php echo formatCurrency($order_details['total_amount']); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sidebar Info -->
                    <div class="col-md-4">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Order Information</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($order_details['order_status'] === 'pending'): ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-clock-history me-2"></i>
                                        <strong>Order Pending</strong>
                                        <p class="mb-0 mt-2 small">Your order is being processed. You'll be notified when it's ready for pickup.</p>
                                    </div>
                                    <div class="d-grid">
                                        <button class="btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order_details['order_id']; ?>)">
                                            <i class="bi bi-x-circle me-2"></i>Cancel Order
                                        </button>
                                    </div>
                                <?php elseif ($order_details['order_status'] === 'ready for pickup'): ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-box-seam me-2"></i>
                                        <strong>Ready for Pickup</strong>
                                        <p class="mb-0 mt-2 small">Your order is ready! Please visit us to pick up and pay.</p>
                                    </div>
                                <?php elseif ($order_details['order_status'] === 'completed'): ?>
                                    <div class="alert alert-success">
                                        <i class="bi bi-check-circle me-2"></i>
                                        <strong>Order Completed</strong>
                                        <p class="mb-0 mt-2 small">Thank you for your order!</p>
                                    </div>
                                <?php endif; ?>
                                
                                <h6 class="mt-3">Need Help?</h6>
                                <p class="small text-muted mb-2">Contact us if you have any questions about your order.</p>
                                <button class="btn btn-outline-primary btn-sm w-100">
                                    <i class="bi bi-chat-dots me-2"></i>Contact Support
                                </button>
                            </div>
                        </div>
                        
                            <?php if ($order_details['order_status'] === 'completed'): ?>
                                <?php if ($order_details['payment_status'] === 'paid'): ?>
                                <div class="card mb-3">
                                    <div class="card-body text-center">
                                        <i class="bi bi-file-pdf text-danger" style="font-size: 3rem;"></i>
                                        <h6 class="mt-3">Download Receipt</h6>
                                        <p class="small text-muted">Get a PDF copy of your receipt</p>
                                        <a href="receipt.php?order_id=<?php echo $order_details['order_id']; ?>&download=pdf" class="btn btn-danger btn-sm" target="_blank">
                                            <i class="bi bi-download me-2"></i>Download PDF
                                        </a>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Removed reorder functionality -->
                            <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Orders List View -->
                <?php if (mysqli_num_rows($orders) > 0): ?>
                    <div class="row g-4">
                        <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="mb-1">Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?php echo date('M j, Y g:i A', strtotime($order['order_date'])); ?>
                                                </small>
                                            </div>
                                            <?php
                                            $badge_class = [
                                                'pending' => 'warning',
                                                'ready for pickup' => 'info',
                                                'completed' => 'success',
                                                'cancelled' => 'danger'
                                            ];
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class[$order['order_status']]; ?>">
                                                <?php echo ucfirst($order['order_status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-muted">Total Amount:</span>
                                                <strong class="text-success"><?php echo formatCurrency($order['total_amount']); ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Payment:</span>
                                                <span><?php echo ucfirst($order['payment_method']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <a href="orders.php?id=<?php echo $order['order_id']; ?>" class="btn btn-primary btn-sm w-100">
                                            <i class="bi bi-eye me-2"></i>View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <h5>No Active Orders</h5>
                        <p>You don't have any active orders at the moment.</p>
                        <a href="products.php" class="btn btn-primary">
                            <i class="bi bi-shop me-2"></i>Start Shopping
                        </a>
                        <a href="order-history.php" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-clock-history me-2"></i>View Order History
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>