<?php

require_once '../config/database.php';
requireAdmin();

$user_id = $_SESSION['user_id'];
$success = null;
$error = null;

// Handle GCash Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_gcash_settings'])) {
    $new_gcash_number = clean($_POST['gcash_number']);
    $new_gcash_name = clean($_POST['gcash_name']);
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update GCASH_NUMBER
        $query_number = "INSERT INTO settings (setting_key, setting_value) VALUES ('gcash_number', '$new_gcash_number')
                         ON DUPLICATE KEY UPDATE setting_value = '$new_gcash_number'";
        if (!mysqli_query($conn, $query_number)) {
            throw new Exception("Failed to update GCash number.");
        }
        
        // Update GCASH_NAME
        $query_name = "INSERT INTO settings (setting_key, setting_value) VALUES ('gcash_name', '$new_gcash_name')
                       ON DUPLICATE KEY UPDATE setting_value = '$new_gcash_name'";
        if (!mysqli_query($conn, $query_name)) {
            throw new Exception("Failed to update GCash name.");
        }
        
        mysqli_commit($conn);
        $success = "GCash settings updated successfully! (Refresh may be required to see changes)";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Error updating GCash settings: " . $e->getMessage();
    }
    
    // Refresh the page to reload constants from DB
    if ($success) {
        header("Location: settings.php?success=gcash_updated");
        exit();
    }
}

// Fetch user profile settings (existing logic)
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Check for success message after redirect
if (isset($_GET['success']) && $_GET['success'] === 'gcash_updated') {
    $success = "GCash settings updated successfully!";
}


// Fetch current GCash settings (using the constants defined in database.php)
// These constants are loaded from the DB via database.php
$current_gcash_number = GCASH_NUMBER;
$current_gcash_name = GCASH_NAME;

// Get the down payment rate percentage from the config
$down_payment_rate = DOWN_PAYMENT_PERCENTAGE * 100;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings - UniNeeds</title>
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
            <h2>Admin Settings</h2>
        </div>
        
        <div class="content-area">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-person-gear me-2"></i>Admin Profile</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Full Name:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                            <p><strong>User Type:</strong> <span class="badge bg-primary"><?php echo ucfirst($user['user_type']); ?></span></p>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Global Financial Settings</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-0"><strong>Required Down Payment:</strong> <span class="badge bg-warning fs-6"><?php echo $down_payment_rate; ?>%</span></p>
                            <p class="small text-muted mt-1 mb-0">This percentage is hardcoded in <code>config/database.php</code> and applies to all products requiring a down payment.</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>GCash Payment Settings</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="gcash_name" class="form-label">GCash Account Name</label>
                                    <input type="text" class="form-control" id="gcash_name" name="gcash_name" value="<?php echo htmlspecialchars($current_gcash_name); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="gcash_number" class="form-label">GCash Account Number</label>
                                    <input type="text" class="form-control" id="gcash_number" name="gcash_number" value="<?php echo htmlspecialchars($current_gcash_number); ?>" required>
                                    <div class="form-text">e.g., 09171234567. This number will be displayed to students.</div>
                                </div>
                                <button type="submit" name="update_gcash_settings" class="btn btn-success">
                                    <i class="bi bi-save me-2"></i>Save GCash Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/mobile-menu.js"></script>
</body>
</html>