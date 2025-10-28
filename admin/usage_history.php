<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../classes/UsageManager.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
$usageManager = new UsageManager();
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
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending'] = 0;
}
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
// Get filter parameters
$facility_filter = $_GET['facility'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_filter = $_GET['user'] ?? '';
$sort_order = $_GET['sort'] ?? 'latest'; // latest or oldest

// Get all usage history
$usageHistory = $usageManager->getAllUsageHistory(200);
// Filter usage history based on parameters
if ($facility_filter) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($facility_filter) {
        return $usage['facility_id'] == $facility_filter;
    });
}
if ($status_filter) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($status_filter) {
        return $usage['status'] == $status_filter;
    });
}
if ($date_from) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($date_from) {
        $endTime = $usage['end_time'] ?? $usage['completed_at'] ?? 'now';
        return date('Y-m-d', strtotime($endTime)) >= $date_from;
    });
}
if ($date_to) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($date_to) {
        $endTime = $usage['end_time'] ?? $usage['completed_at'] ?? 'now';
        return date('Y-m-d', strtotime($endTime)) <= $date_to;
    });
}

if ($user_filter) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($user_filter) {
        return $usage['user_id'] == $user_filter;
    });
}

// Sort usage history
if ($sort_order === 'oldest') {
    usort($usageHistory, function($a, $b) {
        $dateA = strtotime($a['end_time'] ?? $a['completed_at'] ?? $a['created_at']);
        $dateB = strtotime($b['end_time'] ?? $b['completed_at'] ?? $b['created_at']);
        return $dateA - $dateB;
    });
} else {
    // Default: latest first
    usort($usageHistory, function($a, $b) {
        $dateA = strtotime($a['end_time'] ?? $a['completed_at'] ?? $a['created_at']);
        $dateB = strtotime($b['end_time'] ?? $b['completed_at'] ?? $b['created_at']);
        return $dateB - $dateA;
    });
}
// Get facilities for filter
$stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $stmt->fetchAll();

// Get users for filter
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'user' ORDER BY full_name");
$users = $stmt->fetchAll();
// Calculate statistics
$totalUsageTime = 0;
$totalRevenue = 0;
$completedCount = 0;
$verifiedCount = 0;
foreach ($usageHistory as $usage) {
    if ($usage['duration_minutes']) {
        $totalUsageTime += $usage['duration_minutes'];
    }
    if ($usage['total_amount']) {
        $totalRevenue += $usage['total_amount'];
    }
    if ($usage['status'] === 'completed') {
        $completedCount++;
    } elseif ($usage['status'] === 'verified') {
        $verifiedCount++;
    }
}
$totalUsageHours = round($totalUsageTime / 60, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage History - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
   
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(197, 176, 205, 0.3);
        }
        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(23, 49, 62, 0.15);
            border-color: #415E72;
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
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(197, 176, 205, 0.4);
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        /* Enhanced Table Styles */
        .table-enhanced {
            border-collapse: separate;
            border-spacing: 0;
        }
        .table-enhanced thead th {
            position: relative;
            background: linear-gradient(135deg, #EBF8FF 0%, #E0F2FE 50%, #DBEAFE 100%);
            border-bottom: 2px solid #BFDBFE;
            font-weight: 600;
            color: #1E40AF;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        .table-enhanced thead th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3B82F6, #1E40AF, #7C3AED);
            transform: scaleX(0);
            transition: transform 0.4s ease;
            border-radius: 0 0 2px 2px;
        }
        .table-enhanced thead th:hover::after {
            transform: scaleX(1);
        }
        .table-enhanced tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 4px solid transparent;
        }
        .table-enhanced tbody tr:hover {
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
            transform: translateX(4px);
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.15);
            border-left-color: #3B82F6;
        }
        .table-enhanced tbody td {
            border-bottom: 1px solid #E5E7EB;
            padding: 1rem 1.5rem;
            vertical-align: top;
            text-align: left;
        }
        /* Ensure consistent icon sizes and spacing */
        .table-enhanced .icon-container {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
        }
        .table-enhanced .icon-container.large {
            width: 48px;
            height: 48px;
        }
        /* Ensure consistent text alignment in cells */
        .table-enhanced td > div {
            display: flex;
            align-items: center;
            min-height: 40px;
        }
        /* Status badge alignment */
        .table-enhanced .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            min-width: 100px;
            text-align: left;
        }
        .table-enhanced tbody tr {
            border-left: 4px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .table-enhanced tbody tr:hover {
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
            transform: translateX(4px);
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.15);
            border-left-color: #3B82F6;
        }
        /* Ensure proper column alignment */
        .table-enhanced th,
        .table-enhanced td {
            text-align: left;
            vertical-align: top;
        }
        /* Fix any potential table layout issues */
        .table-enhanced {
            table-layout: auto;
            width: 100%;
        }
        /* Ensure consistent column widths */
        .table-enhanced th:nth-child(1),
        .table-enhanced td:nth-child(1) {
            width: 20%; /* Facility column */
        }
        .table-enhanced th:nth-child(2),
        .table-enhanced td:nth-child(2) {
            width: 18%; /* User column */
        }
        .table-enhanced th:nth-child(3),
        .table-enhanced td:nth-child(3) {
            width: 20%; /* Duration column */
        }
        .table-enhanced th:nth-child(4),
        .table-enhanced td:nth-child(4) {
            width: 15%; /* Amount column */
        }
        .table-enhanced th:nth-child(5),
        .table-enhanced td:nth-child(5) {
            width: 15%; /* Status column */
        }
        .table-enhanced th:nth-child(6),
        .table-enhanced td:nth-child(6) {
            width: 12%; /* Completed column */
        }
        /* Enhanced Icon Containers */
        .icon-container {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .icon-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
            transform: scale(0);
            transition: transform 0.3s ease;
        }
        .icon-container:hover::before {
            transform: scale(1);
        }
        .icon-container:hover {
            transform: scale(1.1) rotate(5deg);
        }
        /* Enhanced Status Badges */
        .status-badge {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.6s ease;
        }
        .status-badge:hover::before {
            left: 100%;
        }
        .status-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
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
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: rgba(243, 226, 212, 0.98);
                border-top: 2px solid rgba(65, 94, 114, 0.2);
                box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(15px);
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
                padding: 18px 24px;
                border-radius: 0;
                border-bottom: 1px solid rgba(197, 176, 205, 0.3);
                margin: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.9);
            }
            .nav-links-container .nav-link:hover {
                background: rgba(197, 176, 205, 0.2);
                transform: translateX(5px);
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #415E72, #17313E);
                color: white;
                border-bottom: 2px solid #415E72;
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
<body>
    <!-- Enhanced Navigation -->
      <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-history text-primary mr-3"></i>Usage History
            </h1>
            <p class="text-gray-600">View all completed and verified facility usage records</p>
        </div>
        <!-- Recent Activity Notification -->
        <?php if (count($usageHistory) > 0): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        <span class="font-semibold">Latest Usage Records</span>
                    </div>
                    <div class="text-sm">
                        <i class="fas fa-clock mr-1"></i>
                        Showing <?php echo count($usageHistory); ?> most recent usage records
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <!-- Enhanced Filter Section -->
        <div class="bg-gradient-to-br from-white/90 to-gray-50/90 backdrop-blur-sm rounded-3xl shadow-2xl p-8 mb-8">
            <div class="mb-6">
                <h3 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                    <i class="fas fa-filter text-blue-600 mr-3"></i>Filter Usage Records
                </h3>
                <p class="text-gray-600">Refine your search to find specific usage records</p>
            </div>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-6">
                <!-- Facility Filter -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Facility</label>
                    <select name="facility" class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- User Filter -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">User</label>
                    <select name="user" class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Status Filter -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Status</label>
                    <select name="status" class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                        <option value="">All Statuses</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="verified" <?php echo $status_filter == 'verified' ? 'selected' : ''; ?>>Verified</option>
                    </select>
                </div>
                <!-- Date Range Filter -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>" 
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>" 
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                </div>
                <!-- Sort Order -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-gray-700 uppercase tracking-wide">Sort Order</label>
                    <select name="sort" class="w-full border-2 border-gray-200 rounded-xl px-4 py-4 focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-300 bg-white shadow-sm hover:shadow-md">
                        <option value="latest" <?php echo $sort_order == 'latest' ? 'selected' : ''; ?>>Latest First</option>
                        <option value="oldest" <?php echo $sort_order == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
                <!-- Action Buttons -->
                <div class="md:col-span-6 flex flex-wrap gap-4">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-8 py-4 rounded-xl font-bold transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="usage_history.php" class="bg-gray-500 hover:bg-gray-600 text-white px-8 py-4 rounded-xl font-bold transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-times mr-2"></i>Clear Filters
                    </a>
                    <button type="button" onclick="exportToCSV()" class="bg-green-500 hover:bg-green-600 text-white px-8 py-4 rounded-xl font-bold transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-download mr-2"></i>Export CSV
                    </button>
                </div>
            </form>
        </div>
        <!-- Usage History Table -->
            <?php if (empty($usageHistory)): ?>
            <div class="p-12 text-center bg-white rounded-3xl shadow-2xl">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-info-circle text-gray-500 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">No Usage Records Found</h3>
                    <p class="text-gray-600">No usage history matches your current filters.</p>
                </div>
            <?php else: ?>
            <!-- Table Header -->
            <div class="mb-6 p-6 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 rounded-xl border border-blue-200/50 shadow-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                            <i class="fas fa-table text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">Usage History Records</h3>
                            <p class="text-sm text-gray-600">Detailed view of all facility usage activities</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-blue-600"><?php echo count($usageHistory); ?></div>
                        <div class="text-sm text-gray-500">Total Records</div>
                    </div>
                </div>
            </div>
            <!-- Table Container -->
            <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-building mr-2 text-blue-600"></i>Facility
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-user mr-2 text-blue-600"></i>User
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-clock mr-2 text-blue-600"></i>Duration
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-money-bill-wave mr-2 text-blue-600"></i>Amount
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-info-circle mr-2 text-blue-600"></i>Status
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-blue-700 uppercase tracking-wider">
                                    <i class="fas fa-calendar-check mr-2 text-blue-600"></i>Reservation End
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($usageHistory as $usage): ?>
                                <tr class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 transition-all duration-300">
                                    <!-- Facility Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-building text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">
                                                    <?php echo htmlspecialchars($usage['facility_name'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- User Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-emerald-600 rounded-full flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">
                                                    <?php echo htmlspecialchars($usage['user_name'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Duration Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-orange-400 to-red-500 rounded-lg flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-clock text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">
                                            <?php 
                                                    if (isset($usage['duration_minutes']) && $usage['duration_minutes']) {
                                                $hours = floor($usage['duration_minutes'] / 60);
                                                $minutes = $usage['duration_minutes'] % 60;
                                                echo $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                                            } else {
                                                echo "N/A";
                                            }
                                            ?>
                                        </div>
                                                <div class="text-xs text-orange-600 font-medium">
                                                    Started: <?php echo date('M j, g:i A', strtotime($usage['started_at'] ?? 'now')); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Amount Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-emerald-400 to-green-600 rounded-lg flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-money-bill-wave text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-emerald-700 text-lg">
                                                    ₱<?php echo number_format($usage['total_amount'] ?? 0, 2); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Status Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (($usage['status'] ?? '') === 'verified'): ?>
                                                <span class="inline-flex items-center px-3 py-2 rounded-full text-sm font-semibold bg-gradient-to-r from-green-100 to-emerald-100 text-green-800 border border-green-200 shadow-sm">
                                                    <i class="fas fa-check-circle mr-2 text-green-600"></i>Verified
                                            </span>
                                        <?php else: ?>
                                                <span class="inline-flex items-center px-3 py-2 rounded-full text-sm font-semibold bg-gradient-to-r from-yellow-100 to-orange-100 text-yellow-800 border border-yellow-200 shadow-sm">
                                                    <i class="fas fa-clock mr-2 text-yellow-600"></i>Completed
                                            </span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    <!-- Completed Column -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-br from-purple-400 to-indigo-600 rounded-lg flex items-center justify-center mr-3 shadow-md">
                                                <i class="fas fa-calendar-check text-white"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">
                                                    <?php echo date('M j, Y', strtotime($usage['end_time'] ?? $usage['completed_at'] ?? 'now')); ?>
                                                </div>
                                                <div class="text-xs text-purple-600 font-medium">
                                                    <?php echo date('g:i A', strtotime($usage['end_time'] ?? $usage['completed_at'] ?? 'now')); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
        </div>
        <?php endif; ?>
    <script>
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            mobileToggle.classList.toggle("active");
            navLinksContainer.classList.toggle("show");
        }
        // Clear all filters
        function clearFilters() {
            window.location.href = 'usage_history.php';
        }
        // Export to CSV
        function exportToCSV() {
            const table = document.querySelector('table');
            const rows = Array.from(table.querySelectorAll('tr'));
            let csv = [];
            // Add headers
            const headers = Array.from(rows[0].querySelectorAll('th')).map(th => {
                return th.textContent.trim().replace(/\s+/g, ' ');
            });
            csv.push(headers.join(','));
            // Add data rows
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const cells = Array.from(row.querySelectorAll('td'));
                const rowData = cells.map(cell => {
                    // Extract text content, removing extra whitespace
                    let text = cell.textContent.trim().replace(/\s+/g, ' ');
                    // Escape quotes and wrap in quotes if contains comma
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    return text;
                });
                csv.push(rowData.join(','));
            }
            // Create and download CSV file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'usage_history_<?php echo date('Y-m-d'); ?>.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        // Auto-refresh page every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</body>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</html>
