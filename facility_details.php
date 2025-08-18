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
    SELECT f.*, c.name as category_name 
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

// Get date filter
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_date_obj = new DateTime($selected_date);

// Filter reservations for selected date
$filtered_reservations = array_filter($reservations, function($reservation) use ($selected_date) {
    return date('Y-m-d', strtotime($reservation['start_time'])) === $selected_date;
});

// Generate time slots for the day (8 AM to 10 PM)
$time_slots = [];
$start_hour = 8;
$end_hour = 22;

for ($hour = $start_hour; $hour < $end_hour; $hour++) {
    $time_slots[] = sprintf('%02d:00', $hour);
    $time_slots[] = sprintf('%02d:30', $hour);
}
$time_slots[] = '22:00';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($facility['name']); ?> - <?php echo SITE_NAME; ?></title>
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
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
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
                    <a href="my_reservations.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-gray-900 focus:outline-none focus:text-gray-900">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
            <!-- Mobile Navigation -->
            <div id="mobile-menu" class="hidden md:hidden pb-4">
                <div class="space-y-2">
                    <div class="text-gray-700 py-2">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <a href="my_reservations.php" class="block bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="index.php" class="block bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition duration-200 mt-2">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="auth/logout.php" class="block bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 mt-2">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Facility Details -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-3xl font-bold text-gray-800"><?php echo htmlspecialchars($facility['name']); ?></h1>
                <div class="text-right">
                    <div class="bg-primary text-white px-3 py-1 rounded-full text-sm font-semibold mb-1">
                        ₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr
                    </div>
                    <div class="bg-green-500 text-white px-3 py-1 rounded-full text-sm font-semibold">
                        ₱<?php echo number_format($facility['daily_rate'] ?? 0, 2); ?>/day
                    </div>
                </div>
            </div>
            
            <!-- Facility Image -->
            <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                <div class="mb-6">
                    <div class="relative overflow-hidden rounded-lg shadow-lg group">
                        <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                             class="w-full h-64 md:h-96 object-cover hover:scale-105 transition-transform duration-300">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent"></div>
                        <div class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            <button onclick="viewFullImage('<?php echo htmlspecialchars($facility['image_url']); ?>', '<?php echo htmlspecialchars($facility['name']); ?>')" 
                                    class="bg-white/90 hover:bg-white text-gray-800 px-3 py-2 rounded-lg shadow-lg transition duration-200 transform hover:scale-105 tooltip-enhanced"
                                    title="Click to view image in full size">
                                <i class="fas fa-expand-arrows-alt mr-2"></i>View Full Image
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-6">
                    <div class="relative overflow-hidden rounded-lg shadow-lg bg-gradient-to-br from-gray-400 to-gray-600 h-64 md:h-96 flex items-center justify-center">
                        <div class="text-center text-white">
                            <i class="fas fa-image text-6xl md:text-8xl mb-4 opacity-50"></i>
                            <p class="text-xl font-medium">No Image Available</p>
                            <p class="text-sm opacity-75 mt-2">Contact admin to add facility images</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($facility['description']); ?></p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-gray-500">
                <div><i class="fas fa-users mr-2"></i>Capacity: <?php echo $facility['capacity']; ?> people</div>
                <div><i class="fas fa-tag mr-2"></i>Category: <?php echo htmlspecialchars($facility['category_name']); ?></div>
                <div><i class="fas fa-clock mr-2"></i>Available for booking</div>
            </div>
            
            <!-- Book Now Button -->
            <?php if ($_SESSION['role'] !== 'admin'): ?>
            <div class="mt-6">
                <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                   class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold transition duration-200 inline-flex items-center">
                    <i class="fas fa-calendar-plus mr-2"></i>Book This Facility
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Date Navigation -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Availability Calendar</h2>
                <div class="flex items-center space-x-4">
                    <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime($selected_date . ' -1 day')); ?>" 
                       class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded transition duration-200">
                        <i class="fas fa-chevron-left mr-1"></i>Previous
                    </a>
                    <span class="text-lg font-semibold text-gray-800">
                        <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                    </span>
                    <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?>" 
                       class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded transition duration-200">
                        Next<i class="fas fa-chevron-right ml-1"></i>
                    </a>
                </div>
            </div>
            
            <!-- Quick Date Navigation -->
            <div class="flex flex-wrap gap-2">
                <?php for ($i = 0; $i < 7; $i++): ?>
                    <?php $date = date('Y-m-d', strtotime("+$i days")); ?>
                    <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo $date; ?>" 
                       class="px-3 py-1 rounded <?php echo $date === $selected_date ? 'bg-primary text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> transition duration-200">
                        <?php echo date('M j', strtotime($date)); ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Time Slots Grid -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Time Slots for <?php echo $selected_date_obj->format('l, F j, Y'); ?></h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
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
                    
                    <div class="border rounded-lg p-4 <?php echo $is_booked ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'; ?>">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-semibold text-gray-800"><?php echo date('g:i A', strtotime($time_slot)); ?></span>
                            <?php if ($is_booked): ?>
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                    <i class="fas fa-times mr-1"></i>Booked
                                </span>
                            <?php else: ?>
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                    <i class="fas fa-check mr-1"></i>Available
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($is_booked && $booking_info): ?>
                            <div class="text-sm text-gray-600">
                                <div><strong>Booked by:</strong> <?php echo htmlspecialchars($booking_info['user_name']); ?></div>
                                <div><strong>Purpose:</strong> <?php echo htmlspecialchars($booking_info['purpose']); ?></div>
                                <div><strong>Time:</strong> <?php echo date('g:i A', strtotime($booking_info['start_time'])); ?> - 
                                                          <?php echo date('g:i A', strtotime($booking_info['end_time'])); ?></div>
                                <div><strong>Status:</strong> 
                                    <span class="inline-flex px-1 py-0.5 text-xs font-semibold rounded-full 
                                        <?php echo $booking_info['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo ucfirst($booking_info['status']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-gray-500">
                                Available for booking
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Legend</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="flex items-center">
                    <div class="w-4 h-4 bg-green-100 border border-green-200 rounded mr-3"></div>
                    <span class="text-sm text-gray-700">Available - Ready to book</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 bg-red-100 border border-red-200 rounded mr-3"></div>
                    <span class="text-sm text-gray-700">Booked - Not available</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 bg-yellow-100 border border-yellow-200 rounded mr-3"></div>
                    <span class="text-sm text-gray-700">Pending - Awaiting confirmation</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');

            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });

                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                        mobileMenu.classList.add('hidden');
                    }
                });
            }
        });
        
        // Facility image viewing function
        function viewFullImage(imageUrl, facilityName) {
            ModalSystem.show({
                title: facilityName + ' - Full Image View',
                content: `
                    <div class="text-center">
                        <div class="loading-enhanced mb-4">
                            <div class="spinner-enhanced"></div>
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
    </script>
</body>
</html>
