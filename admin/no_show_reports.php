<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
$pdo = getDBConnection();
// Get statistics for notification badges
$stats = [];
// Total users (excluding admins)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$result = $stmt->fetch();
    $stats['users'] = $result ? $result['count'] : 0;
// Total facilities
$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$result = $stmt->fetch();
    $stats['facilities'] = $result ? $result['count'] : 0;
// Total reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$result = $stmt->fetch();
    $stats['reservations'] = $result ? $result['count'] : 0;
// Pending reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
// Cancelled reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'cancelled'");
$result = $stmt->fetch();
    $stats['cancelled'] = $result ? $result['count'] : 0;
// Active usage count for notifications
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usage_logs WHERE status = 'active'");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_usages'] = 0;
}
// Pending payments count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'confirmed' AND payment_status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending_payments'] = 0;
}
// New users count (last 7 days)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['new_users'] = 0;
}
// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$facility_filter = $_GET['facility'] ?? '';
$user_filter = $_GET['user'] ?? '';
$status_filter = $_GET['status'] ?? '';
// Build query for cancelled and no-show reservations
$query = "
    SELECT r.*, u.full_name as user_name, u.email as user_email, f.name as facility_name, f.hourly_rate
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE r.status IN ('cancelled', 'no_show')
";
$params = [];
if ($date_from) {
    $query .= " AND DATE(r.start_time) >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $query .= " AND DATE(r.start_time) <= ?";
    $params[] = $date_to;
}
if ($facility_filter) {
    $query .= " AND r.facility_id = ?";
    $params[] = $facility_filter;
}
if ($user_filter) {
    $query .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$user_filter%";
    $params[] = "%$user_filter%";
}
if ($status_filter) {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY r.start_time DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inactive_reservations = $stmt->fetchAll();
// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_inactive,
        SUM(r.total_amount) as total_revenue_lost,
        COUNT(DISTINCT r.user_id) as unique_users,
        COUNT(DISTINCT r.facility_id) as facilities_affected,
        SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN r.status = 'no_show' THEN 1 ELSE 0 END) as no_show_count
    FROM reservations r 
    WHERE r.status IN ('cancelled', 'no_show')
";
if ($date_from) {
    $stats_query .= " AND DATE(r.start_time) >= '$date_from'";
}
if ($date_to) {
    $stats_query .= " AND DATE(r.start_time) <= '$date_to'";
}
$stats_stmt = $pdo->query($stats_query);
$inactiveStats = $stats_stmt->fetch();
// Get facilities for filter
$stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $stmt->fetchAll();
// Get top users with inactive reservations
$top_users_query = "
    SELECT u.full_name, u.email, COUNT(*) as inactive_count, SUM(r.total_amount) as total_amount,
           SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
           SUM(CASE WHEN r.status = 'no_show' THEN 1 ELSE 0 END) as no_show_count
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.status IN ('cancelled', 'no_show')
";
if ($date_from) {
    $top_users_query .= " AND DATE(r.start_time) >= '$date_from'";
}
if ($date_to) {
    $top_users_query .= " AND DATE(r.start_time) <= '$date_to'";
}
$top_users_query .= " GROUP BY r.user_id ORDER BY inactive_count DESC LIMIT 10";
$top_users_stmt = $pdo->query($top_users_query);
$top_users = $top_users_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inactive Reservations Report - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
        <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .category-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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
        
        /* Compact modal content */
        .modal-content .space-y-1.5 {
            gap: 0.375rem;
        }
        .modal-content .space-y-2 {
            gap: 0.5rem;
        }
        .modal-content .space-y-3 {
            gap: 0.75rem;
        }
        .modal-content .max-h-32 {
            max-height: 8rem;
        }
        .modal-content .max-h-48 {
            max-height: 12rem;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        /* Enhanced Prettier Navbar Design (Copied from Dashboard) */
        .nav-container {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.95) 0%, rgba(197, 176, 205, 0.95) 50%, rgba(65, 94, 114, 0.1) 100%);
            border-bottom: 3px solid rgba(65, 94, 114, 0.3);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(23, 49, 62, 0.15);
            position: sticky;
            top: 0;
            z-index: 50;
            transition: all 0.3s ease;
            min-height: 80px;
        }
        .nav-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD, #415E72, #17313E, #415E72, #C5B0CD, #F3E2D4);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        /* Enhanced Navigation Layout */
        .nav-links-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #415E72 rgba(243, 226, 212, 0.3);
            flex-wrap: wrap;
            min-height: 60px;
        }
        .nav-links-container::-webkit-scrollbar {
            height: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(90deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(90deg, #17313E, #415E72);
        }
        .nav-link {
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 16px;
            padding: 10px 16px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #17313E;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid transparent;
            backdrop-filter: blur(15px);
            font-family: "Poppins", sans-serif;
            font-size: 0.9rem;
            white-space: nowrap;
            min-width: fit-content;
            flex-shrink: 0;
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
            .nav-links-container {
                padding: 0;
            }
            .nav-links-wrapper {
                flex-direction: column;
                gap: 0;
                min-width: auto;
                padding: 0;
            }
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
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .category-card {
            pointer-events: auto !important;
        }
        .category-card * {
            pointer-events: auto !important;
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
            margin: 0.5rem;
            max-height: calc(100vh - 1rem);
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
                margin: 0.5rem;
                max-width: calc(100vw - 1rem);
                max-height: calc(100vh - 1rem);
                overflow-y: auto;
            }
            .modal-content .p-6 {
                padding: 0.75rem;
            }
            .modal-content .p-3 {
                padding: 0.5rem;
            }
            .modal-content .p-2\\.5 {
                padding: 0.375rem;
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
            /* Reduce text sizes on mobile for better fit */
            .modal-content h3 {
                font-size: 1rem;
            }
            .modal-content h4 {
                font-size: 0.875rem;
            }
            .modal-content .text-base {
                font-size: 0.875rem;
            }
            .modal-content .text-sm {
                font-size: 0.75rem;
            }
            /* Reduce heights on mobile */
            .modal-content .mb-2 {
                margin-bottom: 0.375rem;
            }
            .modal-content .mb-3 {
                margin-bottom: 0.5rem;
            }
            .modal-content .mb-1\\.5 {
                margin-bottom: 0.25rem;
            }
            /* Optimize grid layouts on mobile */
            .modal-content .grid.grid-cols-1.md\\:grid-cols-3 {
                gap: 0.5rem;
            }
            .modal-content .grid.grid-cols-1.md\\:grid-cols-2 {
                gap: 0.5rem;
            }
        }
        /* Extra small screen modal optimizations */
        @media (max-width: 480px) {
            .modal-content {
                margin: 0.25rem;
                max-width: calc(100vw - 0.5rem);
                max-height: calc(100vh - 0.5rem);
            }
            .modal-content .p-3 {
                padding: 0.25rem;
            }
            .modal-content .p-2\\.5 {
                padding: 0.125rem;
            }
            .modal-content .space-y-2 {
                gap: 0.25rem;
            }
            .modal-content .space-y-3 {
                gap: 0.5rem;
            }
            .modal-content .space-y-1\\.5 {
                gap: 0.125rem;
            }
            .modal-content .mb-2 {
                margin-bottom: 0.25rem;
            }
            .modal-content .mb-3 {
                margin-bottom: 0.375rem;
            }
            .modal-content .mb-1\\.5 {
                margin-bottom: 0.125rem;
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
        .notification-badge {
            /* Force visibility */
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        .nav-link .notification-badge {
            animation: pulse-notification 2s infinite !important;
            z-index: 99999 !important;
        }
        .nav-link:hover .notification-badge {
            animation: none !important;
            transform: scale(1.25) !important;
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.7) !important;
        }
        .nav-link:hover .notification-badge.warning {
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.7) !important;
        }
        .nav-link:hover .notification-badge.info {
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.7) !important;
        }
        .nav-link:hover .notification-badge.success {
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.7) !important;
        }
        /* Mobile responsive notification badge */
        @media (max-width: 768px) {
            .notification-badge {
                width: 22px !important;
                height: 22px !important;
                font-size: 0.7rem !important;
                top: -6px !important;
                right: -6px !important;
                border-width: 2px !important;
            }
        }
        /* Enhanced nav-link hover effects */
        .nav-link:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15) !important;
        }
        .nav-link.active {
            box-shadow: 0 4px 16px rgba(65, 94, 114, 0.3) !important;
        }
        .notification-badge {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: absolute !important;
        }
        /* FORCED Notification Badge - Will Always Show */
        .notification-badge {
            position: absolute !important;
            top: -10px !important;
            right: -10px !important;
            background: #ff0000 !important;
            color: white !important;
            border-radius: 50% !important;
            width: 25px !important;
            height: 25px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 0.8rem !important;
            font-weight: bold !important;
            border: 3px solid white !important;
            box-shadow: 0 0 10px rgba(255, 0, 0, 0.8) !important;
            z-index: 999999 !important;
            visibility: visible !important;
            opacity: 1 !important;
            pointer-events: none !important;
        }
        .notification-badge.warning {
            background: #ff8800 !important;
            box-shadow: 0 0 10px rgba(255, 136, 0, 0.8) !important;
        }
        .notification-badge.info {
            background: #0088ff !important;
            box-shadow: 0 0 10px rgba(0, 136, 255, 0.8) !important;
        }
        .notification-badge.success {
            background: #00ff88 !important;
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.8) !important;
        }
        .nav-link {
            position: relative !important;
            overflow: visible !important;
        }
        .nav-links-container {
            position: relative !important;
            z-index: 1000 !important;
            overflow: visible !important;
        }
        .nav-container {
            overflow: visible !important;
        }
    </style>
    <script src="../assets/js/modal-system.js"></script>
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    </script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#1E40AF'
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
     <!-- Navigation -->
    <!-- Enhanced Navigation -->
        <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Cancel History</h1>
            <p class="text-gray-600">Track and analyze cancelled reservations</p>
        </div>
        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold mb-4">Filters</h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Facility</label>
                    <select name="facility" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">User Search</label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($user_filter); ?>"
                           placeholder="Name or email" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">All Status</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="no_show" <?php echo $status_filter === 'no_show' ? 'selected' : ''; ?>>No Show</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-[#17313E] hover:to-[#415E72] text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
        <!-- Enhanced Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 rounded-xl p-6 shadow-lg">
                <div class="flex items-center">
                    <div class="p-3 bg-red-500 rounded-lg">
                        <i class="fas fa-times-circle text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-red-600">Total Inactive</p>
                        <p class="text-2xl font-bold text-red-800"><?php echo $inactiveStats['total_inactive']; ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-xl p-6 shadow-lg">
                <div class="flex items-center">
                    <div class="p-3 bg-orange-500 rounded-lg">
                        <i class="fas fa-ban text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-orange-600">Cancelled</p>
                        <p class="text-2xl font-bold text-orange-800"><?php echo $inactiveStats['cancelled_count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200 rounded-xl p-6 shadow-lg">
                <div class="flex items-center">
                    <div class="p-3 bg-purple-500 rounded-lg">
                        <i class="fas fa-user-times text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-purple-600">No Shows</p>
                        <p class="text-2xl font-bold text-purple-800"><?php echo $inactiveStats['no_show_count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-xl p-6 shadow-lg">
                <div class="flex items-center">
                    <div class="p-3 bg-gray-500 rounded-lg">
                        <i class="fas fa-money-bill-wave text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Revenue Lost</p>
                        <p class="text-2xl font-bold text-gray-800">₱<?php echo number_format($inactiveStats['total_revenue_lost'], 2); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Top Users with Inactive Reservations -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold mb-4 flex items-center">
                <i class="fas fa-chart-bar text-blue-600 mr-2"></i>
                Top Users with Inactive Reservations
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Inactive</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cancelled</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No Shows</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($top_users as $user): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                        <?php echo $user['inactive_count']; ?> total
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">
                                        <?php echo $user['cancelled_count']; ?> cancelled
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                        <?php echo $user['no_show_count']; ?> no shows
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    ₱<?php echo number_format($user['total_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="viewUserCancellations('<?php echo htmlspecialchars($user['email']); ?>')" 
                                            class="text-primary hover:text-secondary">
                                        <i class="fas fa-eye mr-1"></i>View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Inactive Reservations Details Table -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-3 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list-alt text-blue-600 mr-2"></i>
                    Inactive Reservations Details (<?php echo count($inactive_reservations); ?>)
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($inactive_reservations)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    No inactive reservations found for the selected criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inactive_reservations as $inactive): ?>
                                <tr>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inactive['user_name']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($inactive['user_email']); ?></div>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($inactive['facility_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M j, Y', strtotime($inactive['start_time'])); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('g:i A', strtotime($inactive['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($inactive['end_time'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-gray-900">
                                        ₱<?php echo number_format($inactive['total_amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        <?php if ($inactive['status'] === 'cancelled'): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">
                                                <i class="fas fa-ban mr-1"></i>Cancelled
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                                <i class="fas fa-user-times mr-1"></i>No Show
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm font-medium">
                                        <button onclick="viewReservationDetails(<?php echo $inactive['id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-800 mr-3">
                                            <i class="fas fa-eye mr-1"></i>Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- View Details Modal -->
    <div id="viewDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full transform transition-all duration-300 scale-95 opacity-0" id="viewDetailsModalContent">
            <div class="p-2.5">
                <!-- Modal Header -->
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-times-circle text-red-500 text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Reservation Details</h3>
                            <p class="text-sm text-gray-600" id="modalUserInfo"></p>
                        </div>
                    </div>
                    <button onclick="closeViewDetailsModal()" class="text-gray-400 hover:text-gray-600 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <!-- User Summary -->
                <div class="bg-gray-50 rounded-lg p-1.5 mb-2">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-1.5">
                        <div class="text-center">
                            <div class="text-lg font-bold text-red-600" id="totalCancelled">0</div>
                            <div class="text-xs text-gray-600">Total Cancelled</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-yellow-600" id="totalAmount">₱0.00</div>
                            <div class="text-xs text-gray-600">Total Amount</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-blue-600" id="facilitiesCount">0</div>
                            <div class="text-xs text-gray-600">Facilities Used</div>
                        </div>
                    </div>
                </div>
                <!-- Cancellation Timeline -->
                <div class="mb-2">
                    <h4 class="text-sm font-semibold text-gray-900 mb-1.5 flex items-center">
                        <i class="fas fa-history mr-2 text-blue-600"></i>Cancellation Timeline
                    </h4>
                    <div class="space-y-1.5 max-h-32 overflow-y-auto" id="cancellationTimeline">
                        <!-- Timeline items will be populated by JavaScript -->
                    </div>
                </div>
                <!-- Facility Breakdown -->
                <div class="mb-2">
                    <h4 class="text-sm font-semibold text-gray-900 mb-1.5 flex items-center">
                        <i class="fas fa-building mr-2 text-purple-600"></i>Facility Breakdown
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                                                    <th class="px-2 py-0.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                                <th class="px-2 py-0.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cancellations</th>
                                <th class="px-2 py-0.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                                <th class="px-2 py-0.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Cancelled</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="facilityBreakdown">
                                <!-- Facility breakdown will be populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- Cancellation Patterns -->
                <div class="mb-2">
                    <h4 class="text-sm font-semibold text-gray-900 mb-1.5 flex items-center">
                        <i class="fas fa-chart-line mr-2 text-green-600"></i>Cancellation Patterns
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div class="bg-gray-50 rounded-lg p-1.5">
                            <h5 class="font-medium text-gray-900 mb-1 text-xs">Cancellation by Day of Week</h5>
                            <div class="space-y-0.5" id="dayOfWeekPattern">
                                <!-- Day of week pattern will be populated by JavaScript -->
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-1.5">
                            <h5 class="font-medium text-gray-900 mb-1 text-xs">Cancellation by Month</h5>
                            <div class="space-y-0.5" id="monthPattern">
                                <!-- Month pattern will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Modal Footer -->
                <div class="flex justify-end space-x-3 pt-2 border-t border-gray-200">
                    <button onclick="closeViewDetailsModal()" 
                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1 rounded-lg font-medium transition duration-200 text-sm">
                        Close
                    </button>
                    <button onclick="exportUserCancellations()" 
                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg font-medium transition duration-200 text-sm">
                        <i class="fas fa-download mr-2"></i>Export Data
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Global variable to store user cancellation data
        let currentUserCancellations = [];
        function viewUserCancellations(email) {
            // Fetch user cancellation data via AJAX
            fetch(`get_user_cancellations.php?email=${encodeURIComponent(email)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentUserCancellations = data.cancellations;
                        showViewDetailsModal(data.user, data.cancellations);
                    } else {
                        alert('Failed to load user cancellation data: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load user cancellation data');
                });
        }
        function showViewDetailsModal(user, cancellations) {
            // Set user info
            document.getElementById('modalUserInfo').textContent = `${user.full_name} (${user.email})`;
            // Calculate summary statistics
            const totalCancelled = cancellations.length;
            const totalAmount = cancellations.reduce((sum, c) => sum + parseFloat(c.total_amount), 0);
            const facilities = [...new Set(cancellations.map(c => c.facility_name))];
            // Update summary cards
            document.getElementById('totalCancelled').textContent = totalCancelled;
            document.getElementById('totalAmount').textContent = `₱${totalAmount.toFixed(2)}`;
            document.getElementById('facilitiesCount').textContent = facilities.length;
            // Populate cancellation timeline
            populateCancellationTimeline(cancellations);
            // Populate facility breakdown
            populateFacilityBreakdown(cancellations);
            // Populate cancellation patterns
            populateCancellationPatterns(cancellations);
            // Show modal
            const modal = document.getElementById('viewDetailsModal');
            const modalContent = document.getElementById('viewDetailsModalContent');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }
        function closeViewDetailsModal() {
            const modal = document.getElementById('viewDetailsModal');
            const modalContent = document.getElementById('viewDetailsModalContent');
            modalContent.classList.add('scale-95', 'opacity-0');
            modalContent.classList.remove('scale-100', 'opacity-100');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = '';
            }, 300);
        }
        function populateCancellationTimeline(cancellations) {
            const timeline = document.getElementById('cancellationTimeline');
            const sortedCancellations = cancellations.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
            timeline.innerHTML = sortedCancellations.map(cancellation => `
                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0">
                        <div class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-times text-red-500 text-xs"></i>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-900">${cancellation.facility_name}</p>
                                <p class="text-xs text-gray-500">
                                    ${new Date(cancellation.start_time).toLocaleDateString()} at ${new Date(cancellation.start_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">₱${parseFloat(cancellation.total_amount).toFixed(2)}</p>
                                <p class="text-xs text-gray-500">${new Date(cancellation.updated_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                        <div class="mt-1">
                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                ${cancellation.booking_type || 'hourly'} booking
                            </span>
                        </div>
                    </div>
                </div>
            `).join('');
        }
        function populateFacilityBreakdown(cancellations) {
            const breakdown = document.getElementById('facilityBreakdown');
            const facilityStats = {};
            cancellations.forEach(cancellation => {
                if (!facilityStats[cancellation.facility_name]) {
                    facilityStats[cancellation.facility_name] = {
                        count: 0,
                        totalAmount: 0,
                        lastCancelled: null
                    };
                }
                facilityStats[cancellation.facility_name].count++;
                facilityStats[cancellation.facility_name].totalAmount += parseFloat(cancellation.total_amount);
                const cancelledDate = new Date(cancellation.updated_at);
                if (!facilityStats[cancellation.facility_name].lastCancelled || 
                    cancelledDate > new Date(facilityStats[cancellation.facility_name].lastCancelled)) {
                    facilityStats[cancellation.facility_name].lastCancelled = cancellation.updated_at;
                }
            });
            breakdown.innerHTML = Object.entries(facilityStats).map(([facility, stats]) => `
                <tr>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">${facility}</div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                            ${stats.count} cancellation${stats.count > 1 ? 's' : ''}
                        </span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900">
                        ₱${stats.totalAmount.toFixed(2)}
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                        ${new Date(stats.lastCancelled).toLocaleDateString()}
                    </td>
                </tr>
            `).join('');
        }
        function populateCancellationPatterns(cancellations) {
            // Day of week pattern
            const dayOfWeekStats = {};
            const monthStats = {};
            cancellations.forEach(cancellation => {
                const cancelledDate = new Date(cancellation.updated_at);
                const dayOfWeek = cancelledDate.toLocaleDateString('en-US', { weekday: 'long' });
                const month = cancelledDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                dayOfWeekStats[dayOfWeek] = (dayOfWeekStats[dayOfWeek] || 0) + 1;
                monthStats[month] = (monthStats[month] || 0) + 1;
            });
            // Populate day of week pattern
            const dayOfWeekPattern = document.getElementById('dayOfWeekPattern');
            const sortedDays = Object.entries(dayOfWeekStats).sort((a, b) => b[1] - a[1]);
            dayOfWeekPattern.innerHTML = sortedDays.map(([day, count]) => `
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">${day}</span>
                    <div class="flex items-center space-x-2">
                        <div class="w-20 bg-gray-200 rounded-full h-2">
                            <div class="bg-red-500 h-2 rounded-full" style="width: ${(count / Math.max(...sortedDays.map(d => d[1]))) * 100}%"></div>
                        </div>
                        <span class="text-sm font-medium text-gray-900">${count}</span>
                    </div>
                </div>
            `).join('');
            // Populate month pattern
            const monthPattern = document.getElementById('monthPattern');
            const sortedMonths = Object.entries(monthStats).sort((a, b) => new Date(a[0]) - new Date(b[0]));
            monthPattern.innerHTML = sortedMonths.map(([month, count]) => `
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">${month}</span>
                    <div class="flex items-center space-x-2">
                        <div class="w-20 bg-gray-200 rounded-full h-2">
                            <div class="bg-blue-500 h-2 rounded-full" style="width: ${(count / Math.max(...sortedMonths.map(m => m[1]))) * 100}%"></div>
                        </div>
                        <span class="text-sm font-medium text-gray-900">${count}</span>
                    </div>
                </div>
            `).join('');
        }
        function exportUserCancellations() {
            if (currentUserCancellations.length === 0) {
                alert('No data to export');
                return;
            }
            // Create CSV content
            const headers = ['Facility', 'Date', 'Time', 'Amount', 'Booking Type', 'Cancelled At'];
            const csvContent = [
                headers.join(','),
                ...currentUserCancellations.map(c => [
                    `"${c.facility_name}"`,
                    new Date(c.start_time).toLocaleDateString(),
                    new Date(c.start_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}),
                    c.total_amount,
                    c.booking_type || 'hourly',
                    new Date(c.updated_at).toLocaleDateString()
                ].join(','))
            ].join('\n');
            // Create and download file
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `cancellations_${currentUserCancellations[0]?.user_email || 'user'}_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        // Close modal when clicking outside
        document.getElementById('viewDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewDetailsModal();
            }
        });
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('viewDetailsModal');
                if (!modal.classList.contains('hidden')) {
                    closeViewDetailsModal();
                }
            }
        });
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            mobileToggle.classList.toggle("active");
            navLinksContainer.classList.toggle("show");
        }
        // Combined mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Mobile menu functionality
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener("click", function() {
                    const mobileToggle = document.querySelector(".mobile-menu-toggle");
                    const navContainer = document.querySelector(".nav-links-container");
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener("click", function(e) {
                const mobileToggle = document.querySelector(".mobile-menu-toggle");
                const navContainer = document.querySelector(".nav-links-container");
                if (!mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove("active");
                    navContainer.classList.remove("show");
                }
            });
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
            const actionButtonGroups = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtonGroups.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
        // Enhanced navbar scroll effects
        window.addEventListener("scroll", function() {
            const navbar = document.querySelector(".nav-container");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });
        // Proper navigation handling
        document.addEventListener("DOMContentLoaded", function() {
            // Handle navigation links properly
            const navLinks = document.querySelectorAll(".nav-links-container .nav-link");
            navLinks.forEach(link => {
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
            navLinks.forEach(link => {
                const href = link.getAttribute("href");
                if (href && href.includes(currentPage) && currentPage !== "") {
                    link.classList.add("active");
                }
            });
        });
        // Modal functionality for viewing reservation details
        function viewReservationDetails(reservationId) {
            // Find the reservation data from the PHP array
            const reservations = <?php echo json_encode($inactive_reservations); ?>;
            const reservation = reservations.find(r => r.id == reservationId);
            if (!reservation) {
                alert('Reservation not found');
                return;
            }
            // Populate modal with reservation details
            document.getElementById('modalUserInfo').textContent = `${reservation.user_name} (${reservation.user_email})`;
            // Calculate duration
            const startTime = new Date(reservation.start_time);
            const endTime = new Date(reservation.end_time);
            const durationHours = (endTime - startTime) / (1000 * 60 * 60);
            // Update modal content
            const modalContent = document.getElementById('viewDetailsModalContent');
            modalContent.innerHTML = `
                <div class="p-3">
                    <!-- Modal Header -->
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="w-10 h-10 ${reservation.status === 'cancelled' ? 'bg-orange-100' : 'bg-purple-100'} rounded-full flex items-center justify-center mr-3">
                                <i class="fas ${reservation.status === 'cancelled' ? 'fa-ban text-orange-500' : 'fa-user-times text-purple-500'} text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Reservation Details</h3>
                                <p class="text-sm text-gray-600">${reservation.user_name} (${reservation.user_email})</p>
                            </div>
                        </div>
                        <button onclick="closeViewDetailsModal()" class="text-gray-400 hover:text-gray-600 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <!-- Reservation Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div class="bg-gray-50 rounded-lg p-2.5">
                            <h4 class="font-semibold text-gray-900 mb-2 flex items-center">
                                <i class="fas fa-building text-blue-600 mr-2"></i>Facility Information
                            </h4>
                            <div class="space-y-1.5">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Facility:</span>
                                    <span class="font-medium">${reservation.facility_name}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Hourly Rate:</span>
                                    <span class="font-medium">₱${parseFloat(reservation.hourly_rate).toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-2.5">
                            <h4 class="font-semibold text-gray-900 mb-2 flex items-center">
                                <i class="fas fa-calendar-alt text-green-600 mr-2"></i>Booking Information
                            </h4>
                            <div class="space-y-1.5">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Date:</span>
                                    <span class="font-medium">${new Date(reservation.start_time).toLocaleDateString()}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Time:</span>
                                    <span class="font-medium">${new Date(reservation.start_time).toLocaleTimeString()} - ${new Date(reservation.end_time).toLocaleTimeString()}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Duration:</span>
                                    <span class="font-medium">${durationHours} hour${durationHours > 1 ? 's' : ''}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Status and Payment Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div class="bg-gray-50 rounded-lg p-2.5">
                            <h4 class="font-semibold text-gray-900 mb-2 flex items-center">
                                <i class="fas fa-info-circle text-purple-600 mr-2"></i>Status Information
                            </h4>
                            <div class="space-y-1.5">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Status:</span>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${reservation.status === 'cancelled' ? 'bg-orange-100 text-orange-800' : 'bg-purple-100 text-purple-800'}">
                                        <i class="fas ${reservation.status === 'cancelled' ? 'fa-ban' : 'fa-user-times'} mr-1"></i>
                                        ${reservation.status === 'cancelled' ? 'Cancelled' : 'No Show'}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Payment Status:</span>
                                    <span class="font-medium">${reservation.payment_status || 'N/A'}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Created:</span>
                                    <span class="font-medium">${new Date(reservation.created_at).toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Updated:</span>
                                    <span class="font-medium">${new Date(reservation.updated_at).toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-2.5">
                            <h4 class="font-semibold text-gray-900 mb-2 flex items-center">
                                <i class="fas fa-money-bill-wave text-yellow-600 mr-2"></i>Financial Information
                            </h4>
                            <div class="space-y-1.5">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Total Amount:</span>
                                    <span class="font-bold text-base">₱${parseFloat(reservation.total_amount).toFixed(2)}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Booking Type:</span>
                                    <span class="font-medium">${reservation.booking_type || 'hourly'}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Revenue Lost:</span>
                                    <span class="font-medium text-red-600">₱${parseFloat(reservation.total_amount).toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Purpose -->
                    <div class="bg-gray-50 rounded-lg p-2.5 mb-3">
                        <h4 class="font-semibold text-gray-900 mb-2 flex items-center">
                            <i class="fas fa-bullseye text-indigo-600 mr-2"></i>Purpose
                        </h4>
                        <p class="text-gray-700">${reservation.purpose || 'No purpose specified'}</p>
                    </div>
                    <!-- Modal Footer -->
                    <div class="flex justify-end space-x-3 pt-2 border-t border-gray-200">
                        <button onclick="closeViewDetailsModal()" class="px-3 py-1.5 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                            Close
                        </button>
                    </div>
                </div>
            `;
            // Show modal
            const modal = document.getElementById('viewDetailsModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            // Animate modal content
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
        function closeViewDetailsModal() {
            const modal = document.getElementById('viewDetailsModal');
            const modalContent = document.getElementById('viewDetailsModalContent');
            // Animate out
            modalContent.classList.remove('scale-100', 'opacity-100');
            modalContent.classList.add('scale-95', 'opacity-0');
            // Hide modal after animation
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }
        // Close modal when clicking outside
        document.getElementById('viewDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewDetailsModal();
            }
        });
    </script>
</body>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</html>
