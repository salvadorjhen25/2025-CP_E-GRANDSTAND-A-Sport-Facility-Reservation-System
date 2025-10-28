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
// No-show reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
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
// Handle filters
$status_filter = $_GET['status'] ?? '';
$facility_filter = $_GET['facility'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
// Build the query with filters
// Show paid payments OR cancelled/expired/archived reservations OR expired payments
$where_conditions = ["(r.payment_status = 'paid' OR r.status IN ('cancelled', 'expired', 'archived') OR r.payment_status = 'expired')"];
$params = [];
if ($status_filter) {
    if ($status_filter === 'payment_expired') {
        $where_conditions[] = "r.payment_status = 'expired'";
    } else {
        $where_conditions[] = "r.status = ?";
        $params[] = $status_filter;
    }
}
if ($facility_filter) {
    $where_conditions[] = "r.facility_id = ?";
    $params[] = $facility_filter;
}
if ($date_from) {
    $where_conditions[] = "DATE(COALESCE(r.payment_verified_at, r.updated_at, r.created_at)) >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where_conditions[] = "DATE(COALESCE(r.payment_verified_at, r.updated_at, r.created_at)) <= ?";
    $params[] = $date_to;
}
if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR f.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
$where_clause = implode(' AND ', $where_conditions);
// Get payment history
$query = "
    SELECT r.*, u.full_name as user_name, u.email as user_email, f.name as facility_name,
           r.payment_verified_at, r.payment_verified_by, admin.full_name as verified_by_name, admin.role as verified_by_role,
           cancelled_admin.full_name as cancelled_by_name, r.cancelled_at,
           completed_admin.full_name as completed_by_name, r.completed_at,
           archived_admin.full_name as archived_by_name, r.archived_at,
           noshow_admin.full_name as noshow_by_name, r.noshow_at,
           r.action_notes
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    LEFT JOIN users admin ON r.payment_verified_by = admin.id
    LEFT JOIN users cancelled_admin ON r.cancelled_by = cancelled_admin.id
    LEFT JOIN users completed_admin ON r.completed_by = completed_admin.id
    LEFT JOIN users archived_admin ON r.archived_by = archived_admin.id
    LEFT JOIN users noshow_admin ON r.noshow_by = noshow_admin.id
    WHERE $where_clause
    ORDER BY COALESCE(r.payment_verified_at, r.updated_at, r.created_at) DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();
// Get payment statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_payments,
        SUM(total_amount) as total_revenue,
        COUNT(CASE WHEN payment_verified_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as payments_this_week,
        COUNT(CASE WHEN payment_verified_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as payments_this_month,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_payments,
        COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_payments,
        COUNT(CASE WHEN status = 'archived' THEN 1 END) as archived_payments,
        AVG(total_amount) as average_payment
    FROM reservations 
    WHERE (payment_status = 'paid' OR status IN ('cancelled', 'expired', 'archived') OR payment_status = 'expired')
";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute();
$paymentStats = $statsStmt->fetch();
// Get facilities for filter
$facilitiesStmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $facilitiesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
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
                        'bounce-in': 'bounceIn 0.6s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        bounceIn: {
                            '0%': { transform: 'scale(0.3)', opacity: '0' },
                            '50%': { transform: 'scale(1.05)' },
                            '70%': { transform: 'scale(0.9)' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        }
                    }
                }
            }
        }
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
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .filter-card {
            transition: all 0.3s ease;
        }
        .filter-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 0 1rem;
        }
        .nav-links-container::-webkit-scrollbar {
            display: none;
        }
        .nav-links-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: max-content;
            padding: 0 0.5rem;
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
</head>
<body class="bg-gradient-to-br from-green-50 via-white to-blue-50 min-h-screen">
    <!-- Navigation -->
    <!-- Enhanced Navigation -->
        <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Info Alert -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg">
            <div class="flex items-start">
                <i class="fas fa-info-circle text-blue-500 text-xl mr-3 mt-0.5"></i>
                <div>
                    <h4 class="text-sm font-semibold text-blue-900 mb-1">Payment History & Archived Reservations</h4>
                    <p class="text-sm text-blue-800">
                        This page displays: <span class="font-semibold">✅ Verified Payments (paid)</span>, 
                        <span class="font-semibold">⏰ Expired Reservations</span>, and 
                        <span class="font-semibold">❌ Cancelled Reservations</span>
                    </p>
                    <p class="text-xs text-blue-700 mt-2">
                        💡 Clear all filters to see all historical records. Date filters use verification date for paid records, or last update date for cancelled/expired records.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-card bg-white/80 backdrop-blur-sm rounded-2xl shadow-lg p-6 mb-8 border border-gray-100">
            <div class="flex items-center mb-6">
                <i class="fas fa-filter text-green-500 text-xl mr-3"></i>
                <h3 class="text-xl font-semibold text-gray-900">Filter Payments</h3>
            </div>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200 bg-white">
                        <option value="">All Statuses</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>✅ Completed</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>⏰ Expired</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>❌ Cancelled</option>
                        <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>📁 Archived</option>
                        <option value="payment_expired" <?php echo $status_filter === 'payment_expired' ? 'selected' : ''; ?>>💳 Payment Expired</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Facility</label>
                    <select name="facility" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200 bg-white">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                🏢 <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200 bg-white">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200 bg-white">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="User, email, or facility"
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200 bg-white">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 shadow-lg font-semibold">
                        <i class="fas fa-search mr-2"></i>Filter
                    </button>
                    <button type="button" onclick="clearFilters()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-xl transition duration-200 transform hover:scale-105">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </form>
        </div>
        <!-- Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <?php
            $paid_count = 0;
            $cancelled_count = 0;
            $expired_count = 0;
            $completed_count = 0;
            $archived_count = 0;
            $payment_expired_count = 0;
            foreach ($payments as $p) {
                if ($p['payment_status'] === 'paid') $paid_count++;
                if ($p['status'] === 'cancelled') $cancelled_count++;
                if ($p['status'] === 'expired') $expired_count++;
                if ($p['status'] === 'completed') $completed_count++;
                if ($p['status'] === 'archived') $archived_count++;
                if ($p['payment_status'] === 'expired') $payment_expired_count++;
            }
            ?>
            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-600 uppercase">Verified Payments</p>
                        <p class="text-2xl font-bold text-green-900"><?php echo $paid_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-white text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-600 uppercase">Completed</p>
                        <p class="text-2xl font-bold text-blue-900"><?php echo $completed_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-double text-white text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-600 uppercase">Expired</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $expired_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-gray-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-white text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-red-600 uppercase">Cancelled</p>
                        <p class="text-2xl font-bold text-red-900"><?php echo $cancelled_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-times-circle text-white text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-600 uppercase">Archived</p>
                        <p class="text-2xl font-bold text-blue-900"><?php echo $archived_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-archive text-white text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-orange-600 uppercase">Payment Expired</p>
                        <p class="text-2xl font-bold text-orange-900"><?php echo $payment_expired_count; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-credit-card text-white text-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payments Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h4 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-list text-green-500 mr-2"></i>Payment History & Archived Reservations
                    <span class="ml-2 bg-green-100 text-green-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                        <?php echo count($payments); ?> records
                    </span>
                </h4>
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Shows verified payments, completed reservations, and expired/cancelled reservations
                </p>
            </div>
            <?php if (empty($payments)): ?>
                <div class="p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">No Payments Found</h3>
                    <p class="text-gray-600">No payments match your current filters. Try adjusting your search criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User & Facility</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verification</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions Log</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-gradient-to-r from-green-400 to-blue-500 flex items-center justify-center">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($payment['user_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($payment['facility_name']); ?>
                                                </div>
                                                <div class="text-xs text-gray-400">
                                                    <?php echo htmlspecialchars($payment['user_email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            ₱<?php echo number_format($payment['total_amount'], 2); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('M j, Y g:i A', strtotime($payment['created_at'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <?php echo ucfirst($payment['booking_type']); ?> booking
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($payment['payment_verified_at']): ?>
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('M j, Y g:i A', strtotime($payment['payment_verified_at'])); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                by <?php echo htmlspecialchars($payment['verified_by_name'] ?? 'N/A'); ?>
                                                <?php if ($payment['verified_by_role']): ?>
                                                    <span class="ml-1 px-2 py-1 text-xs rounded-full <?php echo $payment['verified_by_role'] === 'admin' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo ucfirst($payment['verified_by_role']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-500">
                                                <i class="fas fa-ban mr-1"></i>Not verified
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                Moved: <?php echo date('M j, Y g:i A', strtotime($payment['updated_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($payment['payment_status'] === 'paid'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>Verified
                                            </span>
                                        <?php elseif ($payment['status'] === 'expired'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-clock mr-1"></i>Expired
                                            </span>
                                        <?php elseif ($payment['status'] === 'cancelled'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle mr-1"></i>Cancelled
                                            </span>
                                        <?php elseif ($payment['status'] === 'archived'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-archive mr-1"></i>Archived
                                            </span>
                                        <?php elseif ($payment['payment_status'] === 'expired'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                                <i class="fas fa-credit-card mr-1"></i>Payment Expired
                                            </span>
                                        <?php endif; ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="space-y-1">
                                            <?php if ($payment['cancelled_by_name']): ?>
                                                <div class="flex items-center text-xs text-red-600">
                                                    <i class="fas fa-times-circle mr-1"></i>
                                                    <span>Cancelled by: <?php echo htmlspecialchars($payment['cancelled_by_name']); ?></span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('M j, Y g:i A', strtotime($payment['cancelled_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($payment['completed_by_name']): ?>
                                                <div class="flex items-center text-xs text-green-600">
                                                    <i class="fas fa-check-double mr-1"></i>
                                                    <span>Completed by: <?php echo htmlspecialchars($payment['completed_by_name']); ?></span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('M j, Y g:i A', strtotime($payment['completed_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($payment['archived_by_name']): ?>
                                                <div class="flex items-center text-xs text-blue-600">
                                                    <i class="fas fa-archive mr-1"></i>
                                                    <span>Archived by: <?php echo htmlspecialchars($payment['archived_by_name']); ?></span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('M j, Y g:i A', strtotime($payment['archived_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($payment['noshow_by_name']): ?>
                                                <div class="flex items-center text-xs text-orange-600">
                                                    <i class="fas fa-user-times mr-1"></i>
                                                    <span>No-show by: <?php echo htmlspecialchars($payment['noshow_by_name']); ?></span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('M j, Y g:i A', strtotime($payment['noshow_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($payment['action_notes']): ?>
                                                <div class="text-xs text-gray-600 mt-2 p-2 bg-gray-50 rounded">
                                                    <i class="fas fa-sticky-note mr-1"></i>
                                                    <?php echo htmlspecialchars($payment['action_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!$payment['cancelled_by_name'] && !$payment['completed_by_name'] && !$payment['archived_by_name'] && !$payment['noshow_by_name']): ?>
                                                <span class="text-xs text-gray-400">No actions logged</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <?php if ($payment['payment_slip_url']): ?>
                                            <button onclick="viewPaymentSlip('<?php echo htmlspecialchars('../' . $payment['payment_slip_url']); ?>', '<?php echo htmlspecialchars($payment['user_name']); ?>')" 
                                                    class="text-green-600 hover:text-green-900 transition duration-200">
                                                <i class="fas fa-receipt mr-1"></i>View Receipt
                                            </button>
                                            <div class="text-xs text-gray-400 mt-1">
                                                <?php echo htmlspecialchars($payment['payment_slip_url']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400">No receipt</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Payment Receipt Modal -->
    <div id="paymentModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible" style="display: none;">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-4xl w-full mx-4 max-h-screen overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-200 bg-gradient-to-r from-green-500 to-blue-600 text-white">
                <h3 class="text-xl font-semibold flex items-center">
                    <i class="fas fa-receipt mr-3"></i>Payment Receipt
                </h3>
                <button onclick="closePaymentModal()" class="text-white hover:text-gray-200 transition duration-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 overflow-auto max-h-96">
                <div id="paymentModalContent" class="text-center">
                    <!-- Payment receipt content will be loaded here -->
                </div>
            </div>
            <div class="flex justify-end p-6 border-t border-gray-200 bg-gray-50">
                <button onclick="closePaymentModal()" class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 font-semibold">
                    <i class="fas fa-times mr-2"></i>Close
                </button>
            </div>
        </div>
    </div>
    <script>
        function clearFilters() {
            window.location.href = window.location.pathname;
        }
        function viewPaymentSlip(imageUrl, userName) {
            const modal = document.getElementById('paymentModal');
            const content = document.getElementById('paymentModalContent');
            if (!modal || !content) {
                console.error('Modal elements not found');
                return;
            }
            // Show loading
            content.innerHTML = `
                <div class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-500 mr-4"></div>
                    <span class="text-gray-600 text-lg">Loading receipt...</span>
                </div>
            `;
            // Show modal
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.visibility = 'visible';
            modal.classList.add('show');
            // Load the image
            const img = new Image();
            img.onload = function() {
                content.innerHTML = `
                    <div class="mb-6">
                        <h4 class="text-2xl font-bold text-gray-800 mb-2">Payment Receipt for ${userName}</h4>
                        <p class="text-gray-600">Click the image to view in full size</p>
                    </div>
                    <div class="border-2 border-gray-200 rounded-xl p-6 bg-gray-50">
                        <a href="${imageUrl}" target="_blank" class="inline-block">
                            <img src="${imageUrl}" alt="Payment Receipt" class="max-w-full h-auto rounded-xl shadow-lg hover:shadow-xl transition duration-300 cursor-pointer">
                        </a>
                    </div>
                    <div class="mt-6 text-sm text-gray-500 bg-green-50 p-4 rounded-xl">
                        <p><i class="fas fa-info-circle mr-2"></i>Click the image above to open in a new tab for better viewing</p>
                    </div>
                `;
            };
            img.onerror = function() {
                content.innerHTML = `
                    <div class="text-center py-12">
                        <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                        </div>
                        <h4 class="text-2xl font-bold text-gray-800 mb-2">Receipt Not Found</h4>
                        <p class="text-gray-600 mb-6">The payment receipt image could not be loaded.</p>
                        <a href="${imageUrl}" target="_blank" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 font-semibold">
                            <i class="fas fa-external-link-alt mr-2"></i>Try Direct Link
                        </a>
                    </div>
                `;
            };
            img.src = imageUrl;
        }
        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.opacity = '0';
                modal.style.visibility = 'hidden';
                modal.classList.remove('show');
            }
        }
        // Close modal when clicking outside
        const paymentModal = document.getElementById('paymentModal');
        if (paymentModal) {
            paymentModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closePaymentModal();
                }
            });
        }
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentModal();
            }
        });
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            if (mobileToggle && navLinksContainer) {
                mobileToggle.classList.toggle("active");
                navLinksContainer.classList.toggle("show");
            }
        }
        // Combined mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Mobile menu functionality
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener("click", function() {
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
            if (navbar) {
                if (window.scrollY > 50) {
                    navbar.classList.add("scrolled");
                } else {
                    navbar.classList.remove("scrolled");
                }
            }
        });
        // Enhanced mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            if (mobileToggle && navLinksContainer) {
                mobileToggle.classList.toggle("active");
                navLinksContainer.classList.toggle("show");
            }
        }
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
    </script>
</body>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</html>
