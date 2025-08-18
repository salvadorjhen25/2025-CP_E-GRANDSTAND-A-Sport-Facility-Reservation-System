<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../classes/PaymentManager.php';

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
$auth->requireAdmin();

$pdo = getDBConnection();
$paymentManager = new PaymentManager();

// Handle status updates and payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $reservation_id = $_POST['reservation_id'] ?? null;
    
    if ($_POST['action'] === 'update_status') {
        $new_status = $_POST['status'] ?? null;
        
        if ($reservation_id && $new_status) {
            if ($new_status === 'no_show') {
                if ($paymentManager->markAsNoShow($reservation_id, $_SESSION['user_id'])) {
                    $success_message = "Reservation marked as no-show successfully!";
                } else {
                    $error_message = "Failed to mark reservation as no-show.";
                }
            } else {
                $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?");
                if ($stmt->execute([$new_status, $reservation_id])) {
                    $success_message = "Reservation status updated successfully!";
                } else {
                    $error_message = "Failed to update reservation status.";
                }
            }
        }
    } elseif ($_POST['action'] === 'verify_payment') {
        $approved = $_POST['approved'] === 'true';
        $notes = $_POST['notes'] ?? '';
        
        if ($paymentManager->verifyPayment($reservation_id, $_SESSION['user_id'], $approved, $notes)) {
            $success_message = $approved ? "Payment verified successfully!" : "Payment rejected.";
        } else {
            $error_message = "Failed to verify payment.";
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$facility_filter = $_GET['facility'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build query
$query = "
    SELECT r.*, u.full_name as user_name, u.email as user_email, f.name as facility_name, f.hourly_rate, f.daily_rate
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE 1=1
";
$params = [];

if ($status_filter) {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}

if ($facility_filter) {
    $query .= " AND r.facility_id = ?";
    $params[] = $facility_filter;
}

if ($date_filter) {
    $query .= " AND DATE(r.start_time) = ?";
    $params[] = $date_filter;
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Get facilities for filter
$stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_reservations,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_payments,
        SUM(total_amount) as total_revenue
    FROM reservations
");
$stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reservations - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/enhanced-ui.css">
    <script src="../assets/js/modal-system.js"></script>
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
        .reservation-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
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
        .action-button {
            transition: all 0.2s ease;
        }
        .action-button:hover {
            transform: scale(1.05);
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
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white/80 backdrop-blur-md shadow-lg sticky top-0 z-40 border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-calendar-check text-white text-xl"></i>
                        </div>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Admin</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700 font-medium">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="dashboard.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="facilities.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-building mr-2"></i>Facilities
                    </a>
                    <a href="users.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-users mr-2"></i>Users
                    </a>
                    <a href="../auth/logout.php" class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105 shadow-md">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="text-center mb-6">
                <h1 class="text-4xl font-bold text-gray-900 mb-2 flex items-center justify-center">
                    <i class="fas fa-calendar-check text-primary mr-3"></i>Manage Reservations
                </h1>
                <p class="text-gray-600 text-lg">Review and manage all facility reservations with ease</p>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">Total Reservations</p>
                        <p class="text-3xl font-bold"><?php echo number_format($stats['total_reservations']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-yellow-500 to-yellow-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-yellow-100 text-sm font-medium">Pending</p>
                        <p class="text-3xl font-bold"><?php echo number_format($stats['pending_count']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100 text-sm font-medium">Completed</p>
                        <p class="text-3xl font-bold"><?php echo number_format($stats['completed_count']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100 text-sm font-medium">Total Revenue</p>
                        <p class="text-3xl font-bold">₱<?php echo number_format($stats['total_revenue'], 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-gradient-to-r from-green-100 to-green-200 border border-green-300 text-green-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-gradient-to-r from-red-100 to-red-200 border border-red-300 text-red-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-card bg-white/80 backdrop-blur-sm rounded-2xl shadow-lg p-6 mb-8 border border-gray-100">
            <div class="flex items-center mb-6">
                <i class="fas fa-filter text-primary text-xl mr-3"></i>
                <h3 class="text-xl font-semibold text-gray-900">Filter Reservations</h3>
            </div>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Status</label>
                    <select name="status" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>✅ Confirmed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>❌ Cancelled</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>🎉 Completed</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>⏰ Expired</option>
                        <option value="no_show" <?php echo $status_filter === 'no_show' ? 'selected' : ''; ?>>👤 No Show</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Facility</label>
                    <select name="facility" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                🏢 <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Date</label>
                    <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>"
                           class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 shadow-lg font-semibold">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Reservations Grid -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-list-ul text-primary mr-3"></i>
                    Reservations (<?php echo count($reservations); ?>)
                </h3>
                <div class="flex space-x-2">
                    <button onclick="clearFilters()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-undo mr-2"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($reservations)): ?>
            <!-- Empty State -->
            <div class="text-center py-16 animate-fade-in">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-calendar-times text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-semibold text-gray-600 mb-2">No reservations found</h3>
                <p class="text-gray-500 mb-6">Try adjusting your filters or check back later for new reservations.</p>
                <button onclick="clearFilters()" class="bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 font-semibold">
                    <i class="fas fa-undo mr-2"></i>Clear All Filters
                </button>
            </div>
        <?php else: ?>
            <!-- Reservations Cards -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($reservations as $index => $reservation): ?>
                    <div class="reservation-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 animate-slide-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        <!-- Header -->
                        <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-6 text-white relative">
                            <div class="absolute top-4 right-4">
                                <?php
                                $statusColors = [
                                    'pending' => 'bg-yellow-400 text-yellow-900',
                                    'confirmed' => 'bg-green-400 text-green-900',
                                    'cancelled' => 'bg-red-400 text-red-900',
                                    'completed' => 'bg-blue-400 text-blue-900',
                                    'expired' => 'bg-gray-400 text-gray-900',
                                    'no_show' => 'bg-red-400 text-red-900'
                                ];
                                $statusColor = $statusColors[$reservation['status']] ?? 'bg-gray-400 text-gray-900';
                                ?>
                                <span class="status-badge px-3 py-1 rounded-full text-xs font-bold <?php echo $statusColor; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $reservation['status'])); ?>
                                </span>
                            </div>
                            <div class="flex items-center mb-4">
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mr-4">
                                    <i class="fas fa-user text-2xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-bold"><?php echo htmlspecialchars($reservation['user_name']); ?></h4>
                                    <p class="text-blue-100 text-sm"><?php echo htmlspecialchars($reservation['user_email']); ?></p>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-building mr-2"></i>
                                <span class="font-semibold"><?php echo htmlspecialchars($reservation['facility_name']); ?></span>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="p-6">
                            <!-- Date & Time -->
                            <div class="mb-4 p-4 bg-gray-50 rounded-xl">
                                <div class="flex items-center mb-2">
                                    <i class="fas fa-calendar-alt text-primary mr-2"></i>
                                    <span class="font-semibold text-gray-900">
                                        <?php 
                                        $startDate = date('M j, Y', strtotime($reservation['start_time']));
                                        $endDate = date('M j, Y', strtotime($reservation['end_time']));
                                        if ($startDate === $endDate) {
                                            echo $startDate;
                                        } else {
                                            echo $startDate . ' - ' . $endDate;
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span>
                                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </span>
                                </div>
                                <div class="mt-2 text-xs text-blue-600 font-medium">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?> Booking
                                    (<?php echo formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type'] ?? 'hourly'); ?>)
                                </div>
                                <?php if ($startDate !== $endDate): ?>
                                <div class="mt-2 text-xs text-purple-600 font-medium">
                                    <i class="fas fa-calendar-week mr-1"></i>
                                    Multi-day booking (<?php echo date_diff(date_create($reservation['start_time']), date_create($reservation['end_time']))->days + 1; ?> days)
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Purpose -->
                            <div class="mb-4">
                                <h5 class="font-semibold text-gray-900 mb-2 flex items-center">
                                    <i class="fas fa-bullseye text-primary mr-2"></i>Purpose
                                </h5>
                                <p class="text-gray-600 text-sm bg-gray-50 p-3 rounded-lg">
                                    <?php echo htmlspecialchars($reservation['purpose']); ?>
                                </p>
                            </div>

                            <!-- Attendees -->
                            <div class="mb-4 flex items-center justify-between">
                                <div class="flex items-center">
                                    <i class="fas fa-users text-primary mr-2"></i>
                                    <span class="font-semibold text-gray-900"><?php echo $reservation['attendees']; ?> attendees</span>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-bold text-primary">₱<?php echo number_format($reservation['total_amount'], 2); ?></div>
                                    <div class="text-xs text-gray-500">Total Amount</div>
                                </div>
                            </div>

                            <!-- Payment Status -->
                            <div class="mb-4 p-3 rounded-lg <?php echo $reservation['payment_status'] === 'paid' ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200'; ?>">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-credit-card text-primary mr-2"></i>
                                        <span class="font-semibold text-gray-900">Payment Status</span>
                                    </div>
                                    <span class="px-2 py-1 rounded-full text-xs font-bold <?php echo $reservation['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($reservation['payment_status']); ?>
                                    </span>
                                </div>
                                <?php if ($reservation['payment_status'] === 'pending' && $reservation['payment_slip_url']): ?>
                                    <div class="mt-2">
                                        <button onclick="viewPaymentSlip('../<?php echo $reservation['payment_slip_url']; ?>', '<?php echo htmlspecialchars($reservation['user_name']); ?>')" 
                                                class="text-blue-600 hover:text-blue-800 text-sm font-medium transition duration-200">
                                            <i class="fas fa-file-alt mr-1"></i>View Receipt
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Actions -->
                            <div class="space-y-2">
                                <!-- Payment Verification Actions -->
                                <?php if ($reservation['payment_status'] === 'pending' && $reservation['payment_slip_url']): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button onclick="confirmVerifyPayment(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-check mr-1"></i>Verify
                                        </button>
                                        <button onclick="confirmRejectPayment(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-times mr-1"></i>Reject
                                        </button>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Status Update Actions -->
                                <?php if ($reservation['status'] === 'pending'): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button onclick="confirmApproveReservation(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-check mr-1"></i>Approve
                                        </button>
                                        <button onclick="confirmRejectReservation(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-times mr-1"></i>Reject
                                        </button>
                                    </div>
                                <?php elseif ($reservation['status'] === 'confirmed'): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button onclick="confirmCompleteReservation(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-check-double mr-1"></i>Complete
                                        </button>
                                        <button onclick="confirmNoShowReservation(<?php echo $reservation['id']; ?>)" 
                                                class="action-button bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                            <i class="fas fa-user-times mr-1"></i>No Show
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-2 text-gray-500 text-sm">
                                        <i class="fas fa-info-circle mr-1"></i>No actions available
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payment Receipt Modal -->
    <div id="paymentModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-4xl w-full mx-4 max-h-screen overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-200 bg-gradient-to-r from-blue-500 to-purple-600 text-white">
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
            
            // Show loading
            content.innerHTML = `
                <div class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mr-4"></div>
                    <span class="text-gray-600 text-lg">Loading receipt...</span>
                </div>
            `;
            
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
                    <div class="mt-6 text-sm text-gray-500 bg-blue-50 p-4 rounded-xl">
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
                        <a href="${imageUrl}" target="_blank" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl transition duration-200 transform hover:scale-105 font-semibold">
                            <i class="fas fa-external-link-alt mr-2"></i>Try Direct Link
                        </a>
                    </div>
                `;
            };
            
            img.src = imageUrl;
        }
        
        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            modal.classList.remove('show');
        }
        
        // Close modal when clicking outside
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePaymentModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentModal();
            }
        });

        // Confirmation functions for reservation actions
        async function confirmVerifyPayment(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Verify this payment?',
                'Payment Verification'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="verify_payment">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="approved" value="true">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        async function confirmRejectPayment(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Reject this payment?',
                'Payment Rejection'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="verify_payment">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="approved" value="false">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        async function confirmApproveReservation(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Confirm this reservation?',
                'Reservation Approval'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="status" value="confirmed">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        async function confirmRejectReservation(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Cancel this reservation?',
                'Reservation Cancellation'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="status" value="cancelled">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        async function confirmCompleteReservation(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Mark as completed?',
                'Reservation Completion'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="status" value="completed">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        async function confirmNoShowReservation(reservationId) {
            const confirmed = await window.ModalSystem.confirm(
                'Mark as no-show?',
                'No-Show Confirmation'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="reservation_id" value="${reservationId}">
                    <input type="hidden" name="status" value="no_show">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
