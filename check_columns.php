<?php
require 'config/database.php';

echo "=== Products table columns ===\n";
$r = mysqli_query($conn, 'SHOW COLUMNS FROM products');
while($row = mysqli_fetch_assoc($r)){
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}

echo "\n=== Order Items table columns ===\n";
$r = mysqli_query($conn, 'SHOW COLUMNS FROM order_items');
while($row = mysqli_fetch_assoc($r)){
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>
