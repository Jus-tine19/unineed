<?php

require_once '../config/database.php';
requireAdmin();

// Get date filter
$date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');

// Sales Summary (Revenue)
$sales_query = "SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_sales,
                AVG(total_amount) as avg_order_value,
                SUM(CASE WHEN order_status = 'completed' THEN total_amount ELSE 0 END) as completed_sales
                FROM orders 
                WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to'";
$sales_result = mysqli_query($conn, $sales_query);
$sales_data = mysqli_fetch_assoc($sales_result);

// COGS Summary (Cost of Goods Sold)
$tbl_cogs = mysqli_query($conn, "SHOW TABLES LIKE 'cogs'");
if (mysqli_num_rows($tbl_cogs) === 0) {
    $sql_cogs = @file_get_contents('../config/sql/cogs.sql');
    if ($sql_cogs) @mysqli_query($conn, $sql_cogs);
}
$cogs_query = "SELECT COALESCE(SUM(total_cost),0) as total_cogs FROM cogs WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'";
$cogs_result = @mysqli_query($conn, $cogs_query);
$cogs_data = $cogs_result ? mysqli_fetch_assoc($cogs_result) : ['total_cogs' => 0];

// Operating Expenses Summary
$tbl_exp = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
if (mysqli_num_rows($tbl_exp) === 0) {
    $sql_e = @file_get_contents('../config/sql/expenses.sql');
    if ($sql_e) @mysqli_query($conn, $sql_e);
}
$expenses_query = "SELECT COALESCE(SUM(amount),0) as total_expenses FROM expenses WHERE DATE(expense_date) BETWEEN '$date_from' AND '$date_to'";
$expenses_result = @mysqli_query($conn, $expenses_query);
$expenses_data = $expenses_result ? mysqli_fetch_assoc($expenses_result) : ['total_expenses' => 0];

// Calculate Net Profit: Revenue - COGS - Operating Expenses
$total_revenue = floatval($sales_data['total_sales'] ?? 0);
$total_cogs = floatval($cogs_data['total_cogs'] ?? 0);
$total_expenses = floatval($expenses_data['total_expenses'] ?? 0);
$gross_profit = $total_revenue - $total_cogs;
$net_profit = $total_revenue - $total_cogs - $total_expenses;
$profit_margin = $total_revenue > 0 ? ($net_profit / $total_revenue * 100) : 0;

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

// Detailed Order Transactions
// FIX: Removed the non-existent 'o.payment_status' column to resolve the Fatal Error
$detailed_orders_query = "SELECT o.order_id, o.order_date, o.total_amount, o.order_status, o.payment_method,
                          u.full_name as customer_name, u.email as customer_email,
                          GROUP_CONCAT(CONCAT(p.product_name, ' (', oi.quantity, 'x)') SEPARATOR ', ') as items
                          FROM orders o
                          LEFT JOIN users u ON o.user_id = u.user_id
                          LEFT JOIN order_items oi ON o.order_id = oi.order_id
                          LEFT JOIN products p ON oi.product_id = p.product_id
                          WHERE DATE(o.order_date) BETWEEN '$date_from' AND '$date_to'
                          GROUP BY o.order_id
                          ORDER BY o.order_date DESC";
$detailed_orders = mysqli_query($conn, $detailed_orders_query);
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
            .card { page-break-inside: avoid; }
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
            <h2>Business Reports & Analytics</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print Report
                </button>
            </div>
        </div>
        
        <div class="content-area">
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
            
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h3>UniNeeds Business Report</h3>
                    <p class="text-muted">Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?></p>
                    <small class="text-muted">Generated on: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
                </div>
            </div>
            
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($total_revenue); ?></h3>
                            <p>Total Revenue</p>
                            <small class="text-muted"><?php echo $sales_data['total_orders']; ?> orders</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-danger">
                        <div class="stat-icon">
                            <i class="bi bi-calculator"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($total_cogs); ?></h3>
                            <p>Cost of Goods Sold</p>
                            <small class="text-muted">Direct costs</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($total_expenses); ?></h3>
                            <p>Operating Expenses</p>
                            <small class="text-muted">Indirect costs</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card <?php echo $net_profit >= 0 ? 'stat-primary' : 'stat-danger'; ?>">
                        <div class="stat-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($net_profit); ?></h3>
                            <p>Net Profit</p>
                            <small class="<?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($profit_margin, 2); ?>% margin
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Profit Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Total Revenue</strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($total_revenue); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 text-danger">Less: Cost of Goods Sold</td>
                                    <td class="text-end text-danger">(<?php echo formatCurrency($total_cogs); ?>)</td>
                                </tr>
                                <tr class="table-light">
                                    <td><strong>Gross Profit</strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($gross_profit); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 text-danger">Less: Operating Expenses</td>
                                    <td class="text-end text-danger">(<?php echo formatCurrency($total_expenses); ?>)</td>
                                </tr>
                                <tr class="table-primary">
                                    <td><strong>Net Profit</strong></td>
                                    <td class="text-end"><strong><?php echo formatCurrency($net_profit); ?></strong></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle me-2"></i>Formula</h6>
                                <p class="mb-1 small"><strong>Net Profit = Revenue - COGS - Operating Expenses</strong></p>
                                <hr>
                                <p class="mb-1 small">Revenue: <?php echo formatCurrency($total_revenue); ?></p>
                                <p class="mb-1 small">COGS: <?php echo formatCurrency($total_cogs); ?></p>
                                <p class="mb-0 small">Op. Expenses: <?php echo formatCurrency($total_expenses); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-muted">Average Order Value</h6>
                            <h3><?php echo formatCurrency($sales_data['avg_order_value'] ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-muted">Gross Profit Margin</h6>
                            <h3><?php echo $total_revenue > 0 ? number_format(($gross_profit / $total_revenue * 100), 2) : 0; ?>%</h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-muted">Operating Expense Ratio</h6>
                            <h3><?php echo $total_revenue > 0 ? number_format(($total_expenses / $total_revenue * 100), 2) : 0; ?>%</h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4 mb-4">
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
                                    <?php mysqli_data_seek($status_result, 0); while ($status = mysqli_fetch_assoc($status_result)): ?>
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
                                                <span class="badge bg-<?php echo $badge_class[$status['order_status']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($status['order_status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo $status['count']; ?></td>
                                            <td class="text-end">
                                                <?php echo $sales_data['total_orders'] > 0 ? round(($status['count'] / $sales_data['total_orders']) * 100, 1) : 0; ?>%
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
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
            
            <div class="card mb-4">
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
                                    mysqli_data_seek($daily_sales, 0);
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

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Detailed Order Transactions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date & Time</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($detailed_orders) > 0): ?>
                                    <?php while ($order = mysqli_fetch_assoc($detailed_orders)): ?>
                                        <tr>
                                            <td><strong>#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($order['customer_email'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($order['items']); ?></small></td>
                                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo ucfirst($order['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'pending' => 'warning',
                                                    'ready for pickup' => 'info',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class[$order['order_status']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No orders for selected period</td>
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