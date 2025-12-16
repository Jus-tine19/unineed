<?php

require_once '../config/database.php';
requireAdmin();

// Handle Add Expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $expense_type = clean($_POST['expense_type']);
    $amount = floatval(clean($_POST['amount']));
    $description = clean($_POST['description']);
    $expense_date = clean($_POST['expense_date']);
    $vendor = clean($_POST['vendor']);
    // Use null coalescing to safely handle 'reference_number' in case the field was hidden
    $reference_number = clean($_POST['reference_number'] ?? ''); 
    
    // Handle file upload
    $receipt_path = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/expense_receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($file_ext, $allowed_ext)) {
            $new_filename = 'expense_' . time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_path)) {
                $receipt_path = 'uploads/expense_receipts/' . $new_filename;
            }
        }
    }
    
    $receipt_sql = $receipt_path ? "'" . mysqli_real_escape_string($conn, $receipt_path) . "'" : "NULL";
    // Ensure reference_number is set to NULL if it's empty, otherwise use the value.
    $ref_sql = empty($reference_number) ? "NULL" : "'" . mysqli_real_escape_string($conn, $reference_number) . "'";
    
    $query = "INSERT INTO expenses 
              (expense_type, amount, description, expense_date, vendor, reference_number, receipt_path, created_by) 
              VALUES ('" . mysqli_real_escape_string($conn, $expense_type) . "',
                      $amount,
                      '" . mysqli_real_escape_string($conn, $description) . "',
                      '$expense_date',
                      '" . mysqli_real_escape_string($conn, $vendor) . "',
                      $ref_sql,
                      $receipt_sql,
                      {$_SESSION['user_id']})";
    
    if (mysqli_query($conn, $query)) {
        $success = "Expense added successfully!";
    } else {
        $error = "Failed to add expense: " . mysqli_error($conn);
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $expense_id = intval($_GET['delete']);
    
    // Get expense details first
    $expense_query = "SELECT receipt_path FROM expenses WHERE expense_id = $expense_id";
    $expense_result = mysqli_query($conn, $expense_query);
    
    if ($expense_result && mysqli_num_rows($expense_result) > 0) {
        $expense = mysqli_fetch_assoc($expense_result);
        
        // Delete receipt file if exists
        if ($expense['receipt_path'] && file_exists('../' . $expense['receipt_path'])) {
            unlink('../' . $expense['receipt_path']);
        }
        
        $delete_query = "DELETE FROM expenses WHERE expense_id = $expense_id";
        if (mysqli_query($conn, $delete_query)) {
            $success = "Expense deleted successfully!";
        } else {
            $error = "Failed to delete expense.";
        }
    }
}

// Get filter parameters
$filter_date_from = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01');
$filter_date_to = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-d');
$filter_type = isset($_GET['type']) ? clean($_GET['type']) : '';

// Build query with filters
$where_clauses = ["expense_date BETWEEN '$filter_date_from' AND '$filter_date_to'"];
if ($filter_type) {
    $where_clauses[] = "expense_type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";
}
$where_sql = implode(' AND ', $where_clauses);

// Get expenses
$expenses_query = "SELECT e.*, u.full_name as created_by_name
                   FROM expenses e
                   LEFT JOIN users u ON e.created_by = u.user_id
                   WHERE $where_sql
                   ORDER BY e.expense_date DESC, e.created_at DESC";
$expenses = mysqli_query($conn, $expenses_query);

// Calculate totals
$totals_query = "SELECT 
                 SUM(amount) as total_expenses,
                 COUNT(*) as total_entries,
                 expense_type,
                 SUM(amount) as type_total
                 FROM expenses
                 WHERE $where_sql
                 GROUP BY expense_type";
$totals_result = mysqli_query($conn, $totals_query);
$type_totals = [];
$grand_total = 0;
$total_count = 0;

while ($row = mysqli_fetch_assoc($totals_result)) {
    $type_totals[$row['expense_type']] = $row['type_total'];
    $grand_total += $row['type_total'];
    $total_count += $row['total_entries'];
}

// Expense types
$expense_types = [
    'transportation' => 'Transportation',
    'utilities' => 'Utilities (Electricity, Water, Internet)',
    'rent' => 'Rent',
    'salaries' => 'Salaries & Wages',
    'office_supplies' => 'Office Supplies',
    'marketing' => 'Marketing & Advertising',
    'maintenance' => 'Maintenance & Repairs',
    'insurance' => 'Insurance',
    'taxes' => 'Taxes & Fees',
    'other' => 'Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Management - UniNeeds Admin</title>
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
            <h2><i class="bi bi-wallet2 me-2"></i>Expenses Management</h2>
            <div class="ms-auto">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                    <i class="bi bi-plus-circle me-2"></i>Add Expense
                </button>
                <a href="expenses_report.php?date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&type=<?php echo $filter_type; ?>" class="btn btn-success" target="_blank">
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
            
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-danger">
                        <div class="stat-icon">
                            <i class="bi bi-currency-peso"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($grand_total); ?></h3>
                            <p>Total Expenses (Period)</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_count; ?></h3>
                            <p>Total Entries</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($type_totals['transportation'] ?? 0); ?></h3>
                            <p>Transportation</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="bi bi-lightning"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo formatCurrency($type_totals['utilities'] ?? 0); ?></h3>
                            <p>Utilities</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Expense Breakdown by Type</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($expense_types as $key => $label): ?>
                            <?php 
                            $amount = $type_totals[$key] ?? 0;
                            $percentage = $grand_total > 0 ? ($amount / $grand_total * 100) : 0;
                            ?>
                            <div class="col-md-6 mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?php echo $label; ?></span>
                                    <span><strong><?php echo formatCurrency($amount); ?></strong> (<?php echo number_format($percentage, 1); ?>%)</span>
                                </div>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
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
                            <label class="form-label">Expense Type</label>
                            <select class="form-select" name="type">
                                <option value="">All Types</option>
                                <?php foreach ($expense_types as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_type == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
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
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Expense Entries</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Vendor</th>
                                    <th>Reference #</th>
                                    <th>Receipt</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($expenses) > 0): ?>
                                    <?php while ($expense = mysqli_fetch_assoc($expenses)): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($expense['expense_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo $expense_types[$expense['expense_type']] ?? $expense['expense_type']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                            <td><strong><?php echo formatCurrency($expense['amount']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($expense['vendor']); ?></td>
                                            <td><?php echo htmlspecialchars($expense['reference_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($expense['receipt_path']): ?>
                                                    <a href="../<?php echo htmlspecialchars($expense['receipt_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-file-earmark-image"></i> View
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">No receipt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="?delete=<?php echo $expense['expense_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this expense?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <i class="bi bi-inbox fs-1 text-muted"></i>
                                            <p class="text-muted mt-2">No expenses found for selected period</p>
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
    
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Expenses</strong> are indirect costs of running the business (transportation, utilities, rent, etc.) that are not directly tied to product purchases.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Expense Type *</label>
                                <select class="form-select" name="expense_type" required id="expenseType" onchange="toggleReferenceNumber()">
                                    <option value="">Select Type</option>
                                    <?php foreach ($expense_types as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control" name="amount" required min="0">
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Description *</label>
                                <textarea class="form-control" name="description" required rows="2" placeholder="e.g., Gasoline for delivery, Internet bill for January"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Vendor/Payee *</label>
                                <input type="text" class="form-control" name="vendor" required placeholder="e.g., Shell Gas Station, PLDT">
                            </div>
                            
                            <div class="col-md-6" id="referenceNumberContainer">
                                <label class="form-label">Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" id="referenceNumberInput" placeholder="e.g., Receipt #, OR #">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Expense Date *</label>
                                <input type="date" class="form-control" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Upload Receipt</label>
                                <input type="file" class="form-control" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">Accepted: JPG, PNG, PDF (Max 5MB)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_expense" class="btn btn-primary">
                            <i class="bi bi-check2 me-2"></i>Add Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        /**
         * Toggles the visibility of the Reference Number field
         * based on the selected Expense Type.
         */
        function toggleReferenceNumber() {
            const expenseType = document.getElementById('expenseType').value;
            const refContainer = document.getElementById('referenceNumberContainer');
            const refInput = document.getElementById('referenceNumberInput');
            
            // Expense types that DO NOT need a Reference Number (Receipt/OR#)
            const nonRequiredTypes = ['transportation', 'other'];

            if (refContainer && refInput) {
                if (nonRequiredTypes.includes(expenseType)) {
                    // Hide the container
                    refContainer.style.display = 'none';
                    // Clear value to ensure no data is submitted for a hidden field
                    refInput.value = ''; 
                } else {
                    // Show the container for all other types
                    refContainer.style.display = 'block';
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            // Run the function when the modal is fully shown to correctly set the state
            const addExpenseModal = document.getElementById('addExpenseModal');
            if (addExpenseModal) {
                 addExpenseModal.addEventListener('shown.bs.modal', toggleReferenceNumber);
            }
            
            // Run on initial load in case the modal is pre-filled
            toggleReferenceNumber(); 
        });
    </script>
</body>
</html>