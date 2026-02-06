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
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Order ID</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                                <?php
                                $items_query = "SELECT COUNT(*) as item_count FROM order_items WHERE order_id = {$order['order_id']}";
                                $items_result = mysqli_query($conn, $items_query);
                                $item_count = mysqli_fetch_assoc($items_result)['item_count'];
                                ?>
                                <tr>
                                    <td class="ps-4"><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                    <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                    <td><?php echo $item_count; ?> item(s)</td>
                                    <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                    <td>
                                        <span class="badge rounded-pill bg-<?php echo $order['order_status'] === 'completed' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($order['order_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="orders.php?id=<?php echo $order['order_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <tr id="cancel-form-<?php echo $order['order_id']; ?>" class="d-none bg-light">
                                    <td colspan="6" class="p-4">
                                        <div class="p-3 border rounded bg-white shadow-sm">
                                            <h6 class="mb-3 text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Cancel Order #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h6>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold">Select Reason:</label>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="radio" name="reason_<?php echo $order['order_id']; ?>" value="Changed my mind" onchange="toggleCancelBtn(<?php echo $order['order_id']; ?>)">
                                                        <label class="form-check-label">Changed my mind</label>
                                                    </div>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="radio" name="reason_<?php echo $order['order_id']; ?>" value="Incorrect items ordered" onchange="toggleCancelBtn(<?php echo $order['order_id']; ?>)">
                                                        <label class="form-check-label">Incorrect items ordered</label>
                                                    </div>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="radio" name="reason_<?php echo $order['order_id']; ?>" value="other" id="radio_other_<?php echo $order['order_id']; ?>" onchange="toggleCancelBtn(<?php echo $order['order_id']; ?>)">
                                                        <label class="form-check-label">Other</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-6 border-start">
                                                    <div id="other_text_container_<?php echo $order['order_id']; ?>" class="mb-3 d-none">
                                                        <label class="form-label fw-bold">Please specify:</label>
                                                        <textarea class="form-control" id="other_reason_<?php echo $order['order_id']; ?>" rows="2" placeholder="Type your reason here..." oninput="toggleCancelBtn(<?php echo $order['order_id']; ?>)"></textarea>
                                                    </div>
                                                    <div class="alert alert-warning py-2 mb-3">
                                                        <small><i class="bi bi-info-circle me-1"></i> <strong>Note:</strong> Payment is non-refundable.</small>
                                                    </div>
                                                    <div class="d-flex gap-2 justify-content-end">
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick="hideCancelForm(<?php echo $order['order_id']; ?>)">Back</button>
                                                        <button type="button" class="btn btn-sm btn-danger" id="confirm_btn_<?php echo $order['order_id']; ?>" disabled onclick="processInlineCancel(<?php echo $order['order_id']; ?>)">
                                                            Confirm Cancellation
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
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
        <div class="empty-state text-center py-5">
            <i class="bi bi-clock-history fs-1 text-muted"></i>
            <h5 class="mt-3">No Order History</h5>
            <p class="text-muted">You haven't completed or cancelled any orders yet.</p>
            <a href="products.php" class="btn btn-primary">Start Shopping</a>
        </div>
    <?php endif; ?>
</div>
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