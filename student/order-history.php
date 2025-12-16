<?php

require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];

// Get completed and cancelled orders
$query = "SELECT * FROM orders 
          WHERE user_id = $user_id 
          AND order_status IN ('completed', 'cancelled')
          ORDER BY order_date DESC";
$orders = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - UniNeeds</title>
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
            <h2>Order History</h2>
        </div>
        
        <div class="content-area">
            <?php if (mysqli_num_rows($orders) > 0): ?>
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Date</th>
                                        <th>Items</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                                        <?php
                                        // Get item count
                                        $items_query = "SELECT COUNT(*) as item_count FROM order_items WHERE order_id = {$order['order_id']}";
                                        $items_result = mysqli_query($conn, $items_query);
                                        $item_count = mysqli_fetch_assoc($items_result)['item_count'];
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                            <td><?php echo $item_count; ?> item(s)</td>
                                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                            <td>
                                                <?php
                                                $badge_class = $order['order_status'] === 'completed' ? 'success' : 'danger';
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="orders.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-primary btn-action" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-clock-history"></i>
                    <h5>No Order History</h5>
                    <p>You haven't completed any orders yet.</p>
                    <a href="products.php" class="btn btn-primary">
                        <i class="bi bi-shop me-2"></i>Start Shopping
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    
    <!-- Initialize tooltips -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Enable tooltips
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(el => new bootstrap.Tooltip(el));
            
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>