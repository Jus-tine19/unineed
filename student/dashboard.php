<?php
// student/dashboard.php
require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];

// Get statistics
$stats = [];

// Total Orders
$query = "SELECT COUNT(*) as total FROM orders WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$stats['total_orders'] = mysqli_fetch_assoc($result)['total'];

// Pending Orders
$query = "SELECT COUNT(*) as pending FROM orders WHERE user_id = $user_id AND order_status = 'pending'";
$result = mysqli_query($conn, $query);
$stats['pending_orders'] = mysqli_fetch_assoc($result)['pending'];

// Completed Orders
$query = "SELECT COUNT(*) as completed FROM orders WHERE user_id = $user_id AND order_status = 'completed'";
$result = mysqli_query($conn, $query);
$stats['completed_orders'] = mysqli_fetch_assoc($result)['completed'];

// Unpaid Invoices
$query = "SELECT COUNT(*) as unpaid FROM invoices i 
          JOIN orders o ON i.order_id = o.order_id 
          WHERE o.user_id = $user_id AND i.payment_status = 'unpaid'";
$result = mysqli_query($conn, $query);
$stats['unpaid_invoices'] = mysqli_fetch_assoc($result)['unpaid'];

// Recent Orders
$query = "SELECT * FROM orders WHERE user_id = $user_id ORDER BY order_date DESC LIMIT 5";
$recent_orders = mysqli_query($conn, $query);

// Recent Notifications
$query = "SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5";
$notifications = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - UniNeeds</title>
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
            <h2>Dashboard</h2>
            <div class="ms-auto">
                <span class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</span>
            </div>
        </div>
        
        <div class="content-area">
            <!-- Quick Stats -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_orders']; ?></h3>
                            <p>Total Orders</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_orders']; ?></h3>
                            <p>Pending Orders</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['completed_orders']; ?></h3>
                            <p>Completed Orders</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-danger">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['unpaid_invoices']; ?></h3>
                            <p>Unpaid Invoices</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <a href="products.php" class="btn btn-primary w-100">
                                <i class="bi bi-shop me-2"></i>Browse Products
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="cart.php" class="btn btn-success w-100">
                                <i class="bi bi-cart me-2"></i>View Cart
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="orders.php" class="btn btn-info w-100">
                                <i class="bi bi-receipt me-2"></i>My Orders
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="notifications.php" class="btn btn-warning w-100">
                                <i class="bi bi-bell me-2"></i>Notifications
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Recent Orders -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Orders</h5>
                            <a href="order-history.php" class="btn btn-sm btn-primary">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($recent_orders) > 0): ?>
                                            <?php while ($order = mysqli_fetch_assoc($recent_orders)): ?>
                                                <tr>
                                                    <td><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                                    <td><?php echo formatCurrency($order['total_amount']); ?></td>
                                                    <td>
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
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                                                                    <td colspan="4" class="text-center py-4">
                                                    <p class="text-muted">No orders yet. Start shopping now!</p>
                                                    <a href="products.php" class="btn btn-primary btn-sm mt-2">
                                                        <i class="bi bi-shop me-2"></i>Browse Products
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Notifications -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-bell me-2"></i>Recent Notifications</h5>
                            <a href="notifications.php" class="btn btn-sm btn-primary">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (mysqli_num_rows($notifications) > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($notif = mysqli_fetch_assoc($notifications)): ?>
                                        <div class="list-group-item notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <small class="text-muted">
                                                    <i class="bi bi-<?php echo $notif['type'] === 'order_update' ? 'box' : 'receipt'; ?> me-1"></i>
                                                    <?php echo date('M j, g:i A', strtotime($notif['created_at'])); ?>
                                                </small>
                                                <?php if (!$notif['is_read']): ?>
                                                    <span class="badge bg-primary">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="mb-0 mt-1 small"><?php echo htmlspecialchars($notif['message']); ?></p>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-3 text-center text-muted">
                                    <i class="bi bi-bell-slash fs-1"></i>
                                    <p class="mb-0 mt-2">No notifications</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .notification-item.unread {
            background-color: #e7f1ff;
            border-left: 3px solid #0d6efd;
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>