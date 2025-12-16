<?php
require 'config/database.php';

$result = mysqli_query($conn, "SELECT product_id, product_name, stock_quantity FROM products WHERE product_id IN (5, 18) ORDER BY product_id");

while ($row = mysqli_fetch_assoc($result)) {
    echo "Product ID: " . $row['product_id'] . ", Name: " . $row['product_name'] . ", Stock: " . $row['stock_quantity'] . PHP_EOL;
}
