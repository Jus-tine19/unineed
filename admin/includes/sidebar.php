<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav class="sidebar bg-primary" id="appSidebar">
    <div class="sidebar-inner d-flex flex-column">
        <div class="sidebar-top d-flex align-items-center justify-content-between">
            <div class="brand d-flex align-items-center gap-2">
                <?php
                $logoFile = __DIR__ . '/../../assets/images/logo.png';
                if (file_exists($logoFile)):
                ?>
                    <img src="/unineeds/assets/images/logo.png" alt="UniNeeds" style="height:36px;object-fit:contain;" />
                <?php else: ?>
                    <div class="avatar bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;">A</div>
                    <strong class="brand-text">UniNeeds</strong>
                <?php endif; ?>
            </div>
            <button class="btn btn-sm btn-light" id="sidebarCollapse" title="Toggle sidebar">
                <i class="bi bi-chevron-left"></i>
            </button>
        </div>

        <div class="nav-wrap flex-grow-1">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
                        <i class="bi bi-house-door"></i>
                        <span class="label">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'orders' || $current_page === 'invoicing' ? 'active' : ''; ?>" href="orders.php">
                        <i class="bi bi-receipt"></i>
                        <span class="label">Orders</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'products' ? 'active' : ''; ?>" href="products.php">
                        <i class="bi bi-shop"></i>
                        <span class="label">Products</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'inventory' ? 'active' : ''; ?>" href="inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="label">Inventory</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'reports' ? 'active' : ''; ?>" href="reports.php">
                        <i class="bi bi-file-earmark-text"></i>
                        <span class="label">Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'users' ? 'active' : ''; ?>" href="users.php">
                        <i class="bi bi-people"></i>
                        <span class="label">Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'settings' ? 'active' : ''; ?>" href="settings.php">
                        <i class="bi bi-gear"></i>
                        <span class="label">Settings</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="user-info">
            <div class="d-flex align-items-center gap-3">
                <div class="user-avatar">
                    <img src="../../assets/images/avatar.png" alt="User Avatar" class="avatar-img rounded-circle" 
                         width="35" height="35"
                         onerror="this.src='../../assets/images/logo.png'">
                </div>
                <div class="user-details">
                    <h6 class="mb-0" style="color: white; font-size: 0.95rem;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></h6>
                    <small style="color: rgba(255,255,255,0.8);">Admin</small>
                </div>
            </div>
        </div>
        <div class="sidebar-footer" style="padding: 15px;">
            <a href="../api/logout.php" class="nav-link text-danger" style="justify-content: center;">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</nav>