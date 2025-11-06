<?php
// student/reports.php
require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];

// Get date range
$date_range = isset($_GET['range']) ? clean($_GET['range']) : '30';
$date_from = date('Y-m-d', strtotime("-$date_range days"));
$date_to = date('Y-m-d');

// Get order statistics
$stats_query = "SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_spent,
                AVG(total_amount) as avg_order,
                SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
                FROM orders 
                WHERE user_id = $user_id 
                AND DATE(order_date) BETWEEN '$date_from' AND '$date_to'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get top ordered products
$top_products_query = "SELECT p.product_name, 
                       SUM(oi.quantity) as total_quantity,
                       SUM(oi.quantity * oi.price) as total_spent
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.product_id
                       JOIN orders o ON oi.order_id = o.order_id
                       WHERE o.user_id = $user_id 
                       AND DATE(o.order_date) BETWEEN '$date_from' AND '$date_to'
                       GROUP BY p.product_id
                       ORDER BY total_quantity DESC
                       LIMIT 5";
$top_products = mysqli_query($conn, $top_products_query);

// Get monthly spending
$monthly_query = "SELECT 
                  DATE_FORMAT(order_date, '%Y-%m') as month,
                  COUNT(*) as order_count,
                  SUM(total_amount) as total_amount
                  FROM orders
                  WHERE user_id = $user_id
                  AND DATE(order_date) BETWEEN '$date_from' AND '$date_to'
                  GROUP BY DATE_FORMAT(order_date, '%Y-%m')
                  ORDER BY month DESC";
$monthly_data = mysqli_query($conn, $monthly_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        @media print {
            .sidebar, .top-bar, .filter-bar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar no-print">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>My Reports</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print Report
                </button>
            </div>
        </div>
        
        <div class="content-area">
            <!-- Date Range Filter -->
            <div class="filter-bar no-print">
                <div class="btn-group" role="group">
                    <a href="?range=7" class="btn btn-outline-primary <?php echo $date_range === '7' ? 'active' : ''; ?>">Last 7 Days</a>
                    <a href="?range=30" class="btn btn-outline-primary <?php echo $date_range === '30' ? 'active' : ''; ?>">Last 30 Days</a>
                    <a href="?range=90" class="btn btn-outline-primary <?php echo $date_range === '90' ? 'active' : ''; ?>">Last 90 Days</a>
                    <a href="?range=365" class="btn btn-outline-primary <?php echo $date_range === '365' ? 'active' : ''; ?>">Last Year</a>
                </div>
            </div>
            
            <!-- Report Header -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h3>Personal Order Report</h3>
                    <p class="text-muted mb-0">Period: <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?></p>
                    <small class="text-muted">Generated on: <?php echo date('F j, Y g:i A'); ?></small>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-cart"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_orders'] ?? 0; ?></h3>
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
                            <h3><?php echo formatCurrency($stats['total_spent'] ?? 0); ?></h3>
                            <p>Total Spent</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($stats['avg_order'] ?? 0); ?></h3>
                            <p>Average Order</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['completed_orders'] ?? 0; ?></h3>
                            <p>Completed</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Top Products -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-star me-2"></i>Most Ordered Products</h5>
                        </div>
                        <div class="card-body">
                            <?php if (mysqli_num_rows($top_products) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Spent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($product = mysqli_fetch_assoc($top_products)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                                    <td class="text-end"><?php echo $product['total_quantity']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($product['total_spent']); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">No order data for this period.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Summary -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Monthly Summary</h5>
                        </div>
                        <div class="card-body">
                            <?php if (mysqli_num_rows($monthly_data) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th class="text-end">Orders</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($month = mysqli_fetch_assoc($monthly_data)): ?>
                                                <tr>
                                                    <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                                    <td class="text-end"><?php echo $month['order_count']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($month['total_amount']); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">No order data for this period.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>