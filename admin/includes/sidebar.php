<?php
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Get statistics for notification badges
$stats = [];
try {
    $pdo = getDBConnection();
    
    // Pending reservations
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
    
    // Active usages (ongoing reservations)
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM reservations 
        WHERE status = 'confirmed' 
        AND start_time <= NOW() 
        AND end_time >= NOW()
    ");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
    
    // Pending payments
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM reservations 
        WHERE payment_status = 'pending' 
        AND payment_slip_url IS NULL
    ");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
    
    // No-show reservations
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
    $result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
    
    // New users in last 7 days
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE role = 'user' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    // Handle database errors gracefully
    $stats = [
        'pending' => 0,
        'active_usages' => 0,
        'pending_payments' => 0,
        'no_shows' => 0,
        'new_users' => 0
    ];
}
?>

<!-- Sidebar -->
<aside id="sidebar" class="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <a href="../index.php" class="flex items-center group">
            <div class="bg-gradient-to-br from-[#415E72] to-[#17313E] p-2 sm:p-3 rounded-xl mr-3 shadow-lg group-hover:shadow-xl transition-all duration-300">
                <i class="fas fa-building text-white text-xl sm:text-2xl"></i>
            </div>
            <div class="brand-text">
                <h1 class="text-xl sm:text-2xl font-bold bg-gradient-to-r from-gray-800 to-gray-600 bg-clip-text text-transparent">
                    <?php echo SITE_NAME; ?>
                </h1>
                <p class="text-xs sm:text-sm text-gray-500 font-medium">Administration Panel</p>
            </div>
        </a>
        <!-- Mobile close button -->
        <button id="sidebar-close" class="sidebar-close lg:hidden">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Admin Info -->
    <div class="sidebar-user-info">
        <div class="admin-badge">
			<i class="<?php echo $_SESSION['role'] === 'admin' ? 'fas fa-user-shield' : 'fas fa-user-tie'; ?>"></i>
			<div>
				<div class="text-sm sm:text-base font-semibold leading-tight"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
				<div class="mt-1">
					<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] sm:text-xs font-semibold bg-gradient-to-r from-[#415E72] to-[#17313E] text-white">
						Role: <?php echo ucfirst($_SESSION['role']); ?>
					</span>
				</div>
			</div>
        </div>
    </div>

    <!-- Navigation Links -->
    <nav class="sidebar-nav">
        <ul class="nav-list">
            <li>
                <a href="dashboard.php" class="nav-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="facilities.php" class="nav-item <?php echo $current_page == 'facilities.php' ? 'active' : ''; ?>">
                    <i class="fas fa-building"></i>
                    <span>Facilities</span>
                </a>
            </li>
            <li>
                <a href="categories.php" class="nav-item <?php echo $current_page == 'categories.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tags"></i>
                    <span>Categories</span>
                </a>
            </li>
            <li>
                <a href="facility_events.php" class="nav-item <?php echo $current_page == 'facility_events.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-times"></i>
                    <span>Facility Events</span>
                </a>
            </li>
            <li>
                <a href="reservations.php" class="nav-item <?php echo $current_page == 'reservations.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Reservations & Payments</span>
                    <?php if (($stats['pending'] ?? 0) > 0 || ($stats['pending_payments'] ?? 0) > 0): ?>
                    <div class="notification-badge error">
                        <?php echo (($stats['pending'] ?? 0) + ($stats['pending_payments'] ?? 0)) > 99 ? '99+' : (($stats['pending'] ?? 0) + ($stats['pending_payments'] ?? 0)); ?>
                    </div>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="usage_management.php" class="nav-item <?php echo $current_page == 'usage_management.php' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i>
                    <span>Usage Management</span>
                    <?php if (($stats['active_usages'] ?? 0) > 0): ?>
                    <div class="notification-badge info">
                        <?php echo ($stats['active_usages'] ?? 0) > 99 ? '99+' : ($stats['active_usages'] ?? 0); ?>
                    </div>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="usage_history.php" class="nav-item <?php echo $current_page == 'usage_history.php' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Usage History</span>
                </a>
            </li>
            <li>
                <a href="payment_history.php" class="nav-item <?php echo $current_page == 'payment_history.php' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payment History</span>
                </a>
            </li>
            <li>
                <a href="no_show_reports.php" class="nav-item <?php echo $current_page == 'no_show_reports.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-times"></i>
                    <span>Cancel History</span>
                    <?php if (($stats['no_shows'] ?? 0) > 0): ?>
                    <div class="notification-badge warning">
                        <?php echo ($stats['no_shows'] ?? 0) > 99 ? '99+' : ($stats['no_shows'] ?? 0); ?>
                    </div>
                    <?php endif; ?>
                </a>
            </li>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <li>
                <a href="users.php" class="nav-item <?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                    <?php if (($stats['new_users'] ?? 0) > 0): ?>
                    <div class="notification-badge success">
                        <?php echo ($stats['new_users'] ?? 0) > 99 ? '99+' : ($stats['new_users'] ?? 0); ?>
                    </div>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="../admin_feedback.php" class="nav-item <?php echo $current_page == 'admin_feedback.php' ? 'active' : ''; ?>">
                    <i class="fas fa-comments"></i>
                    <span>Feedback & Reviews</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Logout Section -->
    <div class="sidebar-footer">
        <a href="../auth/logout.php" class="nav-item logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Mobile Overlay -->
<div id="sidebar-overlay" class="sidebar-overlay lg:hidden"></div>

<!-- Mobile Menu Toggle -->
<button id="sidebar-toggle" class="sidebar-toggle lg:hidden">
    <span></span>
    <span></span>
    <span></span>
</button>
