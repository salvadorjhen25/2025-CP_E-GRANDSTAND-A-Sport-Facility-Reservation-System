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
        @media (max-width: 768px) {
            .reservation-table {
                display: none;
            }
            .reservation-cards {
                display: block;
            }
        }
        @media (min-width: 769px) {
            .reservation-cards {
                display: none;
            }
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
    <nav class="glass-effect sticky top-0 z-40 shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center space-x-3 group">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg group-hover:shadow-xl transition-all duration-300">
                            <i class="fas fa-building text-white text-lg"></i>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                                <?php echo SITE_NAME; ?>
                            </h1>
                            <p class="text-xs text-gray-500">Facility Management</p>
                        </div>
                    </a>
                </div>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center space-x-4">
                    <div class="flex items-center space-x-2 bg-white/80 rounded-full px-4 py-2 shadow-sm">
                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                        <span class="text-sm font-medium text-gray-700">
                            Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </span>
                    </div>
                    <a href="index.php" class="btn-enhanced btn-primary-enhanced group">
                        <i class="fas fa-home mr-2 group-hover:scale-110 transition-transform duration-200"></i>
                        Home
                    </a>
                    <a href="auth/logout.php" class="btn-enhanced btn-danger-enhanced group">
                        <i class="fas fa-sign-out-alt mr-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                        Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="p-2 rounded-lg bg-white/80 shadow-sm hover:bg-white transition-colors duration-200" aria-label="Toggle mobile menu">
                        <i class="fas fa-bars text-gray-700 text-lg"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-4 animate-slide-up">
                <div class="space-y-3">
                    <div class="flex items-center space-x-2 bg-white/80 rounded-lg px-4 py-3 shadow-sm">
                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                        <span class="text-sm font-medium text-gray-700">
                            Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </span>
                    </div>
                    <a href="index.php" class="block btn-enhanced btn-primary-enhanced group">
                        <i class="fas fa-home mr-2 group-hover:scale-110 transition-transform duration-200"></i>
                        Home
                    </a>
                    <a href="auth/logout.php" class="block btn-enhanced btn-danger-enhanced group">
                        <i class="fas fa-sign-out-alt mr-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-calendar-alt text-primary mr-3"></i>My Reservations
            </h1>
            <p class="text-gray-600">Manage and track your facility reservations</p>
        </div>
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
        <div class="quick-stats rounded-xl p-6 mb-8 animate-slide-up" style="animation-delay: 0.1s;">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-white">Quick Overview</h2>
                <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                    <i class="fas fa-chart-line text-white text-xl"></i>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="quick-stats-card rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold mb-1"><?php echo $totalReservations; ?></div>
                    <div class="text-sm opacity-90">Total Reservations</div>
            </div>
                <div class="quick-stats-card rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold mb-1"><?php echo $upcomingReservations; ?></div>
                    <div class="text-sm opacity-90">Upcoming Bookings</div>
            </div>
                <div class="quick-stats-card rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold mb-1"><?php echo $pendingPayments; ?></div>
                    <div class="text-sm opacity-90">Payment Required</div>
                </div>
            </div>
            <?php if ($pendingPayments > 0): ?>
            <div class="mt-4 p-3 bg-yellow-500/20 rounded-lg border border-yellow-400/30">
                <div class="flex items-center text-yellow-100">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <span class="text-sm">You have <?php echo $pendingPayments; ?> reservation(s) requiring payment. Please upload your payment slip.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
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
        <!-- Enhanced Filter Tabs -->
        <div class="enhanced-card p-8 mb-8 animate-slide-up" style="animation-delay: 0.2s;">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-lg mb-4">
                    <i class="fas fa-filter text-white text-2xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-black mb-2">
                    Filter Your Reservations
                </h3>
                <p class="text-black text-lg font-medium opacity-80">Quickly find reservations by status</p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <button class="filter-tab active group relative overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 text-white px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg" data-filter="all">
                    <div class="flex flex-col items-center space-y-2">
                        <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-list text-lg"></i>
                        </div>
                        <span class="text-sm font-bold">All</span>
                        <span class="text-xs opacity-90">Reservations</span>
                    </div>
                    <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </button>
                
                <button class="filter-tab group relative overflow-hidden bg-white border-2 border-gray-200 text-black px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 hover:shadow-lg hover:border-yellow-400" data-filter="pending">
                    <div class="flex flex-col items-center space-y-2">
                        <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-bold text-black">Pending</span>
                        <span class="text-xs text-gray-600">Awaiting</span>
                    </div>
                    <div class="absolute inset-0 bg-yellow-50 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </button>
                
                <button class="filter-tab group relative overflow-hidden bg-white border-2 border-gray-200 text-black px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 hover:shadow-lg hover:border-green-400" data-filter="confirmed">
                    <div class="flex flex-col items-center space-y-2">
                        <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check text-green-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-bold text-black">Confirmed</span>
                        <span class="text-xs text-gray-600">Approved</span>
                    </div>
                    <div class="absolute inset-0 bg-green-50 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </button>
                
                <button class="filter-tab group relative overflow-hidden bg-white border-2 border-gray-200 text-black px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 hover:shadow-lg hover:border-blue-400" data-filter="completed">
                    <div class="flex flex-col items-center space-y-2">
                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-double text-blue-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-bold text-black">Completed</span>
                        <span class="text-xs text-gray-600">Finished</span>
                    </div>
                    <div class="absolute inset-0 bg-blue-50 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </button>
                
                <button class="filter-tab group relative overflow-hidden bg-white border-2 border-gray-200 text-black px-6 py-4 rounded-2xl font-semibold transition-all duration-300 transform hover:scale-105 hover:shadow-lg hover:border-red-400" data-filter="cancelled">
                    <div class="flex flex-col items-center space-y-2">
                        <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-times text-red-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-bold text-black">Cancelled</span>
                        <span class="text-xs text-gray-600">Canceled</span>
                    </div>
                    <div class="absolute inset-0 bg-red-50 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                </button>
            </div>
            
            <!-- Filter Results Counter -->
            <div class="mt-6 text-center">
                <div class="inline-flex items-center bg-gray-100 rounded-full px-4 py-2">
                    <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                    <span class="text-black font-medium" id="filter-results-count">Showing all reservations</span>
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
                                    <p class="text-sm text-gray-600">
                                        <i class="fas fa-users mr-1"></i><?php echo $entry['attendees']; ?> attendees
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
        <!-- Enhanced Reservations Table (Desktop) -->
        <div class="enhanced-card overflow-hidden reservation-table animate-slide-up" style="animation-delay: 0.3s;">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-building mr-2"></i>Facility
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-calendar mr-2"></i>Date & Time
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-users mr-2"></i>Attendees
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-money-bill mr-2"></i>Amount
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-info-circle mr-2"></i>Status
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($reservations as $index => $reservation): ?>
                            <tr class="reservation-row hover:bg-gray-50 transition duration-200" data-status="<?php echo $reservation['status']; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-building text-white"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($reservation['category_name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <i class="fas fa-calendar-day mr-1"></i>
                                        <?php 
                                        $startDate = date('M j, Y', strtotime($reservation['start_time']));
                                        $endDate = date('M j, Y', strtotime($reservation['end_time']));
                                        if ($startDate === $endDate) {
                                            echo $startDate;
                                        } else {
                                            echo $startDate . ' - ' . $endDate;
                                        }
                                        ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <i class="fas fa-clock mr-1"></i><?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </div>
                                    <div class="text-xs text-blue-600 font-medium mt-1">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> Booking
                                        (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <i class="fas fa-users mr-1"></i><?php echo $reservation['attendees']; ?> people
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="font-semibold text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="space-y-2">
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
                                        <span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-800'; ?>">
                                            <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-1"></i>
                                            <?php echo ucfirst($reservation['status']); ?>
                                        </span>
                                        <!-- Payment Status Indicator -->
                                        <?php if ($reservation['status'] !== 'cancelled'): ?>
                                            <?php if ($reservation['payment_status'] === 'pending'): ?>
                                                <?php if ($reservation['payment_slip_url']): ?>
                                                    <div class="flex items-center text-xs text-blue-600">
                                                        <i class="fas fa-file-upload mr-1"></i>
                                                        <span>Payment slip uploaded</span>
                                                        <?php if ($reservation['payment_verified_at']): ?>
                                                            <i class="fas fa-check-circle ml-1 text-green-500"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-clock ml-1 text-yellow-500"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="flex items-center text-xs text-orange-600">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                                        <span>Payment slip required</span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                                                <div class="flex items-center text-xs text-green-600">
                                                    <i class="fas fa-check-circle mr-1"></i>
                                                    <span>Payment verified</span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <a href="facility_details.php?facility_id=<?php echo $reservation['facility_id']; ?>" 
                                           class="text-primary hover:text-secondary transition duration-200 transform hover:scale-110"
                                           title="View facility details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($reservation['status'] === 'pending'): ?>
                                            <?php if ($reservation['payment_slip_url']): ?>
                                                <span class="text-blue-600" title="Payment slip uploaded - awaiting verification">
                                                    <i class="fas fa-file-upload"></i>
                                                </span>
                                            <?php else: ?>
                                                <a href="upload_payment.php?reservation_id=<?php echo $reservation['id']; ?>" 
                                                   class="text-green-600 hover:text-green-800 transition duration-200 transform hover:scale-110"
                                                   title="Upload payment slip">
                                                    <i class="fas fa-upload"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($reservation['payment_status'] === 'paid'): ?>
                                            <span class="text-green-600" title="Payment verified">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (in_array($reservation['status'], ['pending', 'confirmed'])): ?>
                                            <button onclick="showCancelConfirmation(<?php echo $reservation['id']; ?>, '<?php echo htmlspecialchars($reservation['facility_name']); ?>', '<?php echo date('M j, Y g:i A', strtotime($reservation['start_time'])); ?>')" 
                                                    class="text-red-600 hover:text-red-800 transition duration-200 transform hover:scale-110"
                                                    title="Cancel reservation">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Reservations Cards (Mobile) -->
        <div class="reservation-cards space-y-4">
            <?php foreach ($reservations as $index => $reservation): ?>
                <div class="reservation-card bg-white rounded-lg shadow-md p-6 animate-slide-up" 
                     data-status="<?php echo $reservation['status']; ?>"
                     style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center">
                            <div class="h-12 w-12 bg-gradient-to-br from-blue-400 to-purple-500 rounded-lg flex items-center justify-center">
                                <i class="fas fa-building text-white"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($reservation['category_name']); ?></p>
                            </div>
                        </div>
                        <span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-800'; ?>">
                            <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-1"></i>
                            <?php echo ucfirst($reservation['status']); ?>
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <p class="text-sm text-gray-500">Date Range</p>
                            <p class="font-medium">
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
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Time Range</p>
                            <p class="font-medium"><?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - <?php echo date('g:i A', strtotime($reservation['end_time'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Booking Type</p>
                            <p class="font-medium text-blue-600">
                                <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?>
                                (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Attendees</p>
                            <p class="font-medium"><?php echo $reservation['attendees']; ?> people</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Amount</p>
                            <p class="font-semibold text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></p>
                        </div>
                    </div>
                    <!-- Payment Status Section -->
                    <?php if ($reservation['status'] !== 'cancelled'): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">Payment Status</span>
                                <?php if ($reservation['payment_status'] === 'pending'): ?>
                                    <?php if ($reservation['payment_slip_url']): ?>
                                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                            <i class="fas fa-file-upload mr-1"></i>Uploaded
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs bg-orange-100 text-orange-800 px-2 py-1 rounded-full">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Required
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                                    <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">
                                        <i class="fas fa-check-circle mr-1"></i>Verified
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($reservation['payment_status'] === 'pending' && $reservation['payment_slip_url']): ?>
                                <div class="text-xs text-gray-600">
                                    <?php if ($reservation['payment_verified_at']): ?>
                                        <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                        Payment verified on <?php echo date('M j, Y g:i A', strtotime($reservation['payment_verified_at'])); ?>
                                    <?php else: ?>
                                        <i class="fas fa-clock text-yellow-500 mr-1"></i>
                                        Awaiting admin verification
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="flex space-x-2">
                        <a href="facility_details.php?facility_id=<?php echo $reservation['facility_id']; ?>" 
                           class="flex-1 bg-primary hover:bg-secondary text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-eye mr-2"></i>View Details
                        </a>
                        <?php if ($reservation['status'] === 'pending'): ?>
                            <?php if ($reservation['payment_slip_url']): ?>
                                <span class="flex-1 bg-blue-500 text-white text-center py-2 rounded-lg">
                                    <i class="fas fa-file-upload mr-2"></i>Uploaded
                                </span>
                            <?php else: ?>
                                <a href="upload_payment.php?reservation_id=<?php echo $reservation['id']; ?>" 
                                   class="flex-1 bg-green-500 hover:bg-green-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-upload mr-2"></i>Upload Payment
                                </a>
                            <?php endif; ?>
                        <?php elseif ($reservation['payment_status'] === 'paid'): ?>
                            <span class="flex-1 bg-green-500 text-white text-center py-2 rounded-lg">
                                <i class="fas fa-check-circle mr-2"></i>Paid
                            </span>
                        <?php endif; ?>
                        <?php if (in_array($reservation['status'], ['pending', 'confirmed'])): ?>
                            <button onclick="showCancelConfirmation(<?php echo $reservation['id']; ?>, '<?php echo htmlspecialchars($reservation['facility_name']); ?>', '<?php echo date('M j, Y g:i A', strtotime($reservation['start_time'])); ?>')" 
                                    class="flex-1 bg-red-500 hover:bg-red-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-times mr-2"></i>Cancel
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
                <a href="index.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-xl inline-flex items-center">
                    <i class="fas fa-search mr-3"></i>Browse Facilities
                </a>
            </div>
        <?php endif; ?>
    </div>
    <!-- Floating Action Button -->
    <div class="floating-action">
        <a href="index.php" 
           class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:scale-110">
            <i class="fas fa-plus text-xl"></i>
        </a>
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
            const reservationRows = document.querySelectorAll('.reservation-row, .reservation-card');
            const filterResultsCount = document.getElementById('filter-results-count');
            
            function updateFilterResults(filter) {
                let visibleCount = 0;
                reservationRows.forEach(row => {
                    if (filter === 'all' || row.dataset.status === filter) {
                        row.style.display = '';
                        row.classList.add('animate-slide-up');
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
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
                const modal = document.getElementById('cancelModal');
                if (!modal.classList.contains('hidden')) {
                    closeCancelModal();
                }
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
