<?php
// admin/invoicing.php
require_once '../config/database.php';
requireAdmin();

// Handle Generate Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $order_id = clean($_POST['order_id']);
    
    // Check if invoice already exists and order is not cancelled
    $check = "SELECT o.order_status FROM orders o LEFT JOIN invoices i ON o.order_id = i.order_id WHERE o.order_id = $order_id";
    $check_result = mysqli_query($conn, $check);
    $order_data = mysqli_fetch_assoc($check_result);
    
    if (!$order_data['invoice_id'] && $order_data['order_status'] !== 'cancelled') {
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        $query = "INSERT INTO invoices (order_id, invoice_number) VALUES ($order_id, '$invoice_number')";
        
        if (mysqli_query($conn, $query)) {
            $success = "Invoice generated successfully!";
        } else {
            $error = "Failed to generate invoice.";
        }
    } elseif ($order_data['order_status'] === 'cancelled') {
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
        $query = "UPDATE invoices SET payment_status = 'paid', payment_date = NOW() WHERE invoice_id = $invoice_id";
    } else {
        $query = "UPDATE invoices SET payment_status = 'unpaid', payment_date = NULL WHERE invoice_id = $invoice_id";
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

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$query = "SELECT i.*, o.total_amount, o.order_date, u.full_name, u.email 
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
                <form method="GET" class="row g-3">
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
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-2"></i>Filter
                        </button>
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
                                                    <a href="invoice.php?invoice_id=<?php echo $invoice['invoice_id']; ?>" class="btn btn-sm btn-secondary btn-action" title="Print Invoice" target="_blank">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
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
                                                            <h6>Invoice Info:</h6>
                                                            <p>
                                                                <strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br>
                                                                <strong>Date:</strong> <?php echo date('F j, Y', strtotime($invoice['invoice_date'])); ?><br>
                                                                <?php if ($invoice['payment_date']): ?>
                                                                    <strong>Paid:</strong> <?php echo date('F j, Y', strtotime($invoice['payment_date'])); ?>
                                                                <?php endif; ?>
                                                            </p>
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
                                                                <th class="text-center">Quantity</th>
                                                                <th class="text-end">Price</th>
                                                                <th class="text-end">Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php while ($item = mysqli_fetch_assoc($items)): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                                    <td class="text-center"><?php echo $item['quantity']; ?></td>
                                                                    <td class="text-end"><?php echo formatCurrency($item['price']); ?></td>
                                                                    <td class="text-end"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
                                                                </tr>
                                                            <?php endwhile; ?>
                                                        </tbody>
                                                        <tfoot>
                                                            <tr>
                                                                <th colspan="3" class="text-end">Total Amount:</th>
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
                                                            <div class="mb-3">
                                                                <label class="form-label">Amount</label>
                                                                <input type="text" class="form-control" value="<?php echo formatCurrency($invoice['total_amount']); ?>" readonly>
                                                            </div>
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
</body>
</html>