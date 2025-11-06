<?php
require_once 'config/database.php';

// Get products with variants
$query = "SELECT p.product_id, p.product_name, p.price, p.stock_quantity, 
          COUNT(v.variant_id) as variant_count,
          MIN(v.price) as min_variant_price,
          MAX(v.price) as max_variant_price,
          SUM(v.stock_quantity) as total_variant_stock,
          GROUP_CONCAT(CONCAT(v.variant_type, ':', v.variant_value, ' (Price: ', v.price, ', Stock: ', v.stock_quantity, ')') SEPARATOR '\n') as variants
          FROM products p
          LEFT JOIN product_variants v ON p.product_id = v.product_id
          GROUP BY p.product_id
          HAVING variant_count > 0";

$result = mysqli_query($conn, $query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "Product ID: " . $row['product_id'] . "\n";
        echo "Product Name: " . $row['product_name'] . "\n";
        echo "Base Price: " . $row['price'] . "\n";
        echo "Base Stock: " . $row['stock_quantity'] . "\n";
        echo "Variant Count: " . $row['variant_count'] . "\n";
        echo "Price Range: " . $row['min_variant_price'] . " - " . $row['max_variant_price'] . "\n";
        echo "Total Variant Stock: " . $row['total_variant_stock'] . "\n";
        echo "Variants:\n" . $row['variants'] . "\n";
        echo "----------------------------------------\n";
    }
} else {
    echo "Error: " . mysqli_error($conn);
}
?>