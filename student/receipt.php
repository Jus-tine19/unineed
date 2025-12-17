<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Function to format currency with peso sign
function formatPeso($amount) {
    return '₱' . number_format($amount, 2);
}

requireLogin(); // Allow both students and admins

if (!isset($_GET['order_id'])) {
    header('Location: orders.php');
    exit;
}

// Check if we should output PDF
$output_pdf = isset($_GET['download']) && $_GET['download'] === 'pdf';

// If PDF output is requested, start output buffering immediately
if ($output_pdf) {
    ob_start();
}

$order_id = intval($_GET['order_id']);

// Get order details
if (isAdmin()) {
    $order_query = "SELECT o.*, u.full_name, u.email, u.phone 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.order_id = ?";
    $stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
} else {
    $order_query = "SELECT o.*, u.full_name, u.email, u.phone 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.order_id = ? AND o.user_id = ?";
    $stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($stmt, "ii", $order_id, $_SESSION['user_id']);
}
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items
$items_query = "SELECT oi.*, p.product_name 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.product_id 
                WHERE oi.order_id = ?";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$items = mysqli_stmt_get_result($stmt);

// Store items in array for multiple use
$item_list = [];
while ($item = mysqli_fetch_assoc($items)) {
    $item_list[] = $item;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <?php if (!$output_pdf): ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <title>Receipt #<?php echo $order['order_id']; ?> - UniNeeds</title>
    <style>
        @media print {
            body { 
                margin: 0;
                padding: 15px;
            }
            .no-print {
                display: none !important;
            }
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .receipt {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            position: relative;
            padding: 10px 0;
            border-bottom: 2px solid #ddd;
            margin-bottom: 10px;
            text-align: center;
        }
        .company-info {
            text-align: center;
          
        }
        .company-info .tagline {
            font-style: italic;
            color: #666;
        }
        .receipt-title {
            text-align: center;
            color: #333;
            font-size: 14px;
            margin: 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .customer-details, .invoice-details {
            flex: 1;
        }
        .invoice-details {
            text-align: right;
        }
        .info-section h4 {
            color: #137a21ff;
            margin-bottom: 5px;
            font-size: 12px;
        }
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
        }
        .receipt-table th {
            background: #00cc44ff;
            color: white;
            padding: 12px;
            font-weight: 500;
        }
        .receipt-table td {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .receipt-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .text-end {
            text-align: right;
        }
        .total-section {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .total-amount {
            font-size: 18px;
            color: #20a706ff;
            text-align: right;
        }
        .payment-method {
            margin-top: 20px;
            padding: 15px;
            background: #e9ecef;
            border-radius: 8px;
            text-align: center;
            border: 1px dashed #0f9c33ff;
        }
        .receipt-footer {
            margin-top: 10px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            opacity: 0.03;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
        }
        @media print {
            body {
                display: flex;
                justify-content: center;
                align-items: flex-start;
            }
            .receipt {
                width: 104mm;
                max-width: none;
                margin: 1mm;
                padding: 3mm;
                box-shadow: none;
                flex-shrink: 0;
                border: 1px dashed #000;
            }
            .no-print {
                display: none;
            }
            @page {
                size: letter;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>
    <?php if (!$output_pdf): ?>
    <div class="no-print mb-3">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
        <a href="receipt.php?order_id=<?php echo $order_id; ?>&download=pdf" class="btn btn-success">
            <i class="bi bi-download"></i> Download PDF
        </a>
        <a href="orders.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Orders
        </a>
    </div>
    <?php endif; ?>

    <?php for ($copy = 1; $copy <= ($output_pdf ? 1 : 2); $copy++): ?>
    <div class="receipt">
        <div class="receipt-header">
            
            <h2>UniNeeds Store</h2>
            <h5><p><i>Study ready. Style steady.</i></p></h5>
    
        </div>

        <div class="info-section">
            <div class="customer-details">
                <h4>Customer Information</h4>
                <p><strong><?php echo htmlspecialchars($order['full_name']); ?></strong></p>
                <p><?php echo htmlspecialchars($order['email']); ?></p>
                <p><?php echo htmlspecialchars($order['phone']); ?></p>
            </div>

            <div class="invoice-details">
                <h4>Invoice Details</h4>
                <p><strong>Invoice #:</strong> <?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($order['order_date'])); ?></p>
            </div>
        </div>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="width: 100px;">Quantity</th>
                    <th style="width: 120px;" class="text-end">Price</th>
                    <th style="width: 120px;" class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = 0;
                foreach ($item_list as $item):
                    $itemTotal = $item['price'] * $item['quantity'];
                    $total += $itemTotal;
                ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($item['product_name']); ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo formatPeso($item['price']); ?></td>
                        <td class="text-end"><?php echo formatPeso($itemTotal); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-section">
            <div class="total-amount">
                Total Amount:<?php echo formatPeso($total); ?>
            </div>
        </div>

        <div class="payment-method">
            <strong>Payment Method:</strong> <?php echo $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash on Pickup'; ?>
        </div>

    </div>
    <?php endfor; ?>

    <?php if (!$output_pdf): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
</body>
</html>
<?php
if ($output_pdf) {
    $html = ob_get_clean(); // Get current buffer contents and clean it
    
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultPaperSize', 'A4');
    $options->set('chroot', realpath(dirname(__FILE__) . '/../'));
    $options->set('enable_remote', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait'); // Letter size for PDF
    $dompdf->render();
    
    // Generate file name
    $filename = 'UniNeeds_Receipt_' . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . '.pdf';
    
    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $dompdf->output();
    exit;
}
?>