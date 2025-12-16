<?php

require_once '../config/database.php';
requireAdmin();

$user_id = $_SESSION['user_id'];
$success = null;
$error = null;

// Get current user data
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Handle Profile Update (for admin name/phone)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = clean($_POST['full_name']);
    $phone = clean($_POST['phone']);
    
    $update_query = "UPDATE users SET 
                    full_name = '$full_name',
                    phone = '$phone'
                    WHERE user_id = $user_id";
    
    if (mysqli_query($conn, $update_query)) {
        // Update session variables if name was changed
        $_SESSION['full_name'] = $full_name;
        $success = "Profile updated successfully!";
        // Refresh user data
        $result = mysqli_query($conn, $query);
        $user = mysqli_fetch_assoc($result);
    } else {
        $error = "Failed to update profile.";
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = '$hashed_password' WHERE user_id = $user_id";
                
                if (mysqli_query($conn, $update_query)) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to change password.";
                }
            } else {
                $error = "Password must be at least 6 characters long.";
            }
        } else {
            $error = "New passwords do not match.";
        }
    } else {
        $error = "Current password is incorrect.";
    }
}

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
        $success = "GCash settings updated successfully!";
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
    <style>
        /* Custom style for rotating chevron icon */
        .card-header[aria-expanded="true"] .toggle-icon {
            transform: rotate(180deg);
        }
        .toggle-icon {
            transition: transform 0.3s ease;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="btn btn-link d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h2>Settings</h2>
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

            <div class="accordion" id="adminSettingsAccordion">
                
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center p-3" role="button" data-bs-toggle="collapse" data-bs-target="#collapseProfile" aria-expanded="true" aria-controls="collapseProfile">
                        <h5 class="mb-0"><i class="bi bi-person me-2"></i>Admin Profile Information</h5>
                        <i class="bi bi-chevron-down toggle-icon"></i>
                    </div>
                    <div class="collapse show" id="collapseProfile" data-bs-parent="#adminSettingsAccordion">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="09XXXXXXXXX">
                                </div>
                                <button type="submit" name="update_profile" class="btn btn-primary mt-3">
                                    <i class="bi bi-save me-2"></i>Update Profile
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center p-3" role="button" data-bs-toggle="collapse" data-bs-target="#collapsePassword" aria-expanded="false" aria-controls="collapsePassword">
                        <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
                        <i class="bi bi-chevron-down toggle-icon"></i>
                    </div>
                    <div class="collapse" id="collapsePassword" data-bs-parent="#adminSettingsAccordion">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Current Password *</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password *</label>
                                    <input type="password" class="form-control" name="new_password" required minlength="6">
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password *</label>
                                    <input type="password" class="form-control" name="confirm_password" required minlength="6">
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning mt-3">
                                    <i class="bi bi-key me-2"></i>Change Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center p-3" role="button" data-bs-toggle="collapse" data-bs-target="#collapseGcash" aria-expanded="false" aria-controls="collapseGcash">
                        <h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>GCash Payment Settings</h5>
                        <i class="bi bi-chevron-down toggle-icon"></i>
                    </div>
                    <div class="collapse" id="collapseGcash" data-bs-parent="#adminSettingsAccordion">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="gcash_name" class="form-label">GCash Account Name</label>
                                    <input type="text" class="form-control" id="gcash_name" name="gcash_name" value="<?php echo htmlspecialchars($current_gcash_name); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="gcash_number" class="form-label">GCash Account Number</label>
                                    <input type="text" class="form-control" id="gcash_number" name="gcash_number" value="<?php echo htmlspecialchars($current_gcash_number); ?>" required>
                                    <div class="form-text">This number is displayed to students for payment.</div>
                                </div>
                                <button type="submit" name="update_gcash_settings" class="btn btn-success mt-3">
                                    <i class="bi bi-save me-2"></i>Save GCash Settings
                                </button>
                            </form>
                            
                            <hr class="mt-4">
                            
                          
                        </div>
                    </div>
                </div>

            </div>
          

        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/mobile-menu.js"></script>
    <script>
        // Custom JavaScript to handle the toggle icon rotation
        document.addEventListener('DOMContentLoaded', function () {
            var accordion = document.getElementById('adminSettingsAccordion');
            if (accordion) {
                // Event listener for when a collapse item is shown
                accordion.addEventListener('shown.bs.collapse', function (e) {
                    var header = e.target.previousElementSibling;
                    var icon = header.querySelector('.toggle-icon');
                    if (icon) {
                        icon.style.transform = 'rotate(180deg)';
                    }
                });

                // Event listener for when a collapse item is hidden
                accordion.addEventListener('hidden.bs.collapse', function (e) {
                    var header = e.target.previousElementSibling;
                    var icon = header.querySelector('.toggle-icon');
                    if (icon) {
                        icon.style.transform = 'rotate(0deg)';
                    }
                });

                // Initialize the icon state on load
                document.querySelectorAll('.card-header[data-bs-toggle="collapse"]').forEach(header => {
                    var targetId = header.getAttribute('data-bs-target');
                    var targetCollapse = document.querySelector(targetId);
                    var icon = header.querySelector('.toggle-icon');
                    
                    if (targetCollapse && icon) {
                        if (targetCollapse.classList.contains('show')) {
                            icon.style.transform = 'rotate(180deg)';
                        } else {
                            icon.style.transform = 'rotate(0deg)';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>