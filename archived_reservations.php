<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    
    if ($bookingType === 'daily') {
        // For daily bookings, calculate based on calendar days
        $startDate = new DateTime($startTime);
        $endDate = new DateTime($endTime);
        
        if ($startDate->format('Y-m-d') === $endDate->format('Y-m-d')) {
            return '1 day';
        } else {
            $days = $startDate->diff($endDate)->days + 1;
            return $days . ' day' . ($days > 1 ? 's' : '');
        }
    } else {
        // For hourly bookings
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5;
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
}

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();

// Get user's archived reservations (only expired and cancelled)
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, c.name as category_name
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    WHERE r.user_id = ? AND r.status IN ('expired', 'cancelled')
    ORDER BY r.updated_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$archivedReservations = $stmt->fetchAll();

// Get statistics
$expiredCount = count(array_filter($archivedReservations, function($r) { return $r['status'] === 'expired'; }));
$cancelledCount = count(array_filter($archivedReservations, function($r) { return $r['status'] === 'cancelled'; }));

// Status colors
$statusColors = [
    'expired' => 'bg-gray-100 text-gray-800',
    'cancelled' => 'bg-red-100 text-red-800'
];

$statusIcons = [
    'expired' => 'fas fa-clock',
    'cancelled' => 'fas fa-times-circle'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Reservations - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <link rel="stylesheet" href="assets/css/mobile-responsive.css?v=1.0.0">
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
            background: linear-gradient(180deg, #475569 0%, #334155 100%);
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
            background: linear-gradient(135deg, #64748b, #475569);
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.4);
            transition: all 0.3s ease;
        }
        
        .sidebar-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(100, 116, 139, 0.5);
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
        
        .archived-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            opacity: 0.95;
        }
        
        .archived-card:hover {
            opacity: 1;
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);
        }
        
        .filter-tab {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #64748b, #475569) !important;
            color: white !important;
            transform: scale(1.05);
            box-shadow: 0 10px 25px -5px rgba(100, 116, 139, 0.4);
        }
        
        .filter-tab:hover:not(.active) {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px -3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 via-gray-100 to-gray-200 min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-500 mx-auto mb-4"></div>
            <p class="text-gray-600 font-medium">Loading archived reservations...</p>
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
            <a href="my_reservations.php" class="sidebar-nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>My Reservations</span>
            </a>
            <a href="archived_reservations.php" class="sidebar-nav-item active">
                <i class="fas fa-archive"></i>
                <span>Archived</span>
            </a>
            <a href="usage_history.php" class="sidebar-nav-item">
                <i class="fas fa-history"></i>
                <span>Usage History</span>
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
        <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="bg-gradient-to-r from-gray-600 to-gray-700 rounded-2xl p-8 mb-8 shadow-lg text-white animate-slide-up">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center space-x-4">
                    <div class="w-16 h-16 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-archive text-3xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold mb-2">Archived Reservations</h1>
                        <p class="text-sm opacity-90">View your expired and cancelled reservations</p>
                    </div>
                </div>
                <a href="my_reservations.php" class="bg-white text-gray-700 px-6 py-3 rounded-xl font-bold hover:bg-opacity-90 transition-all duration-300 transform hover:scale-105 shadow-lg">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Active
                </a>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">Total Archived</p>
                        <p class="text-4xl font-bold text-gray-800"><?php echo count($archivedReservations); ?></p>
                    </div>
                    <div class="w-14 h-14 bg-gray-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-archive text-gray-600 text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-2xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">Expired</p>
                        <p class="text-4xl font-bold text-gray-500"><?php echo $expiredCount; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-gray-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-clock text-gray-500 text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-2xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">Cancelled</p>
                        <p class="text-4xl font-bold text-red-500"><?php echo $cancelledCount; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-red-50 rounded-xl flex items-center justify-center">
                        <i class="fas fa-times-circle text-red-500 text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Tabs -->
        <div class="bg-white rounded-2xl p-6 mb-8 shadow-lg animate-slide-up" style="animation-delay: 0.4s;">
            <h3 class="text-xl font-bold text-gray-800 mb-4 text-center">Filter Archived</h3>
            
            <div class="flex flex-wrap justify-center gap-3">
                <button class="filter-tab active px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-500 text-white shadow-lg" data-filter="all">
                    <i class="fas fa-th-large mr-2"></i>All Archived
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="expired">
                    <i class="fas fa-clock mr-2"></i>Expired
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-red-100 hover:text-red-700" data-filter="cancelled">
                    <i class="fas fa-times-circle mr-2"></i>Cancelled
                </button>
            </div>
            
            <!-- Filter Results Counter -->
            <div class="mt-4 text-center">
                <div class="inline-flex items-center bg-gray-100 rounded-full px-4 py-2">
                    <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                    <span class="text-gray-700 font-medium" id="filter-results-count">Showing all archived reservations</span>
                </div>
            </div>
        </div>
        
        <!-- Archived Reservation Cards -->
        <?php if (empty($archivedReservations)): ?>
            <div class="text-center py-16 animate-fade-in">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-inbox text-gray-400 text-4xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-3">No archived reservations</h3>
                <p class="text-gray-600 mb-8 max-w-md mx-auto">You don't have any expired or cancelled reservations yet.</p>
                <a href="my_reservations.php" class="bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-700 hover:to-gray-800 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg inline-flex items-center">
                    <i class="fas fa-arrow-left mr-3"></i>View Active Reservations
                </a>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($archivedReservations as $index => $reservation): ?>
                    <div class="archived-card bg-white rounded-3xl shadow-lg p-8 animate-slide-up border-l-4 <?php echo $reservation['status'] === 'expired' ? 'border-gray-400' : 'border-red-400'; ?>" 
                         data-status="<?php echo $reservation['status']; ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        
                        <!-- Card Header -->
                        <div class="flex items-start justify-between mb-6">
                            <div class="flex items-center space-x-4">
                                <div class="h-16 w-16 bg-gradient-to-br from-gray-400 to-gray-500 rounded-2xl flex items-center justify-center shadow-lg opacity-75">
                                    <i class="fas fa-building text-white text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                    <p class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($reservation['category_name']); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-4 py-2 rounded-2xl text-sm font-bold <?php echo $statusColors[$reservation['status']]; ?> shadow-lg">
                                    <i class="<?php echo $statusIcons[$reservation['status']]; ?> mr-2"></i>
                                    <?php echo ucfirst($reservation['status']); ?>
                                </span>
                                <?php if ($reservation['status'] === 'expired'): ?>
                                    <p class="text-xs text-gray-500 mt-2">Payment not completed in time</p>
                                <?php elseif ($reservation['status'] === 'cancelled'): ?>
                                    <p class="text-xs text-gray-500 mt-2">Cancelled on <?php echo date('M j, Y', strtotime($reservation['updated_at'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Reservation Details Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="bg-gray-50 rounded-2xl p-4 border border-gray-200">
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="w-10 h-10 bg-gray-200 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-calendar text-gray-600"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800 text-sm">Date & Time</h4>
                                </div>
                                <p class="text-base font-semibold text-gray-900 mb-1">
                                    <?php 
                                    $startDate = date('M j, Y', strtotime($reservation['start_time']));
                                    $endDate = date('M j, Y', strtotime($reservation['end_time']));
                                    echo ($startDate === $endDate) ? $startDate : $startDate . ' - ' . $endDate;
                                    ?>
                                </p>
                                <p class="text-gray-600 text-sm">
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </p>
                            </div>
                            
                            <div class="bg-gray-50 rounded-2xl p-4 border border-gray-200">
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="w-10 h-10 bg-gray-200 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-money-bill text-gray-600"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800 text-sm">Amount</h4>
                                </div>
                                <p class="text-2xl font-bold text-gray-700 mb-1">₱<?php echo number_format($reservation['total_amount'], 2); ?></p>
                                <p class="text-gray-600 text-sm">
                                    <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> 
                                    (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                                </p>
                            </div>
                            
                            <div class="bg-gray-50 rounded-2xl p-4 border border-gray-200">
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="w-10 h-10 bg-gray-200 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-info-circle text-gray-600"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800 text-sm">Purpose</h4>
                                </div>
                                <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($reservation['purpose'] ?: 'General use'); ?></p>
                            </div>
                        </div>
                        
                        <!-- Action Button -->
                        <div class="flex justify-end">
                            <a href="facility_details.php?facility_id=<?php echo $reservation['facility_id']; ?>" 
                               class="group flex items-center space-x-2 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <i class="fas fa-eye group-hover:scale-110 transition-transform duration-200"></i>
                                <span>View Facility</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <script>
        // Hide loading overlay when page is ready
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                }, 300);
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle functionality
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            function toggleSidebar() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                
                // Change icon
                const icon = sidebarToggle.querySelector('i');
                if (sidebar.classList.contains('active')) {
                    icon.className = 'fas fa-times';
                } else {
                    icon.className = 'fas fa-bars';
                }
            }
            
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
            
            // Filter functionality
            const filterTabs = document.querySelectorAll('.filter-tab');
            const archivedCards = document.querySelectorAll('.archived-card');
            const filterResultsCount = document.getElementById('filter-results-count');
            
            function updateFilterResults(filter) {
                let visibleCount = 0;
                archivedCards.forEach(card => {
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
                        'all': 'all',
                        'expired': 'expired',
                        'cancelled': 'cancelled'
                    };
                    
                    if (visibleCount === 0) {
                        filterResultsCount.textContent = `No ${filterNames[filter]} reservations found`;
                    } else if (filter === 'all') {
                        filterResultsCount.textContent = `Showing all ${visibleCount} archived reservations`;
                    } else {
                        filterResultsCount.textContent = `Showing ${visibleCount} ${filterNames[filter]} reservation${visibleCount !== 1 ? 's' : ''}`;
                    }
                }
            }
            
            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Remove active class from all tabs
                    filterTabs.forEach(t => t.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    updateFilterResults(filter);
                    
                    // Add click animation
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
            
            // Initialize with all archived shown
            updateFilterResults('all');
        });
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session.');
        }
    </script>
</body>
</html>

