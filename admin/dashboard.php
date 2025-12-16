<?php

require_once '../config/database.php';
requireAdmin();

// Get stats
$stats = [];

// Total Orders
$query = "SELECT COUNT(*) as total FROM orders";
$result = mysqli_query($conn, $query);
$stats['total_orders'] = mysqli_fetch_assoc($result)['total'];

// Total Revenue
$query = "SELECT SUM(total_amount) as revenue FROM orders WHERE order_status = 'completed'";
$result = mysqli_query($conn, $query);
$stats['revenue'] = mysqli_fetch_assoc($result)['revenue'] ?? 0;

// Pending Orders
$query = "SELECT COUNT(*) as pending FROM orders WHERE order_status = 'pending'";
$result = mysqli_query($conn, $query);
$stats['pending_orders'] = mysqli_fetch_assoc($result)['pending'];

// Total Products
$query = "SELECT COUNT(*) as total FROM products WHERE status = 'available'";
$result = mysqli_query($conn, $query);
$stats['total_products'] = mysqli_fetch_assoc($result)['total'];

// Low Stock Products
$query = "SELECT COUNT(*) as low_stock FROM products WHERE stock_quantity <= 10 AND stock_quantity > 0 AND status = 'available'";
$result = mysqli_query($conn, $query);
$stats['low_stock'] = mysqli_fetch_assoc($result)['low_stock'];

// Recent Orders
$query = "SELECT o.*, u.full_name, u.email 
          FROM orders o 
          JOIN users u ON o.user_id = u.user_id 
          ORDER BY o.order_date DESC 
          LIMIT 5";
$recent_orders = mysqli_query($conn, $query);

// Monthly Sales Data
$query = "SELECT 
            DATE_FORMAT(order_date, '%Y-%m') as month,
            SUM(total_amount) as total_sales,
            COUNT(*) as order_count
          FROM orders 
          WHERE order_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          GROUP BY DATE_FORMAT(order_date, '%Y-%m')
          ORDER BY month DESC";
$monthly_sales = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - UniNeeds Admin</title>
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
                <span class="text-muted"><?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>
        
        <div class="content-area">
            <!-- Statistics Cards -->
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
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="bi bi-currency-peso"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($stats['revenue']); ?></h3>
                            <p>Total Revenue</p>
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
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_products']; ?></h3>
                            <p>Active Products</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($stats['low_stock'] > 0): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Low Stock Alert!</strong> You have <?php echo $stats['low_stock']; ?> product(s) with low stock levels.
                <a href="inventory.php" class="alert-link">View Inventory</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="row g-4">
                <!-- Recent Orders -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Orders</h5>
                            <a href="orders.php" class="btn btn-sm btn-primary">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Customer</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($recent_orders) > 0): ?>
                                            <?php while ($order = mysqli_fetch_assoc($recent_orders)): ?>
                                                <tr>
                                                    <td>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo htmlspecialchars($order['full_name']); ?></td>
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
                                                <td colspan="5" class="text-center py-4">No orders yet</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Sales Chart -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Monthly Sales</h5>
                        </div>
                        <div class="card-body">
                            <?php if (mysqli_num_rows($monthly_sales) > 0): ?>
                                <?php while ($month = mysqli_fetch_assoc($monthly_sales)): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small><?php echo date('M Y', strtotime($month['month'])); ?></small>
                                            <small class="text-muted"><?php echo $month['order_count']; ?> orders</small>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo min(100, ($month['total_sales'] / ($stats['revenue'] ?: 1)) * 100); ?>%"></div>
                                        </div>
                                        <small class="text-success"><?php echo formatCurrency($month['total_sales']); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p class="text-muted text-center">No sales data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/mobile-menu.js"></script>
</body>
</html>