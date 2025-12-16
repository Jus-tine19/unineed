<?php

require_once '../config/database.php';
requireStudent();

// Check for required order ID parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Please provide a valid order ID in the URL.');
}

// Get and validate order ID
$order_id = intval($_GET['id']);
$download = isset($_GET['download']) && $_GET['download'] == '1';

// Get order details and verify ownership
$order_query = "SELECT o.*, i.invoice_number, i.payment_status, i.payment_date, 
                       u.full_name, u.email 
                FROM orders o 
                LEFT JOIN invoices i ON o.order_id = i.order_id
                JOIN users u ON o.user_id = u.user_id
                WHERE o.order_id = ? AND o.user_id = ?";

$stmt = mysqli_prepare($conn, $order_query);
if (!$stmt) {
    die('Database error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "ii", $order_id, $_SESSION['user_id']);
if (!mysqli_stmt_execute($stmt)) {
    die('Failed to fetch order: ' . mysqli_stmt_error($stmt));
}

$result = mysqli_stmt_get_result($stmt);
if (mysqli_num_rows($result) === 0) {
    die('Order not found or you do not have permission to view it.');
}

$order = mysqli_fetch_assoc($result);

// Check if the order is completed and paid
if ($order['order_status'] !== 'completed' || $order['payment_status'] !== 'paid') {
    die('This invoice is only available for completed and paid orders.');
}

// Get order items
$items_query = "SELECT oi.*, p.product_name 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                WHERE oi.order_id = ?";

$stmt = mysqli_prepare($conn, $items_query);
if (!$stmt) {
    die('Database error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $order_id);
if (!mysqli_stmt_execute($stmt)) {
    die('Failed to fetch order items: ' . mysqli_stmt_error($stmt));
}

$details = mysqli_stmt_get_result($stmt);

// Build invoice HTML
$invoiceHtml = '<!doctype html><html><head><meta charset="utf-8">';
$invoiceHtml .= '<title>Invoice ' . htmlspecialchars($order['invoice_number']) . '</title>';
$invoiceHtml .= '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
$invoiceHtml .= '<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .invoice-box { 
        max-width: 800px;
        margin: auto;
        border: 1px solid #eee;
        padding: 30px;
        box-shadow: 0 0 10px rgba(0,0,0,0.15);
    }
    .table td, .table th { vertical-align: middle; }
    @media print {
        body { padding: 0; }
        .invoice-box { box-shadow: none; border: none; }
    }
</style>';
$invoiceHtml .= '</head><body>';
$invoiceHtml .= '<div class="invoice-box">';
$invoiceHtml .= '<div class="row mb-4">';
$invoiceHtml .= '<div class="col-6">';
$invoiceHtml .= '<h3>UniNeeds</h3>';
$invoiceHtml .= '<p class="mb-1">Student Essentials</p>';
$invoiceHtml .= '<small class="text-muted">Bulacan, Philippines</small>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '<div class="col-6 text-end">';
$invoiceHtml .= '<h4>RECEIPT</h4>';
$invoiceHtml .= '<p class="mb-1">Invoice #: ' . htmlspecialchars($order['invoice_number']) . '</p>';
$invoiceHtml .= '<p class="mb-1">Date: ' . date('F j, Y', strtotime($order['order_date'])) . '</p>';
$invoiceHtml .= '<p class="mb-0">Payment Date: ' . date('F j, Y', strtotime($order['payment_date'])) . '</p>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '</div>';

$invoiceHtml .= '<div class="row mb-4">';
$invoiceHtml .= '<div class="col-6">';
$invoiceHtml .= '<h6>Billed To:</h6>';
$invoiceHtml .= '<p class="mb-1"><strong>' . htmlspecialchars($order['full_name']) . '</strong></p>';
$invoiceHtml .= '<p class="mb-0">' . htmlspecialchars($order['email']) . '</p>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '<div class="col-6 text-end">';
$invoiceHtml .= '<h6>Order Details:</h6>';
$invoiceHtml .= '<p class="mb-1">Order #: ' . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . '</p>';
$invoiceHtml .= '<p class="mb-0">Status: <span class="badge bg-success">Completed & Paid</span></p>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '</div>';

$invoiceHtml .= '<div class="table-responsive">';
$invoiceHtml .= '<table class="table table-bordered">';
$invoiceHtml .= '<thead class="table-light">';
$invoiceHtml .= '<tr>';
$invoiceHtml .= '<th>Product</th>';
$invoiceHtml .= '<th class="text-center" style="width: 100px;">Quantity</th>';
$invoiceHtml .= '<th class="text-end" style="width: 120px;">Unit Price</th>';
$invoiceHtml .= '<th class="text-end" style="width: 120px;">Total</th>';
$invoiceHtml .= '</tr>';
$invoiceHtml .= '</thead><tbody>';

$subtotal = 0.0;
while ($item = mysqli_fetch_assoc($details)) {
    $lineTotal = $item['price'] * $item['quantity'];
    $subtotal += $lineTotal;
    
    $invoiceHtml .= '<tr>';
    $invoiceHtml .= '<td><strong>' . htmlspecialchars($item['product_name']) . '</strong></td>';
    $invoiceHtml .= '<td class="text-center">' . $item['quantity'] . '</td>';
    $invoiceHtml .= '<td class="text-end">' . formatCurrency($item['price']) . '</td>';
    $invoiceHtml .= '<td class="text-end">' . formatCurrency($lineTotal) . '</td>';
    $invoiceHtml .= '</tr>';
}

$invoiceHtml .= '</tbody>';
$invoiceHtml .= '<tfoot class="table-light">';
$invoiceHtml .= '<tr>';
$invoiceHtml .= '<td colspan="3" class="text-end"><strong>Total Amount:</strong></td>';
$invoiceHtml .= '<td class="text-end"><strong>' . formatCurrency($order['total_amount']) . '</strong></td>';
$invoiceHtml .= '</tr>';
$invoiceHtml .= '</tfoot>';
$invoiceHtml .= '</table>';
$invoiceHtml .= '</div>';

$invoiceHtml .= '<hr class="my-4">';
$invoiceHtml .= '<div class="row">';
$invoiceHtml .= '<div class="col-md-8">';
$invoiceHtml .= '<p class="mb-1"><strong>Payment Method:</strong> Cash on Pickup</p>';
$invoiceHtml .= '<p class="mb-0"><small class="text-muted">For questions about this receipt, please contact support.</small></p>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '<div class="col-md-4 text-end">';
$invoiceHtml .= '<p class="mb-1"><strong>Paid on:</strong> ' . date('F j, Y', strtotime($order['payment_date'])) . '</p>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '</div>';

$invoiceHtml .= '<div class="text-center mt-4 pt-4 border-top">';
$invoiceHtml .= '<p class="mb-1">Thank you for your purchase!</p>';
$invoiceHtml .= '<small class="text-muted">This is an official receipt from UniNeeds.</small><br>';
$invoiceHtml .= '<small class="text-muted">Generated on ' . date('F j, Y g:i A') . '</small>';
$invoiceHtml .= '</div>';
$invoiceHtml .= '</div></body></html>';

if ($download) {
    // For PDF download, just show a print-friendly version
    $printHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice ' . htmlspecialchars($order['invoice_number']) . '</title>
    <style>
        @media print {
            body { 
                font-family: Arial, sans-serif;
                line-height: 1.6;
                margin: 0;
                padding: 20px;
            }
            .no-print { display: none; }
            .page-break { page-break-after: always; }
        }
        body { 
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .invoice-box {
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 30px;
        }
        .flex {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .company-info {
            text-align: left;
        }
        .invoice-info {
            text-align: right;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
        }
        .text-end {
            text-align: right;
        }
        .total-row td {
            font-weight: bold;
            font-size: 16px;
        }
        .print-button {
            text-align: center;
            margin: 20px 0;
        }
        @media screen {
            .print-button button {
                background: #007bff;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
            }
            .print-button button:hover {
                background: #0056b3;
            }
        }
    </style>
</head>
<body>
    <div class="no-print print-button">
        <button onclick="window.print()">Print/Save as PDF</button>
    </div>

    <div class="header">
        <h2>OFFICIAL RECEIPT</h2>
    </div>

    <div class="invoice-box">
        <div class="flex">
            <div class="company-info">
                <h3>UniNeeds</h3>
                <p>Student Essentials<br>Bulacan, Philippines</p>
            </div>
            <div class="invoice-info">
                <h4>Receipt #: ' . htmlspecialchars($order['invoice_number']) . '</h4>
                <p>Date: ' . date('F j, Y', strtotime($order['order_date'])) . '<br>
                   Payment Date: ' . date('F j, Y', strtotime($order['payment_date'])) . '</p>
            </div>
        </div>

        <div class="flex">
            <div>
                <strong>Bill To:</strong><br>
                ' . htmlspecialchars($order['full_name']) . '<br>
                ' . htmlspecialchars($order['email']) . '
            </div>
            <div>
                <strong>Order #:</strong> ' . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . '<br>
                <strong>Status:</strong> Completed & Paid
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>';

    // Reset pointer for items
    mysqli_data_seek($details, 0);
    while ($item = mysqli_fetch_assoc($details)) {
        $lineTotal = $item['price'] * $item['quantity'];
        $printHtml .= '
                <tr>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td class="text-end">' . $item['quantity'] . '</td>
                    <td class="text-end">' . formatCurrency($item['price']) . '</td>
                    <td class="text-end">' . formatCurrency($lineTotal) . '</td>
                </tr>';
    }

    $printHtml .= '
                <tr class="total-row">
                    <td colspan="3" class="text-end">Total Amount:</td>
                    <td class="text-end">' . formatCurrency($order['total_amount']) . '</td>
                </tr>
            </tbody>
        </table>

        <hr>

        <div class="flex">
            <div>
                <strong>Payment Method:</strong> Cash on Pickup<br>
                <small>For questions about this receipt, please contact support.</small>
            </div>
            <div class="text-end">
                <strong>Paid on:</strong><br>
                ' . date('F j, Y', strtotime($order['payment_date'])) . '
            </div>
        </div>
    </div>

    <div style="text-align: center; margin-top: 40px;">
        <p>Thank you for your purchase!</p>
        <small style="color: #666;">This is an official receipt from UniNeeds.</small><br>
        <small style="color: #666;">Generated on ' . date('F j, Y g:i A') . '</small>
    </div>

    <script>
        // Automatically open print dialog
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>';

    echo $printHtml;
    exit();
}

// Otherwise, render HTML invoice
echo $invoiceHtml;
