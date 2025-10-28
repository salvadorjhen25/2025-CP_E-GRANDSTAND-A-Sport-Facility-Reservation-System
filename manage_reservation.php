<?php
require_once 'config/database.php';
require_once 'auth/auth.php';
require_once 'classes/ReservationManager.php';
require_once 'classes/PaymentManager.php';
$auth = new Auth();
$auth->requireRegularUser();
$pdo = getDBConnection();
$reservationManager = new ReservationManager();
$paymentManager = new PaymentManager();
$reservation_id = $_GET['id'] ?? null;
$success_message = '';
$error_message = '';
if (!$reservation_id) {
    header('Location: my_reservations.php');
    exit;
}
// Get reservation details
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, f.capacity,
           c.name as category_name, u.full_name as user_name, u.email as user_email
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$reservation_id, $_SESSION['user_id']]);
$reservation = $stmt->fetch();
if (!$reservation) {
    header('Location: my_reservations.php');
    exit;
}
// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    switch ($action) {
        case 'cancel':
            $reason = trim($_POST['reason'] ?? '');
            $result = $reservationManager->cancelReservation($reservation_id, $_SESSION['user_id'], $reason);
            if ($result['success']) {
                $success_message = $result['message'];
                // Refresh reservation data
                $stmt->execute([$reservation_id, $_SESSION['user_id']]);
                $reservation = $stmt->fetch();
            } else {
                $error_message = $result['message'];
            }
            break;
        case 'reschedule':
            $new_start = $_POST['new_start_date'] . ' ' . $_POST['new_start_time'];
            $new_end = $_POST['new_end_date'] . ' ' . $_POST['new_end_time'];
            $reason = trim($_POST['reason'] ?? '');
            $result = $reservationManager->rescheduleReservation($reservation_id, $_SESSION['user_id'], $new_start, $new_end, $reason);
            if ($result['success']) {
                $success_message = $result['message'];
                if ($result['cost_difference'] != 0) {
                    $success_message .= " Cost difference: ₱" . number_format(abs($result['cost_difference']), 2);
                }
                // Refresh reservation data
                $stmt->execute([$reservation_id, $_SESSION['user_id']]);
                $reservation = $stmt->fetch();
            } else {
                $error_message = $result['message'];
            }
            break;
        case 'extend':
            $new_end = $_POST['extend_date'] . ' ' . $_POST['extend_time'];
            $reason = trim($_POST['reason'] ?? '');
            $result = $reservationManager->extendReservation($reservation_id, $_SESSION['user_id'], $new_end, $reason);
            if ($result['success']) {
                $success_message = $result['message'];
                if ($result['additional_cost'] > 0) {
                    $success_message .= " Additional cost: ₱" . number_format($result['additional_cost'], 2);
                }
                // Refresh reservation data
                $stmt->execute([$reservation_id, $_SESSION['user_id']]);
                $reservation = $stmt->fetch();
            } else {
                $error_message = $result['message'];
            }
            break;
    }
}
// Calculate what actions are allowed
$canCancel = in_array($reservation['status'], ['pending', 'confirmed']) && !$reservation['usage_started_at'];
$canReschedule = in_array($reservation['status'], ['pending', 'confirmed']) && !$reservation['usage_started_at'];
$canExtend = in_array($reservation['status'], ['confirmed', 'in_use']);
// Get grace period status
$graceStatus = $paymentManager->getGracePeriodStatus($reservation_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reservation #<?php echo $reservation['id']; ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
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
                        }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    }
                }
            }
        }
    </script>
    <style>
        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .action-card {
            transition: all 0.3s ease;
        }
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .status-indicator {
            position: relative;
            overflow: hidden;
        }
        .status-indicator::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 2s infinite;
        }
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        .modal {
            display: none;
            backdrop-filter: blur(4px);
        }
        .modal.active {
            display: flex;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Navigation -->
    <nav class="glass-effect sticky top-0 z-50 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <a href="index.php" class="text-2xl font-bold bg-gradient-to-r from-primary-600 to-primary-700 bg-clip-text text-transparent">
                        <?php echo SITE_NAME; ?>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="my_reservations.php" class="text-gray-600 hover:text-primary-600 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Reservations
                    </a>
                    <div class="w-8 h-8 bg-primary-500 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white text-sm"></i>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">
                Manage Reservation #<?php echo $reservation['id']; ?>
            </h1>
            <p class="text-gray-600">Make changes to your reservation or view details</p>
        </div>
        <!-- Messages -->
        <?php if ($success_message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <span class="text-green-800"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <span class="text-red-800"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Reservation Details -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Reservation Details</h2>
                    <!-- Status -->
                    <div class="mb-6 p-4 rounded-lg status-indicator <?php 
                        echo $reservation['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 
                            ($reservation['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                            ($reservation['status'] === 'cancelled' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')); 
                    ?>">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-semibold">Status: <?php echo ucfirst($reservation['status']); ?></span>
                                <?php if ($graceStatus['eligible'] && $reservation['payment_status'] === 'pending'): ?>
                                    <span class="ml-2 text-sm">(Payment pending - Grace period active)</span>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-info-circle"></i>
                        </div>
                    </div>
                    <!-- Facility Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Facility</label>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></div>
                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($reservation['category_name']); ?></div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Booking Type</label>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="font-semibold text-gray-900"><?php echo ucfirst($reservation['booking_type']); ?> Booking</div>
                                <div class="text-sm text-gray-600">
                                    <?php
                                    $duration = formatBookingDuration($reservation['start_time'], $reservation['end_time'], $reservation['booking_type']);
                                    echo $duration;
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Date & Time -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date & Time</label>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="font-semibold text-gray-900">
                                    <?php echo date('M j, Y', strtotime($reservation['start_time'])); ?>
                                </div>
                                <div class="text-sm text-gray-600">
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Total Amount</label>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="font-bold text-2xl text-primary-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></div>
                                <div class="text-sm text-gray-600">Payment <?php echo $reservation['payment_status']; ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Purpose -->
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Purpose</label>
                        <div class="p-3 bg-gray-50 rounded-lg">
                            <p class="text-gray-800"><?php echo htmlspecialchars($reservation['purpose']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Action Panel -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl shadow-xl p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">Available Actions</h2>
                    <div class="space-y-4">
                        <!-- Cancel Reservation -->
                        <?php if ($canCancel): ?>
                            <div class="action-card bg-red-50 border border-red-200 rounded-lg p-4 cursor-pointer" onclick="openModal('cancelModal')">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-times text-red-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-red-800">Cancel Reservation</h3>
                                        <p class="text-sm text-red-600">Cancel this reservation with refund policy</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- Reschedule Reservation -->
                        <?php if ($canReschedule): ?>
                            <div class="action-card bg-blue-50 border border-blue-200 rounded-lg p-4 cursor-pointer" onclick="openModal('rescheduleModal')">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-calendar-alt text-blue-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-blue-800">Reschedule</h3>
                                        <p class="text-sm text-blue-600">Change date and time</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- Extend Reservation -->
                        <?php if ($canExtend): ?>
                            <div class="action-card bg-green-50 border border-green-200 rounded-lg p-4 cursor-pointer" onclick="openModal('extendModal')">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-clock text-green-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-green-800">Extend Time</h3>
                                        <p class="text-sm text-green-600">Add more time to your booking</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- Upload Payment -->
                        <?php if ($reservation['payment_status'] === 'pending' && $reservation['status'] !== 'cancelled'): ?>
                            <div class="action-card bg-purple-50 border border-purple-200 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-upload text-purple-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-purple-800">Upload Payment</h3>
                                        <p class="text-sm text-purple-600">Submit your payment slip</p>
                                    </div>
                                </div>
                                <a href="upload_payment.php?id=<?php echo $reservation['id']; ?>" 
                                   class="w-full bg-purple-600 text-white py-2 px-4 rounded-lg text-center block hover:bg-purple-700 transition-colors">
                                    Upload Payment Slip
                                </a>
                            </div>
                        <?php endif; ?>
                        <!-- View Payment Slip -->
                        <?php if ($reservation['payment_slip_url']): ?>
                            <div class="action-card bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-file-image text-gray-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-800">Payment Slip</h3>
                                        <p class="text-sm text-gray-600">View uploaded payment</p>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($reservation['payment_slip_url']); ?>" 
                                   target="_blank"
                                   class="w-full bg-gray-600 text-white py-2 px-4 rounded-lg text-center block hover:bg-gray-700 transition-colors">
                                    View Payment Slip
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Restrictions -->
                    <?php if (!$canCancel && !$canReschedule && !$canExtend): ?>
                        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-info-circle text-gray-500 mr-3"></i>
                                <span class="text-sm text-gray-600">No actions available for this reservation</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- Cancel Modal -->
    <div id="cancelModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Cancel Reservation</h3>
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for cancellation</label>
                    <textarea name="reason" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Optional reason..."></textarea>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <p class="text-sm text-red-800">
                        <strong>Refund Policy:</strong><br>
                        • 24+ hours: 100% refund<br>
                        • 12-24 hours: 75% refund<br>
                        • 6-12 hours: 50% refund<br>
                        • 2-6 hours: 25% refund<br>
                        • &lt;2 hours: No refund
                    </p>
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeModal('cancelModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 px-4 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="flex-1 bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 transition-colors">
                        Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Reschedule Modal -->
    <div id="rescheduleModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-6 max-w-lg w-full mx-4">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Reschedule Reservation</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reschedule">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New Start Date</label>
                        <input type="date" name="new_start_date" required 
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New Start Time</label>
                        <input type="time" name="new_start_time" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New End Date</label>
                        <input type="date" name="new_end_date" required
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New End Time</label>
                        <input type="time" name="new_end_time" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for rescheduling</label>
                    <textarea name="reason" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Optional reason..."></textarea>
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeModal('rescheduleModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 px-4 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors">
                        Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Extend Modal -->
    <div id="extendModal" class="modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Extend Reservation</h3>
            <form method="POST">
                <input type="hidden" name="action" value="extend">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Current End Time</label>
                    <div class="p-3 bg-gray-50 rounded-lg text-gray-700">
                        <?php echo date('M j, Y g:i A', strtotime($reservation['end_time'])); ?>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New End Date</label>
                        <input type="date" name="extend_date" required
                               value="<?php echo date('Y-m-d', strtotime($reservation['end_time'])); ?>"
                               min="<?php echo date('Y-m-d', strtotime($reservation['end_time'])); ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">New End Time</label>
                        <input type="time" name="extend_time" required
                               value="<?php echo date('H:i', strtotime($reservation['end_time'] . ' +1 hour')); ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for extension</label>
                    <textarea name="reason" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Optional reason..."></textarea>
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeModal('extendModal')" 
                            class="flex-1 bg-gray-300 text-gray-700 py-2 px-4 rounded-lg hover:bg-gray-400 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="flex-1 bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition-colors">
                        Extend
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        // Auto-fill end date when start date changes
        document.querySelector('input[name="new_start_date"]').addEventListener('change', function() {
            const endDateInput = document.querySelector('input[name="new_end_date"]');
            if (!endDateInput.value || new Date(endDateInput.value) < new Date(this.value)) {
                endDateInput.value = this.value;
            }
            endDateInput.min = this.value;
        });
        // Auto-calculate end time based on original duration
        document.querySelector('input[name="new_start_time"]').addEventListener('change', function() {
            const originalDuration = <?php 
                $start = new DateTime($reservation['start_time']);
                $end = new DateTime($reservation['end_time']);
                echo $start->diff($end)->h * 60 + $start->diff($end)->i;
            ?>; // minutes
            if (this.value) {
                const startTime = new Date('2000-01-01 ' + this.value);
                const endTime = new Date(startTime.getTime() + originalDuration * 60000);
                const endTimeString = endTime.toTimeString().substr(0, 5);
                document.querySelector('input[name="new_end_time"]').value = endTimeString;
            }
        });
    </script>
</body>
</html>
