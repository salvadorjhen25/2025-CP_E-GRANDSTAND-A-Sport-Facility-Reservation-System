<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
$auth = new Auth();
$auth->requireRegularUser();
$pdo = getDBConnection();
// Get facility details
$facility_id = $_GET['facility_id'] ?? null;
if (!$facility_id) {
    header('Location: index.php');
    exit();
}
$stmt = $pdo->prepare("
    SELECT f.*, c.name as category_name,
           CASE 
               WHEN f.is_closed_for_event = 1 AND f.closure_end_date >= CURDATE() THEN 1
               ELSE 0
           END as is_currently_closed,
           f.closure_reason,
           f.closure_end_date
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE f.id = ? AND f.is_active = 1
");
$stmt->execute([$facility_id]);
$facility = $stmt->fetch();
if (!$facility) {
    header('Location: index.php');
    exit();
}

// Get pricing options for this facility
try {
    $stmt = $pdo->prepare("
        SELECT * FROM facility_pricing_options 
        WHERE facility_id = ? AND is_active = 1 
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([$facility_id]);
    $pricing_options = $stmt->fetchAll();
} catch (PDOException $e) {
    // If tables don't exist yet, set empty array
    $pricing_options = [];
}
// Get reservations for this facility
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name as user_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.facility_id = ? 
    AND r.status IN ('pending', 'confirmed')
    AND r.start_time >= CURDATE()
    ORDER BY r.start_time ASC
");
$stmt->execute([$facility_id]);
$reservations = $stmt->fetchAll();
// Get date filter with validation to prevent past dates
$today = date('Y-m-d');
$requested_date = $_GET['date'] ?? $today;

// Validate that the requested date is not in the past
if ($requested_date < $today) {
    $selected_date = $today;
} else {
    $selected_date = $requested_date;
}

$selected_date_obj = new DateTime($selected_date);
// Filter reservations for selected date
$filtered_reservations = array_filter($reservations, function($reservation) use ($selected_date) {
    return date('Y-m-d', strtotime($reservation['start_time'])) === $selected_date;
});
// Generate time slots for the day (8 AM to 9:30 PM - closing at 10 PM)
$time_slots = [];
$start_hour = 8;
$end_hour = 21; // End at 9 PM to allow 30 min slots
for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
    $time_slots[] = sprintf('%02d:00', $hour);
    $time_slots[] = sprintf('%02d:30', $hour);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($facility['name']); ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
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
        .time-slot-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .time-slot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .time-slot-card:hover::before {
            left: 100%;
        }
        .time-slot-card:hover {
            transform: translateY(-4px);
        }
        
        /* Enhanced time slot animations */
        .time-slot-card.available {
            animation: pulse-available 2s infinite;
        }
        
        @keyframes pulse-available {
            0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
        }
        
        /* Enhanced button hover effects */
        .booking-button {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .booking-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }
        
        .booking-button:hover::before {
            width: 300px;
            height: 300px;
        }
        .availability-indicator {
            position: relative;
            overflow: hidden;
        }
        .availability-indicator::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shimmer 2s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            animation: bounce-in 0.8s ease-out;
        }
        .image-gallery {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .image-gallery img {
            transition: transform 0.5s ease;
        }
        .image-gallery:hover img {
            transform: scale(1.05);
        }
        .image-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.4), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .image-gallery:hover .image-overlay {
            opacity: 1;
        }
        .quick-date-nav {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 transparent;
        }
        .quick-date-nav::-webkit-scrollbar {
            height: 4px;
        }
        .quick-date-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .quick-date-nav::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 2px;
        }
        .quick-date-nav::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
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
            <a href="facilities.php" class="sidebar-nav-item active">
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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb Navigation -->
        <nav class="mb-8 animate-fade-in">
            <ol class="flex items-center space-x-2 text-sm text-gray-600">
                <li>
                    <a href="index.php" class="hover:text-blue-600 transition-colors duration-200">
                        <i class="fas fa-home mr-1"></i>Home
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li>
                    <a href="facilities.php" class="hover:text-blue-600 transition-colors duration-200">
                        Facilities
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li class="text-blue-600 font-medium">
                    <?php echo htmlspecialchars($facility['name']); ?>
                </li>
            </ol>
        </nav>
        
        <!-- Operating Hours Info Banner -->
        <div class="mb-6 animate-fade-in">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-blue-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-blue-800">Operating Hours</h3>
                            <p class="text-xs text-blue-600">8:00 AM - 10:00 PM • Last booking ends at 9:30 PM</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-blue-500">
                            <span class="font-medium">Today:</span><br>
                            <?php echo $selected_date_obj->format('l, F j'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Facility Details Card -->
        <div class="enhanced-card card-hover p-8 mb-8 animate-slide-up">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Facility Image Section -->
                <div class="lg:col-span-2">
                    <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                        <div class="image-gallery">
                            <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                 class="w-full h-80 lg:h-96 object-cover">
                            <div class="image-overlay"></div>
                            <div class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                <button onclick="viewFullImage('<?php echo htmlspecialchars($facility['image_url']); ?>', '<?php echo htmlspecialchars($facility['name']); ?>')" 
                                        class="bg-white/90 hover:bg-white text-gray-800 px-4 py-2 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-expand-arrows-alt mr-2"></i>View Full
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-gray-400 to-gray-600 h-80 lg:h-96 flex items-center justify-center">
                            <div class="text-center text-white">
                                <i class="fas fa-image text-6xl lg:text-8xl mb-4 opacity-50"></i>
                                <p class="text-xl font-medium">No Image Available</p>
                                <p class="text-sm opacity-75 mt-2">Contact admin to add facility images</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Facility Info Section -->
                <div class="space-y-6">
                    <div class="text-center lg:text-left">
                        <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-2">
                            <?php echo htmlspecialchars($facility['name']); ?>
                        </h1>
                        <div class="flex items-center justify-center lg:justify-start space-x-2 mb-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-tag mr-1"></i>
                                <?php echo htmlspecialchars($facility['category_name']); ?>
                            </span>
                            <?php if ($facility['is_currently_closed']): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-1"></i>
                                    Closed for Event
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Available
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Closure Notice -->
                        <?php if ($facility['is_currently_closed']): ?>
                            <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-1"></i>
                                    <div>
                                        <h4 class="text-lg font-semibold text-red-800 mb-2">Facility Closed for Event</h4>
                                        <p class="text-red-700 mb-2">
                                            <strong>Event:</strong> <?php echo htmlspecialchars($facility['closure_reason']); ?>
                                        </p>
                                        <?php if ($facility['closure_end_date']): ?>
                                            <p class="text-red-700">
                                                <strong>Reopens:</strong> <?php echo date('l, F j, Y', strtotime($facility['closure_end_date'])); ?>
                                            </p>
                                        <?php endif; ?>
                                        <p class="text-sm text-red-600 mt-2">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            This facility is temporarily unavailable for bookings due to a scheduled event.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Pricing Options & Selector -->
                    <div class="space-y-4">
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-4 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm opacity-90">Pricing Options</p>
                                    <?php if (!empty($pricing_options)): ?>
                                        <div class="space-y-1">
                                            <?php foreach (array_slice($pricing_options, 0, 3) as $option): ?>
                                                <p class="text-lg font-semibold"><?php echo htmlspecialchars($option['name']); ?>: ₱<?php echo number_format($option['price_per_hour'], 0); ?>/hr</p>
                                            <?php endforeach; ?>
                                            <?php if (count($pricing_options) > 3): ?>
                                                <p class="text-sm opacity-75">+<?php echo count($pricing_options) - 3; ?> more options</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-2xl font-bold">₱<?php echo number_format($facility['hourly_rate'], 2); ?></p>
                                    <?php endif; ?>
                                </div>
                                <i class="fas fa-tag text-3xl opacity-80"></i>
                            </div>
                        </div>

                        <?php if (!empty($pricing_options)): ?>
                        <div class="bg-white rounded-xl border border-purple-200 p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-semibold text-gray-800 flex items-center"><i class="fas fa-tags mr-2 text-purple-600"></i>Select a Pricing Package</h4>
                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-full font-semibold"><?php echo count($pricing_options); ?> packages</span>
                            </div>
                            <div class="grid grid-cols-1 gap-2" id="pricingOptionsList">
                                <?php foreach ($pricing_options as $idx => $po): ?>
                                <label class="flex items-center justify-between p-3 rounded-lg border <?php echo $idx === 0 ? 'border-purple-400 bg-purple-50' : 'border-gray-200 hover:bg-gray-50'; ?> transition-colors">
                                    <div class="flex items-center">
                                        <input type="radio" name="selected_pricing_option" value="<?php echo $po['id']; ?>" <?php echo $idx === 0 ? 'checked' : ''; ?> class="mr-3">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($po['name']); ?></div>
                                            <?php if (!empty($po['description'])): ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($po['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-sm font-bold text-purple-700">₱<?php echo number_format($po['price_per_hour'], 2); ?>/hr</div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <a id="bookWithPackageBtn" href="#" class="w-full block text-center bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white px-4 py-3 rounded-lg font-semibold transition-all duration-300">
                                    <i class="fas fa-calendar-plus mr-2"></i>Book with Selected Package
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Enhanced Facility Details -->
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Capacity</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo $facility['capacity']; ?> people</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Operating Hours</p>
                                <p class="font-bold text-gray-900 text-lg">8:00 AM - 10:00 PM</p>
                                <p class="text-xs text-gray-500">Last booking ends at 9:30 PM</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg border border-purple-200">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar-check text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Booking Policy</p>
                                <p class="font-bold text-gray-900 text-lg">30 min minimum</p>
                                <p class="text-xs text-gray-500">Up to 12 hours max</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg border border-orange-200">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-info-circle text-orange-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Status</p>
                                <?php if ($facility['is_currently_closed']): ?>
                                    <p class="font-bold text-red-600 text-lg">Closed for Event</p>
                                    <p class="text-xs text-red-500">Temporarily unavailable</p>
                                <?php else: ?>
                                    <p class="font-bold text-gray-900 text-lg">Available for booking</p>
                                    <p class="text-xs text-gray-500">Real-time availability</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Description -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-900 mb-2">Description</h3>
                        <p class="text-gray-600 leading-relaxed">
                            <?php echo htmlspecialchars($facility['description']); ?>
                        </p>
                    </div>
                    <?php
                    // Calculate basic availability for current day check
                    $is_current_day = $selected_date === date('Y-m-d');
                    $is_fully_booked = false;
                    // Check if current day is fully booked by looking at filtered reservations
                    if ($is_current_day) {
                        $total_booked_hours_today = 0;
                        foreach ($filtered_reservations as $reservation) {
                            $start_time = strtotime($reservation['start_time']);
                            $end_time = strtotime($reservation['end_time']);
                            $total_booked_hours_today += ($end_time - $start_time) / 3600;
                        }
                        // If booked hours >= 13.5 (8 AM to 9:30 PM), consider it fully booked
                        $is_fully_booked = $total_booked_hours_today >= 13.5;
                    }
                    $is_current_day_fully_booked = $is_current_day && $is_fully_booked;
                    ?>
                    <!-- Book Now Button -->
                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                    <div class="pt-4">
                        <?php if ($facility['is_currently_closed']): ?>
                            <div class="w-full bg-gradient-to-r from-red-400 to-red-500 text-white px-6 py-4 rounded-xl font-semibold inline-flex items-center justify-center shadow-lg cursor-not-allowed opacity-75">
                                <i class="fas fa-calendar-times mr-3 text-lg"></i>
                                Closed for Event
                            </div>
                            <div class="mt-3 text-center">
                                <p class="text-sm text-gray-600">
                                    <strong>Event:</strong> <?php echo htmlspecialchars($facility['closure_reason']); ?>
                                </p>
                                <?php if ($facility['closure_end_date']): ?>
                                    <p class="text-sm text-gray-600">
                                        <strong>Reopens:</strong> <?php echo date('l, F j, Y', strtotime($facility['closure_end_date'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($is_current_day_fully_booked): ?>
                            <div class="w-full bg-gradient-to-r from-gray-400 to-gray-500 text-white px-6 py-4 rounded-xl font-semibold inline-flex items-center justify-center shadow-lg cursor-not-allowed opacity-75">
                                <i class="fas fa-calendar-times mr-3 text-lg"></i>
                                Today is Fully Booked
                            </div>
                            <div class="mt-3 text-center">
                                <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium transition-colors duration-200">
                                    <i class="fas fa-calendar-alt mr-1"></i>Check Tomorrow's Availability
                                </a>
                            </div>
                        <?php else: ?>
                            <a id="primaryBookBtn" href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                               class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-4 rounded-xl font-semibold transition-all duration-300 inline-flex items-center justify-center shadow-lg hover:shadow-xl transform hover:scale-105">
                                <i class="fas fa-calendar-plus mr-3 text-lg"></i>
                                Book This Facility
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Current Day Fully Booked Warning -->
        <?php if ($is_current_day_fully_booked): ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-red-100">
                <h3 class="text-lg font-semibold text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Today is Fully Booked
                </h3>
            </div>
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-calendar-times text-red-500 text-2xl"></i>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-gray-800 mb-2">No Available Slots Today</h4>
                            <p class="text-gray-600 mb-2">This facility is completely booked for today (<?php echo date('l, F j, Y'); ?>).</p>
                            <p class="text-sm text-gray-500">All time slots have been reserved by other users.</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                           class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium transition-colors duration-200 inline-flex items-center">
                            <i class="fas fa-calendar-alt mr-2"></i>Check Tomorrow
                        </a>
                    </div>
                </div>
                <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <h5 class="font-semibold text-blue-800 mb-2">Alternative Options:</h5>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Check availability for tomorrow or future dates</li>
                                <li>• Consider booking a different facility</li>
                                <li>• Contact the administrator for special arrangements</li>
                                <li>• Set up notifications for cancellations</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- Booking Information Guide -->
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-indigo-100">
                <h3 class="text-lg font-semibold text-indigo-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    How to Book This Facility
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="text-center">
                        <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-calendar-alt text-indigo-600 text-xl"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 mb-2">1. Select Date</h4>
                        <p class="text-sm text-gray-600">Choose your preferred date using the calendar navigation</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-clock text-green-600 text-xl"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 mb-2">2. Choose Time</h4>
                        <p class="text-sm text-gray-600">Pick from available time ranges or individual slots</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-bolt text-blue-600 text-xl"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 mb-2">3. Quick Book</h4>
                        <p class="text-sm text-gray-600">Use quick booking options for common durations</p>
                    </div>
                    <div class="text-center">
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-credit-card text-purple-600 text-xl"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 mb-2">4. Complete Booking</h4>
                        <p class="text-sm text-gray-600">Fill in details and upload payment slip</p>
                    </div>
                </div>
                <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-lightbulb text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <h4 class="font-semibold text-blue-800 mb-2">Pro Tips:</h4>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Bookings ending at 8:00 PM still allow for later bookings (8:00 PM onwards)</li>
                                <li>• You can book multiple time slots in one reservation</li>
                                <li>• Payment is required within 24 hours of booking</li>
                                <li>• Cancellations are allowed for pending and confirmed reservations</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Enhanced Date Navigation -->
        <div class="enhanced-card p-6 mb-8 animate-slide-up">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-6">
                <div class="mb-4 lg:mb-0">
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Availability Calendar</h2>
                    <p class="text-gray-600">Check real-time availability and book your preferred time slot</p>
                </div>
                <div class="flex items-center space-x-4">
                    <?php 
                    $previous_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
                    $is_previous_disabled = $previous_date < $today;
                    ?>
                    <?php if ($is_previous_disabled): ?>
                        <span class="btn-enhanced btn-secondary-enhanced opacity-50 cursor-not-allowed">
                            <i class="fas fa-chevron-left mr-2"></i>
                            Previous
                        </span>
                    <?php else: ?>
                        <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo $previous_date; ?>" 
                           class="btn-enhanced btn-secondary-enhanced group">
                            <i class="fas fa-chevron-left mr-2 group-hover:-translate-x-1 transition-transform duration-200"></i>
                            Previous
                        </a>
                    <?php endif; ?>
                    <div class="bg-white rounded-lg px-4 py-2 shadow-sm border">
                        <span class="text-lg font-semibold text-gray-800">
                            <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                        </span>
                    </div>
                    <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?>" 
                       class="btn-enhanced btn-secondary-enhanced group">
                        Next
                        <i class="fas fa-chevron-right ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                    </a>
                </div>
            </div>
            <!-- Quick Date Navigation -->
            <div class="quick-date-nav overflow-x-auto">
                <div class="flex space-x-2 min-w-max">
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <?php $date = date('Y-m-d', strtotime("+$i days")); ?>
                        <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo $date; ?>" 
                           class="px-4 py-2 rounded-lg transition-all duration-200 whitespace-nowrap <?php echo $date === $selected_date ? 'bg-blue-500 text-white shadow-lg' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 hover:shadow-md'; ?>">
                            <div class="text-center">
                                <div class="text-xs font-medium"><?php echo date('D', strtotime($date)); ?></div>
                                <div class="text-sm font-bold"><?php echo date('j', strtotime($date)); ?></div>
                            </div>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <!-- Enhanced Availability Summary -->
        <?php
        // Calculate available time ranges with detailed information
        $available_ranges = [];
        $current_start = null;
        $total_available_hours = 0;
        foreach ($time_slots as $time_slot) {
            $slot_start = $selected_date . ' ' . $time_slot . ':00';
            $slot_end = date('Y-m-d H:i:s', strtotime($slot_start . ' +30 minutes'));
            // Check if this slot is booked
            $is_booked = false;
            $conflicting_booking = null;
            foreach ($filtered_reservations as $reservation) {
                $reservation_start = $reservation['start_time'];
                $reservation_end = $reservation['end_time'];
                if (($slot_start >= $reservation_start && $slot_start < $reservation_end) ||
                    ($slot_end > $reservation_start && $slot_end <= $reservation_end) ||
                    ($slot_start <= $reservation_start && $slot_end >= $reservation_end)) {
                    $is_booked = true;
                    $conflicting_booking = $reservation;
                    break;
                }
            }
            if (!$is_booked) {
                if ($current_start === null) {
                    $current_start = $time_slot;
                }
            } else {
                if ($current_start !== null) {
                    $range_start_time = strtotime($current_start);
                    $range_end_time = strtotime($time_slot);
                    $range_hours = ($range_end_time - $range_start_time) / 3600;
                    $total_available_hours += $range_hours;
                    $available_ranges[] = [
                        'start' => $current_start,
                        'end' => $time_slot,
                        'hours' => $range_hours,
                        'slots' => $range_hours * 2, // 30-minute slots
                        'max_booking_hours' => min($range_hours, 12) // Cap at 12 hours max
                    ];
                    $current_start = null;
                }
            }
        }
        // Add the last range if it ends at the last time slot
        if ($current_start !== null) {
            $range_start_time = strtotime($current_start);
            $range_end_time = strtotime('21:30'); // Last slot is 9:30 PM
            $range_hours = ($range_end_time - $range_start_time) / 3600;
            $total_available_hours += $range_hours;
            $available_ranges[] = [
                'start' => $current_start,
                'end' => '21:30', // Last slot is 9:30 PM
                'hours' => $range_hours,
                'slots' => $range_hours * 2, // 30-minute slots
                'max_booking_hours' => min($range_hours, 12) // Cap at 12 hours max
            ];
        }
        // Calculate booking statistics
        $total_booked_hours = 0;
        foreach ($filtered_reservations as $reservation) {
            $start_time = strtotime($reservation['start_time']);
            $end_time = strtotime($reservation['end_time']);
            $total_booked_hours += ($end_time - $start_time) / 3600;
        }
        $total_day_hours = 13.5; // 8 AM to 9:30 PM (13.5 hours)
        $utilization_percentage = round(($total_booked_hours / $total_day_hours) * 100, 1);
        ?>
        <!-- Availability Statistics -->
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-blue-100">
                <h3 class="text-lg font-semibold text-blue-800">
                    <i class="fas fa-chart-bar mr-2"></i>
                    Availability Statistics for <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-blue-800"><?php echo count($available_ranges); ?></div>
                        <div class="text-sm text-blue-600">Available Ranges</div>
                    </div>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-green-800"><?php echo number_format($total_available_hours, 1); ?></div>
                        <div class="text-sm text-green-600">Available Hours</div>
                    </div>
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-orange-800"><?php echo number_format($total_booked_hours, 1); ?></div>
                        <div class="text-sm text-orange-600">Booked Hours</div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-purple-800"><?php echo $utilization_percentage; ?>%</div>
                        <div class="text-sm text-purple-600">Utilization</div>
                    </div>
                </div>
                <!-- Progress Bar -->
                <div class="mb-4">
                    <div class="flex justify-between text-sm text-gray-600 mb-2">
                        <span>Facility Utilization</span>
                        <span><?php echo $utilization_percentage; ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3">
                        <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-3 rounded-full transition-all duration-500" 
                             style="width: <?php echo $utilization_percentage; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($available_ranges)): ?>
        
        <?php else: ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-red-100">
                <h3 class="text-lg font-semibold text-red-800">
                    <i class="fas fa-ban mr-2"></i>
                    No Available Time Slots
                </h3>
                <p class="text-sm text-red-600 mt-1">This facility is fully booked for <?php echo $selected_date_obj->format('l, F j, Y'); ?></p>
            </div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-calendar-times text-red-500 text-3xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Fully Booked</h4>
                <p class="text-gray-600 mb-4">All time slots for this date have been reserved.</p>
                <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?>" 
                   class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200">
                    <i class="fas fa-calendar-alt mr-2"></i>Check Next Day
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Current Bookings Summary -->
        <?php if (!empty($filtered_reservations)): ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-orange-50 to-orange-100">
                <h3 class="text-lg font-semibold text-orange-800">
                    <i class="fas fa-calendar-check mr-2"></i>
                    Current Bookings for <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                </h3>
                <p class="text-sm text-orange-600 mt-1">These time slots are already reserved</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($filtered_reservations as $index => $reservation): ?>
                    <div class="bg-gradient-to-br from-orange-50 to-red-50 border border-orange-200 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-user text-orange-600 text-lg"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-bold text-orange-800">
                                        Booking <?php echo $index + 1; ?>
                                    </h4>
                                    <p class="text-sm text-orange-600"><?php echo htmlspecialchars($reservation['user_name']); ?></p>
                                </div>
                            </div>
                            <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full 
                                <?php echo $reservation['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst($reservation['status']); ?>
                            </span>
                        </div>
                        <div class="space-y-3 mb-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Time Range:</span>
                                <span class="font-semibold text-gray-800">
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Duration:</span>
                                <span class="font-semibold text-gray-800">
                                    <?php 
                                    $start_time = strtotime($reservation['start_time']);
                                    $end_time = strtotime($reservation['end_time']);
                                    $duration_hours = ($end_time - $start_time) / 3600;
                                    echo number_format($duration_hours, 1) . ' hours';
                                    ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Purpose:</span>
                                <span class="font-semibold text-gray-800 text-xs"><?php echo htmlspecialchars(substr($reservation['purpose'], 0, 30)) . (strlen($reservation['purpose']) > 30 ? '...' : ''); ?></span>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="text-xs text-gray-600 mb-2">Booking Details:</div>
                            <div class="text-xs text-gray-700">
                                <div class="flex justify-between">
                                    <span>Booking Type:</span>
                                    <span class="font-medium"><?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Created:</span>
                                    <span class="font-medium"><?php echo date('M j, Y g:i A', strtotime($reservation['created_at'])); ?></span>
                                </div>
                                <?php if ($reservation['payment_status']): ?>
                                <div class="flex justify-between">
                                    <span>Payment:</span>
                                    <span class="font-medium <?php echo $reservation['payment_status'] === 'paid' ? 'text-green-600' : 'text-orange-600'; ?>">
                                        <?php echo ucfirst($reservation['payment_status']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- Enhanced Time Slots Grid -->
        <div class="enhanced-card overflow-hidden animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-blue-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-semibold text-blue-900">
                            <i class="fas fa-clock mr-2 text-blue-600"></i>
                            Detailed Time Slots for <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                        </h3>
                        <p class="text-sm text-blue-600 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Operating hours: 8:00 AM - 10:00 PM • Last booking ends at 9:30 PM
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-blue-600">
                            <span class="font-semibold"><?php echo count($time_slots); ?></span> time slots available
                        </div>
                        <div class="text-xs text-blue-500">30-minute intervals</div>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 p-6">
                <?php foreach ($time_slots as $time_slot): ?>
                    <?php
                    $slot_start = $selected_date . ' ' . $time_slot . ':00';
                    $slot_end = date('Y-m-d H:i:s', strtotime($slot_start . ' +30 minutes'));
                    // Check if this slot is booked
                    $is_booked = false;
                    $booking_info = null;
                    foreach ($filtered_reservations as $reservation) {
                        $reservation_start = $reservation['start_time'];
                        $reservation_end = $reservation['end_time'];
                        // Check if the slot overlaps with any reservation
                        if (($slot_start >= $reservation_start && $slot_start < $reservation_end) ||
                            ($slot_end > $reservation_start && $slot_end <= $reservation_end) ||
                            ($slot_start <= $reservation_start && $slot_end >= $reservation_end)) {
                            $is_booked = true;
                            $booking_info = $reservation;
                            break;
                        }
                    }
                    ?>
                    <div class="time-slot-card border-2 rounded-xl p-4 transition-all duration-300 transform hover:scale-105 <?php echo $is_booked ? 'bg-red-50 border-red-300 shadow-sm' : 'bg-gradient-to-br from-green-50 to-emerald-50 border-green-300 hover:border-green-400 hover:shadow-md'; ?>">
                        <div class="flex items-center justify-between mb-4">
                            <div class="text-center">
                                <span class="text-xl font-bold <?php echo $is_booked ? 'text-red-800' : 'text-green-800'; ?>">
                                    <?php echo date('g:i A', strtotime($time_slot)); ?>
                                </span>
                                <div class="text-xs <?php echo $is_booked ? 'text-red-600' : 'text-green-600'; ?> font-medium">
                                    <i class="fas fa-clock mr-1"></i>30 min slot
                                </div>
                            </div>
                            <?php if ($is_booked): ?>
                                <div class="availability-indicator">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 border border-red-200">
                                        <i class="fas fa-times mr-1"></i>Booked
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="availability-indicator">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">
                                        <i class="fas fa-check mr-1"></i>Available
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_booked && $booking_info): ?>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-user text-gray-400"></i>
                                    <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($booking_info['user_name']); ?></span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-calendar-alt text-gray-400"></i>
                                    <span class="text-gray-700"><?php echo htmlspecialchars($booking_info['purpose']); ?></span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-clock text-gray-400"></i>
                                    <span class="text-gray-700">
                                        <?php echo date('g:i A', strtotime($booking_info['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($booking_info['end_time'])); ?>
                                    </span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-info-circle text-gray-400"></i>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                        <?php echo $booking_info['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($booking_info['status']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-calendar-plus text-2xl text-green-600"></i>
                                </div>
                                <p class="text-sm font-medium text-green-700 mb-3">Available for booking</p>
                                <!-- Quick booking options for this slot -->
                                <div class="space-y-2">
                                    <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>&start_time=<?php echo $slot_start; ?>&end_time=<?php echo $slot_end; ?>&booking_type=hourly" 
                                       class="block w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white text-center py-2 rounded-lg text-xs font-medium transition-all duration-200 transform hover:scale-105 shadow-sm booking-button">
                                        <i class="fas fa-bolt mr-1"></i>Quick Book (30 min)
                                    </a>
                                    <?php
                                    // Check if we can offer longer booking options
                                    $slot_time = strtotime($time_slot);
                                    $end_of_day = strtotime('21:30'); // Last slot is 9:30 PM
                                    $remaining_hours = ($end_of_day - $slot_time) / 3600;
                                    if ($remaining_hours >= 1) {
                                        $one_hour_end = date('Y-m-d H:i:s', strtotime($slot_start . ' +1 hour'));
                                        echo '<a href="reservation.php?facility_id=' . $facility['id'] . 
                                             '&start_time=' . $slot_start . 
                                             '&end_time=' . $one_hour_end . 
                                             '&booking_type=hourly" 
                                             class="block w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white text-center py-2 rounded-lg text-xs font-medium transition-all duration-200 transform hover:scale-105 shadow-sm booking-button">
                                            <i class="fas fa-clock mr-1"></i>Book 1 Hour
                                        </a>';
                                    }
                                    if ($remaining_hours >= 2) {
                                        $two_hour_end = date('Y-m-d H:i:s', strtotime($slot_start . ' +2 hours'));
                                        echo '<a href="reservation.php?facility_id=' . $facility['id'] . 
                                             '&start_time=' . $slot_start . 
                                             '&end_time=' . $two_hour_end . 
                                             '&booking_type=hourly" 
                                             class="block w-full bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white text-center py-2 rounded-lg text-xs font-medium transition-all duration-200 transform hover:scale-105 shadow-sm booking-button">
                                            <i class="fas fa-clock mr-1"></i>Book 2 Hours
                                        </a>';
                                    }
                                    ?>
                                    <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>&start_time=<?php echo $slot_start; ?>&end_time=<?php echo $slot_end; ?>&booking_type=hourly" 
                                       class="block w-full bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white text-center py-2 rounded-lg text-xs font-medium transition-all duration-200 transform hover:scale-105 shadow-sm booking-button">
                                        <i class="fas fa-cog mr-1"></i>Custom Time
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Enhanced Legend -->
        <div class="enhanced-card p-6 mt-8 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-info-circle mr-2 text-blue-600"></i>
                    Understanding Time Slots
                </h3>
                <p class="text-sm text-gray-600 mt-1">Learn how to read the availability indicators</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex items-center p-4 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl border border-green-200">
                        <div class="w-5 h-5 bg-green-500 rounded-full mr-4 shadow-sm"></div>
                        <div>
                            <span class="text-sm font-semibold text-green-800">Available</span>
                            <p class="text-xs text-green-600">Ready to book • Click to reserve</p>
                        </div>
                    </div>
                    <div class="flex items-center p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-xl border border-red-200">
                        <div class="w-5 h-5 bg-red-500 rounded-full mr-4 shadow-sm"></div>
                        <div>
                            <span class="text-sm font-semibold text-red-800">Booked</span>
                            <p class="text-xs text-red-600">Not available • Reserved by others</p>
                        </div>
                    </div>
                    <div class="flex items-center p-4 bg-gradient-to-r from-yellow-50 to-amber-100 rounded-xl border border-yellow-200">
                        <div class="w-5 h-5 bg-yellow-500 rounded-full mr-4 shadow-sm"></div>
                        <div>
                            <span class="text-sm font-semibold text-yellow-800">Pending</span>
                            <p class="text-xs text-yellow-600">Awaiting confirmation</p>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Information -->
                <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-lightbulb text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-sm font-semibold text-blue-800 mb-2">Booking Tips</h4>
                            <ul class="text-xs text-blue-700 space-y-1">
                                <li>• Time slots are in 30-minute intervals</li>
                                <li>• You can book multiple consecutive slots</li>
                                <li>• Last booking ends at 9:30 PM (facility closes at 10:00 PM)</li>
                                <li>• Quick booking options available for common durations</li>
                            </ul>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Floating Action Button -->
    <?php if ($_SESSION['role'] !== 'admin' && !$facility['is_currently_closed']): ?>
    <div class="floating-action">
        <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
           class="w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:scale-110">
            <i class="fas fa-calendar-plus text-xl"></i>
        </a>
    </div>
    <?php endif; ?>
    <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
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
            // Add smooth scrolling to all links
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
            // Add intersection observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            // Observe all cards for animation
            document.querySelectorAll('.enhanced-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });

            // Pricing selection → wire to Book buttons
            const bookWithPackageBtn = document.getElementById('bookWithPackageBtn');
            const primaryBookBtn = document.getElementById('primaryBookBtn');
            const radios = document.querySelectorAll('input[name="selected_pricing_option"]');

            function getSelectedPricingId() {
                const checked = document.querySelector('input[name="selected_pricing_option"]:checked');
                return checked ? checked.value : null;
            }

            function appendPricingToHref(anchor) {
                if (!anchor) return;
                const poId = getSelectedPricingId();
                if (!poId) return;
                const url = new URL(anchor.href, window.location.origin);
                url.searchParams.set('pricing_option_id', poId);
                anchor.href = url.toString();
            }

            if (bookWithPackageBtn) {
                bookWithPackageBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const poId = getSelectedPricingId();
                    if (!poId) {
                        alert('Please select a pricing package first.');
                        return;
                    }
                    const baseUrl = 'reservation.php?facility_id=<?php echo $facility['id']; ?>';
                    window.location.href = baseUrl + '&pricing_option_id=' + encodeURIComponent(poId);
                });
            }

            if (primaryBookBtn) {
                primaryBookBtn.addEventListener('click', function() {
                    appendPricingToHref(primaryBookBtn);
                });
            }

            // Also augment any quick booking links to carry pricing_option_id if selected
            const allBookingLinks = document.querySelectorAll('a[href^="reservation.php?facility_id="]');
            allBookingLinks.forEach(a => {
                a.addEventListener('click', function() { appendPricingToHref(a); });
            });
        });
        // Enhanced facility image viewing function
        function viewFullImage(imageUrl, facilityName) {
            ModalSystem.show({
                title: facilityName + ' - Full Image View',
                content: `
                    <div class="text-center">
                        <div class="loading-enhanced mb-4">
                            <div class="spinner-enhanced spinner-large-enhanced"></div>
                            <p class="text-gray-600 mt-2">Loading image...</p>
                        </div>
                        <img src="${imageUrl}" 
                             alt="${facilityName}" 
                             class="w-full max-w-4xl mx-auto rounded-lg shadow-lg object-contain hidden"
                             style="max-height: 80vh;"
                             onload="this.classList.remove('hidden'); this.previousElementSibling.style.display='none';">
                        <div class="mt-4 text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            Click outside or press Escape to close
                        </div>
                    </div>
                `,
                size: 'extra-large',
                showCloseButton: true,
                closeOnOverlayClick: true,
                closeOnEscape: true
            });
        }
        // Add smooth hover effects to time slot cards
        document.addEventListener('DOMContentLoaded', function() {
            const timeSlotCards = document.querySelectorAll('.time-slot-card');
            timeSlotCards.forEach(card => {
                // Add 'available' class to available slots for pulse animation
                if (card.querySelector('.bg-green-100')) {
                    card.classList.add('available');
                }
                
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again.');
        }
    </script>
</body>
</html>
