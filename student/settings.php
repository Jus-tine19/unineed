<?php
require_once '../config/database.php';
requireStudent();

// Get current user data
$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM users WHERE user_id = $user_id";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = clean($_POST['phone']);
    
    $update_query = "UPDATE users SET 
                    phone = '$phone'
                    WHERE user_id = $user_id";
    
    if (mysqli_query($conn, $update_query)) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - UniNeeds Student</title>
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

            <div class="row g-4">
                <!-- Profile Information -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-person me-2"></i>Profile Information</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Student ID</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['student_id']); ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
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
                
                <!-- Change Password -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
                        </div>
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
            </div>
            
            <!-- Logout -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Logout</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6>Sign out</h6>
                            <p class="text-muted mb-0">Click below to end your session and return to the login page.</p>
                        <script src="../assets/js/script.js"></script>
                        </div>
                        <a href="../api/logout.php" class="btn btn-outline-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>

        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        </div>

<style>
.main-content {
    min-height: 100vh;
    margin-left: 250px;
    background-color: #f5f6f8;
}

.content-area {
    padding: 30px;
}

.top-bar {
    background-color: white;
    padding: 20px 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.top-bar h2 {
    color: #2c3345;
    font-weight: 600;
    margin: 0;
}

.page-title {
    color: #2c3345;
    font-weight: 600;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    background-color: white;
}

.card-header {
    background-color: white;
    border-bottom: 1px solid #e8e9eb;
    padding: 20px;
    border-radius: 12px 12px 0 0;
}

.card-header h5 {
    color: #2c3345;
    font-weight: 600;
    margin: 0;
    font-size: 1.1rem;
}

.card-body {
    padding: 25px;
}

.form-label {
    color: #2c3345;
    font-weight: 500;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid #dddfe3;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #FF8C00;
    box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.1);
}

.form-control:disabled,
.form-control[readonly] {
    background-color: #f5f6f8;
    border-color: #e8e9eb;
    color: #666;
}

.btn-primary {
    background-color: #4CAF50;
    border-color: #4CAF50;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background-color: #388E3C;
    border-color: #388E3C;
    box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
}

.btn-warning {
    background-color: #FF8C00;
    border-color: #FF8C00;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 500;
    color: white;
    transition: all 0.3s ease;
}

.btn-warning:hover {
    background-color: #E67E00;
    border-color: #E67E00;
    box-shadow: 0 4px 8px rgba(255, 140, 0, 0.3);
}

.btn-outline-danger {
    border: 1px solid #dc3545;
    color: #dc3545;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

.alert {
    border: none;
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    font-size: 0.95rem;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

.small.text-muted {
    color: #888 !important;
    font-size: 0.85rem;
}

.row.g-4 > .col-md-6 {
    display: flex;
    flex-direction: column;
}

.row.g-4 > .col-md-6 .card {
    flex: 1;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding-top: 60px;
    }
    
    .content-area {
        padding: 15px;
    }
    
    .top-bar {
        padding: 15px;
    }
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../assets/js/mobile-menu.js"></script>

</body>
</html>