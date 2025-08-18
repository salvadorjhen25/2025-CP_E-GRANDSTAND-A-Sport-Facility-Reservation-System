<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$facility_filter = $_GET['facility'] ?? '';
$user_filter = $_GET['user'] ?? '';

// Build query for no-show reports
$query = "
    SELECT r.*, u.full_name as user_name, u.email as user_email, f.name as facility_name, f.hourly_rate, f.daily_rate
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE r.status = 'no_show'
";

$params = [];

if ($date_from) {
    $query .= " AND DATE(r.start_time) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(r.start_time) <= ?";
    $params[] = $date_to;
}

if ($facility_filter) {
    $query .= " AND r.facility_id = ?";
    $params[] = $facility_filter;
}

if ($user_filter) {
    $query .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$user_filter%";
    $params[] = "%$user_filter%";
}

$query .= " ORDER BY r.start_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$no_shows = $stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_no_shows,
        SUM(r.total_amount) as total_revenue_lost,
        COUNT(DISTINCT r.user_id) as unique_users,
        COUNT(DISTINCT r.facility_id) as facilities_affected
    FROM reservations r 
    WHERE r.status = 'no_show'
";

if ($date_from) {
    $stats_query .= " AND DATE(r.start_time) >= '$date_from'";
}
if ($date_to) {
    $stats_query .= " AND DATE(r.start_time) <= '$date_to'";
}

$stats_stmt = $pdo->query($stats_query);
$stats = $stats_stmt->fetch();

// Get facilities for filter
$stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
$facilities = $stmt->fetchAll();

// Get top users with no-shows
$top_users_query = "
    SELECT u.full_name, u.email, COUNT(*) as no_show_count, SUM(r.total_amount) as total_amount
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.status = 'no_show'
";

if ($date_from) {
    $top_users_query .= " AND DATE(r.start_time) >= '$date_from'";
}
if ($date_to) {
    $top_users_query .= " AND DATE(r.start_time) <= '$date_to'";
}

$top_users_query .= " GROUP BY r.user_id ORDER BY no_show_count DESC LIMIT 10";

$top_users_stmt = $pdo->query($top_users_query);
$top_users = $top_users_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>No-Show Reports - <?php echo SITE_NAME; ?></title>
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
                        <h1 class="text-xl font-bold text-gray-800"><?php echo SITE_NAME; ?> - Admin</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <a href="index.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="reservations.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-calendar mr-2"></i>Reservations
                    </a>
                    <a href="../auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">No-Show Reports</h1>
            <p class="text-gray-600">Track and analyze no-show incidents</p>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-red-100 text-red-600">
                        <i class="fas fa-user-times text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total No-Shows</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_no_shows']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                        <i class="fas fa-money-bill-wave text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Revenue Lost</p>
                        <p class="text-2xl font-bold text-gray-900">₱<?php echo number_format($stats['total_revenue_lost'], 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-users text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Unique Users</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['unique_users']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                        <i class="fas fa-building text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Facilities Affected</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['facilities_affected']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold mb-4">Filters</h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Facility</label>
                    <select name="facility" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">All Facilities</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($facility['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">User Search</label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($user_filter); ?>"
                           placeholder="Name or email" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- Top Users with No-Shows -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold mb-4">Top Users with No-Shows</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No-Show Count</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($top_users as $user): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                        <?php echo $user['no_show_count']; ?> no-shows
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    ₱<?php echo number_format($user['total_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="viewUserNoShows('<?php echo htmlspecialchars($user['email']); ?>')" 
                                            class="text-primary hover:text-secondary">
                                        <i class="fas fa-eye mr-1"></i>View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- No-Show Details Table -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    No-Show Details (<?php echo count($no_shows); ?>)
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Facility</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Booking Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marked At</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($no_shows)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    No no-show incidents found for the selected criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($no_shows as $no_show): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($no_show['user_name']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($no_show['user_email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($no_show['facility_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M j, Y', strtotime($no_show['start_time'])); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo date('g:i A', strtotime($no_show['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($no_show['end_time'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        ₱<?php echo number_format($no_show['total_amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo ucfirst($no_show['booking_type'] ?? 'hourly'); ?>
                                            <?php if ($no_show['booking_duration_hours']): ?>
                                                (<?php echo $no_show['booking_duration_hours']; ?>h)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y g:i A', strtotime($no_show['updated_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function viewUserNoShows(email) {
            // Add the user filter and reload the page
            const url = new URL(window.location);
            url.searchParams.set('user', email);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
