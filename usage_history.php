<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/UsageManager.php';

$auth = new Auth();
$auth->requireRegularUser();
$usageManager = new UsageManager();
$pdo = getDBConnection();

// Get filter parameters
$facility_filter = $_GET['facility'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_order = $_GET['sort'] ?? 'latest'; // latest or oldest

// Get user's usage history
$usageHistory = $usageManager->getUserUsageHistory($_SESSION['user_id'], 200);

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
        return date('Y-m-d', strtotime($usage['completed_at'] ?? $usage['created_at'])) >= $date_from;
    });
}

if ($date_to) {
    $usageHistory = array_filter($usageHistory, function($usage) use ($date_to) {
        return date('Y-m-d', strtotime($usage['completed_at'] ?? $usage['created_at'])) <= $date_to;
    });
}

// Sort usage history
if ($sort_order === 'oldest') {
    usort($usageHistory, function($a, $b) {
        $dateA = strtotime($a['completed_at'] ?? $a['created_at']);
        $dateB = strtotime($b['completed_at'] ?? $b['created_at']);
        return $dateA - $dateB;
    });
} else {
    // Default: latest first
    usort($usageHistory, function($a, $b) {
        $dateA = strtotime($a['completed_at'] ?? $a['created_at']);
        $dateB = strtotime($b['completed_at'] ?? $b['created_at']);
        return $dateB - $dateA;
    });
}

// Get facilities for filter
$stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $stmt->fetchAll();

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
    <title>My Usage History - <?php echo SITE_NAME; ?></title>
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
        .usage-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .usage-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .usage-card:hover::before {
            left: 100%;
        }
        .usage-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .filter-tab {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .filter-tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }
        
        .filter-tab:hover::before {
            left: 100%;
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: white !important;
            transform: scale(1.05);
            box-shadow: 0 15px 35px -5px rgba(59, 130, 246, 0.4);
            border-color: #1d4ed8;
        }
        
        .filter-tab.active .text-black {
            color: white !important;
        }
        
        .filter-tab.active .text-gray-600 {
            color: rgba(255, 255, 255, 0.8) !important;
        }
        
        .filter-tab:hover:not(.active) {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
        }
        
        .filter-tab:active {
            transform: scale(0.98);
        }
        
        /* Enhanced text visibility */
        .filter-tab .text-black {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        
        .filter-tab .text-gray-600 {
            color: #4b5563 !important;
            font-weight: 500 !important;
        }
        .usage-row {
            transition: all 0.3s ease;
        }
        .usage-row:hover {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            transform: translateX(4px);
        }
        .payment-status-indicator {
            animation: pulse 2s infinite;
        }
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            animation: bounce-in 0.8s ease-out;
        }
        .quick-stats {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .quick-stats-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500 mx-auto mb-4"></div>
            <p class="text-gray-600 font-medium">Loading your usage history...</p>
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
            <a href="archived_reservations.php" class="sidebar-nav-item">
                <i class="fas fa-archive"></i>
                <span>Archived</span>
            </a>
            <a href="usage_history.php" class="sidebar-nav-item active">
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
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-history text-primary-500 mr-3"></i>My Usage History
            </h1>
            <p class="text-gray-600">View your past facility usage and reservation history</p>
        </div>

        <!-- Quick Stats -->
        <?php if (!empty($usageHistory)): ?>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-2xl p-6 shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">Total Usage</p>
                        <p class="text-2xl font-bold"><?php echo count($usageHistory); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-2xl p-6 shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100 text-sm font-medium">Total Hours</p>
                        <p class="text-2xl font-bold"><?php echo $totalUsageHours; ?>h</p>
                    </div>
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-hourglass-half text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-2xl p-6 shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100 text-sm font-medium">Total Spent</p>
                        <p class="text-2xl font-bold">₱<?php echo number_format($totalRevenue, 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-white text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-br from-orange-500 to-orange-600 text-white rounded-2xl p-6 shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-100 text-sm font-medium">Verified</p>
                        <p class="text-2xl font-bold"><?php echo $verifiedCount; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-xl flex items-center justify-center">
                        <i class="fas fa-check-circle text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="bg-white rounded-2xl p-6 mb-8 shadow-lg animate-slide-up">
            <h3 class="text-xl font-bold text-gray-800 mb-4 text-center">Filter Usage History</h3>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <!-- Facility Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Facility</label>
                    <select name="facility" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Statuses</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="verified" <?php echo $status_filter == 'verified' ? 'selected' : ''; ?>>Verified</option>
                    </select>
                </div>

                <!-- Date From -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Sort Order -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Sort Order</label>
                    <select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="latest" <?php echo $sort_order == 'latest' ? 'selected' : ''; ?>>Latest First</option>
                        <option value="oldest" <?php echo $sort_order == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="md:col-span-5 flex flex-wrap gap-4 justify-center">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="usage_history.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-times mr-2"></i>Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Usage History Cards -->
        <div class="space-y-6">
            <?php if (empty($usageHistory)): ?>
                <div class="text-center py-16 animate-fade-in">
                    <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-history text-gray-400 text-4xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-3">No usage history found</h3>
                    <p class="text-gray-600 mb-8 max-w-md mx-auto">You haven't used any facilities yet. Start by making a reservation and using our facilities.</p>
                    <a href="facilities.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl inline-flex items-center">
                        <i class="fas fa-search mr-3"></i>Browse Facilities
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($usageHistory as $index => $usage): ?>
                    <div class="usage-card bg-white rounded-3xl shadow-xl p-8 animate-slide-up border border-gray-100 hover:shadow-2xl transition-all duration-300 transform hover:scale-105" 
                         style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        
                        <!-- Card Header -->
                        <div class="flex items-start justify-between mb-6">
                            <div class="flex items-center space-x-4">
                                <div class="h-16 w-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                    <i class="fas fa-building text-white text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($usage['facility_name'] ?? 'N/A'); ?></h3>
                                    <p class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($usage['category_name'] ?? 'Facility'); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <?php if (($usage['status'] ?? '') === 'verified'): ?>
                                    <span class="inline-flex items-center px-4 py-2 rounded-2xl text-sm font-bold bg-green-100 text-green-800 shadow-lg">
                                        <i class="fas fa-check-circle mr-2"></i>Verified
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-4 py-2 rounded-2xl text-sm font-bold bg-yellow-100 text-yellow-800 shadow-lg">
                                        <i class="fas fa-clock mr-2"></i>Completed
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Usage Details Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl p-6 border border-blue-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-blue-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-calendar text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Usage Date</h4>
                                </div>
                                <p class="text-lg font-semibold text-gray-900 mb-1">
                                    <?php echo date('M j, Y', strtotime($usage['completed_at'] ?? $usage['created_at'])); ?>
                                </p>
                                <p class="text-gray-600 font-medium">
                                    <?php echo date('g:i A', strtotime($usage['completed_at'] ?? $usage['created_at'])); ?>
                                </p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-2xl p-6 border border-green-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-green-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-clock text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Duration</h4>
                                </div>
                                <p class="text-2xl font-bold text-green-600 mb-1">
                                    <?php 
                                    if (isset($usage['duration_minutes']) && $usage['duration_minutes']) {
                                        $hours = floor($usage['duration_minutes'] / 60);
                                        $minutes = $usage['duration_minutes'] % 60;
                                        echo $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                                    } else {
                                        echo "N/A";
                                    }
                                    ?>
                                </p>
                                <p class="text-gray-600 font-medium">Usage time</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-2xl p-6 border border-purple-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-purple-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-money-bill text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Amount</h4>
                                </div>
                                <p class="text-2xl font-bold text-purple-600 mb-1">₱<?php echo number_format($usage['total_amount'] ?? 0, 2); ?></p>
                                <p class="text-gray-600 font-medium">Total cost</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-2xl p-6 border border-orange-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-orange-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-info-circle text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Purpose</h4>
                                </div>
                                <p class="text-lg font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($usage['purpose'] ?: 'General use'); ?></p>
                                <p class="text-gray-600 font-medium">Usage purpose</p>
                            </div>
                        </div>

                        <!-- Additional Info -->
                        <?php if (isset($usage['notes']) && $usage['notes']): ?>
                            <div class="mb-6 p-6 bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl border border-gray-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-gray-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-sticky-note text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Notes</h4>
                                </div>
                                <p class="text-gray-700"><?php echo htmlspecialchars($usage['notes']); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-4">
                            <a href="facility_details.php?facility_id=<?php echo $usage['facility_id']; ?>" 
                               class="group flex items-center justify-center space-x-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <i class="fas fa-eye text-lg group-hover:scale-110 transition-transform duration-200"></i>
                                <span>View Facility</span>
                            </a>
                            
                            <?php if (isset($usage['reservation_id'])): ?>
                                <a href="my_reservations.php#reservation-<?php echo $usage['reservation_id']; ?>" 
                                   class="group flex items-center justify-center space-x-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                                    <i class="fas fa-calendar text-lg group-hover:scale-110 transition-transform duration-200"></i>
                                    <span>View Reservation</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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
        });

        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again to access your usage history.');
        }
    </script>
</body>
</html>
