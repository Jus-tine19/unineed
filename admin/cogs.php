<?php

require_once '../config/database.php';
requireAdmin();

// Handle COGS Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cogs'])) {
    $product_id = intval(clean($_POST['product_id']));
    $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? intval(clean($_POST['variant_id'])) : null;
    $quantity = intval(clean($_POST['quantity']));
    $unit_cost = floatval(clean($_POST['unit_cost']));
    $total_cost = $quantity * $unit_cost;
    $supplier = clean($_POST['supplier']);
    $invoice_number = clean($_POST['invoice_number']);
    $purchase_date = clean($_POST['purchase_date']);
    $notes = clean($_POST['notes']);
    
    // Handle file upload
    $receipt_path = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($file_ext, $allowed_ext)) {
            $new_filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_path)) {
                $receipt_path = 'uploads/receipts/' . $new_filename;
            }
        }
    }
    
    mysqli_begin_transaction($conn);
    try {
        // Insert COGS record
        $receipt_sql = $receipt_path ? "'" . mysqli_real_escape_string($conn, $receipt_path) . "'" : "NULL";
        $variant_sql = $variant_id ? $variant_id : "NULL";
        
        $query = "INSERT INTO cost_of_goods_sold 
                  (product_id, variant_id, quantity, unit_cost, total_cost, supplier, invoice_number, purchase_date, receipt_path, notes, created_by) 
                  VALUES ($product_id, $variant_sql, $quantity, $unit_cost, $total_cost, 
                          '" . mysqli_real_escape_string($conn, $supplier) . "',
                          '" . mysqli_real_escape_string($conn, $invoice_number) . "',
                          '$purchase_date', $receipt_sql,
                          '" . mysqli_real_escape_string($conn, $notes) . "',
                          {$_SESSION['user_id']})";
        
        if (!mysqli_query($conn, $query)) {
            throw new Exception("Failed to insert COGS record");
        }
        
        // Update inventory stock
        if ($variant_id) {
            $update_stock = "UPDATE product_variants SET stock_quantity = stock_quantity + $quantity WHERE variant_id = $variant_id";
        } else {
            $update_stock = "UPDATE products SET stock_quantity = stock_quantity + $quantity WHERE product_id = $product_id";
        }
        
        if (!mysqli_query($conn, $update_stock)) {
            throw new Exception("Failed to update stock");
        }
        
        // Record inventory movement
        $product_query = "SELECT product_name, price FROM products WHERE product_id = $product_id";
        $prod_result = mysqli_query($conn, $product_query);
        $product = mysqli_fetch_assoc($prod_result);
        
        $current_stock_query = $variant_id ? 
            "SELECT stock_quantity FROM product_variants WHERE variant_id = $variant_id" :
            "SELECT stock_quantity FROM products WHERE product_id = $product_id";
        $stock_result = mysqli_query($conn, $current_stock_query);
        $stock_row = mysqli_fetch_assoc($stock_result);
        $new_stock = $stock_row['stock_quantity'];
        $previous_stock = $new_stock - $quantity;
        
        $movement_reason = "COGS Entry - Purchase from " . mysqli_real_escape_string($conn, $supplier) . " (Invoice: " . mysqli_real_escape_string($conn, $invoice_number) . ")";
        $variant_sql_mv = $variant_id ? $variant_id : "NULL";
        
        $movement_query = "INSERT INTO inventory_movements 
                          (product_id, variant_id, quantity_change, previous_quantity, new_quantity, movement_type, reason, created_by)
                          VALUES ($product_id, $variant_sql_mv, $quantity, $previous_stock, $new_stock, 'add', '$movement_reason', {$_SESSION['user_id']})";
        mysqli_query($conn, $movement_query);
        
        mysqli_commit($conn);
        $success = "COGS entry added successfully! Stock updated.";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Failed to add COGS entry: " . $e->getMessage();
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $cogs_id = intval($_GET['delete']);
    
    // Get COGS details first
    $cogs_query = "SELECT * FROM cost_of_goods_sold WHERE cogs_id = $cogs_id";
    $cogs_result = mysqli_query($conn, $cogs_query);
    
    if ($cogs_result && mysqli_num_rows($cogs_result) > 0) {
        $cogs = mysqli_fetch_assoc($cogs_result);
        
        mysqli_begin_transaction($conn);
        try {
            // Delete receipt file if exists
            if ($cogs['receipt_path'] && file_exists('../' . $cogs['receipt_path'])) {
                unlink('../' . $cogs['receipt_path']);
            }
            
            // Delete COGS record
            $delete_query = "DELETE FROM cost_of_goods_sold WHERE cogs_id = $cogs_id";
            mysqli_query($conn, $delete_query);
            
            mysqli_commit($conn);
            $success = "COGS entry deleted successfully!";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to delete COGS entry: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$filter_date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');
$filter_product = isset($_GET['product']) ? intval($_GET['product']) : '';

// Build query with filters
$where_clauses = ["c.purchase_date BETWEEN '$filter_date_from' AND '$filter_date_to'"];
if ($filter_product) {
    $where_clauses[] = "c.product_id = $filter_product";
}
$where_sql = implode(' AND ', $where_clauses);

// Get COGS records
$cogs_query = "SELECT c.*, p.product_name, pv.variant_type, pv.variant_value, u.full_name as created_by_name
               FROM cost_of_goods_sold c
               JOIN products p ON c.product_id = p.product_id
               LEFT JOIN product_variants pv ON c.variant_id = pv.variant_id
               LEFT JOIN users u ON c.created_by = u.user_id
               WHERE $where_sql
               ORDER BY c.purchase_date DESC, c.created_at DESC";
$cogs_records = mysqli_query($conn, $cogs_query);

// Calculate totals
$totals_query = "SELECT 
                 SUM(total_cost) as total_cogs,
                 SUM(quantity) as total_quantity,
                 COUNT(*) as total_entries
                 FROM cost_of_goods_sold c
                 WHERE $where_sql";
$totals_result = mysqli_query($conn, $totals_query);
$totals = mysqli_fetch_assoc($totals_result);

// Get all products for filter
$products_query = "SELECT product_id, product_name FROM products ORDER BY product_name";
$products = mysqli_query($conn, $products_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost of Goods Sold - UniNeeds Admin</title>
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
            <h2><i class="bi bi-receipt-cutoff me-2"></i>Cost of Goods Sold (COGS)</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCogsModal">
                    <i class="bi bi-plus-circle me-2"></i>Add COGS Entry
                </button>
                <a href="cogs_report.php?date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&product=<?php echo $filter_product; ?>" class="btn btn-success" target="_blank">
                    <i class="bi bi-file-earmark-excel me-2"></i>Download Report
                </a>
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
            
            <!-- Summary Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-currency-peso"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($totals['total_cogs'] ?? 0); ?></h3>
                            <p>Total COGS (Period)</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($totals['total_quantity'] ?? 0); ?></h3>
                            <p>Total Units Purchased</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $totals['total_entries'] ?? 0; ?></h3>
                            <p>Total Entries</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $filter_date_to; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Product</label>
                            <select class="form-select" name="product">
                                <option value="">All Products</option>
                                <?php 
                                mysqli_data_seek($products, 0);
                                while ($p = mysqli_fetch_assoc($products)): 
                                ?>
                                    <option value="<?php echo $p['product_id']; ?>" <?php echo $filter_product == $p['product_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['product_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- COGS Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">COGS Entries</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Unit Cost</th>
                                    <th>Total Cost</th>
                                    <th>Supplier</th>
                                    <th>Invoice #</th>
                                    <th>Receipt</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($cogs_records) > 0): ?>
                                    <?php while ($cogs = mysqli_fetch_assoc($cogs_records)): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($cogs['purchase_date'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($cogs['product_name']); ?></strong>
                                                <?php if ($cogs['variant_type']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($cogs['variant_type'] . ': ' . $cogs['variant_value']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $cogs['quantity']; ?> units</td>
                                            <td><?php echo formatCurrency($cogs['unit_cost']); ?></td>
                                            <td><strong><?php echo formatCurrency($cogs['total_cost']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($cogs['supplier']); ?></td>
                                            <td><?php echo htmlspecialchars($cogs['invoice_number']); ?></td>
                                            <td>
                                                <?php if ($cogs['receipt_path']): ?>
                                                    <a href="../<?php echo htmlspecialchars($cogs['receipt_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-file-earmark-image"></i> View
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">No receipt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $cogs['cogs_id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <a href="?delete=<?php echo $cogs['cogs_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this COGS entry?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5">
                                            <i class="bi bi-inbox fs-1 text-muted"></i>
                                            <p class="text-muted mt-2">No COGS entries found for selected period</p>
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
    
    <!-- Add COGS Modal -->
    <div class="modal fade" id="addCogsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add COGS Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Cost of Goods Sold (COGS)</strong> represents the direct costs of producing or purchasing products you sell. This includes purchase price from suppliers, manufacturing costs, etc.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Product *</label>
                                <select class="form-select" name="product_id" id="cogsProductSelect" required onchange="loadCogsVariants()">
                                    <option value="">Select Product</option>
                                    <?php 
                                    mysqli_data_seek($products, 0);
                                    while ($p = mysqli_fetch_assoc($products)): 
                                    ?>
                                        <option value="<?php echo $p['product_id']; ?>">
                                            <?php echo htmlspecialchars($p['product_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="cogsVariantContainer" style="display: none;">
                                <label class="form-label">Variant</label>
                                <select class="form-select" name="variant_id" id="cogsVariantSelect">
                                    <option value="">No Variant</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Quantity *</label>
                                <input type="number" class="form-control" name="quantity" id="cogsQuantity" required min="1" oninput="calculateTotal()">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Unit Cost *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control" name="unit_cost" id="cogsUnitCost" required min="0" oninput="calculateTotal()">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Total Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="text" class="form-control" id="cogsTotalCost" readonly>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Supplier *</label>
                                <input type="text" class="form-control" name="supplier" required placeholder="e.g., ABC Trading">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Invoice Number *</label>
                                <input type="text" class="form-control" name="invoice_number" required placeholder="e.g., INV-2024-001">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Purchase Date *</label>
                                <input type="date" class="form-control" name="purchase_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Upload Receipt/Invoice</label>
                                <input type="file" class="form-control" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">Accepted: JPG, PNG, PDF (Max 5MB)</small>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes about this purchase"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_cogs" class="btn btn-primary">
                            <i class="bi bi-check2 me-2"></i>Add Entry & Update Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
    function loadCogsVariants() {
        const productSelect = document.getElementById('cogsProductSelect');
        const variantContainer = document.getElementById('cogsVariantContainer');
        const variantSelect = document.getElementById('cogsVariantSelect');
        
        if (!productSelect.value) {
            variantContainer.style.display = 'none';
            return;
        }
        
        fetch('../api/get-product-variants.php?product_id=' + productSelect.value)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.variants && data.variants.length > 0) {
                    variantSelect.innerHTML = '<option value="">Select Variant</option>';
                    data.variants.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.variant_id;
                        opt.textContent = v.variant_type + ': ' + v.variant_value;
                        variantSelect.appendChild(opt);
                    });
                    variantContainer.style.display = 'block';
                } else {
                    variantContainer.style.display = 'none';
                }
            });
    }
    
    function calculateTotal() {
        const quantity = parseFloat(document.getElementById('cogsQuantity').value) || 0;
        const unitCost = parseFloat(document.getElementById('cogsUnitCost').value) || 0;
        const total = quantity * unitCost;
        document.getElementById('cogsTotalCost').value = total.toFixed(2);
    }
    
    function viewDetails(cogsId) {
        // You can implement a detail view modal here
        alert('View details for COGS ID: ' + cogsId);
    }
    </script>
</body>
</html>