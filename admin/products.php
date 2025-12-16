<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/image_helper.php';
requireAdmin();

// Create upload directory if it doesn't exist
$uploadDir = dirname(__DIR__) . '/assets/uploads/products';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Ensure inventory_movements table exists (used for tracking stock changes)
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
if ($table_check && mysqli_num_rows($table_check) === 0) {
    $sql_file = dirname(__DIR__) . '/config/sql/inventory_movements.sql';
    if (file_exists($sql_file)) {
        $sql = file_get_contents($sql_file);
        @mysqli_query($conn, $sql);
    }
}

// Handle Add/Edit Product
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product']) || isset($_POST['edit_product'])) {
        $product_name = clean($_POST['product_name']);
        $description = clean($_POST['description']);
        $category = clean($_POST['category']);
        $status = clean($_POST['status']);
        
        // Check if we have variants
        $has_variants = isset($_POST['variant_types']) && is_array($_POST['variant_types']) && !empty(array_filter($_POST['variant_types']));
        
        // Calculate base price and stock from variants or use direct input
        if ($has_variants) {
            // Get first variant's price and calculate total stock from all variants
            $base_price = 0;
            $stock_quantity = 0;
            
            if (isset($_POST['variant_prices']) && is_array($_POST['variant_prices'])) {
                // Ensure we get a valid price from the first variant entry
                $first_valid_price = array_values(array_filter($_POST['variant_prices'], 'is_numeric'));
                $base_price = !empty($first_valid_price) ? floatval($first_valid_price[0]) : 0;
            }
            
            if (isset($_POST['variant_stocks']) && is_array($_POST['variant_stocks'])) {
                foreach ($_POST['variant_stocks'] as $vstock) {
                    $stock_quantity += intval($vstock); // Sum all variant stocks
                }
            }
        } else {
            // No variants - use base price and stock from form
            $base_price = floatval(clean($_POST['price']));
            $stock_quantity = intval(clean($_POST['stock_quantity']));
        }
        
        // Handle image upload if provided
        $image_path = null;
        if (!empty($_FILES['product_image']['name'])) {
            list($success, $result) = uploadProductImage($_FILES['product_image']);
            if ($success) {
                $image_path = $result;
                // Extract colors from uploaded image
                $colors = extractColors($image_path);
            } else {
                $error = $result;
            }
        }
        
        // Get color values from form (these are usually handled via image_helper but kept for completeness if needed)
        $primary_color = clean($_POST['primary_color'] ?? '#2E4412');
        $secondary_color = clean($_POST['secondary_color'] ?? '#F6C500');
        $accent_color = clean($_POST['accent_color'] ?? '#F78C56');
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // If editing, fetch previous stock/variants for inventory movement diffing
        $old_stock = 0;
        $old_variants = [];
        if (isset($_POST['edit_product'])) {
            $existing_id = clean($_POST['product_id']);
            $old_q = mysqli_query($conn, "SELECT stock_quantity FROM products WHERE product_id = $existing_id");
            if ($old_q && mysqli_num_rows($old_q) > 0) {
                $old_stock = intval(mysqli_fetch_assoc($old_q)['stock_quantity']);
            }
            $ov = mysqli_query($conn, "SELECT variant_type, variant_value, stock_quantity FROM product_variants WHERE product_id = $existing_id");
            while ($r = mysqli_fetch_assoc($ov)) {
                $k = $r['variant_type'] . '|' . $r['variant_value'];
                $old_variants[$k] = intval($r['stock_quantity']);
            }
        }

        if (isset($_POST['add_product'])) {
            $query = "INSERT INTO products (product_name, description, category, price, stock_quantity, image_path, primary_color, secondary_color, accent_color, status) 
                     VALUES ('$product_name', '$description', '$category', $base_price, $stock_quantity, " . 
                     ($image_path ? "'$image_path'" : "NULL") . ", '$primary_color', '$secondary_color', '$accent_color', '$status')";
            $message = "Product added successfully!";
        } else {
            $product_id = clean($_POST['product_id']);
            $query = "UPDATE products SET 
                     product_name = '$product_name',
                     description = '$description',
                     category = '$category',
                     price = $base_price,
                     stock_quantity = $stock_quantity,
                     " . ($image_path ? "image_path = '$image_path'," : "") . "
                     status = '$status'
                     WHERE product_id = $product_id";
            $message = "Product updated successfully!";
        }
        
        if (mysqli_query($conn, $query)) {
            $product_id = isset($_POST['edit_product']) ? clean($_POST['product_id']) : mysqli_insert_id($conn);
            
            // Handle variants
            if ($has_variants) {
                // First delete existing variants if editing
                if (isset($_POST['edit_product'])) {
                    $delete_variants = "DELETE FROM product_variants WHERE product_id = $product_id";
                    mysqli_query($conn, $delete_variants);
                }
                
                // Add new variants
                $new_variants = [];
                // Process only the first variant type, due to how the front-end flattens variant value arrays
                $variant_type_base = clean($_POST['variant_types'][0] ?? '');
                
                $values = $_POST['variant_values'] ?? [];
                $prices = $_POST['variant_prices'] ?? [];
                $stocks = $_POST['variant_stocks'] ?? [];

                if (!empty($variant_type_base) && is_array($values)) {
                    foreach ($values as $i => $value) {
                         // Check if this index exists in all arrays before proceeding
                        if (isset($prices[$i]) && isset($stocks[$i])) {
                            $variant_price = floatval($prices[$i]);
                            $variant_stock = intval($stocks[$i]);
                            
                            // FIX: Only call clean() if the variable is a string. If it's an array 
                            // or null due to form structure, it's skipped or defaults to an empty string.
                            $vvalue = is_string($value) ? clean($value) : ''; 

                            if (!empty($vvalue)) {
                                $variant_key = $variant_type_base . '|' . $vvalue;
                                $new_variants[$variant_key] = $variant_stock;
                                
                                $variant_query = "INSERT INTO product_variants (product_id, variant_type, variant_value, price, stock_quantity) 
                                                VALUES ($product_id, '$variant_type_base', '$vvalue', $variant_price, $variant_stock)";
                                
                                if (!mysqli_query($conn, $variant_query)) {
                                    mysqli_rollback($conn);
                                    $error = "Failed to save variant: " . mysqli_error($conn);
                                    break; // Exit foreach
                                }
                            }
                        }
                    }
                }
            }
            
            if (!isset($error)) {
                // Record inventory movements
                // Product-level movement
                if (isset($_POST['add_product'])) {
                    // initial product stock
                    if ($stock_quantity > 0) {
                        $reason = $has_variants ? 'Initial stock from variants' : 'Initial stock on product creation';
                        $mq = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $stock_quantity, 0, $stock_quantity, 'add', '$reason', " . intval($_SESSION['user_id']) . ")";
                        @mysqli_query($conn, $mq);
                    }
                    // initial variant stocks
                    if ($has_variants && !empty($new_variants)) {
                        foreach ($new_variants as $vkey => $vstock) {
                            list($vtype, $vvalue) = explode('|', $vkey, 2);
                            $reason = "Initial variant stock: " . mysqli_real_escape_string($conn, $vtype) . "=" . mysqli_real_escape_string($conn, $vvalue);
                            $mq = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $vstock, 0, $vstock, 'add', '" . mysqli_real_escape_string($conn, $reason) . "', " . intval($_SESSION['user_id']) . ")";
                            @mysqli_query($conn, $mq);
                        }
                    }
                } else {
                    // edit product - record product stock delta
                    $delta = $stock_quantity - $old_stock;
                    if ($delta != 0) {
                        $mtype = $delta > 0 ? 'add' : 'subtract';
                        $reason = $has_variants ? 'Product stock updated from variants' : 'Product stock updated via edit';
                        $mq = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $delta, $old_stock, $stock_quantity, '$mtype', '$reason', " . intval($_SESSION['user_id']) . ")";
                        @mysqli_query($conn, $mq);
                    }

                    // variant-level movements: compare old_variants and new_variants
                    if (!empty($old_variants) || !empty($new_variants)) {
                        // additions or diffs
                        foreach ($new_variants as $vkey => $vstock) {
                            $prev = isset($old_variants[$vkey]) ? intval($old_variants[$vkey]) : 0;
                            $delta = $vstock - $prev;
                            if ($delta != 0) {
                                list($vtype, $vvalue) = explode('|', $vkey, 2);
                                $reason = 'Variant ' . mysqli_real_escape_string($conn, $vtype) . '=' . mysqli_real_escape_string($conn, $vvalue) . ' stock update';
                                $mtype = $delta > 0 ? 'add' : 'subtract';
                                $mq = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $delta, $prev, $vstock, '$mtype', '" . mysqli_real_escape_string($conn, $reason) . "', " . intval($_SESSION['user_id']) . ")";
                                @mysqli_query($conn, $mq);
                            }
                        }
                        // removals: old variants not present in new set
                        foreach ($old_variants as $ok => $oprev) {
                            if (!isset($new_variants[$ok]) && $oprev > 0) {
                                list($vtype, $vvalue) = explode('|', $ok, 2);
                                $reason = 'Variant ' . mysqli_real_escape_string($conn, $vtype) . '=' . mysqli_real_escape_string($conn, $vvalue) . ' removed';
                                $delta = 0 - $oprev;
                                $mq = "INSERT INTO inventory_movements (product_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) VALUES ($product_id, $delta, $oprev, 0, 'subtract', '" . mysqli_real_escape_string($conn, $reason) . "', " . intval($_SESSION['user_id']) . ")";
                                @mysqli_query($conn, $mq);
                            }
                        }
                    }
                }

                mysqli_commit($conn);
                $success = $message;
            }
        } else {
            mysqli_rollback($conn);
            $error = "Operation failed: " . mysqli_error($conn);
        }
    }
} // Close if POST block
    
// Handle Delete
if (isset($_POST['delete_product'])) {
    // Use intval to ensure numeric id and prevent injection
    $product_id = intval(clean($_POST['product_id']));

    // Start transaction to remove dependent rows first (variants, inventory movements) to satisfy FK constraints
    mysqli_begin_transaction($conn);
    $delete_failed = false;

    // Attempt to delete product variants
    if (!mysqli_query($conn, "DELETE FROM product_variants WHERE product_id = $product_id")) {
        $delete_failed = true;
    }

    // Attempt to delete inventory movements for this product
    if (!mysqli_query($conn, "DELETE FROM inventory_movements WHERE product_id = $product_id")) {
        $delete_failed = true;
    }

    // Remove product image file if exists
    $img_q = mysqli_query($conn, "SELECT image_path FROM products WHERE product_id = $product_id");
    if ($img_q && mysqli_num_rows($img_q) > 0) {
        $img_row = mysqli_fetch_assoc($img_q);
        if (!empty($img_row['image_path'])) {
            $full_path = dirname(__DIR__) . '/' . $img_row['image_path'];
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
        }
    }

    // Now delete the product row
    if (!mysqli_query($conn, "DELETE FROM products WHERE product_id = $product_id")) {
        $delete_failed = true;
    }

    if ($delete_failed) {
        mysqli_rollback($conn);
        $error = "Failed to delete product or its related data: " . mysqli_error($conn);
    } else {
        mysqli_commit($conn);
        $success = "Product deleted successfully!";
    }
}

// Get all products with variant price and stock information
$query = "SELECT p.*,
          NULLIF(MIN(v.price), 0) as min_variant_price,
          NULLIF(MAX(v.price), 0) as max_variant_price,
          COUNT(v.variant_id) as variant_count,
          COALESCE(SUM(v.stock_quantity), 0) as total_variant_stock,
          GROUP_CONCAT(DISTINCT v.variant_type) as variant_types
          FROM products p 
          LEFT JOIN product_variants v ON p.product_id = v.product_id 
          GROUP BY p.product_id 
          ORDER BY p.created_at DESC";
$products = mysqli_query($conn, $query);

// Get categories
$categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ''";
$categories = mysqli_query($conn, $categories_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - UniNeeds Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Product Catalog</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="bi bi-plus-circle me-2"></i>Add Product
                </button>
            </div>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($products) > 0): ?>
                                    <?php while ($product = mysqli_fetch_assoc($products)): ?>
                                        <tr>
                                            <td><?php echo $product['product_id']; ?></td>
                                            <td>
                                                <?php if ($product['image_path']): ?>
                                                    <img src="<?php echo htmlspecialchars('../' . $product['image_path']); ?>" alt="Product" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">
                                                <?php else: ?>
                                                    <div style="width: 50px; height: 50px; background: #e9ecef; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="bi bi-image text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...</small>
                                            </td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                            <td><strong>
    <?php if ($product['variant_count'] > 0): ?>
        <?php if ($product['min_variant_price'] == $product['max_variant_price']): ?>
            <?php echo formatCurrency($product['min_variant_price']); ?>
        <?php else: ?>
            <?php echo formatCurrency($product['min_variant_price']) . ' - ' . formatCurrency($product['max_variant_price']); ?>
        <?php endif; ?>
    <?php else: ?>
        <?php echo formatCurrency($product['price']); ?>
    <?php endif; ?>
</strong></td>
                                            <td>
                                                <?php
                                                $stock = $product['variant_count'] > 0 ? $product['total_variant_stock'] : $product['stock_quantity'];
                                                if ($stock < 10): ?>
                                                    <span class="badge bg-warning"><?php echo $stock; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?php echo $stock; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($product['status'] === 'available'): ?>
                                                    <span class="badge bg-success">Available</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Unavailable</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $product['product_id']; ?>" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($product['product_name']); ?>')">
                                                        <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                        <button type="submit" name="delete_product" class="btn btn-sm btn-danger btn-action" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <div class="modal fade" id="editModal<?php echo $product['product_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form method="POST" enctype="multipart/form-data">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Product</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Product Name *</label>
                                                                    <input type="text" class="form-control" name="product_name" value="<?php echo htmlspecialchars($product['product_name']); ?>" required>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Category *</label>
                                                                    <input type="text" class="form-control" name="category" value="<?php echo htmlspecialchars($product['category']); ?>" required>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Description</label>
                                                                    <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($product['description']); ?></textarea>
                                                                </div>

                                                                <div class="col-12">
                                                                    <hr>
                                                                    <div class="d-flex align-items-center gap-2 mb-3">
                                                                        <label class="form-label mb-0"><i class="bi bi-box-seam me-2"></i>Product Variants</label>
                                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariantType(<?php echo $product['product_id']; ?>)">
                                                                            <i class="bi bi-plus"></i> Add Variant Type
                                                                        </button>
                                                                    </div>
                                                                    <small class="text-muted d-block mb-2">Add variants like Color, Size, Material. First variant's price becomes base price, total stock is summed from all variants.</small>
                                                                    <div id="variantTypes<?php echo $product['product_id']; ?>" class="mb-3">
                                                                        <?php
                                                                        // Fetch existing variants for this product
                                                                        $variants_query = "SELECT DISTINCT variant_type FROM product_variants WHERE product_id = " . $product['product_id'] . " ORDER BY variant_type";
                                                                        $variants_result = mysqli_query($conn, $variants_query);
                                                                        
                                                                        $first_variant_group = true;
                                                                        while ($variant_type = mysqli_fetch_assoc($variants_result)) {
                                                                            $type = $variant_type['variant_type'];
                                                                            echo '<div class="variant-type-group mb-3 border p-3 rounded bg-light">';
                                                                            echo '<div class="d-flex align-items-center gap-2 mb-3">';
                                                                            echo '<div class="flex-grow-1">';
                                                                            echo '<label class="form-label mb-1">Variant Type</label>';
                                                                            echo '<input type="text" class="form-control" name="variant_types[]" value="' . htmlspecialchars($type) . '" placeholder="e.g., Color, Size, Material" required>';
                                                                            echo '</div>';
                                                                            echo '<button type="button" class="btn btn-sm btn-outline-danger mt-4" onclick="removeVariantTypeEdit(this,' . $product['product_id'] . ')"><i class="bi bi-trash"></i> Remove Type</button>';
                                                                            echo '</div>';
                                                                            echo '<div class="variant-values">';
                                                                            
                                                                            // Fetch values for this variant type
                                                                            $values_query = "SELECT * FROM product_variants WHERE product_id = " . $product['product_id'] . " AND variant_type = '" . mysqli_real_escape_string($conn, $type) . "'";
                                                                            $values_result = mysqli_query($conn, $values_query);
                                                                            
                                                                            $first_value_in_group = true;
                                                                            while ($value = mysqli_fetch_assoc($values_result)) {
                                                                                echo '<div class="variant-value-row mb-2">';
                                                                                echo '<div class="row g-2">';
                                                                                echo '<div class="col-md-4">';
                                                                                if ($first_value_in_group) echo '<label class="form-label mb-1">Value</label>';
                                                                                echo '<input type="text" class="form-control" name="variant_values[]" value="' . htmlspecialchars($value['variant_value']) . '" placeholder="e.g., Red, Large" required>';
                                                                                echo '</div>';
                                                                                echo '<div class="col-md-3">';
                                                                                if ($first_value_in_group) echo '<label class="form-label mb-1">Price (₱)</label>';
                                                                                echo '<input type="number" step="0.01" class="form-control" name="variant_prices[]" value="' . $value['price'] . '" placeholder="0.00" required min="0">';
                                                                                echo '</div>';
                                                                                echo '<div class="col-md-3">';
                                                                                if ($first_value_in_group) echo '<label class="form-label mb-1">Stock</label>';
                                                                                echo '<input type="number" class="form-control" name="variant_stocks[]" value="' . $value['stock_quantity'] . '" placeholder="0" required min="0">';
                                                                                echo '</div>';
                                                                                echo '<div class="col-md-2' . ($first_value_in_group ? ' d-flex align-items-end' : '') . '">';
                                                                                echo '<button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeVariantValue(this)"><i class="bi bi-x"></i></button>';
                                                                                echo '</div>';
                                                                                echo '</div></div>';
                                                                                $first_value_in_group = false;
                                                                            }
                                                                            
                                                                            echo '<button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariantValue(this)"><i class="bi bi-plus"></i> Add Another Value</button>';
                                                                            echo '</div></div>';
                                                                            $first_variant_group = false;
                                                                        }
                                                                        ?>
                                                                    </div>
                                                                </div>

                                                                <div class="col-md-6" id="basePriceContainer<?php echo $product['product_id']; ?>">
                                                                    <label class="form-label">Price (₱) <span id="priceRequired<?php echo $product['product_id']; ?>">*</span></label>
                                                                    <input type="number" step="0.01" class="form-control" name="price" value="<?php echo $product['price']; ?>" id="priceInput<?php echo $product['product_id']; ?>">
                                                                </div>

                                                                <div class="col-md-6" id="baseStockContainer<?php echo $product['product_id']; ?>">
                                                                    <label class="form-label">Stock Quantity <span id="stockRequired<?php echo $product['product_id']; ?>">*</span></label>
                                                                    <input type="number" class="form-control" name="stock_quantity" value="<?php echo $product['stock_quantity']; ?>" id="stockInput<?php echo $product['product_id']; ?>">
                                                                </div>
                                                                
                                                                <div class="col-md-6"><?php 
                                                                    // Check if this product has variants to hide base fields
                                                                    $has_existing_variants = false;
                                                                    $check_variants = mysqli_query($conn, "SELECT COUNT(*) as count FROM product_variants WHERE product_id = " . $product['product_id']);
                                                                    if ($check_variants) {
                                                                        $variant_count_row = mysqli_fetch_assoc($check_variants);
                                                                        $has_existing_variants = $variant_count_row['count'] > 0;
                                                                    }
                                                                ?>
                                                                <script>
                                                                    // Fix for Edit Modal: Remove 'required' on load if variants exist
                                                                    <?php if ($has_existing_variants): ?>
                                                                    document.addEventListener('DOMContentLoaded', function() {
                                                                        const priceInput = document.getElementById('priceInput<?php echo $product['product_id']; ?>');
                                                                        const stockInput = document.getElementById('stockInput<?php echo $product['product_id']; ?>');
                                                                        if (priceInput) priceInput.removeAttribute('required');
                                                                        if (stockInput) stockInput.removeAttribute('required');
                                                                        // Call the check function to visually hide them
                                                                        if (typeof checkVariantFieldsEdit === 'function') {
                                                                            checkVariantFieldsEdit(<?php echo $product['product_id']; ?>);
                                                                        }
                                                                    });
                                                                    <?php else: ?>
                                                                    document.addEventListener('DOMContentLoaded', function() {
                                                                        const priceInput = document.getElementById('priceInput<?php echo $product['product_id']; ?>');
                                                                        const stockInput = document.getElementById('stockInput<?php echo $product['product_id']; ?>');
                                                                        if (priceInput) priceInput.setAttribute('required', 'required');
                                                                        if (stockInput) stockInput.setAttribute('required', 'required');
                                                                    });
                                                                    <?php endif; ?>
                                                                </script>
                                                                    <label class="form-label">Status *</label>
                                                                    <select class="form-select" name="status" required>
                                                                        <option value="available" <?php echo $product['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                                                        <option value="unavailable" <?php echo $product['status'] === 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Product Image</label>
                                                                    <input type="file" class="form-control" name="product_image" accept="image/*" onchange="previewImage(this, 'imagePreview<?php echo $product['product_id']; ?>', 'previewPlaceholder<?php echo $product['product_id']; ?>')">
                                                                    <?php if ($product['image_path']): ?>
                                                                        <small class="text-muted d-block">Current image: <?php echo basename($product['image_path']); ?></small>
                                                                    <?php endif; ?>
                                                                    <small class="text-muted d-block">Upload new image (JPG, PNG, GIF max 5MB)</small>
                                                                </div>
                                                                <div class="col-12">
                                                                    <div class="border rounded p-2 text-center">
                                                                        <img id="imagePreview<?php echo $product['product_id']; ?>" src="<?php echo $product['image_path'] ? '../' . $product['image_path'] : '#'; ?>" alt="Preview" style="max-width:100%; max-height:200px; <?php echo $product['image_path'] ? '' : 'display:none;'; ?>">
                                                                        <div id="previewPlaceholder<?php echo $product['product_id']; ?>" class="text-muted py-5" <?php echo $product['image_path'] ? 'style="display:none;"' : ''; ?>>
                                                                            <i class="bi bi-image fs-2"></i><br>
                                                                            Image preview will appear here
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-box-seam"></i>
                                                <h5>No Products Yet</h5>
                                                <p>Start by adding your first product.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Product Name *</label>
                                <input type="text" class="form-control" name="product_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category *</label>
                                <input type="text" class="form-control" name="category" required placeholder="e.g., School Supplies, Books, Electronics">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Enter product description"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <hr>
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <label class="form-label mb-0"><i class="bi bi-box-seam me-2"></i>Product Variants</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="addVariantType">
                                        <i class="bi bi-plus"></i> Add Variant Type
                                    </button>
                                </div>
                                <small class="text-muted d-block mb-2">Optional: Add variants like Color, Size, Material. The first variant's price will be used as the base price, and total stock will be summed from all variants.</small>
                                <div id="variantTypes" class="mb-3">
                                    </div>
                                
                                <div id="noVariantsFields">
                                    <div class="alert alert-info mb-3">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>No variants added.</strong> Enter base price and stock quantity below. If you add variants, the first variant's price will become the base price and total stock will be calculated from all variants.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6" id="basePriceFieldAdd" style="display: block;">
                                <label class="form-label">Base Price (₱) <span id="priceRequiredAdd">*</span></label>
                                <input type="number" step="0.01" class="form-control" name="price" min="0" id="priceInputAdd">
                            </div>
                            <div class="col-md-6" id="baseStockFieldAdd" style="display: block;">
                                <label class="form-label">Base Stock Quantity <span id="stockRequiredAdd">*</span></label>
                                <input type="number" class="form-control" name="stock_quantity" min="0" value="0" id="stockInputAdd">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" required>
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Product Image</label>
                                <input type="file" class="form-control" name="product_image" accept="image/*" onchange="previewImage(this)">
                                <small class="text-muted">Upload product image (JPG, PNG, GIF max 5MB)</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Image Preview</label>
                                <div class="border rounded p-2 text-center">
                                    <img id="imagePreview" src="#" alt="Preview" style="max-width:100%; max-height:200px; display:none;">
                                    <div id="previewPlaceholder" class="text-muted py-5">
                                        <i class="bi bi-image fs-2"></i><br>
                                        Image preview will appear here
                                    </div>
                                </div>
                            </div>
                            <div class="col-12" id="colorPalette" style="display:none;">
                                <label class="form-label">Color Palette</label>
                                <div class="d-flex gap-2 mb-2">
                                    <div class="color-box primary-color">
                                        <div class="color-preview rounded" style="width:50px;height:50px;"></div>
                                        <small class="d-block text-muted">Primary</small>
                                    </div>
                                    <div class="color-box secondary-color">
                                        <div class="color-preview rounded" style="width:50px;height:50px;"></div>
                                        <small class="d-block text-muted">Secondary</small>
                                    </div>
                                    <div class="color-box accent-color">
                                        <div class="color-preview rounded" style="width:50px;height:50px;"></div>
                                        <small class="d-block text-muted">Accent</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/product-image.js"></script>
    <script src="../assets/js/product-variants.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var addModal = document.getElementById('addProductModal');
        if (addModal) {
            // Re-check variants when the modal is opened using Bootstrap's event
            addModal.addEventListener('shown.bs.modal', function () {
                // checkVariantFields is defined in product-variants.js and handles the hiding/un-requiring logic
                if (typeof checkVariantFields === 'function') {
                    checkVariantFields(); 
                }
            });
        }
        
        // Ensure all Edit modals are initialized with the correct field state
        document.querySelectorAll('[id^="editModal"]').forEach(function(modal) {
             const productId = modal.id.replace('editModal', '');
             if (productId && !isNaN(productId)) {
                modal.addEventListener('shown.bs.modal', function() {
                    if (typeof checkVariantFieldsEdit === 'function') {
                        checkVariantFieldsEdit(productId);
                    }
                });
             }
        });
    });
    </script>
</body>
</html>