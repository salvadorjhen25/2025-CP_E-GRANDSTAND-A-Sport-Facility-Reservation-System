<?php
// Suppress PHP errors to prevent them from appearing in JavaScript
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to prevent any PHP errors from appearing in JavaScript
ob_start();

require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
require_once 'classes/ReservationManager.php';

// Define site constants if not already defined
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'Facility Reservation System');
}
// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    if ($bookingType === 'daily') {
        // For daily bookings, calculate based on calendar days, not time difference
        $startDate = new DateTime($startTime);
        $endDate = new DateTime($endTime);
        // If start and end are on the same day, it's 1 day
        if ($startDate->format('Y-m-d') === $endDate->format('Y-m-d')) {
            return '1 day';
        } else {
            // Calculate the difference in days
            $days = $startDate->diff($endDate)->days;
            // Add 1 because we count both start and end days
            $days += 1;
            return $days . ' day' . ($days > 1 ? 's' : '');
        }
    } else {
        // For hourly bookings, calculate based on actual time difference
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
}
// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
$auth->requireRegularUser();
$pdo = getDBConnection();
$paymentManager = new PaymentManager();
// Initialize error array
$errors = [];
// Handle waitlist removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_waitlist') {
    $waitlist_id = $_POST['waitlist_id'] ?? null;
    if ($waitlist_id) {
        if ($paymentManager->removeFromWaitlist($waitlist_id, $_SESSION['user_id'])) {
            $success_message = 'Removed from waitlist successfully.';
        } else {
            $errors[] = 'Failed to remove from waitlist.';
        }
    }
}
// Handle reservation cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation') {
    $reservation_id = $_POST['reservation_id'] ?? null;
    if ($reservation_id) {
        // Verify the reservation belongs to the current user
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ?");
        $stmt->execute([$reservation_id, $_SESSION['user_id']]);
        $reservation = $stmt->fetch();
        if ($reservation) {
            // Only allow cancellation of pending or confirmed reservations
            if (in_array($reservation['status'], ['pending', 'confirmed'])) {
                // Update reservation status to cancelled
                $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$reservation_id])) {
                    $success_message = 'Reservation cancelled successfully.';
                } else {
                    $errors[] = 'Failed to cancel reservation.';
                }
            } else {
                $errors[] = 'This reservation cannot be cancelled.';
            }
        } else {
            $errors[] = 'Reservation not found or access denied.';
        }
    }
}
// Get user's active reservations (exclude expired and cancelled)
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, c.name as category_name,
           r.or_number, r.verified_by_staff_name, r.payment_verified_at,
           admin.full_name as verified_by_admin, admin.role as verifier_role,
           u.email as user_email, u.full_name as user_full_name, u.organization as user_organization
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    LEFT JOIN users admin ON r.payment_verified_by = admin.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.user_id = ? AND r.status NOT IN ('expired', 'cancelled')
    ORDER BY r.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$reservations = $stmt->fetchAll();
// Get user's waitlist entries
$waitlist_entries = $paymentManager->getUserWaitlist($_SESSION['user_id']);
// Check for expired payments
$paymentManager->checkExpiredPayments();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Reservations - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <link rel="stylesheet" href="assets/css/mobile-responsive.css?v=1.0.0">
    <script src="assets/js/modal-system.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a'
                        },
                        secondary: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155'
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.6s ease-out',
                        'scale-in': 'scaleIn 0.4s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* Modern Sidebar Navigation */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .sidebar-logo {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }
        
        .sidebar-title {
            font-family: 'Inter', sans-serif;
            font-weight: 800;
            font-size: 1.25rem;
            color: white;
            line-height: 1.2;
        }
        
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
        }
        
        .sidebar-user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }
        
        .sidebar-user-info {
            flex: 1;
            min-width: 0;
        }
        
        .sidebar-user-name {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.875rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sidebar-user-role {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .sidebar-nav {
            flex: 1;
            padding: 1.5rem 1rem;
            overflow-y: auto;
        }
        
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        
        .sidebar-nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            margin-bottom: 0.5rem;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: white;
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }
        
        .sidebar-nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(4px);
        }
        
        .sidebar-nav-item:hover::before {
            transform: scaleY(1);
        }
        
        .sidebar-nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            font-weight: 600;
        }
        
        .sidebar-nav-item.active::before {
            transform: scaleY(1);
        }
        
        .sidebar-nav-item i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-nav-item.logout {
            background: rgba(220, 38, 38, 0.15);
            color: #fca5a5;
            margin-top: auto;
        }
        
        .sidebar-nav-item.logout:hover {
            background: rgba(220, 38, 38, 0.3);
            color: #fecaca;
        }
        
        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Mobile Toggle Button */
        .sidebar-toggle {
            position: fixed;
            top: 1.25rem;
            left: 1.25rem;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transition: all 0.3s ease;
        }
        
        .sidebar-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.5);
        }
        
        .sidebar-toggle i {
            color: white;
            font-size: 1.25rem;
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Main Content Wrapper */
        .main-wrapper {
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: flex;
            }
            
            .main-wrapper {
                margin-left: 0;
                padding-top: 80px;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
            }
            
            .sidebar-toggle {
                top: 1rem;
                left: 1rem;
            }
        }
        
        /* Adjust loading overlay z-index */
        #loading-overlay {
            z-index: 9999 !important;
        }

        /* Fallback to hide loading overlay after 5 seconds */
        #loading-overlay {
            animation: fadeOut 0.3s ease-out 5s forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        @keyframes scaleIn {
            from { 
                opacity: 0; 
                transform: scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: scale(1); 
            }
        }
        @keyframes bounceIn {
            0% { opacity: 0; transform: scale(0.3); }
            50% { opacity: 1; transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1); }
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .status-badge {
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .reservation-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .reservation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.15), transparent);
            transition: left 0.5s;
        }
        .reservation-card:hover::before {
            left: 100%;
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -8px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(59, 130, 246, 0.05);
            border-color: rgba(59, 130, 246, 0.2);
        }
        .filter-tab {
            transition: all 0.2s ease;
            position: relative;
            cursor: pointer;
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: white !important;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .filter-tab:hover:not(.active) {
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-1px);
        }
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            animation: bounce-in 0.8s ease-out;
        }

        /* Receipt Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            .receipt-content, .receipt-content * {
                visibility: visible;
            }
            .receipt-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border: none;
            }
            .no-print {
                display: none !important;
            }
        }

        .receipt-content {
            font-family: 'Courier New', monospace;
            line-height: 1.4;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .receipt-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .receipt-subtitle {
            font-size: 14px;
            color: #666;
        }

        .receipt-section {
            margin-bottom: 15px;
        }

        .receipt-label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
        }

        .receipt-value {
            display: inline-block;
        }

        .receipt-divider {
            border-top: 1px dashed #000;
            margin: 15px 0;
        }

        .receipt-footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #666;
        }

    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500 mx-auto mb-4"></div>
            <p class="text-gray-600 font-medium">Loading your reservations...</p>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Mobile Toggle Button -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Modern Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="sidebar-logo">
                    <i class="fas fa-building text-white text-xl"></i>
                </div>
                <h1 class="sidebar-title"><?php echo SITE_NAME; ?></h1>
            </div>
            
            <!-- User Info -->
            <div class="sidebar-user">
                <div class="sidebar-user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <div class="sidebar-user-role">User</div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Navigation -->
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-nav-item">
                <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
            <a href="facilities.php" class="sidebar-nav-item">
                <i class="fas fa-building"></i>
                    <span>Facilities</span>
                </a>
            <a href="my_reservations.php" class="sidebar-nav-item active">
                <i class="fas fa-calendar-check"></i>
                    <span>My Reservations</span>
                </a>
            <a href="archived_reservations.php" class="sidebar-nav-item">
                <i class="fas fa-archive"></i>
                <span>Archived</span>
            </a>
            <a href="usage_history.php" class="sidebar-nav-item">
                <i class="fas fa-history"></i>
                    <span>Usage History</span>
                </a>
            <a href="profile.php" class="sidebar-nav-item">
                <i class="fas fa-user-circle"></i>
                    <span>My Profile</span>
                </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <a href="auth/logout.php" class="sidebar-nav-item logout" onclick="return confirmLogout()">
                <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
    </aside>
    
    <!-- Main Content Wrapper -->
    <div class="main-wrapper">
        <div class="max-w-6xl mx-auto px-4 py-6">
            <!-- Header Section -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">My Reservations</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage your active facility bookings</p>
            </div>
                <a href="archived_reservations.php" class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors duration-200">
                    <i class="fas fa-archive mr-2"></i>View Archived
                    </a>
                </div>
       
        <?php
        $totalReservations = count($reservations);
        $pendingPayments = count(array_filter($reservations, function($r) { 
            return $r['payment_status'] === 'pending' && !$r['payment_slip_url'] && $r['status'] !== 'cancelled'; 
        }));
        $upcomingReservations = count(array_filter($reservations, function($r) { 
            return $r['status'] === 'confirmed' && strtotime($r['start_time']) > time(); 
        }));
        ?>
        
        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-calendar-check text-blue-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo $totalReservations; ?></div>
                            <div class="text-sm text-gray-600">Total Reservations</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-orange-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo $pendingPayments; ?></div>
                            <div class="text-sm text-gray-600">Pending Payment</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-calendar-alt text-green-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo $upcomingReservations; ?></div>
                            <div class="text-sm text-gray-600">Upcoming</div>
                        </div>
                    </div>
                </div>
            </div>
        
            <!-- Filter Tabs -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center space-x-2 bg-gray-50 rounded-lg p-1">
                    <button class="filter-tab active px-4 py-2 rounded-md font-medium transition-all duration-200 text-sm" data-filter="all">
                        <i class="fas fa-th-large mr-2"></i>All
                </button>
                    <button class="filter-tab px-4 py-2 rounded-md font-medium transition-all duration-200 text-sm" data-filter="pending">
                        <i class="fas fa-clock mr-2"></i>Pending
                </button>
                    <button class="filter-tab px-4 py-2 rounded-md font-medium transition-all duration-200 text-sm" data-filter="confirmed">
                        <i class="fas fa-check-circle mr-2"></i>Confirmed
                </button>
                    <button class="filter-tab px-4 py-2 rounded-md font-medium transition-all duration-200 text-sm" data-filter="completed">
                        <i class="fas fa-check-double mr-2"></i>Completed
                </button>
            </div>
                <div class="text-sm text-gray-600" id="filter-results-count">
                    <?php echo $totalReservations; ?> reservations
            </div>
        </div>
        <!-- Waitlist Section -->
        <?php if (!empty($waitlist_entries)): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                            <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-hourglass-half text-amber-600"></i>
                            </div>
                                <div>
                                <h3 class="font-semibold text-amber-800">Waitlist Entries</h3>
                                <p class="text-sm text-amber-700"><?php echo count($waitlist_entries); ?> pending</p>
                                </div>
                        </div>
                        <div class="flex space-x-2">
                            <?php foreach ($waitlist_entries as $entry): ?>
                                <div class="bg-white rounded-lg px-3 py-2 border border-amber-200">
                                    <div class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($entry['facility_name']); ?></div>
                                    <div class="text-xs text-gray-600">
                                        <?php echo date('M j, g:i A', strtotime($entry['start_time'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                        </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Reservation Cards -->
            <div class="grid gap-4">
            <?php foreach ($reservations as $index => $reservation): ?>
                    <div class="reservation-card bg-white rounded-xl p-4 border border-gray-100 hover:shadow-md transition-all duration-200"
                     data-status="<?php echo $reservation['status']; ?>"
                         style="animation-delay: <?php echo $index * 0.05; ?>s;">
                    
                    <!-- Card Header -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-building text-white"></i>
                            </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($reservation['category_name']); ?></p>
                            </div>
                        </div>
                            <?php
                            $statusColors = [
                                'pending' => 'bg-yellow-100 text-yellow-700',
                                'confirmed' => 'bg-green-100 text-green-700',
                                'completed' => 'bg-blue-100 text-blue-700',
                                'cancelled' => 'bg-red-100 text-red-700',
                                'expired' => 'bg-gray-100 text-gray-700'
                            ];
                            $statusIcons = [
                                'pending' => 'fas fa-clock',
                                'confirmed' => 'fas fa-check-circle',
                                'completed' => 'fas fa-check-double',
                                'cancelled' => 'fas fa-times-circle',
                                'expired' => 'fas fa-ban'
                            ];
                            ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-700'; ?>">
                                <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-1"></i>
                            <?php echo ucfirst($reservation['status']); ?>
                        </span>
                    </div>

                        <!-- Reservation Details -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-xs text-gray-500 uppercase tracking-wide">Date</div>
                                <div class="font-semibold text-gray-900">
                                    <?php echo date('M j', strtotime($reservation['start_time'])); ?>
                    </div>
                                </div>
                            <div class="text-center">
                                <div class="text-xs text-gray-500 uppercase tracking-wide">Time</div>
                                <div class="font-semibold text-gray-900">
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> -
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                            </div>
                        </div>
                            <div class="text-center">
                                <div class="text-xs text-gray-500 uppercase tracking-wide">Amount</div>
                                <div class="font-semibold text-green-600">₱<?php echo number_format($reservation['total_amount'], 0); ?></div>
                        </div>
                            <div class="text-center">
                                <div class="text-xs text-gray-500 uppercase tracking-wide">Duration</div>
                                <div class="font-semibold text-gray-900">
                                    <?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>
                            </div>
                            </div>
                        </div>
                        
                        <!-- Purpose -->
                        <?php if ($reservation['purpose']): ?>
                            <div class="mb-4">
                                <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Purpose</div>
                                <div class="text-sm text-gray-700 bg-gray-50 rounded-lg px-3 py-2">
                                    <?php echo htmlspecialchars($reservation['purpose']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Payment Status -->
                    <?php if ($reservation['status'] !== 'cancelled'): ?>
                            <div class="mb-4">
                                <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Payment Status</div>
                                <div class="flex items-center justify-between">
                                <?php if ($reservation['payment_status'] === 'pending'): ?>
                                    <span class="inline-flex items-center px-2 py-1 bg-orange-100 text-orange-700 rounded-md text-xs">
                                        <i class="fas fa-clock mr-1"></i>Pending Payment
                                    </span>
                                <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                                    <span class="inline-flex items-center px-2 py-1 bg-green-100 text-green-700 rounded-md text-xs">
                                        <i class="fas fa-check-circle mr-1"></i>Paid
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Payment Verification Details -->
                            <?php if ($reservation['payment_status'] === 'paid' && $reservation['payment_verified_at']): ?>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
                                    <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">Payment Verification Details</div>
                                    <div class="space-y-2">
                                        <?php if ($reservation['or_number']): ?>
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-receipt text-green-600 mr-2"></i>
                                                <span class="text-gray-700">OR Number: <strong><?php echo htmlspecialchars($reservation['or_number']); ?></strong></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($reservation['verified_by_staff_name']): ?>
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-user-check text-green-600 mr-2"></i>
                                                <span class="text-gray-700">Verified by: <strong><?php echo htmlspecialchars($reservation['verified_by_staff_name']); ?></strong></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($reservation['verified_by_admin']): ?>
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-user-shield text-green-600 mr-2"></i>
                                                <span class="text-gray-700">Role: <strong><?php echo $reservation['verifier_role'] === 'admin' ? 'Admin' : 'Staff'; ?></strong></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="flex items-center text-sm">
                                            <i class="fas fa-calendar-check text-green-600 mr-2"></i>
                                            <span class="text-gray-700">Verified on: <strong><?php echo date('M j, Y g:i A', strtotime($reservation['payment_verified_at'])); ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                        <!-- Action Buttons -->
                        <div class="flex items-center justify-between">
                        <a href="facility_details.php?facility_id=<?php echo $reservation['facility_id']; ?>" 
                               class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                <i class="fas fa-eye mr-1"></i>View Details
                        </a>
                        
                            <div class="flex items-center space-x-2">
                        <?php if ($reservation['payment_status'] === 'paid'): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">Paid</span>
                        <?php elseif ($reservation['status'] === 'pending'): ?>
                                    <span class="px-2 py-1 bg-orange-100 text-orange-700 rounded text-xs">Payment Required</span>
                        <?php endif; ?>
                        
                        <?php if (in_array($reservation['status'], ['confirmed', 'completed']) && $reservation['payment_status'] === 'paid'): ?>
                            <button onclick="generateReceipt(<?php echo $reservation['id']; ?>)" 
                                            class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium transition-colors">
                                        <i class="fas fa-receipt mr-1"></i>Receipt
                            </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($reservation['status'], ['pending', 'confirmed'])): ?>
                            <button onclick="showCancelConfirmation(<?php echo $reservation['id']; ?>, '<?php echo htmlspecialchars($reservation['facility_name']); ?>', '<?php echo date('M j, Y g:i A', strtotime($reservation['start_time'])); ?>')" 
                                            class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-sm font-medium transition-colors">
                                        <i class="fas fa-times mr-1"></i>Cancel
                            </button>
                        <?php endif; ?>
                            </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
            <!-- Empty State -->
        <?php if (empty($reservations)): ?>
                <div class="text-center py-12">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-calendar-times text-gray-400 text-2xl"></i>
                </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">No reservations found</h3>
                    <p class="text-gray-600 mb-6">You haven't made any reservations yet. Browse available facilities to get started.</p>
                    <a href="facilities.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-search mr-2"></i>Browse Facilities
                </a>
            </div>
        <?php endif; ?>
        </div>
    </div>
  
    <!-- Cancel Confirmation Modal -->
    <div id="cancelModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0" id="cancelModalContent">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Cancel Reservation</h3>
                        <p class="text-sm text-gray-600">Are you sure you want to cancel this reservation?</p>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="text-sm text-gray-700">
                        <div class="font-medium mb-2">Reservation Details:</div>
                        <div class="space-y-1">
                            <div><span class="font-medium">Facility:</span> <span id="cancelFacilityName"></span></div>
                            <div><span class="font-medium">Date & Time:</span> <span id="cancelDateTime"></span></div>
                        </div>
                    </div>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-yellow-500 mt-0.5 mr-2"></i>
                        <div class="text-sm text-yellow-800">
                            <p class="font-medium mb-1">Important:</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>This action cannot be undone</li>
                                <li>If payment was made, refund policies apply</li>
                                <li>You may need to re-book if you change your mind</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <button onclick="closeCancelModal()" 
                            class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium transition duration-200">
                        Keep Reservation
                    </button>
                    <form id="cancelForm" method="POST" class="flex-1">
                        <input type="hidden" name="action" value="cancel_reservation">
                        <input type="hidden" name="reservation_id" id="cancelReservationId">
                        <button type="submit" 
                                class="w-full bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg font-medium transition duration-200 transform hover:scale-105">
                            <i class="fas fa-times mr-2"></i>Cancel Reservation
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Facility Details Modal -->
    <div id="facilityModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div id="facilityModalContent" class="transform transition-all duration-300 scale-95 opacity-0">
            <!-- Modal content will be dynamically inserted here -->
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto transform transition-all duration-300 scale-95 opacity-0" id="receiptModalContent">
            <!-- Receipt content will be dynamically inserted here -->
        </div>
    </div>

    
    <script>
        // Hide loading overlay when page is ready
        window.addEventListener('load', function() {
            try {
                const loadingOverlay = document.getElementById('loading-overlay');
                if (loadingOverlay) {
                    loadingOverlay.style.opacity = '0';
                    setTimeout(() => {
                        loadingOverlay.style.display = 'none';
                    }, 300);
                }
            } catch (error) {
                console.error('Error hiding loading overlay:', error);
                // Force hide the loading overlay if there's an error
                const loadingOverlay = document.getElementById('loading-overlay');
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }
            }
        });

        // Also hide loading overlay on DOMContentLoaded as a fallback
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const loadingOverlay = document.getElementById('loading-overlay');
                if (loadingOverlay && loadingOverlay.style.display !== 'none') {
                    loadingOverlay.style.opacity = '0';
                    setTimeout(() => {
                        loadingOverlay.style.display = 'none';
                    }, 300);
                }
            }, 1000); // Wait 1 second as fallback
        });
        document.addEventListener('DOMContentLoaded', function() {
            try {
                // Sidebar toggle functionality
                const sidebar = document.getElementById('sidebar');
                const sidebarToggle = document.getElementById('sidebarToggle');
                const sidebarOverlay = document.getElementById('sidebarOverlay');
                
                const toggleSidebar = function() {
                    sidebar.classList.toggle('active');
                    sidebarOverlay.classList.toggle('active');
                    
                    // Change icon
                    const icon = sidebarToggle.querySelector('i');
                    if (sidebar.classList.contains('active')) {
                        icon.className = 'fas fa-times';
                    } else {
                        icon.className = 'fas fa-bars';
                    }
                };
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', toggleSidebar);
            }
            
            // Close sidebar on navigation (mobile)
            const sidebarLinks = document.querySelectorAll('.sidebar-nav-item');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 1024) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        const icon = sidebarToggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    }
                });
            });
            // Enhanced Filter functionality
            const filterTabs = document.querySelectorAll('.filter-tab');
            const reservationCards = document.querySelectorAll('.reservation-card');
            const filterResultsCount = document.getElementById('filter-results-count');
            
            function updateFilterResults(filter) {
                let visibleCount = 0;
                reservationCards.forEach(card => {
                    if (filter === 'all' || card.dataset.status === filter) {
                        card.style.display = '';
                        card.classList.add('animate-slide-up');
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Update results counter
                if (filterResultsCount) {
                    const filterNames = {
                        'all': 'active',
                        'pending': 'pending',
                        'confirmed': 'confirmed',
                        'completed': 'completed'
                    };
                    
                    if (visibleCount === 0) {
                        filterResultsCount.textContent = `No ${filterNames[filter]} reservations found`;
                    } else if (filter === 'all') {
                        filterResultsCount.textContent = `Showing all ${visibleCount} reservations`;
                    } else {
                        filterResultsCount.textContent = `Showing ${visibleCount} ${filterNames[filter]} reservation${visibleCount !== 1 ? 's' : ''}`;
                    }
                }
            }
            
            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Remove active class from all tabs
                    filterTabs.forEach(t => {
                        t.classList.remove('active');
                        // Reset to default styling for inactive tabs
                        if (t.dataset.filter !== 'all') {
                            t.className = t.className.replace(/bg-gradient-to-br from-\w+-\d+ to-\w+-\d+/, 'bg-white border-2 border-gray-200');
                        }
                    });
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    
                    // Apply active styling
                    if (this.dataset.filter !== 'all') {
                        this.className = this.className.replace('bg-white border-2 border-gray-200', 'bg-gradient-to-br from-blue-500 to-blue-600 text-white');
                    }
                    
                    const filter = this.dataset.filter;
                    updateFilterResults(filter);
                    
                    // Add click animation
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
            
            // Initialize with all reservations shown
            updateFilterResults('all');
            // Add loading states to forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 2000);
                    }
                });
            });
            // Smooth scroll for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            } catch (error) {
                console.error('Error in DOMContentLoaded:', error);
                // Ensure loading overlay is hidden even if there's an error
                const loadingOverlay = document.getElementById('loading-overlay');
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }
            }
        });
        // Cancel reservation modal functions
        function showCancelConfirmation(reservationId, facilityName, dateTime) {
            // Set the reservation details in the modal
            document.getElementById('cancelReservationId').value = reservationId;
            document.getElementById('cancelFacilityName').textContent = facilityName;
            document.getElementById('cancelDateTime').textContent = dateTime;
            // Show the modal
            const modal = document.getElementById('cancelModal');
            const modalContent = document.getElementById('cancelModalContent');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            // Animate modal in
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }
        function closeCancelModal() {
            const modal = document.getElementById('cancelModal');
            const modalContent = document.getElementById('cancelModalContent');
            // Animate modal out
            modalContent.classList.add('scale-95', 'opacity-0');
            modalContent.classList.remove('scale-100', 'opacity-100');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                // Restore body scroll
                document.body.style.overflow = '';
            }, 300);
        }
        // Close modal when clicking outside
        document.getElementById('cancelModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCancelModal();
            }
        });
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again to access your reservations.');
        }

        
        // Facility details modal function
        function showFacilityDetails(facilityId) {
            // Filter reservations for this facility
            const facilityReservations = <?php echo json_encode($reservations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const facilityData = facilityReservations.filter(r => r.facility_id == facilityId);
            
            if (facilityData.length === 0) {
                alert('No reservations found for this facility.');
                return;
            }
            
            // Get facility info
            const facilityName = facilityData[0].facility_name;
            const categoryName = facilityData[0].category_name;
            
            // Create modal content
            let modalContent = `
                <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-200 p-6 rounded-t-2xl">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-building text-white text-lg"></i>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-gray-900">${facilityName}</h3>
                                    <p class="text-gray-600">${categoryName}</p>
                                </div>
                            </div>
                            <button onclick="closeFacilityModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                                <i class="fas fa-times text-2xl"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="bg-blue-50 rounded-xl p-4 text-center">
                                <div class="text-3xl font-bold text-blue-600">${facilityData.length}</div>
                                <div class="text-blue-800 font-medium">Total Reservations</div>
                            </div>
                            <div class="bg-green-50 rounded-xl p-4 text-center">
                                <div class="text-3xl font-bold text-green-600">${facilityData.filter(r => r.status === 'completed').length}</div>
                                <div class="text-green-800 font-medium">Completed</div>
                            </div>
                            <div class="bg-yellow-50 rounded-xl p-4 text-center">
                                <div class="text-3xl font-bold text-yellow-600">${facilityData.filter(r => r.status === 'pending').length}</div>
                                <div class="text-yellow-800 font-medium">Pending</div>
                            </div>
                        </div>
                        
                        <h4 class="text-xl font-bold text-gray-900 mb-4">Reservation History</h4>
                        <div class="space-y-4">
            `;
            
            // Add each reservation
            facilityData.forEach((reservation, index) => {
                const startDate = new Date(reservation.start_time).toLocaleDateString('en-US', { 
                    month: 'short', day: 'numeric', year: 'numeric' 
                });
                const startTime = new Date(reservation.start_time).toLocaleTimeString('en-US', { 
                    hour: 'numeric', minute: '2-digit', hour12: true 
                });
                const endTime = new Date(reservation.end_time).toLocaleTimeString('en-US', { 
                    hour: 'numeric', minute: '2-digit', hour12: true 
                });
                
                const statusColors = {
                    'pending': 'bg-yellow-100 text-yellow-800',
                    'confirmed': 'bg-green-100 text-green-800',
                    'completed': 'bg-blue-100 text-blue-800',
                    'cancelled': 'bg-red-100 text-red-800'
                };
                
                modalContent += `
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar text-white"></i>
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-900">${startDate}</div>
                                    <div class="text-sm text-gray-600">${startTime} - ${endTime}</div>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${statusColors[reservation.status] || 'bg-gray-100 text-gray-800'}">
                                ${reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1)}
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <div class="text-gray-600">Amount</div>
                                <div class="font-semibold text-green-600">₱${parseFloat(reservation.total_amount).toLocaleString()}</div>
                            </div>
                            <div>
                                <div class="text-gray-600">Purpose</div>
                                <div class="font-semibold text-gray-900">${reservation.purpose || 'General use'}</div>
                            </div>
                            <div>
                                <div class="text-gray-600">Booking Type</div>
                                <div class="font-semibold text-gray-900">${reservation.booking_type || 'hourly'}</div>
                            </div>
                        </div>
                        
                        
                        ${reservation.payment_status === 'paid' ? `
                            <div class="mt-3 p-3 bg-green-50 rounded-lg border border-green-200">
                                <div class="flex items-center text-green-800">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <span class="text-sm font-medium">Payment verified</span>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                `;
            });
            
            modalContent += `
                        </div>
                    </div>
                </div>
            `;
            
            // Show modal
            const modal = document.getElementById('facilityModal');
            const modalContentDiv = document.getElementById('facilityModalContent');
            modalContentDiv.innerHTML = modalContent;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Animate modal in
            setTimeout(() => {
                modalContentDiv.classList.remove('scale-95', 'opacity-0');
                modalContentDiv.classList.add('scale-100', 'opacity-100');
            }, 10);
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }
        
        function closeFacilityModal() {
            const modal = document.getElementById('facilityModal');
            const modalContentDiv = document.getElementById('facilityModalContent');
            
            // Animate modal out
            modalContentDiv.classList.add('scale-95', 'opacity-0');
            modalContentDiv.classList.remove('scale-100', 'opacity-100');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                // Restore body scroll
                document.body.style.overflow = '';
            }, 300);
        }

        // Receipt generation functions
        function generateReceipt(reservationId) {
            // Get reservation data
            const reservations = <?php echo json_encode($reservations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const reservation = reservations.find(r => r.id == reservationId);
            
            if (!reservation) {
                alert('Reservation not found.');
                return;
            }

            // Format dates and times
            const startDate = new Date(reservation.start_time);
            const endDate = new Date(reservation.end_time);
            const createdDate = new Date(reservation.created_at);
            const verifiedDate = reservation.payment_verified_at ? new Date(reservation.payment_verified_at) : null;

            // Get PHP variables
            const siteName = <?php echo json_encode(SITE_NAME); ?>;
            const customerName = reservation.user_full_name || 'User';
            const customerEmail = reservation.user_email || 'user@example.com';
            const customerOrganization = reservation.user_organization || '';

            // Create receipt content
            const receiptContent = `
                <div class="receipt-content p-6">
                    <!-- Receipt Header -->
                    <div class="receipt-header">
                        <div class="receipt-title">${siteName}</div>
                        <div class="receipt-subtitle">Facility Reservation Receipt</div>
                    </div>

                    <!-- Reservation Details -->
                    <div class="receipt-section">
                        <div><span class="receipt-label">Receipt No:</span><span class="receipt-value">#${reservation.id}</span></div>
                        <div><span class="receipt-label">Date:</span><span class="receipt-value">${createdDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span></div>
                        <div><span class="receipt-label">Time:</span><span class="receipt-value">${createdDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span></div>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Customer Information -->
                    <div class="receipt-section">
                        <div><span class="receipt-label">Customer:</span><span class="receipt-value">${customerName}</span></div>
                        <div><span class="receipt-label">Email:</span><span class="receipt-value">${customerEmail}</span></div>
                        ${customerOrganization ? `<div><span class="receipt-label">Organization:</span><span class="receipt-value">${customerOrganization}</span></div>` : ''}
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Facility Information -->
                    <div class="receipt-section">
                        <div><span class="receipt-label">Facility:</span><span class="receipt-value">${reservation.facility_name}</span></div>
                        <div><span class="receipt-label">Category:</span><span class="receipt-value">${reservation.category_name}</span></div>
                        <div><span class="receipt-label">Purpose:</span><span class="receipt-value">${reservation.purpose || 'General use'}</span></div>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Booking Details -->
                    <div class="receipt-section">
                        <div><span class="receipt-label">Booking Date:</span><span class="receipt-value">${startDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span></div>
                        <div><span class="receipt-label">Start Time:</span><span class="receipt-value">${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span></div>
                        <div><span class="receipt-label">End Time:</span><span class="receipt-value">${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span></div>
                        <div><span class="receipt-label">Duration:</span><span class="receipt-value">${formatBookingDuration(reservation.start_time, reservation.end_time, reservation.booking_type || 'hourly')}</span></div>
                        <div><span class="receipt-label">Booking Type:</span><span class="receipt-value">${(reservation.booking_type || 'hourly').charAt(0).toUpperCase() + (reservation.booking_type || 'hourly').slice(1)}</span></div>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Payment Information -->
                    <div class="receipt-section">
                        <div><span class="receipt-label">Amount:</span><span class="receipt-value">₱${parseFloat(reservation.total_amount).toLocaleString()}</span></div>
                        <div><span class="receipt-label">Status:</span><span class="receipt-value">${reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1)}</span></div>
                        <div><span class="receipt-label">Payment:</span><span class="receipt-value">${reservation.payment_status.charAt(0).toUpperCase() + reservation.payment_status.slice(1)}</span></div>
                        ${reservation.or_number ? `<div><span class="receipt-label">OR Number:</span><span class="receipt-value">${reservation.or_number}</span></div>` : ''}
                        ${reservation.verified_by_staff_name ? `<div><span class="receipt-label">Verified by:</span><span class="receipt-value">${reservation.verified_by_staff_name}</span></div>` : ''}
                        ${verifiedDate ? `<div><span class="receipt-label">Verified on:</span><span class="receipt-value">${verifiedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} ${verifiedDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span></div>` : ''}
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Footer -->
                    <div class="receipt-footer">
                        <div>Thank you for using our facility reservation system!</div>
                        <div>For inquiries, please contact our support team.</div>
                        <div>Generated on: ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} at ${new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex justify-center space-x-4 mt-6 no-print">
                        <button onclick="printReceipt()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                            <i class="fas fa-print mr-2"></i>Print Receipt
                        </button>
                        <button onclick="downloadReceipt()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                            <i class="fas fa-download mr-2"></i>Download PDF
                        </button>
                        <button onclick="closeReceiptModal()" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition-colors">
                            <i class="fas fa-times mr-2"></i>Close
                        </button>
                    </div>
                </div>
            `;

            // Show receipt modal
            const modal = document.getElementById('receiptModal');
            const modalContent = document.getElementById('receiptModalContent');
            modalContent.innerHTML = receiptContent;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Animate modal in
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }

        function printReceipt() {
            window.print();
        }

        function downloadReceipt() {
            // Create a new window for printing/downloading
            const printWindow = window.open('', '_blank');
            const receiptContent = document.querySelector('.receipt-content').outerHTML;
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Reservation Receipt</title>
                    <style>
                        body { font-family: 'Courier New', monospace; margin: 0; padding: 20px; }
                        .receipt-content { max-width: 400px; margin: 0 auto; }
                        .receipt-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
                        .receipt-title { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
                        .receipt-subtitle { font-size: 14px; color: #666; }
                        .receipt-section { margin-bottom: 15px; }
                        .receipt-label { font-weight: bold; display: inline-block; width: 120px; }
                        .receipt-value { display: inline-block; }
                        .receipt-divider { border-top: 1px dashed #000; margin: 15px 0; }
                        .receipt-footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                        .no-print { display: none !important; }
                    </style>
                </head>
                <body>
                    ${receiptContent}
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.focus();
            
            // Wait for content to load, then trigger print dialog
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }

        function closeReceiptModal() {
            const modal = document.getElementById('receiptModal');
            const modalContent = document.getElementById('receiptModalContent');
            
            // Animate modal out
            modalContent.classList.add('scale-95', 'opacity-0');
            modalContent.classList.remove('scale-100', 'opacity-100');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                // Restore body scroll
                document.body.style.overflow = '';
            }, 300);
        }

        // Helper function to format booking duration (same as PHP function)
        function formatBookingDuration(startTime, endTime, bookingType) {
            const start = new Date(startTime);
            const end = new Date(endTime);
            
            if (bookingType === 'daily') {
                const startDate = new Date(startTime);
                const endDate = new Date(endTime);
                
                if (startDate.toDateString() === endDate.toDateString()) {
                    return '1 day';
                } else {
                    const days = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;
                    return days + ' day' + (days > 1 ? 's' : '');
                }
            } else {
                const hours = Math.ceil((end - start) / (1000 * 60 * 60));
                return hours + ' hour' + (hours > 1 ? 's' : '');
            }
        }

        // Event listeners (moved after function definitions)
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const cancelModal = document.getElementById('cancelModal');
                const facilityModal = document.getElementById('facilityModal');
                const receiptModal = document.getElementById('receiptModal');
                if (!cancelModal.classList.contains('hidden')) {
                    closeCancelModal();
                }
                if (!facilityModal.classList.contains('hidden')) {
                    closeFacilityModal();
                }
                if (!receiptModal.classList.contains('hidden')) {
                    closeReceiptModal();
                }
            }
        });
        
        // Close facility modal when clicking outside
        document.getElementById('facilityModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFacilityModal();
            }
        });

        // Close receipt modal when clicking outside
        document.getElementById('receiptModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReceiptModal();
            }
        });

        // Handle form submission with loading state
        document.getElementById('cancelForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Cancelling...';
            // The form will submit normally, but we show loading state
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 2000);
        });
    </script>
</body>
</html>
