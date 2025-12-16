<?php

// Backfill inventory_movements table from current product and variant stock
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$created_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 'NULL';

// Ensure inventory_movements table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
if (!$table_check || mysqli_num_rows($table_check) === 0) {
    $sql_file = __DIR__ . '/../config/sql/inventory_movements.sql';
    if (file_exists($sql_file)) {
        $sql = file_get_contents($sql_file);
        mysqli_query($conn, $sql);
    }
}

// Get products with variant info
$products_q = "SELECT p.*, COALESCE(SUM(v.stock_quantity),0) as total_variant_stock, COUNT(v.variant_id) as variant_count 
               FROM products p 
               LEFT JOIN product_variants v ON p.product_id = v.product_id 
               GROUP BY p.product_id";
$products = mysqli_query($conn, $products_q);

$inserted = 0;
while ($p = mysqli_fetch_assoc($products)) {
    $product_id = intval($p['product_id']);

    // Skip if there are already movements for this product
    $check = mysqli_query($conn, "SELECT COUNT(*) as c FROM inventory_movements WHERE product_id = $product_id");
    $crow = mysqli_fetch_assoc($check);
    if ($crow && intval($crow['c']) > 0) {
        continue;
    }

    if (intval($p['variant_count']) > 0) {
        // Backfill per variant
        $vals = mysqli_query($conn, "SELECT * FROM product_variants WHERE product_id = $product_id");
        while ($v = mysqli_fetch_assoc($vals)) {
            $vstock = intval($v['stock_quantity']);
            if ($vstock <= 0) continue;
            $reason = "Initial variant stock: " . mysqli_real_escape_string($conn, $v['variant_type']) . "=" . mysqli_real_escape_string($conn, $v['variant_value']) . " @ " . number_format($v['price'], 2);
            $sql = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $vstock, 0, $vstock, 'add', '" . mysqli_real_escape_string($conn, $reason) . "', " . ($created_by === 'NULL' ? 'NULL' : $created_by) . ")";
            mysqli_query($conn, $sql);
            $inserted++;
        }
    } else {
        $stock = intval($p['stock_quantity']);
        if ($stock > 0) {
            $reason = "Initial stock @ " . number_format($p['price'], 2);
            $sql = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $stock, 0, $stock, 'add', '" . mysqli_real_escape_string($conn, $reason) . "', " . ($created_by === 'NULL' ? 'NULL' : $created_by) . ")";
            mysqli_query($conn, $sql);
            $inserted++;
        }
    }
}

echo "Backfill completed: $inserted movement(s) inserted.\n";

// Provide a link back to inventory
echo "<p><a href=\"inventory.php\">Back to Inventory</a></p>";

?>