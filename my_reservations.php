<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';
require_once 'classes/ReservationManager.php';
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
// Get user's reservations
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, c.name as category_name
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    WHERE r.user_id = ?
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
    <title>My Reservations - <?php echo SITE_NAME; ?></title>
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
        
        /* Enhanced Navigation */
        .nav-bar {
            background: #1e40af !important;
            border-bottom: 1px solid #1d4ed8 !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 100 !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .nav-container {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 0 2rem !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            height: 80px !important;
        }
        
        .nav-title {
            font-family: 'Inter', sans-serif !important;
            font-weight: 800 !important;
            color: white !important;
            font-size: 1.5rem !important;
        }
        
        .nav-user-name {
            font-family: 'Inter', sans-serif !important;
            font-weight: 600 !important;
            color: white !important;
        }
        
        /* Unified Navigation Buttons */
        .nav-btn {
            background: rgba(255, 255, 255, 0.15) !important;
            color: white !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            padding: 0.75rem 1.5rem !important;
            border-radius: 0.5rem !important;
            font-family: 'Inter', sans-serif !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            transition: all 0.3s ease !important;
            min-width: 140px !important;
            justify-content: center !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }
        
        .nav-btn.logout-btn {
            background: rgba(220, 38, 38, 0.8) !important;
            border: 1px solid rgba(220, 38, 38, 0.9) !important;
        }
        
        .nav-btn.logout-btn:hover {
            background: rgba(220, 38, 38, 1) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3) !important;
        }
        
        .nav-user-info {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            background: rgba(255, 255, 255, 0.1) !important;
            padding: 0.75rem 1rem !important;
            border-radius: 0.5rem !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-user-icon {
            color: white !important;
            font-size: 1rem !important;
        }
        
        /* Navigation Menu Visibility */
        .nav-menu {
            display: flex !important;
            align-items: center !important;
            gap: 1rem !important;
        }
        
        .nav-menu-mobile {
            display: none !important;
        }
        
        @media (max-width: 768px) {
            .nav-menu {
                display: none !important;
            }
            
            .nav-menu-mobile {
                display: block !important;
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
        }
        .reservation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .reservation-card:hover::before {
            left: 100%;
        }
        .reservation-card:hover {
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
        .reservation-row {
            transition: all 0.3s ease;
        }
        .reservation-row:hover {
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
        /* Card-only layout for all screen sizes */
        .reservation-table {
            display: none !important;
        }
        .reservation-cards {
            display: block !important;
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

    <!-- Enhanced Navigation -->
    <nav class="nav-bar">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="nav-logo">
                    <i class="fas fa-building text-white"></i>
                </div>
                <h1 class="nav-title"><?php echo SITE_NAME; ?></h1>
            </div>
            
            <!-- Desktop Navigation -->
            <div class="nav-menu">
                <div class="nav-user-info">
                    <i class="fas fa-user nav-user-icon"></i>
                    <span class="nav-user-name">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
                <a href="index.php" class="nav-btn">
                    <span>Home</span>
                </a>
                <a href="facilities.php" class="nav-btn">
                    <span>Facilities</span>
                </a>
                <a href="my_reservations.php" class="nav-btn">
                            <span>My Reservations</span>
                        </a>
                <a href="auth/logout.php" class="nav-btn logout-btn" onclick="return confirmLogout()">
                    <span>Logout</span>
                </a>
            </div>
            
            <!-- Enhanced Mobile menu button -->
            <div class="nav-menu-mobile">
                <button id="mobile-menu-button" class="mobile-menu-btn" aria-label="Toggle mobile menu">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
            </div>
        </div>
            <!-- Mobile Navigation -->
        <div id="mobile-menu" class="hidden mobile-menu">
            <div class="container" style="padding: 1rem 0;">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="color: white; padding: 0.75rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; font-weight: 500; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-user" style="margin-right: 0.5rem;"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </div>
                    <a href="index.php" class="nav-btn" style="display: block; text-align: center;">
                        Home
                    </a>
                    <a href="facilities.php" class="nav-btn" style="display: block; text-align: center;">
                        Facilities
                    </a>
                    <a href="auth/logout.php" class="nav-btn logout-btn" style="display: block; text-align: center;" onclick="return confirmLogout()">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 py-8">
       
       
        <!-- Quick Overview Section -->
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
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
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
        
       
        
        <!-- Simplified Filter Tabs -->
        <div class="bg-white rounded-2xl p-6 mb-8 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
            <h3 class="text-xl font-bold text-gray-800 mb-4 text-center">Filter Reservations</h3>
            
            <div class="flex flex-wrap justify-center gap-3">
                <button class="filter-tab active px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-blue-500 text-white shadow-lg" data-filter="all">
                    All Reservations
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-yellow-100 hover:text-yellow-700" data-filter="pending">
                    Pending
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-green-100 hover:text-green-700" data-filter="confirmed">
                    Confirmed
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-blue-100 hover:text-blue-700" data-filter="completed">
                    Completed
                </button>
                
                <button class="filter-tab px-6 py-3 rounded-xl font-semibold transition-all duration-300 bg-gray-100 text-gray-700 hover:bg-red-100 hover:text-red-700" data-filter="cancelled">
                    Cancelled
                </button>
            </div>
            
            <!-- Filter Results Counter -->
            <div class="mt-4 text-center">
                <div class="inline-flex items-center bg-gray-100 rounded-full px-4 py-2">
                    <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                    <span class="text-gray-700 font-medium" id="filter-results-count">Showing all reservations</span>
                </div>
            </div>
        </div>
        <!-- Waitlist Section -->
        <?php if (!empty($waitlist_entries)): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6 animate-slide-up" style="animation-delay: 0.2s;">
                <h2 class="text-xl font-semibold text-yellow-800 mb-4 flex items-center">
                    <i class="fas fa-hourglass-half mr-2"></i>Waitlist Entries
                </h2>
                <div class="space-y-4">
                    <?php foreach ($waitlist_entries as $entry): ?>
                        <div class="bg-white rounded-lg p-4 border border-yellow-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($entry['facility_name']); ?></h3>
                                    <p class="text-sm text-gray-600">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?php echo date('M j, Y g:i A', strtotime($entry['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($entry['end_time'])); ?>
                                    </p>
                                </div>
                                <form method="POST" class="flex items-center space-x-2">
                                    <input type="hidden" name="action" value="remove_waitlist">
                                    <input type="hidden" name="waitlist_id" value="<?php echo $entry['id']; ?>">
                                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition duration-200 transform hover:scale-105">
                                        <i class="fas fa-times mr-1"></i>Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <!-- Reservation Cards -->
        <div class="space-y-6">
            <?php foreach ($reservations as $index => $reservation): ?>
                <div class="reservation-card bg-white rounded-3xl shadow-xl p-8 animate-slide-up border border-gray-100 hover:shadow-2xl transition-all duration-300 transform hover:scale-105" 
                     data-status="<?php echo $reservation['status']; ?>"
                     style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    
                    <!-- Card Header -->
                    <div class="flex items-start justify-between mb-6">
                        <div class="flex items-center space-x-4">
                            <div class="h-16 w-16 bg-gradient-to-br from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-building text-white text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                <p class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($reservation['category_name']); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center px-4 py-2 rounded-2xl text-sm font-bold <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-800'; ?> shadow-lg">
                                <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-2"></i>
                            <?php echo ucfirst($reservation['status']); ?>
                        </span>
                    </div>
                    </div>
                    <!-- Reservation Details Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl p-6 border border-blue-200">
                            <div class="flex items-center space-x-3 mb-3">
                                <div class="w-10 h-10 bg-blue-500 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-calendar text-white"></i>
                                </div>
                                <h4 class="font-bold text-gray-800">Date & Time</h4>
                            </div>
                            <p class="text-lg font-semibold text-gray-900 mb-1">
                                <?php 
                                $startDate = date('M j, Y', strtotime($reservation['start_time']));
                                $endDate = date('M j, Y', strtotime($reservation['end_time']));
                                if ($startDate === $endDate) {
                                    echo $startDate;
                                } else {
                                    echo $startDate . ' - ' . $endDate;
                                }
                                ?>
                            </p>
                            <p class="text-gray-600 font-medium">
                                <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                            </p>
                        </div>
                        
                        <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-2xl p-6 border border-green-200">
                            <div class="flex items-center space-x-3 mb-3">
                                <div class="w-10 h-10 bg-green-500 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-money-bill text-white"></i>
                        </div>
                                <h4 class="font-bold text-gray-800">Payment Info</h4>
                            </div>
                            <p class="text-2xl font-bold text-green-600 mb-1">₱<?php echo number_format($reservation['total_amount'], 2); ?></p>
                            <p class="text-gray-600 font-medium">
                                <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> booking
                                (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                            </p>
                        </div>
                        
                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-2xl p-6 border border-purple-200">
                        
                        <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-2xl p-6 border border-orange-200">
                            <div class="flex items-center space-x-3 mb-3">
                                <div class="w-10 h-10 bg-orange-500 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-info-circle text-white"></i>
                                </div>
                                <h4 class="font-bold text-gray-800">Purpose</h4>
                            </div>
                            <p class="text-lg font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($reservation['purpose'] ?: 'General use'); ?></p>
                            <p class="text-gray-600 font-medium">Booking purpose</p>
                        </div>
                    </div>
                    <!-- Enhanced Payment Status Section -->
                    <?php if ($reservation['status'] !== 'cancelled'): ?>
                        <div class="mb-6 p-6 bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl border border-gray-200">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-gray-500 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-credit-card text-white"></i>
                                    </div>
                                    <h4 class="font-bold text-gray-800">Payment Status</h4>
                                </div>
                                <?php if ($reservation['payment_status'] === 'pending'): ?>
                                    <?php if ($reservation['payment_slip_url']): ?>
                                        <span class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-xl font-semibold shadow-lg">
                                            <i class="fas fa-file-upload mr-2"></i>Uploaded
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-4 py-2 bg-orange-100 text-orange-800 rounded-xl font-semibold shadow-lg">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>Required
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                                    <span class="inline-flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-xl font-semibold shadow-lg">
                                        <i class="fas fa-check-circle mr-2"></i>Verified
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($reservation['payment_status'] === 'pending' && $reservation['payment_slip_url']): ?>
                                <div class="flex items-center text-gray-600 font-medium">
                                    <?php if ($reservation['payment_verified_at']): ?>
                                        <i class="fas fa-check-circle text-green-500 mr-2 text-lg"></i>
                                        Payment verified on <?php echo date('M j, Y g:i A', strtotime($reservation['payment_verified_at'])); ?>
                                    <?php else: ?>
                                        <i class="fas fa-clock text-yellow-500 mr-2 text-lg"></i>
                                        Awaiting admin verification
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Enhanced Action Buttons -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="facility_details.php?facility_id=<?php echo $reservation['facility_id']; ?>" 
                           class="group flex items-center justify-center space-x-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-eye text-lg group-hover:scale-110 transition-transform duration-200"></i>
                            <span>View Details</span>
                        </a>
                        
                        <?php if ($reservation['status'] === 'pending'): ?>
                            <?php if ($reservation['payment_slip_url']): ?>
                                <div class="flex items-center justify-center space-x-2 bg-blue-500 text-white px-6 py-4 rounded-2xl font-semibold shadow-lg">
                                    <i class="fas fa-file-upload text-lg"></i>
                                    <span>Uploaded</span>
                                </div>
                            <?php else: ?>
                                <a href="upload_payment.php?reservation_id=<?php echo $reservation['id']; ?>" 
                                   class="group flex items-center justify-center space-x-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                                    <i class="fas fa-upload text-lg group-hover:scale-110 transition-transform duration-200"></i>
                                    <span>Upload Payment</span>
                                </a>
                            <?php endif; ?>
                        <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                            <div class="flex items-center justify-center space-x-2 bg-green-500 text-white px-6 py-4 rounded-2xl font-semibold shadow-lg">
                                <i class="fas fa-check-circle text-lg"></i>
                                <span>Paid</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (in_array($reservation['status'], ['pending', 'confirmed'])): ?>
                            <button onclick="showCancelConfirmation(<?php echo $reservation['id']; ?>, '<?php echo htmlspecialchars($reservation['facility_name']); ?>', '<?php echo date('M j, Y g:i A', strtotime($reservation['start_time'])); ?>')" 
                                    class="group flex items-center justify-center space-x-2 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <i class="fas fa-times text-lg group-hover:scale-110 transition-transform duration-200"></i>
                                <span>Cancel</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Enhanced Empty State -->
        <?php if (empty($reservations)): ?>
            <div class="text-center py-16 animate-fade-in">
                <div class="w-24 h-24 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-calendar-times text-gray-400 text-4xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-800 mb-3">No reservations found</h3>
                <p class="text-gray-600 mb-8 max-w-md mx-auto">You haven't made any reservations yet. Start by browsing our available facilities and book your first reservation.</p>
                <a href="facilities.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl inline-flex items-center">
                    <i class="fas fa-search mr-3"></i>Browse Facilities
                </a>
            </div>
        <?php endif; ?>
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
            // Mobile menu functionality
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    const isHidden = mobileMenu.classList.contains('hidden');
                    if (isHidden) {
                        mobileMenu.classList.remove('hidden');
                        mobileMenuButton.innerHTML = '<i class="fas fa-times text-xl"></i>';
                    } else {
                        mobileMenu.classList.add('hidden');
                        mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                    }
                });
            }
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
                        'all': 'all',
                        'pending': 'pending',
                        'confirmed': 'confirmed',
                        'completed': 'completed',
                        'cancelled': 'cancelled'
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
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const cancelModal = document.getElementById('cancelModal');
                const facilityModal = document.getElementById('facilityModal');
                if (!cancelModal.classList.contains('hidden')) {
                    closeCancelModal();
                }
                if (!facilityModal.classList.contains('hidden')) {
                    closeFacilityModal();
                }
            }
        });
        
        // Close facility modal when clicking outside
        document.getElementById('facilityModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFacilityModal();
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
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again to access your reservations.');
        }
        
        // Facility details modal function
        function showFacilityDetails(facilityId) {
            // Filter reservations for this facility
            const facilityReservations = <?php echo json_encode($reservations); ?>;
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
                        
                        ${reservation.payment_status === 'pending' && reservation.payment_slip_url ? `
                            <div class="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                                <div class="flex items-center text-blue-800">
                                    <i class="fas fa-file-upload mr-2"></i>
                                    <span class="text-sm font-medium">Payment slip uploaded - awaiting verification</span>
                                </div>
                            </div>
                        ` : ''}
                        
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
    </script>
</body>
</html>
