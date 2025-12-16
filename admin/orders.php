<?php

require_once '../config/database.php';
requireAdmin();

// Handle order status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = clean($_POST['order_id']);
    $status = clean($_POST['status']);
    
    // NEW: Check if this is a payment confirmation and update invoice status if needed
    $confirm_payment = isset($_POST['confirm_payment']) ? true : false; 
    

    // Start transaction so status change and any stock restores are atomic
    mysqli_begin_transaction($conn);
    try {
        // Fetch current status and payment method/status for logic
        $cur_q = "SELECT o.order_status, o.user_id, o.payment_method, i.payment_status 
                  FROM orders o 
                  LEFT JOIN invoices i ON o.order_id = i.order_id 
                  WHERE o.order_id = $order_id FOR UPDATE";
        $cur_res = mysqli_query($conn, $cur_q);
        $cur_row = $cur_res ? mysqli_fetch_assoc($cur_res) : null;
        $previous_status = $cur_row ? $cur_row['order_status'] : null;
        $previous_payment_status = $cur_row ? $cur_row['payment_status'] : null;
        $order_user_id = $cur_row ? $cur_row['user_id'] : null;

        // --- Payment Verification Logic ---
        if ($previous_status === 'pending_payment' && $status === 'pending' && $confirm_payment) {
             // Admin confirmed payment. Update invoice status to downpayment_paid
             $new_payment_status = 'downpayment_paid'; 
             $update_invoice = "UPDATE invoices SET payment_status = '$new_payment_status' WHERE order_id = $order_id";
             if (!mysqli_query($conn, $update_invoice)) {
                 throw new Exception('Failed to update invoice payment status: ' . mysqli_error($conn));
             }
             // Status is now 'pending' (processing) which is the new submitted status.
        } elseif ($status === 'completed') {
             // If marking as completed, set payment status to fully_paid (assuming final cash payment made)
             $update_invoice = "UPDATE invoices SET payment_status = 'fully_paid' WHERE order_id = $order_id AND payment_status != 'fully_paid'";
             if (!mysqli_query($conn, $update_invoice)) {
                 throw new Exception('Failed to update invoice payment status to fully paid: ' . mysqli_error($conn));
             }
        }
        
        // Update order status
        $query = "UPDATE orders SET order_status = '$status' WHERE order_id = $order_id";
        if (!mysqli_query($conn, $query)) {
            throw new Exception('Failed to update order status: ' . mysqli_error($conn));
        }

        // If status changed to cancelled and previous status wasn't cancelled, restore stock
        if ($status === 'cancelled' && $previous_status !== 'cancelled') {
            $items_query = "SELECT oi.*, oi.quantity as qty, oi.product_id, oi.variant_id 
                            FROM order_items oi 
                            WHERE oi.order_id = $order_id";
            $items_res = mysqli_query($conn, $items_query);
            if ($items_res) {
                while ($it = mysqli_fetch_assoc($items_res)) {
                    $qty = intval($it['qty']);
                    $p_id = intval($it['product_id']);
                    $v_id = isset($it['variant_id']) ? intval($it['variant_id']) : null;
                    
                    // Restore base product stock
                    $upd = "UPDATE products SET stock_quantity = stock_quantity + $qty WHERE product_id = $p_id";
                    mysqli_query($conn, $upd);
                    
                    // Restore variant stock (if applicable)
                    if ($v_id) {
                        $var_upd = "UPDATE product_variants SET stock_quantity = stock_quantity + $qty WHERE variant_id = $v_id";
                        mysqli_query($conn, $var_upd);
                    }
                }
            }
        }

        // Create notification for student
        $message = "Your order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been updated to: " . ucfirst($status);
        $notif_query = "INSERT INTO notifications (user_id, message, type) VALUES ({$order_user_id}, '$message', 'order_update')";
        mysqli_query($conn, $notif_query);

        mysqli_commit($conn);
        $success = "Order status updated successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to update order status: " . $e->getMessage();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';

// Build query
$where_clauses = [];
if ($status_filter) {
    $where_clauses[] = "o.order_status = '$status_filter'";
}
if ($search) {
    $where_clauses[] = "(u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR o.order_id LIKE '%$search%')";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT o.*, u.full_name, u.email, u.phone, i.payment_status 
          FROM orders o 
          JOIN users u ON o.user_id = u.user_id 
          LEFT JOIN invoices i ON o.order_id = i.order_id /* JOIN INVOICES TO GET PAYMENT STATUS */
          $where_sql
          ORDER BY o.order_date DESC";
$orders = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .order-details-row {
            background-color: #f8f9fa;
            border-top: 2px solid #dee2e6;
        }
        .order-details-content {
            padding: 20px;
        }
        .order-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .order-row:hover {
            background-color: #f8f9fa;
        }
        .order-row.expanded {
            background-color: #e9ecef;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Orders Management</h2>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="filter-bar">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search" placeholder="Search by customer name, email, or order ID" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="pending_payment" <?php echo $status_filter === 'pending_payment' ? 'selected' : ''; ?>>Pending Payment</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending (Processing)</option>
                            <option value="ready for pickup" <?php echo $status_filter === 'ready for pickup' ? 'selected' : ''; ?>>Ready for Pickup</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-2"></i>Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="orders.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($orders) > 0): ?>
                                    <?php while ($order = mysqli_fetch_assoc($orders)): ?>
                                        <tr class="order-row" data-order-id="<?php echo $order['order_id']; ?>" onclick="toggleOrderDetails(<?php echo $order['order_id']; ?>)">
                                            <td><strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($order['full_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($order['email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['phone']); ?></td>
                                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                                            <td>
                                                <?php
                                                $payment_badge_class = 'secondary';
                                                if (isset($order['payment_status'])) {
                                                    switch ($order['payment_status']) {
                                                        case 'fully_paid': $payment_badge_class = 'success'; break;
                                                        case 'downpayment_paid': $payment_badge_class = 'primary'; break;
                                                        case 'pending_proof': $payment_badge_class = 'warning'; break;
                                                        case 'unpaid': $payment_badge_class = 'danger'; break;
                                                    }
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $payment_badge_class; ?>" title="Payment Status: <?php echo ucfirst(str_replace('_', ' ', $order['payment_status'] ?? 'N/A')); ?>">
                                                    <?php echo ucfirst($order['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = [
                                                    'pending_payment' => 'secondary', // ADDED
                                                    'pending' => 'warning',
                                                    'ready for pickup' => 'info',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                $order_status_clean = str_replace('_', ' ', $order['order_status']);
                                                $current_badge_class = $badge_class[$order['order_status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $current_badge_class; ?>">
                                                    <?php echo ucfirst($order_status_clean); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info btn-action" onclick="event.stopPropagation(); toggleOrderDetails(<?php echo $order['order_id']; ?>)" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($order['order_status'] !== 'completed' && $order['order_status'] !== 'cancelled'): ?>
                                                        <button class="btn btn-sm btn-primary btn-action" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#statusModal<?php echo $order['order_id']; ?>" title="Update Status">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <tr class="order-details-row d-none" id="details-<?php echo $order['order_id']; ?>">
                                            <td colspan="8">
                                                <div class="order-details-content">
                                                    <?php
                                                    // Re-select order details including invoice information for the detail view
                                                    $detail_q = "SELECT o.*, i.payment_status, i.down_payment_due, i.remaining_balance 
                                                                 FROM orders o 
                                                                 LEFT JOIN invoices i ON o.order_id = i.order_id 
                                                                 WHERE o.order_id = {$order['order_id']}";
                                                    $detail_res = mysqli_query($conn, $detail_q);
                                                    $detail_data = $detail_res ? mysqli_fetch_assoc($detail_res) : [];
                                                    
                                                    $items_query = "SELECT oi.*, p.product_name, p.image_url, v.variant_value 
                                                                   FROM order_items oi 
                                                                   JOIN products p ON oi.product_id = p.product_id 
                                                                   LEFT JOIN product_variants v ON oi.variant_id = v.variant_id
                                                                   WHERE oi.order_id = {$order['order_id']}";
                                                    $items = mysqli_query($conn, $items_query);
                                                    ?>
                                                    
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h5 class="mb-0">Order Details - #<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleOrderDetails(<?php echo $order['order_id']; ?>)">
                                                            <i class="bi bi-x-lg me-1"></i> Close
                                                        </button>
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-md-4">
                                                            <h6>Customer Information</h6>
                                                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['full_name']); ?></p>
                                                            <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
                                                            <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone']); ?></p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <h6>Order & Status</h6>
                                                            <p class="mb-1"><strong>Order Date:</strong> <?php echo date('M j, Y g:i A', strtotime($order['order_date'])); ?></p>
                                                            <p class="mb-1"><strong>Payment Method:</strong> <?php echo ucfirst($order['payment_method']); ?></p>
                                                            <p class="mb-1"><strong>Order Status:</strong> <span class="badge bg-<?php echo $current_badge_class; ?>"><?php echo ucfirst($order_status_clean); ?></span></p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <h6>Financials</h6>
                                                            <p class="mb-1"><strong>Total Amount:</strong> <strong class="text-success"><?php echo formatCurrency($detail_data['total_amount'] ?? 0); ?></strong></p>
                                                            <p class="mb-1"><strong>Payment Status:</strong> <span class="badge bg-<?php echo $payment_badge_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $detail_data['payment_status'] ?? 'N/A')); ?></span></p>
                                                            <p class="mb-1"><strong>Down Payment Due:</strong> <?php echo formatCurrency($detail_data['down_payment_due'] ?? 0); ?></p>
                                                            <p class="mb-1"><strong>Remaining Balance:</strong> <?php echo formatCurrency($detail_data['remaining_balance'] ?? 0); ?></p>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($order['order_status'] === 'pending_payment' && $order['payment_method'] === 'gcash'): ?>
                                                        <div class="alert alert-warning mb-4">
                                                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                            <strong>GCash Payment Pending Verification.</strong> The student must submit proof of payment (min. <?php echo formatCurrency($detail_data['down_payment_due'] ?? 0); ?>) before you can update this status to 'Pending (Processing)'.
                                                            </div>
                                                    <?php endif; ?>
                                                    
                                                    <h6>Order Items</h6>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm">
                                                            <thead>
                                                                <tr>
                                                                    <th>Product</th>
                                                                    <th>Price</th>
                                                                    <th>Quantity</th>
                                                                    <th>Subtotal</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php while ($item = mysqli_fetch_assoc($items)): ?>
                                                                    <tr>
                                                                        <td>
                                                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                                                            <?php if ($item['variant_value']): ?>
                                                                                <small class="text-muted d-block">(<?php echo htmlspecialchars($item['variant_value']); ?>)</small>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td><?php echo formatCurrency($item['price']); ?></td>
                                                                        <td><?php echo $item['quantity']; ?></td>
                                                                        <td><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                                    </tr>
                                                                <?php endwhile; ?>
                                                            </tbody>
                                                            <tfoot>
                                                                <tr>
                                                                    <th colspan="3" class="text-end">Total:</th>
                                                                    <th><?php echo formatCurrency($order['total_amount']); ?></th>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <div class="modal fade" id="statusModal<?php echo $order['order_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Update Order Status</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Current Status</label>
                                                                <input type="text" class="form-control" value="<?php echo ucfirst($order_status_clean); ?>" readonly>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">New Status</label>
                                                                <select class="form-select" name="status" id="statusSelect<?php echo $order['order_id']; ?>" required>
                                                                    <option value="">Select Status</option>
                                                                    <?php
                                                                    $statusOptions = [
                                                                        'pending_payment' => 'Pending Payment',
                                                                        'pending' => 'Pending (Processing)',
                                                                        'ready for pickup' => 'Ready for Pickup',
                                                                        'completed' => 'Completed',
                                                                        'cancelled' => 'Cancelled'
                                                                    ];
                                                                    $current_status = $order['order_status'];
                                                                    foreach ($statusOptions as $sKey => $sLabel) {
                                                                        if ($sKey === $current_status) continue;
                                                                        
                                                                        // Cannot move backward from R-F-P except to Cancelled
                                                                        if ($current_status === 'ready for pickup' && in_array($sKey, ['pending', 'pending_payment'])) continue;

                                                                        // Cannot jump from P-P to R-F-P or Completed directly
                                                                        if ($current_status === 'pending_payment' && in_array($sKey, ['ready for pickup', 'completed'])) continue;

                                                                        // Cannot update status from Completed/Cancelled
                                                                        if (in_array($current_status, ['completed', 'cancelled'])) continue;
                                                                    ?>
                                                                        <option value="<?php echo htmlspecialchars($sKey); ?>"><?php echo htmlspecialchars($sLabel); ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </div>
                                                            
                                                            <?php if ($current_status === 'pending_payment'): ?>
                                                            <div class="alert alert-info" id="paymentConfirmBlock<?php echo $order['order_id']; ?>" style="display:none;">
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" value="1" name="confirm_payment" id="confirmPaymentCheck<?php echo $order['order_id']; ?>">
                                                                    <label class="form-check-label" for="confirmPaymentCheck<?php echo $order['order_id']; ?>">
                                                                        Confirm Down Payment (<?php echo formatCurrency($detail_data['down_payment_due'] ?? 0); ?>) has been received and verified.
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-cart-x"></i>
                                                <h5>No Orders Found</h5>
                                                <p>There are no orders matching your criteria.</p>
                                            </div>
                                        </td>
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
        function toggleOrderDetails(orderId) {
            const detailsRow = document.getElementById('details-' + orderId);
            const orderRow = document.querySelector('.order-row[data-order-id="' + orderId + '"]');
            
            // Close all other open details
            document.querySelectorAll('.order-details-row').forEach(row => {
                if (row.id !== 'details-' + orderId) {
                    row.classList.add('d-none');
                }
            });
            
            // Remove expanded class from all rows
            document.querySelectorAll('.order-row').forEach(row => {
                if (row.dataset.orderId != orderId) {
                    row.classList.remove('expanded');
                }
            });
            
            // Toggle current details
            if (detailsRow.classList.contains('d-none')) {
                detailsRow.classList.remove('d-none');
                orderRow.classList.add('expanded');
            } else {
                detailsRow.classList.add('d-none');
                orderRow.classList.remove('expanded');
            }
        }
        
        // JS to toggle payment confirmation box when changing status from PENDING_PAYMENT to PENDING
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('select[id^="statusSelect"]').forEach(selectElement => {
                const orderId = selectElement.id.replace('statusSelect', '');
                const confirmBlock = document.getElementById('paymentConfirmBlock' + orderId);
                
                if (confirmBlock) {
                    selectElement.addEventListener('change', function() {
                        if (this.value === 'pending') {
                            confirmBlock.style.display = 'block';
                            // Ensure checkbox is required if visible and target is 'pending'
                            document.getElementById('confirmPaymentCheck' + orderId).setAttribute('required', 'required');
                        } else {
                            confirmBlock.style.display = 'none';
                            document.getElementById('confirmPaymentCheck' + orderId).removeAttribute('required');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>