<style>
/* Sidebar Styles */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    background: linear-gradient(180deg, #F3E2D4 0%, #C5B0CD 100%);
    border-right: 3px solid rgba(65, 94, 114, 0.2);
    box-shadow: 4px 0 20px rgba(23, 49, 62, 0.1);
    z-index: 50;
    transition: transform 0.3s ease;
    overflow-y: auto;
    overflow-x: hidden;
}

.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(243, 226, 212, 0.3);
}

.sidebar::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #415E72, #17313E);
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #17313E, #415E72);
}

/* Sidebar Header */
.sidebar-header {
    padding: 1.5rem 1.25rem;
    border-bottom: 2px solid rgba(65, 94, 114, 0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sidebar-close {
    background: rgba(255, 255, 255, 0.8);
    border: 2px solid rgba(197, 176, 205, 0.3);
    border-radius: 8px;
    padding: 8px;
    color: #17313E;
    cursor: pointer;
    transition: all 0.3s ease;
}

.sidebar-close:hover {
    background: rgba(197, 176, 205, 0.4);
    transform: scale(1.05);
}

/* User Info */
.sidebar-user-info {
    padding: 1rem 1.25rem;
    border-bottom: 2px solid rgba(65, 94, 114, 0.1);
}

.admin-badge {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 12px;
    border: 2px solid rgba(197, 176, 205, 0.3);
    color: #17313E;
    font-weight: 600;
    transition: all 0.3s ease;
}

.admin-badge:hover {
    background: rgba(197, 176, 205, 0.4);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
}

.admin-badge i {
    font-size: 1.1rem;
    color: #415E72;
}

/* Navigation */
.sidebar-nav {
    flex: 1;
    padding: 1rem 0;
}

.nav-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-item {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.25rem;
    margin: 0.25rem 1rem;
    color: #17313E;
    text-decoration: none;
    border-radius: 12px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.nav-item::before {
    content: "";
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(197, 176, 205, 0.4), transparent);
    transition: left 0.6s ease;
    border-radius: 12px;
}

.nav-item:hover::before {
    left: 100%;
}

.nav-item:hover {
    color: #415E72;
    background: rgba(197, 176, 205, 0.4);
    transform: translateX(8px);
    border-color: rgba(197, 176, 205, 0.6);
    box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
}

.nav-item.active {
    color: white;
    background: linear-gradient(135deg, #415E72, #17313E);
    box-shadow: 0 4px 20px rgba(23, 49, 62, 0.3);
    border-color: #415E72;
    transform: translateX(8px);
}

.nav-item.active::after {
    content: "";
    position: absolute;
    right: -1rem;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 30px;
    background: linear-gradient(180deg, #F3E2D4, #C5B0CD);
    border-radius: 2px;
    box-shadow: 0 2px 6px rgba(243, 226, 212, 0.5);
}

.nav-item i {
    font-size: 1.1rem;
    transition: all 0.3s ease;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
    width: 20px;
    text-align: center;
}

.nav-item:hover i {
    transform: scale(1.15) rotate(5deg);
    filter: drop-shadow(0 3px 6px rgba(0,0,0,0.2));
}

.nav-item.active i {
    transform: scale(1.1);
    filter: drop-shadow(0 3px 6px rgba(255,255,255,0.3));
}

.nav-item span {
    font-size: 0.9rem;
    font-weight: 500;
}

/* Notification Badges */
.notification-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: white;
    z-index: 10;
}

.notification-badge.error {
    background: #ef4444;
}

.notification-badge.warning {
    background: #f59e0b;
}

.notification-badge.info {
    background: #3b82f6;
}

.notification-badge.success {
    background: #10b981;
}

/* Sidebar Footer */
.sidebar-footer {
    padding: 1rem 1.25rem;
    border-top: 2px solid rgba(65, 94, 114, 0.1);
}

.sidebar-footer .nav-item {
    background: linear-gradient(135deg, #C5B0CD, #415E72);
    color: white;
    border-color: #415E72;
}

.sidebar-footer .nav-item:hover {
    background: linear-gradient(135deg, #415E72, #C5B0CD);
    transform: translateX(8px) scale(1.02);
}

/* Mobile Overlay */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 40;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.sidebar-overlay.show {
    opacity: 1;
    visibility: visible;
}

/* Mobile Toggle Button */
.sidebar-toggle {
    position: fixed;
    top: 1rem;
    left: 1rem;
    z-index: 60;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid rgba(197, 176, 205, 0.3);
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
}

.sidebar-toggle:hover {
    background: rgba(197, 176, 205, 0.4);
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(23, 49, 62, 0.2);
}

.sidebar-toggle span {
    display: block;
    width: 24px;
    height: 3px;
    background: linear-gradient(90deg, #17313E, #415E72);
    margin: 4px 0;
    transition: 0.3s;
    border-radius: 2px;
    box-shadow: 0 2px 4px rgba(23, 49, 62, 0.2);
}

.sidebar-toggle.active span:nth-child(1) {
    transform: rotate(-45deg) translate(-6px, 7px);
    background: linear-gradient(90deg, #C5B0CD, #415E72);
}

.sidebar-toggle.active span:nth-child(2) {
    opacity: 0;
    transform: scale(0);
}

.sidebar-toggle.active span:nth-child(3) {
    transform: rotate(45deg) translate(-6px, -7px);
    background: linear-gradient(90deg, #C5B0CD, #415E72);
}

/* Main Content Area */
.main-content {
    margin-left: 280px;
    min-height: 100vh;
    transition: margin-left 0.3s ease;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .sidebar-toggle {
        display: block;
    }
}

@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        max-width: 320px;
    }
    
    .sidebar-header {
        padding: 1rem;
    }
    
    .brand-text h1 {
        font-size: 1.25rem;
    }
    
    .brand-text p {
        font-size: 0.75rem;
    }
    
    .nav-item {
        padding: 1rem 1.25rem;
        margin: 0.25rem 0.75rem;
    }
    
    .nav-item span {
        font-size: 1rem;
    }
}

/* Animation for page transitions */
@keyframes slideInFromLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.sidebar {
    animation: slideInFromLeft 0.3s ease;
}

/* Hide scrollbar for cleaner look */
.scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.scrollbar-hide::-webkit-scrollbar {
    display: none;
}
</style>
