<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/PaymentManager.php';

// Helper function to format booking duration
function formatBookingDuration($startTime, $endTime, $bookingType) {
    $start = new DateTime($startTime);
    $end = new DateTime($endTime);
    $duration = $start->diff($end);
    
    if ($bookingType === 'daily') {
        $days = $duration->days + ($duration->h > 0 ? 1 : 0);
        return $days . ' day' . ($days > 1 ? 's' : '');
    } else {
        $hours = $duration->h + ($duration->days * 24);
        if ($duration->i > 0) $hours += 0.5; // Round up for partial hours
        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }
}

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();
$paymentManager = new PaymentManager();

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
    <script src="assets/js/modal-system.js"></script>
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
    </script>
    <style>
        .status-badge {
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .reservation-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .filter-tab {
            transition: all 0.2s ease;
        }
        .filter-tab.active {
            background-color: #3B82F6;
            color: white;
        }
        .filter-tab:hover:not(.active) {
            background-color: #f3f4f6;
        }
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .reservation-row {
            transition: all 0.3s ease;
        }
        .reservation-row:hover {
            background-color: #f8fafc;
            transform: translateX(4px);
        }
        .payment-status-indicator {
            animation: pulse 2s infinite;
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
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Loading your reservations...</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center">
                        <i class="fas fa-building text-primary text-2xl mr-3"></i>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?></h1>
                    </a>
                </div>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center space-x-4">
                    <span class="text-gray-700">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="index.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-gray-900 focus:outline-none focus:text-gray-900 p-2 rounded-lg hover:bg-gray-100 transition duration-200" aria-label="Toggle mobile menu">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-4 border-t border-gray-200">
                <div class="space-y-2 pt-4">
                    <div class="text-gray-700 py-2 px-4 bg-gray-50 rounded-lg">
                        <i class="fas fa-user mr-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    </div>
                    <a href="index.php" class="block bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="auth/logout.php" class="block bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105 mt-2">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
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

        <!-- Statistics Cards -->
        <?php
        $totalReservations = count($reservations);
        $pendingReservations = count(array_filter($reservations, function($r) { return $r['status'] === 'pending'; }));
        $confirmedReservations = count(array_filter($reservations, function($r) { return $r['status'] === 'confirmed'; }));
        $completedReservations = count(array_filter($reservations, function($r) { return $r['status'] === 'completed'; }));
        $pendingPayments = count(array_filter($reservations, function($r) { return $r['payment_status'] === 'pending' && !$r['payment_slip_url']; }));
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-xl p-4 shadow-lg animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">Total</p>
                        <p class="text-2xl font-bold"><?php echo $totalReservations; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-yellow-500 to-yellow-600 text-white rounded-xl p-4 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-yellow-100 text-sm font-medium">Pending</p>
                        <p class="text-2xl font-bold"><?php echo $pendingReservations; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-4 shadow-lg animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100 text-sm font-medium">Confirmed</p>
                        <p class="text-2xl font-bold"><?php echo $confirmedReservations; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-4 shadow-lg animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100 text-sm font-medium">Completed</p>
                        <p class="text-2xl font-bold"><?php echo $completedReservations; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-double text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-orange-500 to-orange-600 text-white rounded-xl p-4 shadow-lg animate-slide-up" style="animation-delay: 0.5s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-100 text-sm font-medium">Payment Due</p>
                        <p class="text-2xl font-bold"><?php echo $pendingPayments; ?></p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-lg"></i>
                    </div>
                </div>
            </div>
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

        <!-- Filter Tabs -->
        <div class="bg-white rounded-lg shadow-md p-4 mb-6 animate-slide-up" style="animation-delay: 0.1s;">
            <div class="flex flex-wrap gap-2">
                <button class="filter-tab active px-4 py-2 rounded-lg font-medium" data-filter="all">
                    <i class="fas fa-list mr-2"></i>All Reservations
                </button>
                <button class="filter-tab px-4 py-2 rounded-lg font-medium" data-filter="pending">
                    <i class="fas fa-clock mr-2"></i>Pending
                </button>
                <button class="filter-tab px-4 py-2 rounded-lg font-medium" data-filter="confirmed">
                    <i class="fas fa-check mr-2"></i>Confirmed
                </button>
                <button class="filter-tab px-4 py-2 rounded-lg font-medium" data-filter="completed">
                    <i class="fas fa-check-double mr-2"></i>Completed
                </button>
                <button class="filter-tab px-4 py-2 rounded-lg font-medium" data-filter="cancelled">
                    <i class="fas fa-times mr-2"></i>Cancelled
                </button>
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

        <!-- Reservations Table (Desktop) -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden reservation-table animate-slide-up" style="animation-delay: 0.3s;">
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
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($reservations)): ?>
            <div class="text-center py-12 animate-fade-in">
                <i class="fas fa-calendar-times text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No reservations found</h3>
                <p class="text-gray-500 mb-6">You haven't made any reservations yet.</p>
                <a href="index.php" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                    <i class="fas fa-search mr-2"></i>Browse Facilities
                </a>
            </div>
        <?php endif; ?>
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

            // Filter functionality
            const filterTabs = document.querySelectorAll('.filter-tab');
            const reservationRows = document.querySelectorAll('.reservation-row, .reservation-card');

            filterTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Remove active class from all tabs
                    filterTabs.forEach(t => t.classList.remove('active'));
                    // Add active class to clicked tab
                    this.classList.add('active');

                    const filter = this.dataset.filter;

                    reservationRows.forEach(row => {
                        if (filter === 'all' || row.dataset.status === filter) {
                            row.style.display = '';
                            row.classList.add('animate-slide-up');
                        } else {
                            row.style.display = 'none';
                        }
                    });
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
    </script>
</body>
</html>
