<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../classes/UsageManager.php';

$auth = new Auth();
$auth->requireAdmin();

$usageManager = new UsageManager();
$pdo = getDBConnection();

// Handle usage operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'start_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                $result = $usageManager->startUsage($reservationId, $_SESSION['user_id'], $notes);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                break;
                
            case 'complete_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                $result = $usageManager->completeUsage($reservationId, $_SESSION['user_id'], $notes);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                break;
                
            case 'verify_usage':
                $reservationId = intval($_POST['reservation_id']);
                $notes = trim($_POST['notes'] ?? '');
                $result = $usageManager->verifyUsage($reservationId, $_SESSION['user_id'], $notes);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                break;
        }
    }
}

// Get current usage and pending verifications
$currentUsage = $usageManager->getCurrentUsage();
$pendingVerifications = $usageManager->getPendingVerifications();

// Get confirmed reservations ready for usage
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, u.full_name as user_name
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    JOIN users u ON r.user_id = u.id 
    WHERE r.status = 'confirmed' 
      AND r.payment_status = 'paid' 
      AND r.usage_started_at IS NULL
      AND r.start_time <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
      AND r.end_time >= NOW()
    ORDER BY r.start_time ASC
");
$stmt->execute();
$readyForUsage = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Management - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        .usage-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .usage-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .modal {
            transition: all 0.3s ease;
            pointer-events: auto;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .usage-card, .usage-card * {
            pointer-events: auto !important;
        }
        
        /* Timer styles */
        .timer-display {
            transition: all 0.3s ease;
        }
        
        .timer-display.timer-active {
            background: linear-gradient(45deg, #10B981, #059669) !important;
            color: white !important;
            animation: pulse-timer 2s infinite;
        }
        
        .timer-text {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        
        @keyframes pulse-timer {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }
        }
        
        /* Enhanced card styles for timer */
        .usage-card.timer-active {
            border-color: #10B981;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
        }
        
        /* Large timer display styles */
        .timer-display-large {
            transition: all 0.3s ease;
        }
        
        .timer-display-large.timer-active {
            color: #059669 !important;
            animation: pulse-timer-large 2s infinite;
        }
        
        @keyframes pulse-timer-large {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.02);
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center">
                        <i class="fas fa-clock text-primary text-2xl mr-3"></i>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Usage Management</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="dashboard.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="reservations.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-calendar mr-2"></i>Reservations
                    </a>
                    <a href="../index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>View Site
                    </a>
                    <a href="../auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
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
                <i class="fas fa-clock text-primary mr-3"></i>Facility Usage Management
            </h1>
            <p class="text-gray-600">Track and verify facility usage by users</p>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Live Timer Dashboard -->
        <?php if (!empty($currentUsage)): ?>
            <div class="mb-8 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-xl p-6 animate-slide-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-stopwatch text-green-500 mr-3"></i>Live Usage Timers
                    </h2>
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-sync-alt mr-1"></i>Auto-refreshing every 60 seconds
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($currentUsage as $usage): ?>
                        <div class="bg-white rounded-lg p-4 border border-green-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($usage['facility_name']); ?></h3>
                                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($usage['user_name']); ?></span>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-mono font-bold text-green-600 timer-display-large" 
                                     data-usage-started="true" 
                                     data-reservation-id="<?php echo $usage['id']; ?>" 
                                     data-usage-started="<?php echo $usage['usage_started_at']; ?>">
                                    00:00:00
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Started: <?php echo date('g:i A', strtotime($usage['usage_started_at'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Current Usage Section -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-play-circle text-green-500 mr-2"></i>Currently In Use
                <span class="ml-2 bg-green-100 text-green-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                    <?php echo count($currentUsage); ?>
                </span>
            </h2>
            
            <?php if (empty($currentUsage)): ?>
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <i class="fas fa-info-circle text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600">No facilities are currently in use.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($currentUsage as $usage): ?>
                        <div class="usage-card bg-white rounded-lg shadow-md overflow-hidden animate-slide-up">
                            <div class="h-32 bg-gradient-to-br from-green-400 to-blue-500 flex items-center justify-center relative">
                                <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                                <div class="relative z-10 text-center">
                                    <div class="h-16 w-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-2">
                                        <i class="fas fa-play text-white text-2xl"></i>
                                    </div>
                                    <p class="text-white font-semibold"><?php echo htmlspecialchars($usage['facility_name']); ?></p>
                                </div>
                                <div class="absolute top-2 right-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 timer-display" 
                                          data-usage-started="true" 
                                          data-reservation-id="<?php echo $usage['id']; ?>" 
                                          data-usage-started="<?php echo $usage['usage_started_at']; ?>">
                                        <i class="fas fa-clock mr-1"></i>
                                        <span class="timer-text">00:00:00</span>
                                    </span>
                                </div>
                            </div>
                            <div class="p-6">
                                <div class="mb-3">
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($usage['user_name']); ?></h3>
                                    <p class="text-sm text-gray-600">Started: <?php echo date('M j, Y g:i A', strtotime($usage['usage_started_at'])); ?></p>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="completeUsage(<?php echo $usage['id']; ?>)" 
                                            class="flex-1 bg-orange-500 hover:bg-orange-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                        <i class="fas fa-stop mr-2"></i>Complete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Ready for Usage Section -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-hourglass-half text-blue-500 mr-2"></i>Ready for Usage
                <span class="ml-2 bg-blue-100 text-blue-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                    <?php echo count($readyForUsage); ?>
                </span>
            </h2>
            
            <?php if (empty($readyForUsage)): ?>
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <i class="fas fa-info-circle text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600">No reservations are ready for usage at this time.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($readyForUsage as $reservation): ?>
                        <div class="usage-card bg-white rounded-lg shadow-md overflow-hidden animate-slide-up">
                            <div class="h-32 bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center relative">
                                <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                                <div class="relative z-10 text-center">
                                    <div class="h-16 w-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-2">
                                        <i class="fas fa-hourglass-half text-white text-2xl"></i>
                                    </div>
                                    <p class="text-white font-semibold"><?php echo htmlspecialchars($reservation['facility_name']); ?></p>
                                </div>
                            </div>
                            <div class="p-6">
                                <div class="mb-3">
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($reservation['user_name']); ?></h3>
                                    <p class="text-sm text-gray-600">Start: <?php echo date('M j, Y g:i A', strtotime($reservation['start_time'])); ?></p>
                                    <p class="text-sm text-gray-600">End: <?php echo date('M j, Y g:i A', strtotime($reservation['end_time'])); ?></p>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="startUsage(<?php echo $reservation['id']; ?>)" 
                                            class="flex-1 bg-green-500 hover:bg-green-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                        <i class="fas fa-play mr-2"></i>Start Usage
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pending Verifications Section -->
        <div class="mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-check-circle text-yellow-500 mr-2"></i>Pending Verifications
                <span class="ml-2 bg-yellow-100 text-yellow-800 text-sm font-medium px-2.5 py-0.5 rounded-full">
                    <?php echo count($pendingVerifications); ?>
                </span>
            </h2>
            
            <?php if (empty($pendingVerifications)): ?>
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <i class="fas fa-info-circle text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600">No usage verifications are pending.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($pendingVerifications as $verification): ?>
                        <div class="usage-card bg-white rounded-lg shadow-md overflow-hidden animate-slide-up">
                            <div class="h-32 bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center relative">
                                <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                                <div class="relative z-10 text-center">
                                    <div class="h-16 w-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-2">
                                        <i class="fas fa-check-circle text-white text-2xl"></i>
                                    </div>
                                    <p class="text-white font-semibold"><?php echo htmlspecialchars($verification['facility_name']); ?></p>
                                </div>
                                <div class="absolute top-2 right-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?php echo $verification['usage_duration_minutes']; ?> min
                                    </span>
                                </div>
                            </div>
                            <div class="p-6">
                                <div class="mb-3">
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($verification['user_name']); ?></h3>
                                    <p class="text-sm text-gray-600">Completed: <?php echo date('M j, Y g:i A', strtotime($verification['usage_completed_at'])); ?></p>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="verifyUsage(<?php echo $verification['id']; ?>)" 
                                            class="flex-1 bg-green-500 hover:bg-green-600 text-white text-center py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                        <i class="fas fa-check mr-2"></i>Verify
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Usage Action Modal -->
    <div id="usageModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 id="modalTitle" class="text-xl font-semibold text-gray-900">Usage Action</h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="usageForm" method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="">
                    <input type="hidden" id="reservation_id" name="reservation_id" value="">
                    
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                        <textarea id="notes" name="notes" rows="3" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200"
                                  placeholder="Add any notes about this usage action..."></textarea>
                    </div>
                    
                    <div class="flex space-x-3 pt-4">
                        <button type="submit" id="submitBtn" 
                                class="flex-1 bg-primary hover:bg-secondary text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Confirm Action
                        </button>
                        <button type="button" onclick="closeModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Timer management system
        class UsageTimer {
            constructor() {
                this.timers = new Map();
                this.init();
            }

            init() {
                // Initialize timers for all current usage items
                const currentUsageElements = document.querySelectorAll('[data-usage-started]');
                currentUsageElements.forEach(element => {
                    const reservationId = element.dataset.reservationId;
                    const startedAt = new Date(element.dataset.usageStarted);
                    this.startTimer(reservationId, startedAt, element);
                });
            }

            startTimer(reservationId, startedAt, element) {
                const updateTimer = () => {
                    const now = new Date();
                    const elapsed = now - startedAt;
                    const hours = Math.floor(elapsed / (1000 * 60 * 60));
                    const minutes = Math.floor((elapsed % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((elapsed % (1000 * 60)) / 1000);

                    const timeString = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                    
                    if (element) {
                        // Update the main element
                        element.textContent = timeString;
                        element.classList.add('timer-active');
                        
                        // Also update any timer-text spans within this element
                        const timerText = element.querySelector('.timer-text');
                        if (timerText) {
                            timerText.textContent = timeString;
                        }
                        
                        // Update the parent card if it exists
                        const parentCard = element.closest('.usage-card');
                        if (parentCard) {
                            parentCard.classList.add('timer-active');
                        }
                    }
                };

                // Update immediately
                updateTimer();
                
                // Update every second
                const intervalId = setInterval(updateTimer, 1000);
                
                // Store timer info
                this.timers.set(reservationId, {
                    intervalId,
                    startedAt,
                    element
                });
            }

            stopTimer(reservationId) {
                const timer = this.timers.get(reservationId);
                if (timer) {
                    clearInterval(timer.intervalId);
                    this.timers.delete(reservationId);
                }
            }

            formatDuration(minutes) {
                const hours = Math.floor(minutes / 60);
                const mins = minutes % 60;
                return `${hours}h ${mins}m`;
            }
        }

        // Initialize timer system
        const usageTimer = new UsageTimer();

        function startUsage(reservationId) {
            const modal = document.getElementById('usageModal');
            document.getElementById('modalTitle').textContent = 'Start Facility Usage';
            document.getElementById('action').value = 'start_usage';
            document.getElementById('reservation_id').value = reservationId;
            document.getElementById('notes').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-play mr-2"></i>Start Usage';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function completeUsage(reservationId) {
            const modal = document.getElementById('usageModal');
            document.getElementById('modalTitle').textContent = 'Complete Facility Usage';
            document.getElementById('action').value = 'complete_usage';
            document.getElementById('reservation_id').value = reservationId;
            document.getElementById('notes').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-stop mr-2"></i>Complete Usage';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function verifyUsage(reservationId) {
            const modal = document.getElementById('usageModal');
            document.getElementById('modalTitle').textContent = 'Verify Facility Usage';
            document.getElementById('action').value = 'verify_usage';
            document.getElementById('reservation_id').value = reservationId;
            document.getElementById('notes').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-check mr-2"></i>Verify Usage';
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }

        function closeModal() {
            const modal = document.getElementById('usageModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('usageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Auto-refresh page every 60 seconds instead of 30 for better timer experience
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>
