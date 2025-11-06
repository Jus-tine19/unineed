<?php
// admin/includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar" id="appSidebar">
    
    
    <div class="user-info">
        <div class="user-avatar">
            <i class="bi bi-person-circle"></i>
        </div>
        <div class="user-details">
            <h6><?php echo htmlspecialchars($_SESSION['full_name']); ?></h6>
            <small>Administrator</small>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>
        
        <a href="orders.php" class="nav-link <?php echo $current_page === 'orders.php' ? 'active' : ''; ?>">
            <i class="bi bi-cart-check"></i>
            <span>Orders</span>
        </a>
        
        <a href="products.php" class="nav-link <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
            <i class="bi bi-box-seam"></i>
            <span>Products</span>
        </a>
        
        <a href="inventory.php" class="nav-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
            <i class="bi bi-boxes"></i>
            <span>Inventory</span>
        </a>
        
        <a href="users.php" class="nav-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
            <i class="bi bi-people"></i>
            <span>Users</span>
        </a>
        
        <a href="invoicing.php" class="nav-link <?php echo $current_page === 'invoicing.php' ? 'active' : ''; ?>">
            <i class="bi bi-receipt"></i>
            <span>Invoicing</span>
        </a>
        
        <a href="reports.php" class="nav-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
            <i class="bi bi-graph-up"></i>
            <span>Reports</span>
        </a>
        
        <a href="settings.php" class="nav-link <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <a href="../api/logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<style>
.sidebar {
    width: 260px;
    background: #2c3e50;
    color: white;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    transition: all 0.3s;
    z-index: 1000;
}

.sidebar-header {
    padding: 20px;
    background: #34495e;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: bold;
}

.user-info {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.user-avatar {
    font-size: 2.5rem;
    color: #3498db;
}

.user-details h6 {
    margin: 0;
    font-weight: 600;
}

.user-details small {
    color: #95a5a6;
}

.sidebar-nav {
    padding: 20px 0;
}

.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 12px 20px;
    color: #ecf0f1;
    text-decoration: none;
    transition: all 0.3s;
    border-left: 3px solid transparent;
}

.sidebar-nav .nav-link:hover {
    background: #34495e;
    border-left-color: #3498db;
}

.sidebar-nav .nav-link.active {
    background: #34495e;
    border-left-color: #3498db;
    color: #3498db;
}

.sidebar-nav .nav-link i {
    font-size: 1.2rem;
    width: 20px;
}

.sidebar-footer {
    position: absolute;
    bottom: 0;
    width: 100%;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.1);
}

@media (max-width: 768px) {
    .sidebar {
        width: 260px;
    }
    .main-content {
        margin-left: 260px;
    }
}
</style>