// Mobile Menu Toggle Script
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle') || document.getElementById('sidebarCollapse');
    
    if (!sidebar) return; // Exit if sidebar not found
    
    // Create overlay for mobile
    let overlay = document.getElementById('sidebarOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sidebarOverlay';
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }
    
    // Function to toggle sidebar
    function toggleSidebar() {
        const isSmallScreen = window.innerWidth <= 768;
        
        if (isSmallScreen) {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : 'auto';
        }
    }
    
    // Function to close sidebar
    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
    
    // Toggle button click
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
    
    // Close sidebar when overlay is clicked
    overlay.addEventListener('click', closeSidebar);
    
    // Close sidebar when sidebar links are clicked
    const sidebarLinks = sidebar.querySelectorAll('a, .nav-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });
    
    // Close sidebar on window resize to larger screen
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
    
    // Hamburger button for mobile menu (if using the button)
    const hamburgerBtn = document.querySelector('[id="sidebarToggle"]');
    if (hamburgerBtn) {
        hamburgerBtn.style.display = 'none'; // Hide by default
        
        // Show on small screens
        if (window.innerWidth <= 768) {
            hamburgerBtn.style.display = 'block';
        }
        
        window.addEventListener('resize', function() {
            hamburgerBtn.style.display = window.innerWidth <= 768 ? 'block' : 'none';
        });
    }
});
