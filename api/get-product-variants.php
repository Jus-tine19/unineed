<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
    exit;
}

$q = "SELECT variant_id, variant_type, variant_value, stock_quantity FROM product_variants WHERE product_id = ? ORDER BY variant_id ASC";
$stmt = mysqli_prepare($conn, $q);
mysqli_stmt_bind_param($stmt, 'i', $product_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$variants = [];
while ($row = mysqli_fetch_assoc($res)) {
    $variants[] = [
        'variant_id' => intval($row['variant_id']),
        'variant_type' => $row['variant_type'],
        'variant_value' => $row['variant_value'],
        'stock_quantity' => intval($row['stock_quantity'])
    ];
}

echo json_encode(['success' => true, 'variants' => $variants]);
