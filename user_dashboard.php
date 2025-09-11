<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();

// Get user's reservation
$stmt = $pdo->prepare("
    SELECT r.*, f.name as facility_name, f.hourly_rate, c.name as category_name
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$user_reservations = $stmt->fetchAll();

// Get user's pending reservations
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM reservations 
    WHERE user_id = ? AND status = 'pending'
");
$stmt->execute([$_SESSION['user_id']]);
$pending_count = $stmt->fetch()['count'];

// Get user's total spent
$stmt = $pdo->prepare("
    SELECT SUM(total_amount) as total 
    FROM reservations 
    WHERE user_id = ? AND status = 'confirmed'
");
$stmt->execute([$_SESSION['user_id']]);
$total_spent = $stmt->fetch()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - <?php echo SITE_NAME; ?></title>
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
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .reservation-card {
            transition: all 0.3s ease;
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
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
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="my_reservations.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-calendar mr-2"></i>My Reservations
                    </a>
                    <a href="index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-home mr-2"></i>Browse Facilities
                    </a>
                    <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
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
                <i class="fas fa-user text-primary mr-3"></i>User Dashboard
            </h1>
            <p class="text-gray-600">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Total Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo count($user_reservations); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Pending Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-clock text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Pending Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $pending_count; ?></dd>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Total Spent -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-money-bill text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Spent</dt>
                            <dd class="text-lg font-semibold text-gray-900">₱<?php echo number_format($total_spent, 2); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <a href="index.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-primary rounded-md flex items-center justify-center">
                            <i class="fas fa-search text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Browse Facilities</h3>
                        <p class="text-sm text-gray-500">Find and book available facilities</p>
                    </div>
                </div>
            </a>

            <a href="my_reservations.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.5s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar-check text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">My Reservations</h3>
                        <p class="text-sm text-gray-500">View and manage your bookings</p>
                    </div>
                </div>
            </a>
        </div>

        <!-- Recent Reservations -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate-slide-up" style="animation-delay: 0.6s;">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list text-primary mr-2"></i>Recent Reservations
                </h2>
            </div>
            <div class="p-6">
                <?php if (!empty($user_reservations)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach (array_slice($user_reservations, 0, 6) as $reservation): ?>
                            <div class="reservation-card bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                    <?php
                                    $statusColors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'confirmed' => 'bg-green-100 text-green-800',
                                        'completed' => 'bg-blue-100 text-blue-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $statusIcons = [
                                        'pending' => 'fas fa-clock',
                                        'confirmed' => 'fas fa-check',
                                        'completed' => 'fas fa-check-double',
                                        'cancelled' => 'fas fa-times'
                                    ];
                                    ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$reservation['status']]; ?>">
                                        <i class="<?php echo $statusIcons[$reservation['status']]; ?> mr-1"></i>
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mb-2">
                                    <i class="fas fa-calendar mr-1"></i>
                                    <?php echo date('M j, Y', strtotime($reservation['start_time'])); ?>
                                </p>
                                <p class="text-sm text-gray-600 mb-2">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </p>
                                <p class="text-sm font-semibold text-green-600">
                                    <i class="fas fa-money-bill mr-1"></i>
                                    ₱<?php echo number_format($reservation['total_amount'], 2); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-calendar text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-semibold text-gray-600 mb-2">No reservations yet</h3>
                        <p class="text-gray-500 mb-4">Start by browsing and booking facilities</p>
                        <a href="index.php" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-search mr-2"></i>Browse Facilities
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Add loading states to links
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                if (!this.classList.contains('no-loading')) {
                    this.style.pointerEvents = 'none';
                    const originalContent = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                    
                    setTimeout(() => {
                        this.style.pointerEvents = 'auto';
                        this.innerHTML = originalContent;
                    }, 1000);
                }
            });
        });
    </script>
</body>
</html>
