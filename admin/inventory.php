<?php
// admin/inventory.php
require_once '../config/database.php';
requireAdmin();

// Handle stock updates from either the modal (update_stock) or quick adjustment (adjust_stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_stock']) || isset($_POST['adjust_stock']))) {
    // determine which form was submitted
    $is_quick = isset($_POST['adjust_stock']);
    $product_id = intval(clean($_POST['product_id'] ?? 0));
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? intval(clean($_POST['variant_id'])) : null;

    // For modal update_stock, the form provides the exact new stock in stock_quantity.
    // For quick adjust, the form provides adjustment_type (add/subtract) and quantity.
    $reason = isset($_POST['reason']) ? clean($_POST['reason']) : '';

    // Expense fields (AVAILABLE IN BOTH MODAL AND QUICK ADJUST NOW)
    $expense_amount = isset($_POST['expense_amount']) && $_POST['expense_amount'] !== '' ? floatval(clean($_POST['expense_amount'])) : 0;
    $expense_note = isset($_POST['expense_note']) ? clean($_POST['expense_note']) : '';

    // Start transaction
    mysqli_begin_transaction($conn);
    try {
        if ($is_quick) {
            // Quick adjust: compute new_stock based on current stock + adjustment
            $adjust_type = isset($_POST['adjustment_type']) ? clean($_POST['adjustment_type']) : 'add';
            $qty = isset($_POST['quantity']) ? intval(clean($_POST['quantity'])) : 0;

            if ($variant_id) {
                $cur_q = "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id AND product_id = $product_id LIMIT 1";
                $r = mysqli_query($conn, $cur_q);
                if (!$r || mysqli_num_rows($r) === 0) throw new Exception('Variant not found');
                $row = mysqli_fetch_assoc($r);
                $current_stock = intval($row['stock_quantity']);
                $price = floatval($row['price']);

                $new_stock = ($adjust_type === 'add') ? ($current_stock + $qty) : ($current_stock - $qty);
                if ($new_stock < 0) $new_stock = 0;

                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id";
                mysqli_query($conn, $upd);

                // Ensure inventory_movements columns exist
                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                if (mysqli_num_rows($col_check) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                }
                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ("Quick variant adjustment"));
                $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                $ins_mv .= "VALUES ($product_id, $variant_id, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                mysqli_query($conn, $ins_mv);

                // FIXED: Record expense for quick variant adjustment
                if ($expense_amount > 0) {
                    // ensure expenses table exists
                    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
                    if (mysqli_num_rows($tbl) === 0) {
                        $sql_e = @file_get_contents('../config/sql/expenses.sql');
                        if ($sql_e) mysqli_query($conn, $sql_e);
                    }
                    $desc = mysqli_real_escape_string($conn, $expense_note ?: ('Quick stock purchase for variant ' . $variant_id));
                    $ins_e = "INSERT INTO expenses (amount, description, related_product_id, related_variant_id, created_by) VALUES ($expense_amount, '$desc', $product_id, $variant_id, {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_e);
                }

            } else {
                // product-level quick adjust
                $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
                if (!$current_q || mysqli_num_rows($current_q) === 0) throw new Exception('Product not found');
                $prow = mysqli_fetch_assoc($current_q);
                $current_stock = intval($prow['stock_quantity']);
                $price = floatval($prow['price']);

                $new_stock = ($adjust_type === 'add') ? ($current_stock + $qty) : ($current_stock - $qty);
                if ($new_stock < 0) $new_stock = 0;

                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $product_id";
                mysqli_query($conn, $upd);

                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ('Quick product adjustment'));
                $ins_mv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                $ins_mv .= "VALUES ($product_id, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                mysqli_query($conn, $ins_mv);

                // FIXED: Record expense for quick product adjustment
                if ($expense_amount > 0) {
                    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
                    if (mysqli_num_rows($tbl) === 0) {
                        $sql_e = @file_get_contents('../config/sql/expenses.sql');
                        if ($sql_e) mysqli_query($conn, $sql_e);
                    }
                    $desc = mysqli_real_escape_string($conn, $expense_note ?: ('Quick stock purchase for product ' . $product_id));
                    $ins_e = "INSERT INTO expenses (amount, description, related_product_id, created_by) VALUES ($expense_amount, '$desc', $product_id, {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_e);
                }
            }

        } else {
            // Modal form: update_stock - expects exact new stock in stock_quantity
            $new_stock = isset($_POST['stock_quantity']) ? intval(clean($_POST['stock_quantity'])) : null;
            if ($variant_id) {
                $cur_q = "SELECT stock_quantity, price FROM product_variants WHERE variant_id = $variant_id AND product_id = $product_id LIMIT 1";
                $r = mysqli_query($conn, $cur_q);
                if (!$r || mysqli_num_rows($r) === 0) throw new Exception('Variant not found');
                $row = mysqli_fetch_assoc($r);
                $current_stock = intval($row['stock_quantity']);
                $price = floatval($row['price']);

                if ($new_stock === null) throw new Exception('New stock quantity required for variant update');
                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE product_variants SET stock_quantity = $new_stock WHERE variant_id = $variant_id";
                mysqli_query($conn, $upd);

                // Ensure inventory_movements has variant_id & price_at_movement columns (safe alter)
                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                if (mysqli_num_rows($col_check) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                }
                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ("Variant update"));
                $ins_mv = "INSERT INTO inventory_movements (product_id, variant_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                $ins_mv .= "VALUES ($product_id, $variant_id, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                mysqli_query($conn, $ins_mv);

                // If there's an expense amount, record it against the product/variant
                if ($expense_amount > 0) {
                    // ensure expenses table exists
                    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
                    if (mysqli_num_rows($tbl) === 0) {
                        $sql_e = file_get_contents('../config/sql/expenses.sql');
                        if ($sql_e) mysqli_query($conn, $sql_e);
                    }
                    $desc = mysqli_real_escape_string($conn, $expense_note ?: ('Stock purchase for variant ' . $variant_id));
                    $ins_e = "INSERT INTO expenses (amount, description, related_product_id, related_variant_id, created_by) VALUES ($expense_amount, '$desc', $product_id, $variant_id, {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_e);
                }

            } else {
                // Product-level update (no variants)
                if ($new_stock === null) throw new Exception('New stock quantity required');
                $current_q = mysqli_query($conn, "SELECT stock_quantity, price FROM products WHERE product_id = $product_id LIMIT 1");
                if (!$current_q || mysqli_num_rows($current_q) === 0) throw new Exception('Product not found');
                $prow = mysqli_fetch_assoc($current_q);
                $current_stock = intval($prow['stock_quantity']);
                $price = floatval($prow['price']);
                $stock_change = $new_stock - $current_stock;
                $movement_type = $stock_change > 0 ? 'add' : ($stock_change < 0 ? 'subtract' : 'adjustment');

                $upd = "UPDATE products SET stock_quantity = $new_stock WHERE product_id = $product_id";
                mysqli_query($conn, $upd);

                // Ensure columns exist before inserting price_at_movement (variant_id will be NULL)
                $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                if (mysqli_num_rows($col_check2) === 0) {
                    mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER product_id");
                }

                $mv_reason = mysqli_real_escape_string($conn, $reason ?: ('Product update'));
                $ins_mv = "INSERT INTO inventory_movements (product_id, price_at_movement, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by) ";
                $ins_mv .= "VALUES ($product_id, $price, $stock_change, $current_stock, $new_stock, '$movement_type', '$mv_reason', {$_SESSION['user_id']})";
                mysqli_query($conn, $ins_mv);

                if ($expense_amount > 0) {
                    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
                    if (mysqli_num_rows($tbl) === 0) {
                        $sql_e = file_get_contents('../config/sql/expenses.sql');
                        if ($sql_e) mysqli_query($conn, $sql_e);
                    }
                    $desc = mysqli_real_escape_string($conn, $expense_note ?: ('Stock purchase for product ' . $product_id));
                    $ins_e = "INSERT INTO expenses (amount, description, related_product_id, created_by) VALUES ($expense_amount, '$desc', $product_id, {$_SESSION['user_id']})";
                    mysqli_query($conn, $ins_e);
                }
            }
        }

        mysqli_commit($conn);
        $success = "Stock updated successfully!" . ($expense_amount > 0 ? " Expense of " . formatCurrency($expense_amount) . " recorded." : "");
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to update stock: " . $e->getMessage();
    }
}

// Get statistics
$total_products = mysqli_query($conn, "SELECT COUNT(*) as total FROM products");
$total_count = mysqli_fetch_assoc($total_products)['total'];

// Low stock based on total_stock (variants considered)
$low_stock_q = "SELECT COUNT(*) as low FROM (
    SELECT p.product_id, (CASE WHEN COUNT(v.variant_id) > 0 THEN COALESCE(SUM(v.stock_quantity),0) ELSE p.stock_quantity END) as total_stock
    FROM products p
    LEFT JOIN product_variants v ON p.product_id = v.product_id
    GROUP BY p.product_id
    HAVING total_stock <= 10 AND total_stock > 0
) t";
$low_stock = mysqli_query($conn, $low_stock_q);
$low_count = mysqli_fetch_assoc($low_stock)['low'] ?? 0;

// Out of stock based on total_stock
$out_stock_q = "SELECT COUNT(*) as out_of_stock FROM (
    SELECT p.product_id, (CASE WHEN COUNT(v.variant_id) > 0 THEN COALESCE(SUM(v.stock_quantity),0) ELSE p.stock_quantity END) as total_stock
    FROM products p
    LEFT JOIN product_variants v ON p.product_id = v.product_id
    GROUP BY p.product_id
    HAVING total_stock = 0
) t";
$out_of_stock = mysqli_query($conn, $out_stock_q);
$out_count = mysqli_fetch_assoc($out_of_stock)['out_of_stock'] ?? 0;

// Inventory value based on total_stock * price
$total_value_q = "SELECT (
    COALESCE((SELECT SUM(v.price * v.stock_quantity) FROM product_variants v), 0) +
    COALESCE((SELECT SUM(p.price * p.stock_quantity) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.product_id)), 0)
) as value";
$total_value = mysqli_query($conn, $total_value_q);
$value = mysqli_fetch_assoc($total_value)['value'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - UniNeeds Admin</title>
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
            <h2>Inventory Management</h2>
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
            
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_count; ?></h3>
                            <p>Total Products</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $low_count; ?></h3>
                            <p>Low Stock Items</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-danger">
                        <div class="stat-icon">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $out_count; ?></h3>
                            <p>Out of Stock</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="bi bi-currency-peso"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($value); ?></h3>
                            <p>Inventory Value</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stock Adjustment -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>Quick Stock Adjustment</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="adjustmentForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Select Product *</label>
                                <select class="form-select" name="product_id" id="productSelect" required onchange="loadVariants()">
                                    <option value="">Choose a product...</option>
                                    <?php
                                    // Run a fresh product query specifically for the select
                                    $opts_q = "SELECT p.product_id, p.product_name, p.stock_quantity as base_stock, 
                                                      COUNT(v.variant_id) as variant_count,
                                                      GROUP_CONCAT(DISTINCT CONCAT(v.variant_id, '::', v.variant_type, '::', v.variant_value, '::', v.stock_quantity) SEPARATOR '||') as variants
                                                FROM products p
                                                LEFT JOIN product_variants v ON p.product_id = v.product_id
                                                GROUP BY p.product_id
                                                ORDER BY p.product_name ASC";
                                    $opts_res = mysqli_query($conn, $opts_q);
                                    if (!$opts_res) {
                                        ?>
                                        <option value="" disabled>Unable to load products</option>
                                        <?php
                                    } else {
                                        while ($popt = mysqli_fetch_assoc($opts_res)):
                                            $variants_raw = $popt['variants'] ?? '';
                                            if (is_array($variants_raw)) $variants_raw = implode('||', $variants_raw);
                                            $variants_attr = htmlspecialchars((string)$variants_raw);
                                            $has_variants = !empty($popt['variant_count']) && intval($popt['variant_count']) > 0 ? '1' : '0';
                                            $base_stock = isset($popt['base_stock']) ? intval($popt['base_stock']) : 0;
                                            $label = htmlspecialchars($popt['product_name']);
                                            if ($has_variants === '1') $label .= ' (' . intval($popt['variant_count']) . ' variants)';
                                        ?>
                                            <option value="<?php echo $popt['product_id']; ?>"
                                                    data-variants="<?php echo $variants_attr; ?>"
                                                    data-has-variants="<?php echo $has_variants; ?>"
                                                    data-base-stock="<?php echo $base_stock; ?>">
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endwhile; }
                                    ?>
                                </select>
                                <div class="form-text">Select a product to adjust its stock. Products with variants will require selecting a specific variant.</div>
                            </div>
                            
                            <div class="col-md-6" id="variantSelectContainer" style="display: none;">
                                <label class="form-label">Select Variant</label>
                                <select class="form-select" id="variantSelect" name="variant_id" required>
                                    <option value="">-- choose variant to adjust --</option>
                                </select>
                                <div class="form-text">Pick the specific variant to update (e.g., Black)</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Adjustment Type *</label>
                                <select class="form-select" name="adjustment_type" required>
                                    <option value="add">Add Stock (+)</option>
                                    <option value="subtract">Remove Stock (-)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Quantity *</label>
                                <input type="number" class="form-control" name="quantity" required min="1">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Reason *</label>
                                <textarea class="form-control" name="reason" required rows="2" placeholder="Enter reason for stock adjustment"></textarea>
                            </div>

                            <!-- EXPENSE FIELDS - NOW WORKING IN QUICK ADJUST -->
                            <div class="col-12">
                                <hr>
                                <h6 class="text-muted"><i class="bi bi-receipt me-2"></i>Expense Tracking (Optional)</h6>
                                <small class="text-muted">Record the cost of this stock adjustment for financial reports</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Expense Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" min="0" name="expense_amount" class="form-control" placeholder="0.00">
                                </div>
                                <small class="form-text text-muted">Enter the amount spent on this stock (e.g., purchase cost)</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Expense Note</label>
                                <input type="text" class="form-control" name="expense_note" placeholder="e.g., Supplier name, Invoice #">
                                <small class="form-text text-muted">Additional details about the expense</small>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-info mt-3" id="stockInfo">
                                    Select a product to see current stock information.
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" name="adjust_stock" class="btn btn-primary">
                                    <i class="bi bi-check2 me-2"></i>Apply Adjustment
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stock Movement History -->
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Stock Movements</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Product</th>
                                    <th>Change</th>
                                    <th>Previous Stock</th>
                                    <th>New Stock</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                    <th>Updated By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Ensure inventory_movements table exists
                                $table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'inventory_movements'");
                                if (mysqli_num_rows($table_exists) === 0) {
                                    $create_table_sql = file_get_contents('../config/sql/inventory_movements.sql');
                                    mysqli_query($conn, $create_table_sql);
                                }

                                // Ensure variant_id column exists
                                $col = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'variant_id'");
                                if (mysqli_num_rows($col) === 0) {
                                    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN variant_id INT NULL AFTER product_id");
                                }
                                // Ensure price_at_movement column exists
                                $col2 = mysqli_query($conn, "SHOW COLUMNS FROM inventory_movements LIKE 'price_at_movement'");
                                if (mysqli_num_rows($col2) === 0) {
                                    @mysqli_query($conn, "ALTER TABLE inventory_movements ADD COLUMN price_at_movement DECIMAL(10,2) NULL AFTER variant_id");
                                }

                                $movements_query = "SELECT m.*, p.product_name, u.full_name AS username, pv.variant_type, pv.variant_value 
                                                  FROM inventory_movements m 
                                                  JOIN products p ON m.product_id = p.product_id 
                                                  LEFT JOIN product_variants pv ON m.variant_id = pv.variant_id
                                                  LEFT JOIN users u ON m.created_by = u.user_id 
                                                  ORDER BY m.created_at DESC LIMIT 10";
                                $movements = mysqli_query($conn, $movements_query);
                                
                                if (mysqli_num_rows($movements) > 0):
                                    while ($movement = mysqli_fetch_assoc($movements)):
                                        $change_class = $movement['quantity_change'] > 0 ? 'text-success' : ($movement['quantity_change'] < 0 ? 'text-danger' : 'text-warning');
                                        $change_icon = $movement['quantity_change'] > 0 ? 'plus' : ($movement['quantity_change'] < 0 ? 'dash' : 'arrow-left-right');
                                ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($movement['created_at'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($movement['product_name']); ?></strong></td>
                                        <td class="<?php echo $change_class; ?>">
                                            <i class="bi bi-<?php echo $change_icon; ?>"></i>
                                            <?php echo $movement['quantity_change'] > 0 ? '+' : ''; ?><?php echo $movement['quantity_change']; ?>
                                        </td>
                                        <td><?php echo $movement['previous_quantity']; ?></td>
                                        <td><?php echo $movement['new_quantity']; ?></td>
                                        <td>
                                            <?php 
                                            $type_class = [
                                                'add' => 'success',
                                                'subtract' => 'danger',
                                                'adjustment' => 'warning'
                                            ][$movement['movement_type']];
                                            ?>
                                            <span class="badge bg-<?php echo $type_class; ?>">
                                                <?php echo ucfirst($movement['movement_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                                $extra = '';
                                                if (!empty($movement['variant_type']) || !empty($movement['variant_value'])) {
                                                    $extra = ' ('. htmlspecialchars($movement['variant_type'] . ': ' . $movement['variant_value']) . ')';
                                                }
                                            ?>
                                            <?php echo htmlspecialchars($movement['reason']) . $extra; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($movement['username']); ?></td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-clock-history"></i>
                                                <h5>No Stock Movements Yet</h5>
                                                <p>Stock movements will be recorded when you update product quantities.</p>
                                                <small class="text-muted">Try updating a product's stock to see the history.</small>
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
    
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    
    <script>
    function loadVariants() {
        const productSelect = document.getElementById('productSelect');
        const variantContainer = document.getElementById('variantSelectContainer');
        const variantSelect = document.getElementById('variantSelect');
        const stockInfo = document.getElementById('stockInfo');

        // reset
        variantSelect.innerHTML = '<option value="">-- choose variant to adjust --</option>';
        variantContainer.style.display = 'none';

        if (!productSelect || !productSelect.value) {
            stockInfo.innerHTML = 'Select a product to see current stock information.';
            return;
        }

        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const hasVariants = selectedOption.dataset.hasVariants === '1' || selectedOption.dataset.hasVariants === 'true';
        const variants = selectedOption.dataset.variants || '';

        function populateFromList(list) {
            // list = array of {variant_id, variant_type, variant_value, stock_quantity}
            variantSelect.innerHTML = '<option value="">-- choose variant to adjust --</option>';
            list.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.variant_id;
                opt.dataset.stock = v.stock_quantity;
                opt.textContent = v.variant_type + ': ' + v.variant_value + ' (Stock: ' + v.stock_quantity + ')';
                variantSelect.appendChild(opt);
            });
            variantContainer.style.display = list.length ? 'block' : 'none';
            if (list.length) {
                stockInfo.innerHTML = '<div class="alert alert-info">Please select a variant to view stock.</div>';
                variantSelect.onchange = function() {
                    const sel = this.options[this.selectedIndex];
                    if (sel && sel.value) updateStockInfo(sel.dataset.stock);
                };
                // auto-select first
                variantSelect.selectedIndex = 1;
                variantSelect.onchange();
            } else {
                stockInfo.innerHTML = '<div class="alert alert-info">No variants available for this product.</div>';
            }
        }

        if (hasVariants) {
            if (variants && variants.length > 0) {
                // parse GROUP_CONCAT string
                const list = variants.split('||').map(s => {
                    const parts = s.split('::');
                    return { variant_id: parts[0], variant_type: parts[1] || '', variant_value: parts[2] || '', stock_quantity: parseInt(parts[3] || 0) };
                }).filter(v => v.variant_id);
                populateFromList(list);
            } else {
                // fall back to AJAX fetch of variants
                const pid = productSelect.value;
                fetch('/unineeds/api/get-product-variants.php?product_id=' + encodeURIComponent(pid))
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success && Array.isArray(data.variants)) {
                            populateFromList(data.variants);
                        } else {
                            stockInfo.innerHTML = '<div class="alert alert-warning">Unable to load variants.</div>';
                        }
                    })
                    .catch(err => {
                        console.error('Failed to fetch variants', err);
                        stockInfo.innerHTML = '<div class="alert alert-warning">Unable to load variants.</div>';
                    });
            }
        } else {
            const baseStock = parseInt(selectedOption.dataset.baseStock || 0);
            stockInfo.innerHTML = `
                <div class="alert alert-info">
                    <strong>Current Stock:</strong> ${baseStock} units
                </div>`;
        }
    }

    function updateStockInfo(currentStock) {
        const stockInfo = document.getElementById('stockInfo');
        
        if (currentStock !== undefined) {
            currentStock = parseInt(currentStock);
            stockInfo.innerHTML = `
                <div class="alert ${currentStock < 10 ? 'alert-warning' : 'alert-info'}">
                    <strong>Current Stock:</strong> ${currentStock} units
                    ${currentStock < 10 ? '<br><small class="text-warning">Low stock warning!</small>' : ''}
                </div>`;
        } else {
            stockInfo.innerHTML = '<div class="alert alert-info">Please select a variant to view stock.</div>';
        }
    }

    // Initialize variants on page load if a product is selected
    document.addEventListener('DOMContentLoaded', function() {
        const productSelect = document.getElementById('productSelect');
        if (productSelect && productSelect.value) {
            loadVariants();
        }
    });
    </script>
</body>
</html>