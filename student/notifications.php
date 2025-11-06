<?php
// student/notifications.php
require_once '../config/database.php';
requireStudent();

$user_id = $_SESSION['user_id'];

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = $user_id";
    if (mysqli_query($conn, $query)) {
        $success = "All notifications marked as read!";
    }
}

// Get notifications
$query = "SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC";
$notifications = mysqli_query($conn, $query);

// Get unread count
$unread_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = $user_id AND is_read = FALSE";
$unread_result = mysqli_query($conn, $unread_query);
$unread_count = mysqli_fetch_assoc($unread_result)['unread'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - UniNeeds</title>
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
            <h2>Notifications</h2>
            <?php if ($unread_count > 0): ?>
                <div class="ms-auto">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-primary">
                            <i class="bi bi-check-all me-2"></i>Mark All as Read
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="content-area">
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($unread_count > 0): ?>
                <div class="alert alert-info">
                    <i class="bi bi-bell me-2"></i>You have <?php echo $unread_count; ?> unread notification(s).
                </div>
            <?php endif; ?>
            
            <?php if (mysqli_num_rows($notifications) > 0): ?>
                <div class="card">
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php while ($notif = mysqli_fetch_assoc($notifications)): ?>
                                <div class="list-group-item notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" 
                                     data-id="<?php echo $notif['notification_id']; ?>">
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="bi bi-<?php echo $notif['type'] === 'order_update' ? 'box' : 'receipt'; ?> me-2 fs-5 text-primary"></i>
                                                <h6 class="mb-0"><?php echo ucfirst(str_replace('_', ' ', $notif['type'])); ?></h6>
                                            </div>
                                            <p class="mb-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <small class="text-muted">
                                                <i class="bi bi-clock me-1"></i>
                                                <?php echo date('F j, Y g:i A', strtotime($notif['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="ms-3">
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="badge bg-primary">New</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <h5>No Notifications</h5>
                    <p>You don't have any notifications yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
        .notification-item {
            transition: background-color 0.3s;
            cursor: pointer;
        }
        .notification-item:hover {
            background-color: #f8f9fa;
        }
        .notification-item.unread {
            background-color: #e7f1ff;
            border-left: 4px solid #0d6efd;
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        document.querySelectorAll('.notification-item.unread').forEach(item => {
            item.addEventListener('click', function() {
                const notifId = this.dataset.id;
                markAsRead(notifId);
            });
        });
    </script>
</body>
</html>