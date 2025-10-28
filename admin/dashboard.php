<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
$pdo = getDBConnection();


// Get statistics
$stats = [
    'users' => 0,
    'facilities' => 0,
    'reservations' => 0,
    'pending' => 0,
    'no_shows' => 0,
    'active_usages' => 0,
    'pending_payments' => 0,
    'new_users' => 0
];
// Total users (excluding admins)
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
        $result = $stmt->fetch();
        $stats['users'] = $result ? $result['count'] : 0;
    } else {
        $stats['users'] = 0;
    }
} catch (Exception $e) {
    $stats['users'] = 0;
}
// Total facilities
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
        $result = $stmt->fetch();
        $stats['facilities'] = $result ? $result['count'] : 0;
    } else {
        $stats['facilities'] = 0;
    }
} catch (Exception $e) {
    $stats['facilities'] = 0;
}
// Total reservations
try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
        $result = $stmt->fetch();
        $stats['reservations'] = $result ? $result['count'] : 0;
    } else {
        $stats['reservations'] = 0;
    }
} catch (Exception $e) {
    $stats['reservations'] = 0;
}
// Pending reservations
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending'] = 0;
}
// No-show reservations
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
    $result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['no_shows'] = 0;
}
// Recent reservations
try {
    $stmt = $pdo->query("
        SELECT r.*, u.full_name as user_name, f.name as facility_name 
        FROM reservations r 
        JOIN users u ON r.user_id = u.id 
        JOIN facilities f ON r.facility_id = f.id 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $recent_reservations = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_reservations = [];
}
// Revenue this month
try {
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as total 
        FROM reservations 
        WHERE status = 'confirmed' 
        AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $monthly_revenue = $result ? ($result['total'] ?? 0) : 0;
} catch (Exception $e) {
    $monthly_revenue = 0;
}
// Additional statistics for notification badges
// Active usages (ongoing reservations)
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM reservations 
        WHERE status = 'confirmed' 
        AND start_time <= NOW() 
        AND end_time >= NOW()
    ");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_usages'] = 0;
}
// Pending payments
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM reservations 
        WHERE payment_status = 'pending' 
        AND payment_slip_url IS NULL
    ");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending_payments'] = 0;
}
// New users in last 7 days
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE role = 'user' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['new_users'] = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                const closeButtons = modal.querySelectorAll("[data-dismiss='modal'], .close");
                closeButtons.forEach(button => {
                    button.addEventListener("click", function() {
                        modal.style.display = "none";
                    });
                });
            });
            // Mobile table improvements
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
     <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <link rel="stylesheet" href="../assets/css/mobile-responsive.css?v=1.0.0">
    <script src="../assets/js/modal-system.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1E40AF'
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                        'pulse-slow': 'pulse 3s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        }
                    }
                }
            }
        }
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
        });
        // Mobile table enhancements
        document.addEventListener("DOMContentLoaded", function() {
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
      <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .facility-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .facility-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .modal {
            transition: all 0.3s ease;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .action-button {
            transition: all 0.2s ease;
        }
        .action-button:hover {
            transform: scale(1.05);
        }
        .upload-zone {
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            border-color: #3B82F6;
            background-color: #F0F9FF;
        }
        .upload-zone.dragover {
            border-color: #3B82F6;
            background-color: #E0F2FE;
            transform: scale(1.02);
        }
        /* Ensure modal is properly positioned and clickable */
        .modal {
            pointer-events: auto;
        }
        .modal.show {
            pointer-events: auto;
        }
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .facility-card {
            pointer-events: auto !important;
        }
        .facility-card * {
            pointer-events: auto !important;
        }
        .nav-link::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(197, 176, 205, 0.4), transparent);
            transition: left 0.6s ease;
        }
        .nav-link:hover::before {
            left: 100%;
        }
        .nav-link:hover {
            color: #415E72;
            background: rgba(197, 176, 205, 0.4);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .nav-link.active {
            color: white;
            background: linear-gradient(135deg, #415E72, #17313E);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.4);
            border-color: #415E72;
            transform: translateY(-2px);
        }
        .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 4px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD);
            border-radius: 2px;
            box-shadow: 0 2px 6px rgba(243, 226, 212, 0.5);
        }
        .nav-link i {
            font-size: 16px;
            transition: all 0.4s ease;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .nav-link:hover i {
            transform: scale(1.15) rotate(5deg);
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.2));
        }
        .nav-link.active i {
            transform: scale(1.1);
            filter: drop-shadow(0 3px 6px rgba(255,255,255,0.3));
        }
        .nav-link span {
            font-size: 0.85rem;
            font-weight: 500;
        }
        /* Enhanced Brand and Admin Badge */
        .nav-brand {
            font-family: "Poppins", sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
            background: linear-gradient(135deg, #415E72, #17313E, #C5B0CD);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 4px rgba(23, 49, 62, 0.1);
            transition: all 0.3s ease;
        }
        .nav-brand:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }
        .admin-badge {
            background: linear-gradient(135deg, #415E72, #17313E);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.3);
            font-family: "Poppins", sans-serif;
            border: 2px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .admin-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.4);
            border-color: rgba(243, 226, 212, 0.5);
        }
        .admin-badge i {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        /* Enhanced Mobile Navigation */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.4s ease;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            border: 2px solid rgba(197, 176, 205, 0.3);
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.4);
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: linear-gradient(90deg, #17313E, #415E72);
            margin: 3px 0;
            transition: 0.4s;
            border-radius: 2px;
            box-shadow: 0 2px 4px rgba(23, 49, 62, 0.2);
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        /* Enhanced Mobile Layout */
        @media (max-width: 1024px) {
            .nav-links-container {
                gap: 6px;
                padding: 10px 0;
            }
            .nav-link {
                padding: 8px 12px;
                font-size: 0.8rem;
                gap: 6px;
            }
            .nav-link span {
                font-size: 0.8rem;
            }
            .nav-link i {
                font-size: 14px;
            }
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                display: none;
            }
            .nav-links-container.show {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: linear-gradient(180deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.95) 100%);
                border-top: 3px solid rgba(65, 94, 114, 0.3);
                box-shadow: 0 12px 35px rgba(23, 49, 62, 0.2);
                z-index: 40;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(20px);
                padding: 20px;
                gap: 12px;
            }
            .nav-links-container .nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 16px 20px;
                border-radius: 12px;
                margin-bottom: 8px;
                font-size: 1rem;
                gap: 12px;
            }
            .nav-links-container .nav-link:hover {
                transform: translateX(8px);
                border-left: 4px solid #415E72;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #F3E2D4;
            }
        }
        /* Enhanced Navbar animations on scroll */
        .nav-container.scrolled {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.98) 100%);
            box-shadow: 0 12px 40px rgba(23, 49, 62, 0.2);
            border-bottom: 3px solid rgba(65, 94, 114, 0.4);
        }
        /* Enhanced scrollbar for mobile menu */
        .nav-links-container::-webkit-scrollbar {
            width: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #17313E, #415E72);
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        /* Enhanced Mobile Navigation Styles */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.3);
            transform: scale(1.05);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: #17313E;
            margin: 3px 0;
            transition: 0.3s;
            border-radius: 2px;
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
        }
        .nav-links-container {
            transition: all 0.3s ease;
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: white;
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
            }
            .nav-links-container.show {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }
            .nav-links-container .nav-link {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-radius: 0;
                border-bottom: 1px solid #f3f4f6;
                margin: 0;
                width: 100%;
            }
            .nav-links-container .nav-link:hover {
                background: rgba(59, 130, 246, 0.05);
                transform: none;
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #3B82F6, #1E40AF);
                color: white;
                border-bottom: 1px solid #3B82F6;
            }
            .nav-links-container .nav-link.active::before {
                display: none;
            }
            .nav-links-container .nav-link span {
                font-size: 16px;
                font-weight: 500;
            }
            .nav-links-container .nav-link i {
                font-size: 18px;
            }
            .nav-divider {
                height: 1px;
                background: #e5e7eb;
                margin: 8px 20px;
            }
            .brand-text {
                display: none;
            }
            .brand-text-mobile {
                display: block;
            }
        }
        @media (min-width: 769px) {
            .brand-text {
                display: block;
            }
            .brand-text-mobile {
                display: none;
            }
        }
        /* Enhanced Mobile Responsiveness */
        @media (max-width: 640px) {
            /* Mobile-first grid adjustments */
            .grid {
                grid-template-columns: 1fr !important;
            }
            /* Mobile card adjustments */
            .stat-card, .facility-card, .category-card {
                margin-bottom: 1rem;
            }
            /* Mobile table responsiveness */
            .overflow-x-auto {
                font-size: 0.875rem;
            }
            /* Mobile form adjustments */
            .space-y-4 > * {
                margin-bottom: 1rem;
            }
            /* Mobile button adjustments */
            .btn, button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            /* Mobile modal adjustments */
            .modal-content {
                margin: 1rem;
                max-height: calc(100vh - 2rem);
            }
            /* Mobile navigation improvements */
            .nav-links-container {
                padding: 0;
            }
            .nav-links-container .nav-link {
                padding: 1rem 1.25rem;
                font-size: 1rem;
            }
            /* Mobile header adjustments */
            .text-3xl, .text-4xl {
                font-size: 1.5rem !important;
            }
            .text-2xl {
                font-size: 1.25rem !important;
            }
            /* Mobile spacing adjustments */
            .p-6, .p-8 {
                padding: 1rem !important;
            }
            .mb-8, .mb-6 {
                margin-bottom: 1rem !important;
            }
            /* Mobile filter adjustments */
            .filter-section {
                flex-direction: column;
            }
            .filter-section > * {
                margin-bottom: 0.5rem;
            }
            /* Mobile table cell adjustments */
            .px-6 {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            .py-4 {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
        }
        @media (max-width: 768px) {
            /* Tablet adjustments */
            .grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
            .grid-cols-3, .grid-cols-4 {
                grid-template-columns: repeat(2, 1fr);
            }
            /* Tablet navigation */
            .nav-links-container {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .nav-links-container .nav-link {
                flex: 1 1 auto;
                min-width: 120px;
            }
        }
        @media (max-width: 1024px) {
            /* Small desktop adjustments */
            .max-w-7xl {
                max-width: 100%;
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            /* Touch device optimizations */
            .nav-link, .btn, button, a {
                min-height: 44px;
                min-width: 44px;
            }
            .nav-link {
                padding: 12px 16px;
            }
            /* Larger touch targets for mobile */
            .mobile-menu-toggle {
                min-height: 44px;
                min-width: 44px;
                padding: 12px;
            }
            /* Improved touch feedback */
            .nav-link:active, .btn:active, button:active {
                transform: scale(0.95);
            }
        }
        /* Accessibility improvements */
        .nav-link:focus, .btn:focus, button:focus {
            outline: 2px solid #3B82F6;
            outline-offset: 2px;
        }
        /* Loading states for mobile */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        /* Mobile-specific animations */
        @media (max-width: 768px) {
            .animate-slide-up {
                animation: slideUpMobile 0.3s ease-out;
            }
            @keyframes slideUpMobile {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        }
        /* Mobile Table Enhancements */
        @media (max-width: 768px) {
            /* Mobile table card layout */
            .mobile-table-card {
                display: block;
            }
            .mobile-table-card .table-row {
                display: block;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
                background: white;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            .mobile-table-card .table-row > * {
                display: block;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .mobile-table-card .table-row > *:last-child {
                border-bottom: none;
            }
            .mobile-table-card .table-header {
                display: none;
            }
            /* Mobile table labels */
            .mobile-table-card .table-row > *::before {
                content: attr(data-label) ": ";
                font-weight: 600;
                color: #6b7280;
                display: inline-block;
                min-width: 100px;
            }
            /* Hide regular table on mobile */
            .table-responsive {
                display: none;
            }
            .mobile-table-card {
                display: block;
            }
        }
        @media (min-width: 769px) {
            /* Show regular table on desktop */
            .table-responsive {
                display: block;
            }
            .mobile-table-card {
                display: none;
            }
        }
        /* Mobile Filter Enhancements */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-section > * {
                width: 100%;
            }
            .filter-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .filter-buttons > * {
                width: 100%;
            }
            /* Mobile date inputs */
            input[type="date"], input[type="datetime-local"] {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            /* Mobile select dropdowns */
            select {
                font-size: 16px;
                padding: 0.75rem;
            }
            /* Mobile search inputs */
            input[type="search"], input[type="text"] {
                font-size: 16px;
                padding: 0.75rem;
            }
        }
        /* Mobile Modal Enhancements */
        @media (max-width: 768px) {
            .modal-content {
                margin: 1rem;
                max-width: calc(100vw - 2rem);
                max-height: calc(100vh - 2rem);
                overflow-y: auto;
            }
            .modal-content .p-6 {
                padding: 1rem;
            }
            /* Mobile form in modals */
            .modal-content form {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            .modal-content form > * {
                width: 100%;
            }
            .modal-content .flex.space-x-3 {
                flex-direction: column;
                gap: 0.5rem;
            }
            .modal-content .flex.space-x-3 > * {
                width: 100%;
            }
        }
        /* Mobile Button Enhancements */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-buttons > * {
                width: 100%;
                justify-content: center;
            }
            /* Mobile card actions */
            .card-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .card-actions > * {
                width: 100%;
            }
        }
        /* Mobile Statistics Cards */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .stat-card .text-3xl {
                font-size: 1.5rem !important;
            }
            .stat-card .text-lg {
                font-size: 1rem !important;
            }
        }
        /* Mobile Navigation Final Touches */
        @media (max-width: 768px) {
            .nav-links-container .nav-link {
                border-radius: 0;
                margin: 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .nav-links-container .nav-link:last-child {
                border-bottom: none;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #3B82F6;
                background: linear-gradient(90deg, #3B82F6, #1E40AF);
            }
            /* Mobile brand text */
            .brand-text-mobile {
                font-size: 1rem;
            }
        }
        /* Enhanced Notification Badge Styles - Fixed Visibility */
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: pulse-notification 2s infinite;
            z-index: 9999;
            transition: all 0.3s ease;
            pointer-events: none;
        }
        .notification-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
        }
        .notification-badge.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .notification-badge.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        @keyframes pulse-notification {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
            50% {
                transform: scale(1.15);
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
            }
        }
        .nav-link {
            position: relative;
            overflow: visible !important;
        }
        .nav-links-container {
            position: relative;
            z-index: 1000;
        }
        .nav-link .notification-badge {
            animation: pulse-notification 2s infinite;
            z-index: 9999;
        }
        .nav-link:hover .notification-badge {
            animation: none;
            transform: scale(1.2);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
        }
        .nav-link:hover .notification-badge.warning {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.6);
        }
        .nav-link:hover .notification-badge.info {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.6);
        }
        .nav-link:hover .notification-badge.success {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.6);
        }
        /* Ensure nav-container doesn't clip badges */
        .nav-container {
            overflow: visible !important;
        }
        /* Mobile responsive notification badge */
        @media (max-width: 768px) {
            .notification-badge {
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
                top: -5px;
                right: -5px;
                border-width: 2px;
            }
        }
        /* Enhanced nav-link hover effects */
        .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
        }
        .nav-link.active {
            box-shadow: 0 4px 16px rgba(65, 94, 114, 0.3);
        }
        .notification-badge {
            /* Force visibility */
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Loading dashboard...</p>
        </div>
    </div>
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Error Messages -->
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'admins_cannot_book'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 text-xl"></i>
                    <div>
                        <h3 class="text-lg font-semibold text-red-800">Access Restricted</h3>
                        <p class="text-red-700">Administrators cannot book facilities. Please use the admin panel to manage reservations and facilities.</p>
                    </div>
                </div>
            </div>
            <?php elseif ($_GET['error'] === 'admins_cannot_access_user_features'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 text-xl"></i>
                    <div>
                        <h3 class="text-lg font-semibold text-red-800">Access Restricted</h3>
                        <p class="text-red-700">Administrators cannot access user-specific features. Please use the admin panel for management tasks.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-tachometer-alt text-primary mr-3"></i>Admin Dashboard
            </h1>
            <p class="text-gray-600">Welcome to the administration panel</p>
        </div>
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8 stats-grid">
            <!-- Total Users -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-users text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Users</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['users'] ?? 0; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-green-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>12% increase</span>
                    </div>
                </div>
            </div>
            <!-- Total Facilities -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-building text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Active Facilities</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['facilities'] ?? 0; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-green-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>5% increase</span>
                    </div>
                </div>
            </div>
            <!-- Total Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['reservations'] ?? 0; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-blue-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>8% increase</span>
                    </div>
                </div>
            </div>
            <!-- Pending Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-clock text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Pending Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['pending'] ?? 0; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-red-600">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <span>Requires attention</span>
                    </div>
                </div>
            </div>
            <!-- No-Show Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.5s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-orange-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-user-times text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">No-Show Incidents</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['no_shows'] ?? 0; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-orange-600">
                        <i class="fas fa-chart-line mr-1"></i>
                        <span>Track patterns</span>
                    </div>
                </div>
            </div>
        </div>
        <!-- Recent Reservations -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate-slide-up" style="animation-delay: 0.6s;">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list text-primary mr-2"></i>Recent Reservations
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-user mr-2"></i>User
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-building mr-2"></i>Facility
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-calendar mr-2"></i>Date & Time
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-money-bill mr-2"></i>Amount
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-info-circle mr-2"></i>Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recent_reservations as $reservation): ?>
                            <tr class="reservation-row hover:bg-gray-50 transition duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center">
                                            <span class="text-white text-sm font-medium">
                                                <?php echo strtoupper(substr($reservation['user_name'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($reservation['user_name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo date('M j, Y', strtotime($reservation['start_time'])); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="font-semibold text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusColors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'confirmed' => 'bg-green-100 text-green-800',
                                        'completed' => 'bg-blue-100 text-blue-800',
                                        'cancelled' => 'bg-red-100 text-red-800',
                                        'expired' => 'bg-gray-100 text-gray-800'
                                    ];
                                    $statusIcons = [
                                        'pending' => 'fas fa-clock',
                                        'confirmed' => 'fas fa-check',
                                        'completed' => 'fas fa-check-double',
                                        'cancelled' => 'fas fa-times',
                                        'expired' => 'fas fa-clock'
                                    ];
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-800'; ?>">
                                        <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-1"></i>
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-8">
            <a href="reservations.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.7s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar-check text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Manage Reservations</h3>
                        <p class="text-sm text-gray-500">View and manage all reservations</p>
                    </div>
                </div>
            </a>
            <a href="facilities.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.8s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-building text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Manage Facilities</h3>
                        <p class="text-sm text-gray-500">Add, edit, and manage facilities</p>
                    </div>
                </div>
            </a>
            <a href="no_show_reports.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.9s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-user-times text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Cancel History
                        <p class="text-sm text-gray-500">Track and analyze no-show incidents</p>
                    </div>
                </div>
            </a>
            <a href="users.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 1.0s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-users-cog text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">User Management</h3>
                        <p class="text-sm text-gray-500">Manage user accounts and permissions</p>
                    </div>
                </div>
            </a>
            <a href="../admin_feedback.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 1.1s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-comments text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Feedback & Reviews</h3>
                        <p class="text-sm text-gray-500">Manage user feedback and reviews</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <script>
        // Simple and reliable loading overlay removal
        document.addEventListener("DOMContentLoaded", function() {
            // Hide loading overlay immediately
            const loadingOverlay = document.getElementById("loading-overlay");
            if (loadingOverlay) {
                loadingOverlay.style.opacity = "0";
                setTimeout(() => {
                    loadingOverlay.style.display = "none";
                }, 300);
            }
            // Enhanced navbar scroll effects
            window.addEventListener("scroll", function() {
                const navbar = document.querySelector(".nav-container");
                if (navbar) {
                    if (window.scrollY > 50) {
                        navbar.classList.add("scrolled");
                    } else {
                        navbar.classList.remove("scrolled");
                    }
                }
            });
            // Enhanced mobile menu functionality
            window.toggleMobileMenu = function() {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navLinksContainer = document.querySelector(".nav-links-container");
                if (mobileToggle && navLinksContainer) {
                    mobileToggle.classList.toggle("active");
                    navLinksContainer.classList.toggle("show");
                }
            };
            // Handle navigation links properly
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener("click", function(e) {
                    // Don't prevent default for logout link
                    if (this.getAttribute("href") === "../auth/logout.php") {
                        return;
                    }
                    // Close mobile menu if open
                    const mobileToggle = document.querySelector(".mobile-menu-toggle");
                    const navContainer = document.querySelector(".nav-links-container");
                    if (mobileToggle && navContainer) {
                        mobileToggle.classList.remove("active");
                        navContainer.classList.remove("show");
                    }
                    // Add loading state
                    if (!this.classList.contains("no-loading")) {
                        this.classList.add("loading");
                        setTimeout(() => {
                            this.classList.remove("loading");
                        }, 1000);
                    }
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener("click", function(e) {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navContainer = document.querySelector(".nav-links-container");
                if (mobileToggle && navContainer && !mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                }
            });
            // Highlight current page
            const currentPage = window.location.pathname.split("/").pop();
            const currentNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            currentNavLinks.forEach(link => {
                const href = link.getAttribute("href");
                if (href && href.includes(currentPage) && currentPage !== "") {
                    link.classList.add("active");
                }
            });
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
            // Add loading states to links
            const allLinks = document.querySelectorAll("a, button");
            allLinks.forEach(link => {
                if (!link.classList.contains("no-loading")) {
                    link.addEventListener("click", function() {
                        this.classList.add("loading");
                        setTimeout(() => {
                            this.classList.remove("loading");
                        }, 1000);
                    });
                }
            });
            // Initialize Chart.js if the element exists
            const chartElement = document.getElementById("revenueChart");
            if (chartElement && typeof Chart !== "undefined") {
                const ctx = chartElement.getContext("2d");
                // Sample data - replace with actual data from your database
                const revenueData = {
                    labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
                    datasets: [{
                        label: "Monthly Revenue",
                        data: [12000, 19000, 15000, 25000, 22000, 30000],
                        borderColor: "#3B82F6",
                        backgroundColor: "rgba(59, 130, 246, 0.1)",
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                };
                new Chart(ctx, {
                    type: "line",
                    data: revenueData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return "₱" + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        elements: {
                            point: {
                                radius: 4,
                                hoverRadius: 6
                            }
                        }
                    }
                });
            }
        });
        // Fallback: Hide loading overlay after 2 seconds if DOMContentLoaded doesn't work
        setTimeout(function() {
            const loadingOverlay = document.getElementById("loading-overlay");
            if (loadingOverlay && loadingOverlay.style.display !== "none") {
                loadingOverlay.style.opacity = "0";
                setTimeout(() => {
                    loadingOverlay.style.display = "none";
                }, 300);
            }
        }, 2000);
    </script>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</body>
</html>