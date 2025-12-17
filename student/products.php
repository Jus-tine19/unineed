<?php

require_once '../config/database.php';
requireStudent();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle Add to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = clean($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $variants = isset($_POST['variants']) ? $_POST['variants'] : [];
    $variant_price = isset($_POST['selected_variant_price']) ? floatval($_POST['selected_variant_price']) : 0;
    
    // Check if product exists and is available
    $query = "SELECT * FROM products WHERE product_id = $product_id AND status = 'available'";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $product = mysqli_fetch_assoc($result);
        $valid = true;
        
        // If product has variants, validate them
        $variants_check = mysqli_query($conn, "SELECT COUNT(DISTINCT variant_type) as variant_count FROM product_variants WHERE product_id = $product_id");
        $variant_count = mysqli_fetch_assoc($variants_check)['variant_count'];
        
        if ($variant_count > 0) {
            // Verify all variants are selected and valid
            if (count($variants) != $variant_count) {
                $error = "Please select all variants.";
                $valid = false;
            } else {
                // Check stock for the specific variant combination
                foreach ($variants as $type => $value) {
                    $type = mysqli_real_escape_string($conn, $type);
                    $value = mysqli_real_escape_string($conn, $value);
                    $stock_check = mysqli_query($conn, "SELECT stock_quantity FROM product_variants 
                                                      WHERE product_id = $product_id 
                                                      AND variant_type = '$type' 
                                                      AND variant_value = '$value'");
                    if ($stock_row = mysqli_fetch_assoc($stock_check)) {
                        if ($stock_row['stock_quantity'] < $quantity) {
                            $error = "Insufficient stock for selected variant.";
                            $valid = false;
                            break;
                        }
                    } else {
                        $error = "Invalid variant selected.";
                        $valid = false;
                        break;
                    }
                }
            }
        } else {
            // No variants, check base stock
            if ($product['stock_quantity'] < $quantity) {
                $error = "Insufficient stock.";
                $valid = false;
            }
        }
        
        if ($valid) {
            // Generate a unique key for the cart item that includes variants
            $cart_key = $product_id;
            if (!empty($variants)) {
                ksort($variants); // Sort variant types to ensure consistent keys
                $variant_string = '';
                foreach ($variants as $type => $value) {
                    $variant_string .= "_{$type}-{$value}";
                }
                $cart_key .= $variant_string;
            }
            
            if (isset($_SESSION['cart'][$cart_key])) {
                $_SESSION['cart'][$cart_key]['quantity'] += $quantity;
            } else {
                $_SESSION['cart'][$cart_key] = [
                    'product_id' => $product_id,
                    'product_name' => $product['product_name'],
                    'price' => $variant_price > 0 ? $variant_price : $product['price'],
                    'quantity' => $quantity,
                    'image_path' => $product['image_path'],
                    'variants' => $variants,
                    'requires_down_payment' => $product['requires_down_payment']
                ];
            }
            $success = "Product added to cart!";
        }
    } else {
        $error = "Product not found or unavailable.";
    }
}

// Get filter parameters
$category_filter = isset($_GET['category']) ? clean($_GET['category']) : '';
$search = isset($_GET['search']) ? clean($_GET['search']) : '';

// Build query (prefix columns with table alias to avoid ambiguity when joining)
$where_clauses = ["p.status = 'available'"];
if ($category_filter) {
    $where_clauses[] = "p.category = '$category_filter'";
}
if ($search) {
    $where_clauses[] = "(p.product_name LIKE '%$search%' OR p.description LIKE '%$search%')";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$query = "SELECT p.*, 
          MIN(v.price) as min_variant_price, 
          MAX(v.price) as max_variant_price,
          COUNT(v.variant_id) as variant_count,
          COALESCE(SUM(v.stock_quantity), 0) as total_variant_stock 
          FROM products p 
          LEFT JOIN product_variants v ON p.product_id = v.product_id 
          $where_sql
          GROUP BY p.product_id 
          ORDER BY p.product_name ASC";
$products = mysqli_query($conn, $query);

// Get categories
$categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND status = 'available'";
$categories = mysqli_query($conn, $categories_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Products - UniNeeds</title>
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
            <h2>Shop Products</h2>
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
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">Search Products</label>
                        <input type="text" class="form-control" name="search" placeholder="Search by name or description" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category">
                            <option value="">All Categories</option>
                            <?php while ($cat = mysqli_fetch_assoc($categories)): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter === $cat['category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-2"></i>Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="products.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Products Grid -->
            <div class="row g-4">
                <?php if (mysqli_num_rows($products) > 0): ?>
                    <?php while ($product = mysqli_fetch_assoc($products)): ?>
                        <div class="col-md-3">
                            <div class="product-card" data-category="<?php echo htmlspecialchars($product['category']); ?>">
                                <?php
                                    $imgSrc = '';
                                    if (!empty($product['image_path'])) $imgSrc = $product['image_path'];
                                    elseif (!empty($product['image_url'])) $imgSrc = $product['image_url'];
                                ?>
                                <?php if ($imgSrc): ?>
                                    <?php
                                        // Normalize image path for absolute URL
                                        $imgSrcNorm = $imgSrc;
                                        // If it's a relative path, make it absolute from root
                                        if (!preg_match('/^(https?:)?\\/\\//i', $imgSrcNorm)) {
                                            // Remove leading ../ or /
                                            $imgSrcNorm = ltrim($imgSrcNorm, '/.');
                                            // Build absolute path from domain root
                                            $imgSrcNorm = '/' . $imgSrcNorm;
                                        }
                                    ?>
                                    <img src="<?php echo htmlspecialchars($imgSrcNorm); ?>" 
                                         alt="<?php echo htmlspecialchars($product['product_name']); ?>" 
                                         class="product-image"
                                         onerror="this.onerror=null; this.src='/assets/images/product-placeholder.jpg';">
                                <?php else: ?>
                                    <div class="product-image d-flex align-items-center justify-content-center">
                                        <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="product-details">
                                    <span class="badge bg-secondary mb-2"><?php echo htmlspecialchars($product['category']); ?></span>
                                    <h6 class="product-title"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                    <p class="text-muted small mb-2"><?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?><?php echo strlen($product['description']) > 80 ? '...' : ''; ?></p>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="product-price">
                                            <?php if ($product['variant_count'] > 0): ?>
                                                <?php if ($product['min_variant_price'] == $product['max_variant_price']): ?>
                                                    <?php echo formatCurrency($product['min_variant_price']); ?>
                                                <?php else: ?>
                                                    <?php echo formatCurrency($product['min_variant_price']) . ' - ' . formatCurrency($product['max_variant_price']); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php echo formatCurrency($product['price']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="bi bi-box-seam me-1"></i>
                                            <?php 
                                            $stock = $product['variant_count'] > 0 ? $product['total_variant_stock'] : $product['stock_quantity'];
                                            if ($stock > 0): 
                                            ?>
                                                Stock: <?php echo $stock; ?>
                                            <?php else: ?>
                                                <span class="text-danger">Out of Stock</span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    
                                    <?php 
                                    $stock = $product['variant_count'] > 0 ? $product['total_variant_stock'] : $product['stock_quantity'];
                                    if ($stock > 0): 
                                    ?>
                                        <button id="addToCartBtn<?php echo $product['product_id']; ?>" class="btn btn-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#addModal<?php echo $product['product_id']; ?>">
                                            <i class="bi bi-cart-plus me-2"></i>Add to Cart
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm w-100" disabled>
                                            Out of Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Add to Cart Modal -->
                        <div class="modal fade" id="addModal<?php echo $product['product_id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><?php echo htmlspecialchars($product['product_name']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-center">
                                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">

                                                    <div class="mb-3">
                                                        <?php
                                                            $modalImg = '';
                                                            if (!empty($product['image_path'])) $modalImg = $product['image_path'];
                                                            elseif (!empty($product['image_url'])) $modalImg = $product['image_url'];
                                                        ?>
                                                        <?php if ($modalImg): ?>
                                                            <?php
                                                                $modalImgNorm = $modalImg;
                                                                if (!preg_match('/^(https?:)?\\/\\//i', $modalImgNorm)) {
                                                                    // Remove leading ../ or /
                                                                    $modalImgNorm = ltrim($modalImgNorm, '/.');
                                                                    // Build absolute path from domain root
                                                                    $modalImgNorm = '/' . $modalImgNorm;
                                                                }
                                                            ?>
                                                            <img src="<?php echo htmlspecialchars($modalImgNorm); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>" style="max-width: 220px; border-radius: 10px; border:1px solid #e9ecef; padding:8px;">
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="text-start mb-3">
                                                        <small class="text-muted"><?php echo htmlspecialchars($product['category']); ?></small>
                                                        <h6 class="mt-1"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                                        <p class="text-muted small mb-1"><?php echo htmlspecialchars($product['description']); ?></p>
                                                        <p class="text-success fw-bold mb-1">Base Price: <?php echo formatCurrency($product['price']); ?></p>
                                                    </div>

                                                    <?php
                                                    // Fetch variants for this product
                                                    $variants_query = "SELECT DISTINCT variant_type FROM product_variants WHERE product_id = " . $product['product_id'] . " ORDER BY variant_type";
                                                    $variants_result = mysqli_query($conn, $variants_query);
                                                    $has_variants = mysqli_num_rows($variants_result) > 0;
                                                    ?>

                                                    <div class="variant-options mb-3 text-start">
                                                        <?php
                                                        while ($variant_type = mysqli_fetch_assoc($variants_result)) {
                                                            $type = $variant_type['variant_type'];
                                                            echo '<div class="mb-2">';
                                                            echo '<label class="form-label">' . htmlspecialchars(ucfirst($type)) . ' *</label>';
                                                            echo '<select class="form-select variant-select" name="variants[' . htmlspecialchars($type) . ']" data-product-id="' . $product['product_id'] . '" required>';
                                                            echo '<option value="">Select ' . htmlspecialchars(ucfirst($type)) . '</option>';

                                                            // Fetch values for this variant type
                                                            $values_query = "SELECT * FROM product_variants WHERE product_id = " . $product['product_id'] . " AND variant_type = '" . mysqli_real_escape_string($conn, $type) . "'";
                                                            $values_result = mysqli_query($conn, $values_query);

                                                            while ($value = mysqli_fetch_assoc($values_result)) {
                                                                echo '<option value="' . htmlspecialchars($value['variant_value']) . '" 
                                                                        data-price="' . $value['price'] . '"
                                                                        data-stock="' . $value['stock_quantity'] . '">
                                                                        ' . htmlspecialchars($value['variant_value']) . ' - ' . formatCurrency($value['price']) . '
                                                                    </option>';
                                                            }

                                                            echo '</select>';
                                                            echo '</div>';
                                                        }
                                                        ?>
                                                    </div>

                                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <label class="form-label mb-0">Quantity</label>
                                                        </div>
                                                        <div class="d-flex align-items-center">
                                                            <button type="button" class="btn btn-outline-secondary btn-sm me-1 qty-decrease" data-target="#quantityInput<?php echo $product['product_id']; ?>">-</button>
                                                            <input type="number" class="form-control text-center" name="quantity" value="1" min="1" 
                                                                max="<?php echo $has_variants ? 1 : $product['stock_quantity']; ?>" 
                                                                required
                                                                <?php echo $has_variants ? 'disabled' : ''; ?>
                                                                id="quantityInput<?php echo $product['product_id']; ?>" style="width:80px;">
                                                            <button type="button" class="btn btn-outline-secondary btn-sm ms-1 qty-increase" data-target="#quantityInput<?php echo $product['product_id']; ?>">+</button>
                                                        </div>
                                                    </div>

                                                    <div class="price-stock-info text-start mb-2">
                                                        <p class="mb-1"><strong>Price:</strong> <span id="displayPrice<?php echo $product['product_id']; ?>">
                                                            <?php echo $has_variants ? 'Select variants to see price' : formatCurrency($product['price']); ?>
                                                        </span></p>
                                                        <p class="mb-1"><strong>Available Stock:</strong> <span id="displayStock<?php echo $product['product_id']; ?>">
                                                            <?php echo $has_variants ? 'Select variants to see stock' : $product['stock_quantity'] . ' units'; ?>
                                                        </span></p>
                                                        <p class="mb-0"><strong>Total:</strong> <span id="displayTotal<?php echo $product['product_id']; ?>">
                                                            <?php echo $has_variants ? '₱0.00' : formatCurrency($product['price']); ?>
                                                        </span></p>
                                                    </div>

                                                    <input type="hidden" name="selected_variant_price" id="variantPrice<?php echo $product['product_id']; ?>" value="<?php echo $has_variants ? '' : $product['price']; ?>">
                                                    <input type="hidden" name="selected_variant_id" id="variantId<?php echo $product['product_id']; ?>" value="">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button id="modalAddBtn<?php echo $product['product_id']; ?>" type="submit" name="add_to_cart" class="btn btn-success">
                                                        <i class="bi bi-cart-plus me-2"></i>Add to Cart
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="bi bi-search"></i>
                            <h5>No Products Found</h5>
                            <p>Try adjusting your search or filter criteria.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/product-variants-shop.js"></script>
</body>
</html>