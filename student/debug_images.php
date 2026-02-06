<?php
require_once '../config/database.php';
requireStudent();

echo "<h2>Cart Debug Info</h2>";

// Check session cart
echo "<h3>Session Cart Data:</h3>";
if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $key => $item) {
        echo "<strong>" . htmlspecialchars($item['product_name']) . "</strong><br>";
        echo "  image_path: " . htmlspecialchars($item['image_path'] ?? 'NULL') . "<br>";
        echo "  image_url: " . htmlspecialchars($item['image_url'] ?? 'NULL') . "<br>";
        
        // Check if product has images in database
        $p_id = intval($item['product_id']);
        $p_q = mysqli_query($conn, "SELECT image_path, image_url FROM products WHERE product_id = $p_id LIMIT 1");
        if ($prow = mysqli_fetch_assoc($p_q)) {
            echo "  DB image_path: " . htmlspecialchars($prow['image_path'] ?? 'NULL') . "<br>";
            echo "  DB image_url: " . htmlspecialchars($prow['image_url'] ?? 'NULL') . "<br>";
        }
        echo "<br>";
    }
} else {
    echo "Cart is empty";
}

// Check first 5 products with images
echo "<h3>Sample Products in Database:</h3>";
$result = mysqli_query($conn, "SELECT product_id, product_name, image_path, image_url FROM products LIMIT 5");
while ($row = mysqli_fetch_assoc($result)) {
    echo htmlspecialchars($row['product_name']) . " (ID: " . $row['product_id'] . ")<br>";
    echo "  image_path: " . htmlspecialchars($row['image_path'] ?? 'NULL') . "<br>";
    echo "  image_url: " . htmlspecialchars($row['image_url'] ?? 'NULL') . "<br><br>";
}
?>
