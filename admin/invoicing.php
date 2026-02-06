<?php

require_once '../config/database.php';
requireAdmin();

// Fix existing invoices with incorrect downpayment calculations
// For invoices with down_payment_due > 0, ensure remaining_balance = total_amount - down_payment_due
$fix_query = "UPDATE invoices i
              JOIN orders o ON i.order_id = o.order_id
              SET i.remaining_balance = ROUND(o.total_amount - i.down_payment_due, 2),
                  i.balance_due = ROUND(o.total_amount - i.down_payment_due, 2),
                  i.amount_paid = i.down_payment_due
              WHERE i.down_payment_due > 0 AND i.payment_status != 'paid'";
mysqli_query($conn, $fix_query);

// Handle Generate Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $order_id = clean($_POST['order_id']);
    
    // Check if invoice already exists and order is not cancelled
    $check = "SELECT o.*, i.invoice_id FROM orders o LEFT JOIN invoices i ON o.order_id = i.order_id WHERE o.order_id = $order_id LIMIT 1";
    $check_result = mysqli_query($conn, $check);
    $order_data = $check_result ? mysqli_fetch_assoc($check_result) : null;

    if ($order_data && empty($order_data['invoice_id']) && $order_data['order_status'] !== 'cancelled') {
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        
        // Get all items in the order to check if any requires downpayment
        $items_check = "SELECT oi.*, p.requires_down_payment FROM order_items oi 
                        JOIN products p ON oi.product_id = p.product_id 
                        WHERE oi.order_id = $order_id";
        $items_result = mysqli_query($conn, $items_check);
        
        $requires_downpayment = false;
        if ($items_result) {
            while ($item = mysqli_fetch_assoc($items_result)) {
                if ($item['requires_down_payment']) {
                    $requires_downpayment = true;
                    break;
                }
            }
        }
        
        // Calculate downpayment if required
        $down_payment_due = 0;
        $remaining_balance = 0;
        $amount_paid = 0;
        $balance_due = 0;
        $payment_status = 'unpaid';
        
        if ($requires_downpayment) {
            // Calculate 20% downpayment
            $down_payment_due = round($order_data['total_amount'] * 0.20, 2);
            // Remaining balance = Total - Downpayment
            $remaining_balance = round($order_data['total_amount'] - $down_payment_due, 2);
            // For unpaid orders with downpayment: amount_paid = downpayment, balance_due = remaining balance
            $amount_paid = $down_payment_due;
            $balance_due = $remaining_balance;
        } else {
            // No downpayment: full amount is balance due
            $down_payment_due = 0;
            $remaining_balance = 0;
            $amount_paid = 0;
            $balance_due = $order_data['total_amount'];
        }
        
        // First, try to update existing invoice with correct values
        $update_query = "UPDATE invoices 
                         SET down_payment_due = $down_payment_due,
                             remaining_balance = $remaining_balance,
                             amount_paid = $amount_paid,
                             balance_due = $balance_due,
                             payment_status = '$payment_status'
                         WHERE order_id = $order_id AND invoice_id IS NOT NULL";
        
        // If no invoice exists, create new one
        if (!mysqli_query($conn, $update_query) || mysqli_affected_rows($conn) === 0) {
            $query = "INSERT INTO invoices (order_id, invoice_number, payment_status, amount_paid, balance_due, down_payment_due, remaining_balance) 
                      VALUES ($order_id, '$invoice_number', '$payment_status', $amount_paid, $balance_due, $down_payment_due, $remaining_balance)";
            
            if (!mysqli_query($conn, $query)) {
                $error = "Failed to generate invoice.";
            } else {
                $success = "Invoice generated successfully!";
            }
        } else {
            $success = "Invoice generated successfully!";
        }
    } elseif ($order_data && $order_data['order_status'] === 'cancelled') {
        $error = "Cannot generate invoice for cancelled orders.";
    } else {
        $error = "Invoice already exists for this order.";
    }
}

// Handle Update Payment Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $invoice_id = clean($_POST['invoice_id']);
    $payment_status = clean($_POST['payment_status']);
    
    if ($payment_status === 'paid') {
        // When marking as paid, set amount_paid to total amount and balance_due to 0
        $query = "UPDATE invoices i
                  JOIN orders o ON i.order_id = o.order_id
                  SET i.payment_status = 'paid', 
                      i.payment_date = NOW(),
                      i.amount_paid = o.total_amount,
                      i.balance_due = 0,
                      i.remaining_balance = 0
                  WHERE i.invoice_id = $invoice_id";
    } else {
        // When marking as unpaid, set amount_paid to downpayment and balance_due to remaining balance
        $query = "UPDATE invoices i
                  SET i.payment_status = 'unpaid', 
                      i.payment_date = NULL,
                      i.amount_paid = COALESCE(i.down_payment_due, 0),
                      i.balance_due = COALESCE(i.remaining_balance, 0)
                  WHERE i.invoice_id = $invoice_id";
    }
    
    if (mysqli_query($conn, $query)) {
        // Get user_id from order
        $get_user = "SELECT o.user_id FROM invoices i JOIN orders o ON i.order_id = o.order_id WHERE i.invoice_id = $invoice_id";
        $user_result = mysqli_query($conn, $get_user);
        $user_data = mysqli_fetch_assoc($user_result);
        
        // Create notification
        $message = "Your payment status has been updated to: " . ucfirst($payment_status);
        $notif_query = "INSERT INTO notifications (user_id, message, type) VALUES ({$user_data['user_id']}, '$message', 'payment')";
        mysqli_query($conn, $notif_query);
        
        $success = "Payment status updated successfully!";
    } else {
        $error = "Failed to update payment status.";
    }
}

// Get invoices
$status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';

$where_clauses = [];
if ($status_filter) {
    $where_clauses[] = "i.payment_status = '$status_filter'";
}
if ($search) {
    $where_clauses[] = "(i.invoice_number LIKE '%$search%' OR u.full_name LIKE '%$search%')";
}
// Always exclude cancelled orders from invoice list
$where_clauses[] = "o.order_status != 'cancelled'";
$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT i.*, o.total_amount, o.order_date, o.payment_method, u.full_name, u.email 
          FROM invoices i
          JOIN orders o ON i.order_id = o.order_id
          JOIN users u ON o.user_id = u.user_id
          $where_sql
          ORDER BY i.invoice_date DESC";
$invoices = mysqli_query($conn, $query);

// Get orders without invoices
$no_invoice_query = "SELECT o.*, u.full_name FROM orders o 
                     JOIN users u ON o.user_id = u.user_id 
                     WHERE o.order_id NOT IN (SELECT order_id FROM invoices) 
                     AND o.order_status != 'cancelled'
                     ORDER BY o.order_date DESC";
$no_invoice_orders = mysqli_query($conn, $no_invoice_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoicing - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .invoice-expanded-row {
            background-color: #f8f9fa;
        }
        .invoice-expanded-content {
            padding: 20px;
            border-left: 4px solid #0dcaf0;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        /* Receipt modal tweaks: reduce space between iframe content and footer */
        .receipt-modal .modal-body {
            padding-bottom: 0; /* remove bottom padding to bring footer closer */
        }
        .receipt-modal iframe {
            height: 500px;
        }
        /* Pull footer up over the modal body to reduce visual gap */
        .receipt-modal .modal-footer {
            margin-top: -95px;
            background: transparent;
            border-top: none;
            justify-content: flex-end;
            gap: .75rem;
            padding: 0.75rem 1rem;
        }

        /* Gradient download button - blue gradient */
        .btn-gradient-success {
            color: #fff;
            background: linear-gradient(135deg, #0066ff 0%, #0099ff 100%);
            border: none;
            box-shadow: 0 6px 18px rgba(0,102,255,0.3);
        }
        .btn-gradient-success:hover {
            filter: brightness(0.92);
        }
        .receipt-modal .modal-footer .btn {
            padding: .5rem .9rem;
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
            <h2>Invoicing</h2>
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
            
            <!-- Orders Without Invoices -->
            <?php if (mysqli_num_rows($no_invoice_orders) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Orders Without Invoices</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($order = mysqli_fetch_assoc($no_invoice_orders)): ?>
                                        <tr>
                                            <td>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                            <td><?php echo htmlspecialchars($order['full_name']); ?></td>
                                            <td><?php echo formatCurrency($order['total_amount']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                    <button type="submit" name="generate_invoice" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-file-earmark-plus me-1"></i>Generate Invoice
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <form id="invoicesFilterForm" method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="search" placeholder="Search by invoice number or customer name" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <a href="invoicing.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Invoices Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Invoice History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice Number</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Invoice Date</th>
                                    <th>Payment Status</th>
                                    <th>Payment Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($invoices) > 0): ?>
                                    <?php while ($invoice = mysqli_fetch_assoc($invoices)): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($invoice['full_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($invoice['email']); ?></small>
                                            </td>
                                            <td><strong><?php echo formatCurrency($invoice['total_amount']); ?></strong></td>
                                            <td><?php echo date('M j, Y', strtotime($invoice['invoice_date'])); ?></td>
                                            <td>
                                                <?php if ($invoice['payment_status'] === 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $invoice['payment_date'] ? date('M j, Y', strtotime($invoice['payment_date'])) : '-'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-info btn-action" onclick="toggleInvoiceRow(<?php echo $invoice['invoice_id']; ?>)" title="View Invoice">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary btn-action" title="Print Invoice" data-bs-toggle="modal" data-bs-target="#receiptModal<?php echo $invoice['invoice_id']; ?>">
                                                        <i class="bi bi-printer"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#updatePayment<?php echo $invoice['invoice_id']; ?>" title="Update Payment">
                                                        <i class="bi bi-cash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Expandable Invoice Details Row -->
                                        <tr id="invoiceRow<?php echo $invoice['invoice_id']; ?>" class="invoice-expanded-row" style="display: none;">
                                            <td colspan="7" class="p-0">
                                                <div class="invoice-expanded-content">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Invoice Details</h6>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleInvoiceRow(<?php echo $invoice['invoice_id']; ?>)">
                                                            <i class="bi bi-x-lg me-1"></i>Close
                                                        </button>
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <h6>Bill To:</h6>
                                                            <p>
                                                                <strong><?php echo htmlspecialchars($invoice['full_name']); ?></strong><br>
                                                                <?php echo htmlspecialchars($invoice['email']); ?>
                                                            </p>
                                                        </div>
                                                        <div class="col-md-6 text-end">
                                                            <h6>Invoice & Payment Info:</h6>
                                                            <p>
                                                                <strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br>
                                                                <strong>Date:</strong> <?php echo date('F j, Y', strtotime($invoice['invoice_date'])); ?><br>
                                                                <strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_method'])); ?><br>
                                                                <?php if ($invoice['payment_date']): ?>
                                                                    <strong>Paid:</strong> <?php echo date('F j, Y', strtotime($invoice['payment_date'])); ?>
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Payment Summary Card -->
                                                    <div class="card card-body bg-light mb-3">
                                                        <h6 class="mb-3"><i class="bi bi-cash-coin me-2"></i>Payment Summary</h6>
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <p class="mb-2"><strong>Total Amount:</strong> <span class="text-success"><?php echo formatCurrency($invoice['total_amount']); ?></span></p>
                                                                <?php if (!empty($invoice['down_payment_due'])): ?>
                                                                    <p class="mb-2"><strong>Down Payment Due:</strong> <span class="text-info"><?php echo formatCurrency($invoice['down_payment_due']); ?></span></p>
                                                                    <p class="mb-0"><strong>Remaining Balance:</strong> <span class="text-warning"><?php echo formatCurrency($invoice['remaining_balance'] ?? 0); ?></span></p>
                                                                <?php else: ?>
                                                                    <p class="mb-0"><strong>Full Payment Due:</strong> <span class="text-primary"><?php echo formatCurrency($invoice['total_amount']); ?></span></p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <p class="mb-2"><strong>Amount Paid:</strong> <span class="text-success"><?php echo formatCurrency($invoice['amount_paid'] ?? 0); ?></span></p>
                                                                <p class="mb-0"><strong>Balance Due:</strong> <span class="text-danger"><?php echo formatCurrency($invoice['balance_due'] ?? 0); ?></span></p>
                                                                <p class="mb-0 mt-2"><strong>Status:</strong> 
                                                                    <?php if ($invoice['payment_status'] === 'paid'): ?>
                                                                        <span class="badge bg-success">Paid</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning">Pending</span>
                                                                    <?php endif; ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php
                                                    $items_query = "SELECT oi.*, p.product_name 
                                                                   FROM order_items oi 
                                                                   JOIN products p ON oi.product_id = p.product_id 
                                                                   WHERE oi.order_id = {$invoice['order_id']}";
                                                    $items = mysqli_query($conn, $items_query);
                                                    ?>
                                                    
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <th>Variant</th>
                                                                <th class="text-center">Quantity</th>
                                                                <th class="text-end">Price</th>
                                                                <th class="text-end">Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php while ($item = mysqli_fetch_assoc($items)): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                                    <td>
                                                                        <?php 
                                                                        $variant_text = '';
                                                                        if (!empty($item['variant_value'])) {
                                                                            $decoded = json_decode($item['variant_value'], true);
                                                                            if (is_array($decoded)) {
                                                                                $variant_text = implode(', ', array_map(function($type, $value) {
                                                                                    return ucfirst($type) . ': ' . $value;
                                                                                }, array_keys($decoded), $decoded));
                                                                            } else {
                                                                                $variant_text = $item['variant_value'];
                                                                            }
                                                                        }
                                                                        echo $variant_text ? htmlspecialchars($variant_text) : '-';
                                                                        ?>
                                                                    </td>
                                                                    <td class="text-center"><?php echo $item['quantity']; ?></td>
                                                                    <td class="text-end"><?php echo formatCurrency($item['price']); ?></td>
                                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                                </tr>
                                                            <?php endwhile; ?>
                                                        </tbody>
                                                        <tfoot>
                                                            <tr>
                                                                <th colspan="4" class="text-end">Total Amount:</th>
                                                                <th class="text-end"><?php echo formatCurrency($invoice['total_amount']); ?></th>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                    
                                                    <div class="mt-3">
                                                        <p class="text-muted mb-0"><small>Payment Method: Cash on Pickup</small></p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Update Payment Modal -->
                                        <div class="modal fade" id="updatePayment<?php echo $invoice['invoice_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Update Payment Status</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $invoice['invoice_id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Invoice Number</label>
                                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['invoice_number']); ?>" readonly>
                                                            </div>
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Total Amount</label>
                                                                    <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['total_amount']); ?>" readonly>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Amount Paid</label>
                                                                    <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['amount_paid'] ?? 0); ?>" readonly>
                                                                </div>
                                                            </div>
                                                            <?php if (!empty($invoice['down_payment_due'])): ?>
                                                                <div class="alert alert-info mb-3">
                                                                    <strong>Downpayment Plan:</strong><br>
                                                                    Down Payment Due: <strong><?php echo formatCurrency($invoice['down_payment_due']); ?></strong><br>
                                                                    Remaining Balance: <strong><?php echo formatCurrency($invoice['remaining_balance'] ?? 0); ?></strong><br>
                                                                    <hr class="my-2">
                                                                    <strong>Balance Due:</strong> <span class="text-danger"><?php echo formatCurrency($invoice['balance_due'] ?? 0); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Status *</label>
                                                                <select class="form-select" name="payment_status" required>
                                                                    <option value="unpaid" <?php echo $invoice['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                                                    <option value="paid" <?php echo $invoice['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="update_payment" class="btn btn-primary">Update Status</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Receipt Modal (loads student receipt template) -->
                                        <div class="modal fade receipt-modal" id="receiptModal<?php echo $invoice['invoice_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-xl">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Receipt Preview</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <iframe id="receiptIframe<?php echo $invoice['invoice_id']; ?>" src="../student/receipt.php?order_id=<?php echo $invoice['order_id']; ?>" style="width: 100%; height: 600px; border: none;"></iframe>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <a href="../student/receipt.php?order_id=<?php echo $invoice['order_id']; ?>&download=pdf" target="_blank" class="btn btn-sm btn-gradient-success me-2">
                                                            <i class="bi bi-download"></i> Download PDF
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-primary me-2" onclick="printReceiptIframe('receiptIframe<?php echo $invoice['invoice_id']; ?>')">
                                                            <i class="bi bi-printer me-1"></i> Print Receipt
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="bi bi-receipt"></i>
                                                <h5>No Invoices Found</h5>
                                                <p>No invoices match your search criteria.</p>
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
        function toggleInvoiceRow(invoiceId) {
            const row = document.getElementById('invoiceRow' + invoiceId);
            
            if (row.style.display === 'none' || row.style.display === '') {
                // Close all other expanded rows first
                const allRows = document.querySelectorAll('.invoice-expanded-row');
                allRows.forEach(r => r.style.display = 'none');
                
                // Show this row
                row.style.display = 'table-row';
            } else {
                // Hide this row
                row.style.display = 'none';
            }
        }
    </script>
    <script>
        // Reload page after payment status update to refresh invoice details
        document.addEventListener('DOMContentLoaded', function() {
            // Find all update payment forms and listen for submission
            document.querySelectorAll('form').forEach(form => {
                // Check if this is an update payment form (has update_payment input)
                if (form.querySelector('input[name="update_payment"]')) {
                    form.addEventListener('submit', function() {
                        // Delay reload slightly to ensure server-side update completes
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    });
                }
            });
        });

        // Auto-submit invoice filter form on change (debounced)
        (function(){
            const form = document.getElementById('invoicesFilterForm');
            if (!form) return;
            let debounceTimer = null;
            const submitDebounced = (delay) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => form.submit(), delay || 300);
            };

            form.querySelectorAll('select, input').forEach(el => {
                el.addEventListener('change', () => submitDebounced(300));
                if (el.tagName === 'INPUT' && el.type === 'text') {
                    el.addEventListener('input', () => submitDebounced(800));
                }
            });
        })();
    </script>
    <script>
        function printReceiptIframe(iframeId) {
            var iframe = document.getElementById(iframeId);





</html></body>    </script>        }            iframe.contentWindow.print();            if (!iframe) return;
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                // Fallback: open receipt in new tab
                window.open(iframe.src, '_blank');
            }
        }
    </script>
</body>
</html>