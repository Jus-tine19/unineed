<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

requireStudent();

if (!isset($_GET['order_id'])) {
    header('Location: order-history.php');
    exit;
}

$order_id = intval($_GET['order_id']);

// Get order details
$order_query = "SELECT o.*, u.full_name, u.email, u.phone 
                FROM orders o 
                JOIN users u ON o.user_id = u.user_id 
                WHERE o.order_id = ? AND o.user_id = ?";
$stmt = mysqli_prepare($conn, $order_query);
mysqli_stmt_bind_param($stmt, "ii", $order_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$order) {
    header('Location: order-history.php');
    exit;
}

// Get order items
$items_query = "SELECT oi.*, p.product_name, pv.variant_type, pv.variant_value 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id 
                WHERE oi.order_id = ?";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);

// Get invoice number if exists
$invoice_query = "SELECT invoice_number FROM invoices WHERE order_id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $invoice_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$invoice_result = mysqli_stmt_get_result($stmt);
$invoice = mysqli_fetch_assoc($invoice_result);

// Create PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);

// Get the absolute path to the logo
$logo_path = $_SERVER['DOCUMENT_ROOT'] . '/unineeds/assets/images/logo.png';
$logo_data = base64_encode(file_get_contents($logo_path));

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { 
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .receipt {
            max-width: 800px;
            margin: 0 auto;
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .receipt-logo {
            max-width: 150px;
            margin-bottom: 15px;
        }
        .row {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .text-right {
            text-align: right;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th {
            background-color: #f4f4f4;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #666;
        }
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="receipt-header">
            <img src="data:image/png;base64,' . $logo_data . '" class="receipt-logo">
            <h2>Order Receipt</h2>
            <p>Thank you for shopping with UniNeeds!</p>
        </div>

        <div class="row">
            <div class="col">
                <h4>Customer Information</h4>
                <p>
                    <strong>Name:</strong> ' . htmlspecialchars($order['full_name']) . '<br>
                    <strong>Email:</strong> ' . htmlspecialchars($order['email']) . '<br>
                    <strong>Phone:</strong> ' . htmlspecialchars($order['phone']) . '
                </p>
            </div>
            <div class="col text-right">
                <h4>Order Details</h4>
                <p>
                    <strong>Order #:</strong> ' . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . '<br>
                    ' . ($invoice ? '<strong>Invoice #:</strong> ' . htmlspecialchars($invoice['invoice_number']) . '<br>' : '') . '
                    <strong>Date:</strong> ' . date('F j, Y g:i A', strtotime($order['created_at'])) . '<br>
                    <strong>Payment Method:</strong> Cash on Pickup
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="width: 80px; text-align: center;">Quantity</th>
                    <th style="width: 100px; text-align: right;">Price</th>
                    <th style="width: 100px; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>';

$total = 0;
mysqli_data_seek($items, 0);
while ($item = mysqli_fetch_assoc($items)) {
    $itemTotal = $item['price'] * $item['quantity'];
    $total += $itemTotal;
    
    $html .= '
        <tr>
            <td>
                ' . htmlspecialchars($item['product_name']) . '
                ' . ($item['variant_type'] ? '<br><small style="color: #666;">'.htmlspecialchars($item['variant_type'].': '.$item['variant_value']).'</small>' : '') . '
            </td>
            <td style="text-align: center;">' . $item['quantity'] . '</td>
            <td style="text-align: right;">' . formatCurrency($item['price']) . '</td>
            <td style="text-align: right;">' . formatCurrency($itemTotal) . '</td>
        </tr>';
}

$html .= '
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align: right;"><strong>Total Amount</strong></td>
                    <td style="text-align: right;"><strong>' . formatCurrency($total) . '</strong></td>
                </tr>
            </tfoot>
        </table>

        <div class="receipt-footer">
            <p><strong>UniNeeds - Student Essentials</strong></p>
            <p>Bulacan, Philippines</p>
            <hr>
            <small>This is a computer-generated receipt. No signature required.</small>
        </div>
    </div>
</body>
</html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Generate file name
$filename = 'Receipt_' . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . '.pdf';

// Output PDF
$dompdf->stream($filename, array('Attachment' => true));