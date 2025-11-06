<?php
require_once '../includes/header.php';

// Ensure user is a student
if ($_SESSION['user_type'] !== 'student') {
    header("Location: /unineeds/index.php");
    exit();
}

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Invalid email format";
        } else {
            // Check if email is already taken by another user
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error_message = "Email is already taken";
            } else {
                // Update profile
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
                $stmt->bind_param("sssi", $name, $email, $phone, $user_id);
                if ($stmt->execute()) {
                    $success_message = "Profile updated successfully";
                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $user = $stmt->get_result()->fetch_assoc();
                } else {
                    $error_message = "Error updating profile";
                }
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            $error_message = "Current password is incorrect";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            if ($stmt->execute()) {
                $success_message = "Password changed successfully";
            } else {
                $error_message = "Error changing password";
            }
        }
    }
}
?>

<!-- Main Layout -->
<div class="d-flex">
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main content -->
    <div class="main-content flex-grow-1 bg-light">
        <div class="container-fluid px-4 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="page-title mb-0">Settings</h2>
            </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success" role="alert">
            <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Profile Settings -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0">Profile Settings</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user['phone']); ?>">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" 
                                   name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" 
                                   name="new_password" required>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" 
                                   name="confirm_password" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">
                            Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Notification Settings -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0">Notification Settings</h5>
                </div>
                <div class="card-body">
                    <form method="post" id="notificationForm">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="order_updates" 
                                       name="notifications[order_updates]" checked>
                                <label class="form-check-label" for="order_updates">
                                    Order Updates
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="product_alerts" 
                                       name="notifications[product_alerts]" checked>
                                <label class="form-check-label" for="product_alerts">
                                    Product Alerts
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="promotional_emails" 
                                       name="notifications[promotional_emails]">
                                <label class="form-check-label" for="promotional_emails">
                                    Promotional Emails
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-save notification settings when changed
    $('.form-check-input').change(function() {
        const data = $('#notificationForm').serialize();
        $.post('/unineeds/api/update-notification-settings.php', data, function(response) {
            if (response.success) {
                // Show a temporary success message
                const alert = $('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                              'Notification settings updated successfully' +
                              '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                              '</div>');
                $('.card-body').first().prepend(alert);
                setTimeout(() => alert.alert('close'), 3000);
            }
        });
    });
});
</script>

    </div>
</div>

<style>
.main-content {
    min-height: 100vh;
    margin-left: 250px;
}

.page-title {
    color: #2c3345;
    font-weight: 600;
}

.card {
    border: none;
    border-radius: 10px;
}

.card-header {
    border-bottom: 1px solid rgba(0,0,0,.1);
}

.form-control {
    padding: 0.75rem 1rem;
    border-radius: 8px;
}

.form-control:focus {
    border-color: #4CAF50;
    box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25);
}

.btn-primary {
    background-color: #4CAF50;
    border-color: #4CAF50;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
}

.btn-primary:hover {
    background-color: #388E3C;
    border-color: #388E3C;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding-top: 60px;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>