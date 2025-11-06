<?php
require_once '../config/database.php';
requireAdmin();

if (!isset($_GET['invoice_id'])) {
    header('Location: invoicing.php');
    exit;
}

$invoice_id = intval($_GET['invoice_id']);

// Get invoice details with customer info
$invoice_query = "SELECT i.*, o.total_amount, o.order_date, o.order_id, u.full_name, u.email, u.phone 
                 FROM invoices i
                 JOIN orders o ON i.order_id = o.order_id
                 JOIN users u ON o.user_id = u.user_id 
                 WHERE i.invoice_id = ?";
$stmt = mysqli_prepare($conn, $invoice_query);
mysqli_stmt_bind_param($stmt, "i", $invoice_id);
mysqli_stmt_execute($stmt);
$invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$invoice) {
    header('Location: invoicing.php');
    exit;
}

// Get order items - simplified query without variant_id
$items_query = "SELECT oi.*, p.product_name 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                WHERE oi.order_id = ?";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $invoice['order_id']);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo $invoice['invoice_number']; ?> - UniNeeds</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body { 
                margin: 0;
                padding: 10px;
            }
            .no-print {
                display: none !important;
            }
            .table {
                width: 100% !important;
            }
        }
        .invoice {
            max-width: 800px;
            margin: 0 auto;
            padding: 10px;
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 10px;
        }
        .invoice-logo {
            max-width: 200px;
            margin-bottom: 10px;
        }
        .customer-info {
            margin-bottom: 10px;
        }
        .invoice-table th,
        .invoice-table td {
            padding: 8px;
        }
        .invoice-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }
        .payment-status {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
            color: #198754;
        }
        .payment-status.unpaid {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="no-print mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Invoice
            </button>
            <a href="invoicing.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Invoicing
            </a>
        </div>

        <div class="invoice-header">
            <img src="../assets/images/receiptlogo.png" alt="UniNeeds Logo" class="invoice-logo">
            <h2>INVOICE</h2>
            <div class="payment-status <?php echo $invoice['payment_status']; ?>">
                <?php echo strtoupper($invoice['payment_status']); ?>
            </div>
        </div>

        <div class="row customer-info">
            <div class="col-md-6">
                <h5>Bill To:</h5>
                <p>
                    <strong><?php echo htmlspecialchars($invoice['full_name']); ?></strong><br>
                    Email: <?php echo htmlspecialchars($invoice['email']); ?><br>
                    Phone: <?php echo htmlspecialchars($invoice['phone']); ?>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <h5>Invoice Details:</h5>
                <p>
                    <strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br>
                    <strong>Order #:</strong> <?php echo str_pad($invoice['order_id'], 6, '0', STR_PAD_LEFT); ?><br>
                    <strong>Invoice Date:</strong> <?php echo date('F j, Y', strtotime($invoice['invoice_date'])); ?><br>
                    <?php if ($invoice['payment_date']): ?>
                        <strong>Payment Date:</strong> <?php echo date('F j, Y', strtotime($invoice['payment_date'])); ?><br>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <table class="table table-bordered invoice-table">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th class="text-center" width="100">Quantity</th>
                    <th class="text-end" width="120">Price</th>
                    <th class="text-end" width="120">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                while ($item = mysqli_fetch_assoc($items)):
                    $itemTotal = $item['price'] * $item['quantity'];
                    $total += $itemTotal;
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['price']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($itemTotal); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-end"><strong>Total Amount</strong></td>
                    <td class="text-end"><strong><?php echo formatCurrency($total); ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <div class="row mt-4">
            <div class="col-md-6">
                <h6>Payment Method:</h6>
                <p>Cash on Pickup</p>
            </div>
            <div class="col-md-6 text-md-end">
                <h6>Status:</h6>
                <p>
                    <strong>Payment Status:</strong> <?php echo ucfirst($invoice['payment_status']); ?><br>
                    <?php if ($invoice['payment_date']): ?>
                        <small class="text-muted">Paid on <?php echo date('F j, Y', strtotime($invoice['payment_date'])); ?></small>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="invoice-footer">
            <hr>
            <p><strong>UniNeeds - Student Essentials</strong></p>
            <p>Bulacan, Philippines</p>
            <p><small>This is a computer-generated invoice. No signature required.</small></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>