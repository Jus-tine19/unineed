<?php
require 'config/database.php';

echo "=== STOCK VERIFICATION ===\n\n";

// Check products and their variant stock
echo "Product 5 (Ballpen):\n";
$r = mysqli_query($conn, "SELECT * FROM products WHERE product_id = 5");
$p = mysqli_fetch_assoc($r);
echo "  Base Stock: " . $p['stock_quantity'] . "\n";

$r = mysqli_query($conn, "SELECT variant_id, variant_type, variant_value, stock_quantity FROM product_variants WHERE product_id = 5 ORDER BY variant_value");
while ($v = mysqli_fetch_assoc($r)) {
    echo "  - Variant {$v['variant_id']} ({$v['variant_type']}={$v['variant_value']}): Stock = {$v['stock_quantity']}\n";
}

echo "\nProduct 18 (Polo):\n";
$r = mysqli_query($conn, "SELECT * FROM products WHERE product_id = 18");
$p = mysqli_fetch_assoc($r);
echo "  Base Stock: " . $p['stock_quantity'] . "\n";

$r = mysqli_query($conn, "SELECT variant_id, variant_type, variant_value, stock_quantity FROM product_variants WHERE product_id = 18 ORDER BY variant_value");
while ($v = mysqli_fetch_assoc($r)) {
    echo "  - Variant {$v['variant_id']} ({$v['variant_type']}={$v['variant_value']}): Stock = {$v['stock_quantity']}\n";
}

echo "\n=== RECENT ORDERS WITH VARIANT INFO ===\n";
$r = mysqli_query($conn, "SELECT o.order_id, o.user_id, o.order_status, oi.item_id, oi.product_id, oi.quantity, oi.variant_id, oi.variant_value FROM orders o JOIN order_items oi ON o.order_id = oi.order_id ORDER BY o.order_id DESC LIMIT 20");
while ($row = mysqli_fetch_assoc($r)) {
    echo "Order #{$row['order_id']} (Status: {$row['order_status']}): Item {$row['item_id']}, Product {$row['product_id']}, Qty {$row['quantity']}, Variant ID: {$row['variant_id']}, Value: {$row['variant_value']}\n";
}
?>
