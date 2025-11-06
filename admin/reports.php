<?php
// admin/reports.php
require_once '../config/database.php';
requireAdmin();

// Get date filter
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');

// Sales Summary
$sales_query = "SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_sales,
                AVG(total_amount) as avg_order_value,
                SUM(CASE WHEN order_status = 'completed' THEN total_amount ELSE 0 END) as completed_sales
                FROM orders 
                WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to'";
$sales_result = mysqli_query($conn, $sales_query);
$sales_data = mysqli_fetch_assoc($sales_result);

// Expenses Summary (total expenses in the period)
// Ensure expenses table exists (create from SQL file if missing)
$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
if (mysqli_num_rows($tbl) === 0) {
    $sql_e = @file_get_contents('../config/sql/expenses.sql');
    if ($sql_e) {
        @mysqli_query($conn, $sql_e);
    }
}
$expenses_query = "SELECT COALESCE(SUM(amount),0) as total_expenses FROM expenses WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'";
$expenses_result = @mysqli_query($conn, $expenses_query);
if ($expenses_result) {
    $expenses_data = mysqli_fetch_assoc($expenses_result);
} else {
    $expenses_data = ['total_expenses' => 0];
}

// Net profit = total_sales - total_expenses
$total_sales_amount = floatval($sales_data['total_sales'] ?? 0);
$total_expenses_amount = floatval($expenses_data['total_expenses'] ?? 0);
$net_profit = $total_sales_amount - $total_expenses_amount;

// Orders by Status
$status_query = "SELECT order_status, COUNT(*) as count 
                FROM orders 
                WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to'
                GROUP BY order_status";
$status_result = mysqli_query($conn, $status_query);

// Top Selling Products
$top_products_query = "SELECT p.product_name, SUM(oi.quantity) as total_sold, SUM(oi.quantity * oi.price) as revenue
                       FROM order_items oi
                       JOIN products p ON oi.product_id = p.product_id
                       JOIN orders o ON oi.order_id = o.order_id
                       WHERE DATE(o.order_date) BETWEEN '$date_from' AND '$date_to'
                       GROUP BY p.product_id
                       ORDER BY total_sold DESC
                       LIMIT 10";
$top_products = mysqli_query($conn, $top_products_query);

// Daily Sales
$daily_sales_query = "SELECT DATE(order_date) as date, 
                      COUNT(*) as orders,
                      SUM(total_amount) as sales
                      FROM orders
                      WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to'
                      GROUP BY DATE(order_date)
                      ORDER BY date ASC";
$daily_sales = mysqli_query($conn, $daily_sales_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - UniNeeds Admin</title>
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
            <h2>Sales & Analytics Reports</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print Report
                </button>
            </div>
        </div>
        
        <div class="content-area">
            <!-- Date Filter -->
            <div class="filter-bar no-print">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-2"></i>Generate
                        </button>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('today')">Today</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('week')">This Week</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="setDateRange('month')">This Month</button>
                    </div>
                </form>
            </div>
            
            <!-- Report Header -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h3>UniNeeds Sales Report</h3>
                    <p class="text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>
                    <small class="text-muted">Generated on: <?php echo date('F j, Y g:i A'); ?></small>
                </div>
            </div>
            
            <!-- Sales Summary Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-cart-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $sales_data['total_orders']; ?></h3>
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
                            <h3><?php echo formatCurrency($sales_data['total_sales'] ?? 0); ?></h3>
                            <p>Total Sales</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($sales_data['avg_order_value'] ?? 0); ?></h3>
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
                            <h3><?php echo formatCurrency($sales_data['completed_sales'] ?? 0); ?></h3>
                            <p>Completed Sales</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Expenses & Net Profit -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-danger">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($expenses_data['total_expenses'] ?? 0); ?></h3>
                            <p>Total Expenses</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="stat-card <?php echo $net_profit >= 0 ? 'stat-success' : 'stat-danger'; ?>">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($net_profit); ?></h3>
                            <p>Net Profit</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4 mb-4">
                <!-- Orders by Status -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Orders by Status</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($status = mysqli_fetch_assoc($status_result)): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'pending' => 'warning',
                                                    'ready for pickup' => 'info',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class[$status['order_status']]; ?>">
                                                    <?php echo ucfirst($status['order_status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo $status['count']; ?></td>
                                            <td class="text-end">
                                                <?php echo round(($status['count'] / $sales_data['total_orders']) * 100, 1); ?>%
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Top Selling Products -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-star me-2"></i>Top Selling Products</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Sold</th>
                                        <th class="text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($top_products) > 0): ?>
                                        <?php while ($product = mysqli_fetch_assoc($top_products)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                                <td class="text-end"><?php echo $product['total_sold']; ?></td>
                                                <td class="text-end"><?php echo formatCurrency($product['revenue']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">No sales data</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Daily Sales Chart -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Daily Sales Overview</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Total Sales</th>
                                    <th class="text-end">Avg Order Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($daily_sales) > 0): ?>
                                    <?php 
                                    $total_orders = 0;
                                    $total_sales = 0;
                                    while ($day = mysqli_fetch_assoc($daily_sales)): 
                                        $total_orders += $day['orders'];
                                        $total_sales += $day['sales'];
                                    ?>
                                        <tr>
                                            <td><?php echo date('M j, Y (D)', strtotime($day['date'])); ?></td>
                                            <td class="text-end"><?php echo $day['orders']; ?></td>
                                            <td class="text-end"><?php echo formatCurrency($day['sales']); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($day['sales'] / $day['orders']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <tr class="table-light fw-bold">
                                        <td>TOTAL</td>
                                        <td class="text-end"><?php echo $total_orders; ?></td>
                                        <td class="text-end"><?php echo formatCurrency($total_sales); ?></td>
                                        <td class="text-end"><?php echo $total_orders > 0 ? formatCurrency($total_sales / $total_orders) : formatCurrency(0); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No sales data for selected period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        function setDateRange(range) {
            const dateFrom = document.querySelector('input[name="date_from"]');
            const dateTo = document.querySelector('input[name="date_to"]');
            const today = new Date();
            
            if (range === 'today') {
                dateFrom.value = today.toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
            } else if (range === 'week') {
                const weekStart = new Date(today.setDate(today.getDate() - today.getDay()));
                dateFrom.value = weekStart.toISOString().split('T')[0];
                dateTo.value = new Date().toISOString().split('T')[0];
            } else if (range === 'month') {
                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                dateFrom.value = monthStart.toISOString().split('T')[0];
                dateTo.value = new Date().toISOString().split('T')[0];
            }
        }
    </script>
</body>
</html>