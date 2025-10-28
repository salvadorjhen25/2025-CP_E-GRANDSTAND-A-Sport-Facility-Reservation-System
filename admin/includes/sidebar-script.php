<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebarClose = document.getElementById('sidebar-close');
    const mainContent = document.querySelector('.main-content');

    // Toggle sidebar on mobile
    function toggleSidebar() {
        sidebar.classList.toggle('show');
        sidebarOverlay.classList.toggle('show');
        sidebarToggle.classList.toggle('active');
        
        // Prevent body scroll when sidebar is open on mobile
        if (sidebar.classList.contains('show')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    // Close sidebar
    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
        sidebarToggle.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Event listeners
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    // Close sidebar when clicking on nav items on mobile
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 1024) {
                closeSidebar();
            }
        });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            closeSidebar();
        }
    });

    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });

    // Smooth scrolling for sidebar
    const sidebarNav = document.querySelector('.sidebar-nav');
    if (sidebarNav) {
        sidebarNav.addEventListener('scroll', function() {
            // Add subtle shadow when scrolling
            if (this.scrollTop > 0) {
                this.style.boxShadow = 'inset 0 2px 4px rgba(23, 49, 62, 0.1)';
            } else {
                this.style.boxShadow = 'none';
            }
        });
    }

    // Add active state animation
    const activeNavItem = document.querySelector('.nav-item.active');
    if (activeNavItem) {
        activeNavItem.style.animation = 'slideInFromLeft 0.5s ease';
    }

    // Notification badge animations
    const notificationBadges = document.querySelectorAll('.notification-badge');
    notificationBadges.forEach(badge => {
        if (badge.textContent.trim() !== '0') {
            badge.style.animation = 'pulse 2s infinite';
        }
    });

    // Add pulse animation for notification badges
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }
    `;
    document.head.appendChild(style);
});
</script>
