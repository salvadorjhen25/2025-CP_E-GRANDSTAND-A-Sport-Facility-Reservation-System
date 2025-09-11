<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Get stats
$stats = [];

// Total users (excluding admins)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$stats['users'] = $stmt->fetch()['count'];

// Total facilities
$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$stats['facilities'] = $stmt->fetch()['count'];

// Total reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$stats['reservations'] = $stmt->fetch()['count'];

// Pending reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$stats['pending'] = $stmt->fetch()['count'];

// No-show reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$stats['no_shows'] = $stmt->fetch()['count'];

// Recent reservations
$stmt = $pdo->query("
    SELECT r.*, u.full_name as user_name, f.name as facility_name 
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    ORDER BY r.created_at DESC 
    LIMIT 5
");
$recent_reservations = $stmt->fetchAll();

// Revenue this month
$stmt = $pdo->query("
    SELECT SUM(total_amount) as total 
    FROM reservations 
    WHERE status = 'confirmed' 
    AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$monthly_revenue = $stmt->fetch()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/enhanced-ui.css">
    <script src="../assets/js/modal-system.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .reservation-row {
            transition: all 0.2s ease;
        }
        .reservation-row:hover {
            background-color: #f8fafc;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Loading dashboard...</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="../index.php" class="flex items-center">
                        <i class="fas fa-building text-primary text-2xl mr-3"></i>
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Admin</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="facilities.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-building mr-2"></i>Facilities
                    </a>
                    <a href="categories.php" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-tags mr-2"></i>Categories
                    </a>
                    <a href="usage_management.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-clock mr-2"></i>Usage Management
                    </a>
                    <a href="no_show_reports.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-user-times mr-2"></i>No-Show Reports
                    </a>
                    <a href="users.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                        <i class="fas fa-users-cog mr-2"></i>Users
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
        <!-- Error Messages -->
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'admins_cannot_book'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 text-xl"></i>
                    <div>
                        <h3 class="text-lg font-semibold text-red-800">Access Restricted</h3>
                        <p class="text-red-700">Administrators cannot book facilities. Please use the admin panel to manage reservations and facilities.</p>
                    </div>
                </div>
            </div>
            <?php elseif ($_GET['error'] === 'admins_cannot_access_user_features'): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 text-xl"></i>
                    <div>
                        <h3 class="text-lg font-semibold text-red-800">Access Restricted</h3>
                        <p class="text-red-700">Administrators cannot access user-specific features. Please use the admin panel for management tasks.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center">
                <i class="fas fa-tachometer-alt text-primary mr-3"></i>Admin Dashboard
            </h1>
            <p class="text-gray-600">Welcome to the administration panel</p>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8 stats-grid">
            <!-- Total Users -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-users text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Users</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['users']; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-green-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>12% increase</span>
                    </div>
                </div>
            </div>

            <!-- Total Facilities -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-building text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Active Facilities</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['facilities']; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-green-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>5% increase</span>
                    </div>
                </div>
            </div>

            <!-- Total Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Total Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['reservations']; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-blue-600">
                        <i class="fas fa-arrow-up mr-1"></i>
                        <span>8% increase</span>
                    </div>
                </div>
            </div>

            <!-- Pending Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-clock text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Pending Reservations</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['pending']; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-red-600">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <span>Requires attention</span>
                    </div>
                </div>
            </div>

            <!-- No-Show Reservations -->
            <div class="stat-card bg-white rounded-lg shadow-md p-6 animate-slide-up" style="animation-delay: 0.5s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-orange-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-user-times text-white"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">No-Show Incidents</dt>
                            <dd class="text-lg font-semibold text-gray-900"><?php echo $stats['no_shows']; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center text-sm text-orange-600">
                        <i class="fas fa-chart-line mr-1"></i>
                        <span>Track patterns</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Section -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8 animate-slide-up" style="animation-delay: 0.5s;">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-chart-line text-green-600 mr-2"></i>Monthly Revenue
                </h2>
                <div class="text-right">
                    <p class="text-2xl font-bold text-green-600">₱<?php echo number_format($monthly_revenue, 2); ?></p>
                    <p class="text-sm text-gray-500">This month</p>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Recent Reservations -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden animate-slide-up" style="animation-delay: 0.6s;">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list text-primary mr-2"></i>Recent Reservations
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-user mr-2"></i>User
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-building mr-2"></i>Facility
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-calendar mr-2"></i>Date & Time
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-money-bill mr-2"></i>Amount
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <i class="fas fa-info-circle mr-2"></i>Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recent_reservations as $reservation): ?>
                            <tr class="reservation-row hover:bg-gray-50 transition duration-200">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center">
                                            <span class="text-white text-sm font-medium">
                                                <?php echo strtoupper(substr($reservation['user_name'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($reservation['user_name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo date('M j, Y', strtotime($reservation['start_time'])); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="font-semibold text-green-600">₱<?php echo number_format($reservation['total_amount'], 2); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
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
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo isset($statusColors[$reservation['status']]) ? $statusColors[$reservation['status']] : 'bg-gray-100 text-gray-800'; ?>">
                                        <i class="<?php echo isset($statusIcons[$reservation['status']]) ? $statusIcons[$reservation['status']] : 'fas fa-question'; ?> mr-1"></i>
                                        <?php echo ucfirst($reservation['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-8">
            <a href="reservations.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.7s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-calendar-check text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Manage Reservations</h3>
                        <p class="text-sm text-gray-500">View and manage all reservations</p>
                    </div>
                </div>
            </a>

            <a href="facilities.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.8s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-building text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Manage Facilities</h3>
                        <p class="text-sm text-gray-500">Add, edit, and manage facilities</p>
                    </div>
                </div>
            </a>

            <a href="no_show_reports.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 0.9s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-user-times text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">No-Show Reports</h3>
                        <p class="text-sm text-gray-500">Track and analyze no-show incidents</p>
                    </div>
                </div>
            </a>

            <a href="users.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-200 transform hover:scale-105 animate-slide-up" style="animation-delay: 1.0s;">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-users-cog text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">User Management</h3>
                        <p class="text-sm text-gray-500">Manage user accounts and permissions</p>
                    </div>
                </div>
            </a>
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

        // Revenue Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            // Sample data - replace with actual data from your database
            const revenueData = {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Monthly Revenue',
                    data: [12000, 19000, 15000, 25000, 22000, 30000],
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            };

            new Chart(ctx, {
                type: 'line',
                data: revenueData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 4,
                            hoverRadius: 6
                        }
                    }
                }
            });

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

            // Auto-refresh dashboard data every 30 seconds
            setInterval(function() {
                // You can add AJAX calls here to refresh dashboard data
        
            }, 30000);
        });
    </script>
</body>
</html>
