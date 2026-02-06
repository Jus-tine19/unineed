<?php

require_once '../config/database.php';
requireStudent();

// Get active orders (not completed or cancelled)
$user_id = $_SESSION['user_id'];

// Check for success message
$success = isset($_GET['success']) && $_GET['success'] == 1;
$new_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;

// Check for GCash payment redirect flag
$payment_needed_gcash = isset($_GET['payment_needed']) && $_GET['payment_needed'] === 'gcash';

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
    // MODIFIED QUERY: Include payment_proof_path and amount tracking from invoices
    $query = "SELECT o.*, i.invoice_number, i.payment_status, i.down_payment_due, i.remaining_balance, i.payment_proof_path, i.amount_paid, i.balance_due 
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
                <div class="ms-auto d-flex">
                    <a href="orders.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Orders
                    </a>
                        <?php if (!in_array($order_details['order_status'], ['completed','cancelled'])): ?>
                        <button class="btn btn-danger ms-2" data-bs-toggle="modal" data-bs-target="#cancelOrderModal" data-order-id="<?php echo $order_details['order_id']; ?>">
                            <i class="bi bi-x-lg me-2"></i>Cancel Order
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="content-area">
            <?php if ($success && $new_order_id && !$payment_needed_gcash): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <h5 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Order Placed Successfully!</h5>
                    <p>Your order #<?php echo str_pad($new_order_id, 6, '0', STR_PAD_LEFT); ?> has been placed successfully. We'll notify you when it's ready for pickup.</p>
                    <hr>
                    <a href="orders.php?id=<?php echo $new_order_id; ?>" class="btn btn-sm btn-success">View Order Details</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div id="alertPlaceholder">
                </div>
            
            <?php if ($order_details): ?>
                <div class="row g-4">
                    <div class="col-md-12">
                        <div class="card mb-4">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Order #<?php echo str_pad($order_details['order_id'], 6, '0', STR_PAD_LEFT); ?></h5>
                                    <?php
                                    $badge_class = [
                                        'pending_payment' => 'secondary', 
                                        'pending' => 'warning',
                                        'ready for pickup' => 'info',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class[$order_details['order_status']] ?? 'secondary'; ?> fs-6">
                                        <?php echo ucfirst(str_replace('_', ' ', $order_details['order_status'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Order Date:</strong> <?php echo date('F j, Y g:i A', strtotime($order_details['order_date'])); ?></p>
                                        <p class="mb-2"><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order_details['payment_method'])); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Total Amount:</strong> <span class="text-success fs-5"><?php echo formatCurrency($order_details['total_amount']); ?></span></p>
                                        <?php if ($order_details['invoice_number']): ?>
                                            <p class="mb-2"><strong>Invoice:</strong> <?php echo htmlspecialchars($order_details['invoice_number']); ?></p>
                                            <p class="mb-2"><strong>Payment Status:</strong> 
                                                <span class="badge bg-<?php echo (in_array($order_details['payment_status'], ['fully_paid','paid'])) ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $order_details['payment_status'])); ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Payment Breakdown Section -->
                                <div class="card bg-light mt-4" style="display: block !important; visibility: visible !important;">
                                    <div class="card-body" style="display: block !important;">
                                        <h6 class="card-title mb-3"><i class="bi bi-receipt me-2"></i>Payment Breakdown</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-2"><strong>Full Amount:</strong></p>
                                                <p class="text-success fs-5"><strong><?php echo formatCurrency($order_details['total_amount']); ?></strong></p>
                                            </div>
                                            <div class="col-md-6">
                                                <?php 
                                                $amount_paid = floatval($order_details['amount_paid'] ?? 0);
                                                $balance_due = floatval($order_details['balance_due'] ?? $order_details['remaining_balance'] ?? 0);
                                                $down_payment = floatval($order_details['down_payment_due'] ?? 0);
                                                $total_amount = floatval($order_details['total_amount']);
                                                
                                                // If invoice/payment status indicates fully paid/paid or order is completed but amount_paid isn't set, treat it as fully paid
                                                if ((in_array($order_details['payment_status'], ['fully_paid','paid']) || $order_details['order_status'] === 'completed') && $amount_paid <= 0) {
                                                    $amount_paid = $total_amount;
                                                    $balance_due = 0;
                                                }
                                                
                                                // Determine payment type based on amount paid and method
                                                $payment_type = '';
                                                if ($order_details['payment_method'] === 'gcash') {
                                                    if ($amount_paid >= $total_amount) {
                                                        $payment_type = ' (Full Payment via GCash)';
                                                    } elseif ($amount_paid > 0 && $amount_paid < $total_amount) {
                                                        $payment_type = ' (Downpayment via GCash)';
                                                    }
                                                } elseif ($order_details['payment_method'] === 'cash_on_pickup') {
                                                    if ($amount_paid >= $total_amount) {
                                                        $payment_type = ' (Full Payment - Cash on Pickup)';
                                                    } else {
                                                        $payment_type = ' (Cash on Pickup)';
                                                    }
                                                }
                                                ?>
                                                <p class="mb-2"><strong>Amount Paid:</strong></p>
                                                <p class="text-info fs-5"><strong><?php echo formatCurrency($amount_paid); ?></strong><span class="small text-muted"><?php echo $payment_type; ?></span></p>
                                            </div>
                                        </div>
                                        <?php if ($balance_due > 0): ?>
                                            <hr class="my-3">
                                            <div class="alert alert-warning p-3 mb-0" style="display: block !important;">
                                                <p class="mb-2"><strong><i class="bi bi-exclamation-triangle me-2"></i>Balance Due:</strong></p>
                                                <p class="text-danger fs-5 mb-0"><strong><?php echo formatCurrency($balance_due); ?></strong></p>
                                                <small class="text-muted d-block mt-2">This amount must be paid when claiming your order.</small>
                                            </div>
                                        <?php else: ?>
                                            <hr class="my-3">
                                            <div class="alert alert-success p-3 mb-0">
                                                <p class="mb-0"><i class="bi bi-check-circle me-2"></i><strong>Fully Paid!</strong> No balance due.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <h6 class="mb-3">Order Status</h6>
                                    <div class="progress" style="height: 30px;">
                                        <?php
                                        $status_progress = [
                                            'pending_payment' => 10, 
                                            'pending' => 33,
                                            'ready for pickup' => 66,
                                            'completed' => 100,
                                            'cancelled' => 100
                                        ];
                                        // Use null-coalescing or array key check to prevent warning if a status is missing
                                        $progress = $status_progress[$order_details['order_status']] ?? 0;
                                        $status_class = $badge_class[$order_details['order_status']] ?? 'secondary';
                                        ?>
                                        <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                             style="width: <?php echo $progress; ?>%">
                                            <?php echo ucfirst(str_replace('_', ' ', $order_details['order_status'])); ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-2 small text-muted">
                                        <span>Pending Payment</span>
                                        <span>Processing</span>
                                        <span>Ready for Pickup</span>
                                        <span>Completed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
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
                                                <th>Variant</th>
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
                                                    <td>
                                                        <?php 
                                                        $variants = null;
                                                        
                                                        // Try to get variants from variant_value column
                                                        if (!empty($item['variant_value'])) {
                                                            $decoded = json_decode($item['variant_value'], true);
                                                            if (is_array($decoded)) {
                                                                $variants = $decoded;
                                                            }
                                                        }
                                                        
                                                        if ($variants && is_array($variants) && count($variants) > 0): ?>
                                                            <small class="text-muted">
                                                                <?php foreach ($variants as $type => $value): ?>
                                                                    <span class="badge bg-light text-dark me-1 mb-1">
                                                                        <?php echo htmlspecialchars(ucfirst($type)); ?>: <?php echo htmlspecialchars($value); ?>
                                                                    </span><br>
                                                                <?php endforeach; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="text-muted">No variants</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo formatCurrency($item['price']); ?></td>
                                                    <td><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="4" class="text-end">Total:</th>
                                                <th class="text-end"><?php echo formatCurrency($order_details['total_amount']); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        
                        <?php 
                        $is_gcash_order = $order_details['payment_method'] === 'gcash';
                        $is_proof_uploaded = $order_details['payment_proof_path'] && $order_details['payment_status'] === 'pending_proof';
                        $balance_due = floatval($order_details['balance_due'] ?? $order_details['remaining_balance'] ?? 0);
                        
                        if ($is_gcash_order && !in_array($order_details['order_status'], ['completed', 'cancelled'])):
                            
                            $gcash_name = GCASH_NAME;
                            $gcash_number = GCASH_NUMBER;
                            
                            // If balance is still due, show balance due. Otherwise show down payment due
                            if ($balance_due > 0 && $order_details['order_status'] === 'ready for pickup') {
                                $amount_due = formatCurrency($balance_due);
                                $payment_type = 'Remaining Balance';
                            } else {
                                $amount_due = formatCurrency($order_details['down_payment_due'] ?? $order_details['total_amount']);
                                $payment_type = 'Amount Due';
                            }
                            
                            $full_amount = formatCurrency($order_details['total_amount']);
                        ?>
                            <div class="card mb-3 border-<?php echo $is_proof_uploaded ? 'warning' : 'success'; ?>">
                                <div class="card-header bg-<?php echo $is_proof_uploaded ? 'warning' : 'success'; ?> text-white">
                                    <h6 class="mb-0"><i class="bi bi-wallet me-2"></i><?php echo htmlspecialchars($payment_type); ?>: GCash</h6>
                                </div>
                                <div class="card-body">
                                    
                                    <?php if ($is_proof_uploaded): ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-hourglass-split me-2"></i>
                                            <strong>Proof Uploaded!</strong> Awaiting Admin verification.
                                            <a href="../<?php echo htmlspecialchars($order_details['payment_proof_path']); ?>" target="_blank" class="alert-link d-block mt-1">View Receipt Proof</a>
                                        </div>
                                    <?php elseif ($order_details['order_status'] === 'pending_payment'): // Only show form if still in initial pending_payment status ?>
                                        <p class="mb-2">Your order is placed, but requires payment confirmation before processing. **Please pay now.**</p>
                                        <h5 class="text-success">Amount Due Now (Min. 20%): <strong class="fs-4"><?php echo $amount_due; ?></strong></h5>
                                        <p class="small text-muted mb-3">Total Amount: <?php echo $full_amount; ?>. Remaining balance (if any) will be due on claim.</p>

                                        <h6 class="text-muted">Payment Details:</h6>
                                        <div class="alert alert-light p-2 small">
                                            <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                            <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                        </div>
                                        
                                        <a href="tel:<?php echo htmlspecialchars($gcash_number); ?>" class="btn btn-success w-100 mb-3">
                                            <i class="bi bi-phone me-2"></i> Tap to Pay / Open GCash App
                                        </a>

                                        <h6 class="text-muted border-top pt-3 mt-3">Upload Payment Proof</h6>
                                        <form id="gcashReceiptForm" enctype="multipart/form-data">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['order_id']; ?>">
                                            <div class="mb-3">
                                                <label for="receipt_image" class="form-label small">Upload Screenshot (JPG or PNG)</label>
                                                <input class="form-control form-control-sm" type="file" id="receipt_image" name="receipt_image" accept="image/png, image/jpeg" required>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm w-100" id="uploadBtn">
                                                <i class="bi bi-upload me-2"></i>Submit Proof
                                            </button>
                                        </form>
                                    <?php elseif ($order_details['order_status'] === 'ready for pickup' && $balance_due > 0): // Show remaining balance payment section ?>
                                        <p class="mb-2">Your order is ready for pickup, but you still need to pay the remaining balance.</p>
                                        <h5 class="text-success">Balance Due: <strong class="fs-4"><?php echo $amount_due; ?></strong></h5>
                                        <p class="small text-muted mb-3">Total Amount: <?php echo $full_amount; ?></p>

                                        <h6 class="text-muted">Payment Details:</h6>
                                        <div class="alert alert-light p-2 small">
                                            <strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?><br>
                                            <strong>Account Number:</strong> <?php echo htmlspecialchars($gcash_number); ?>
                                        </div>
                                        
                                        <a href="tel:<?php echo htmlspecialchars($gcash_number); ?>" class="btn btn-success w-100 mb-3">
                                            <i class="bi bi-phone me-2"></i> Tap to Pay / Open GCash App
                                        </a>

                                        <h6 class="text-muted border-top pt-3 mt-3">Upload Payment Proof</h6>
                                        <form id="gcashReceiptForm" enctype="multipart/form-data">
                                            <input type="hidden" name="order_id" value="<?php echo $order_details['order_id']; ?>">
                                            <div class="mb-3">
                                                <label for="receipt_image" class="form-label small">Upload Screenshot (JPG or PNG)</label>
                                                <input class="form-control form-control-sm" type="file" id="receipt_image" name="receipt_image" accept="image/png, image/jpeg" required>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm w-100" id="uploadBtn">
                                                <i class="bi bi-upload me-2"></i>Submit Proof
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="bi bi-check-circle me-2"></i>
                                            <strong>Payment Confirmed.</strong> Your order is now being processed.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 'Order Information' card intentionally removed per request -->
                        
                            <?php if ($order_details['order_status'] === 'completed'): ?>
                                <?php if (in_array($order_details['payment_status'], ['fully_paid','paid'])): ?>
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
                            <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
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
                                                'pending_payment' => 'secondary', 
                                                'pending' => 'warning',
                                                'ready for pickup' => 'info',
                                                'completed' => 'success',
                                                'cancelled' => 'danger'
                                            ];
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class[$order['order_status']] ?? 'secondary'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-muted">Total Amount:</span>
                                                <strong class="text-success"><?php echo formatCurrency($order['total_amount']); ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-muted">Payment:</span>
                                                <span><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>
                                            </div>
                                        </div>
                                        
                                        <a href="orders.php?id=<?php echo $order['order_id']; ?>" class="btn btn-primary btn-sm w-100 mb-2">
                                            <i class="bi bi-eye me-2"></i>View Details
                                        </a>
                                        <?php if (!in_array($order['order_status'], ['completed','cancelled'])): ?>
                                            <button class="btn btn-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#cancelOrderModal" data-order-id="<?php echo $order['order_id']; ?>">
                                                <i class="bi bi-x-lg me-2"></i>Cancel Order
                                            </button>
                                        <?php endif; ?>
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
    
        <!-- Cancel Order Modal -->
        <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cancelOrderModalLabel">Select reason</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                      <div class="modal-body">
                        <input type="hidden" id="cancel_order_id" value="">

                        <div id="cancel_reasons_list">
                                <div class="list-group">
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="changed_mind"> Changed mind
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="wrong_item"> Wrong item ordered
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="change_size"> Change of size
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="ordered_mistake"> Ordered by mistake
                                        </label>
                                        <label class="list-group-item">
                                            <input class="form-check-input me-2" type="radio" name="cancel_reason_radio" value="other"> Other
                                        </label>
                                </div>
                        </div>

                        <div class="mb-3 mt-3" id="cancel_reason_other_container" style="display:none;">
                            <label for="cancel_reason_other" class="form-label">If other, please specify</label>
                            <textarea id="cancel_reason_other" class="form-control" rows="3" placeholder="Type reason here..."></textarea>
                        </div>

                        <div class="small text-muted mt-3">Note: Payment is non-refundable.</div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                        <button id="cancel_confirm_btn" type="button" class="btn btn-dark w-100" disabled onclick="submitCancelFromModal(this)">Cancel order</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="../assets/js/script.js"></script>
    <script>
        // Function to display alerts
        function showAlert(message, type = 'success') {
            const alertPlaceholder = document.getElementById('alertPlaceholder');
            // Remove existing alerts
            alertPlaceholder.innerHTML = '';
            
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i>${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            alertPlaceholder.innerHTML = alertHtml;
            // Scroll to the top to show the alert
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Handle receipt upload form submission
        document.getElementById('gcashReceiptForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            const uploadBtn = document.getElementById('uploadBtn');
            
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Uploading...';

            fetch('../api/upload-gcash-receipt.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    // Reload the page to show the new status and hide the form
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500); 
                } else {
                    showAlert(data.message, 'danger');
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="bi bi-upload me-2"></i>Submit Proof';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An unexpected error occurred during upload.', 'danger');
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="bi bi-upload me-2"></i>Submit Proof';
            });
        });
    </script>
</body>
</html>