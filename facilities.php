<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();
$auth->requireRegularUser();

$pdo = getDBConnection();

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'name';
$sort_order = $_GET['sort_order'] ?? 'asc';

// Build the query with filters
$where_conditions = ['f.is_active = 1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(f.name LIKE ? OR f.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category_id)) {
    $where_conditions[] = "f.category_id = ?";
    $params[] = $category_id;
}

$where_clause = implode(' AND ', $where_conditions);

// Validate sort parameters
$allowed_sort_fields = ['name', 'capacity', 'hourly_rate', 'created_at'];
$allowed_sort_orders = ['asc', 'desc'];

if (!in_array($sort_by, $allowed_sort_fields)) {
    $sort_by = 'name';
}
if (!in_array($sort_order, $allowed_sort_orders)) {
    $sort_order = 'asc';
}

// Get facilities with categories, pricing options, event closure info, and ratings
$stmt = $pdo->prepare("
    SELECT f.*, c.name as category_name,
           CASE 
               WHEN f.is_closed_for_event = 1 AND f.closure_end_date >= CURDATE() THEN 1
               ELSE 0
           END as is_currently_closed,
           f.closure_reason,
           f.closure_end_date,
           COALESCE(f.average_rating, 0) as average_rating,
           COALESCE(f.total_ratings, 0) as total_ratings
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE $where_clause
    ORDER BY f.$sort_by $sort_order
");
$stmt->execute($params);
$facilities = $stmt->fetchAll();

// Get pricing options for each facility
foreach ($facilities as &$facility) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, description, pricing_type, price_per_unit, price_per_hour, is_active, sort_order 
            FROM facility_pricing_options 
            WHERE facility_id = ? AND is_active = 1 
            ORDER BY sort_order ASC, name ASC
            LIMIT 3
        ");
        $stmt->execute([$facility['id']]);
        $facility['pricing_options'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $facility['pricing_options'] = [];
    }
}
// Important: Unset the reference to avoid bugs
unset($facility);

// Get categories for filter dropdown
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

// Get facility statistics
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_facilities,
        AVG(capacity) as avg_capacity,
        MIN(hourly_rate) as min_rate,
        MAX(hourly_rate) as max_rate
    FROM facilities 
    WHERE is_active = 1
");
$stats = $stats_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Facilities - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css">
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
        
        .facility-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .facility-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .facility-card:hover::before {
            left: 100%;
        }
        
        .facility-card:hover {
            transform: translateY(-4px);
        }
        
        .filter-card {
            transition: all 0.3s ease;
        }
        
        .filter-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stats-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shimmer 3s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .search-input {
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            transform: scale(1.02);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .category-badge {
            transition: all 0.2s ease;
        }
        
        .category-badge:hover {
            transform: scale(1.05);
        }
        
        .sort-button {
            transition: all 0.2s ease;
        }
        
        .sort-button:hover {
            transform: translateY(-1px);
        }
        
        .floating-action {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            animation: bounce-in 0.8s ease-out;
        }
        
        .image-gallery {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .image-gallery img {
            transition: transform 0.5s ease;
        }
        
        .image-gallery:hover img {
            transform: scale(1.05);
        }
        
        .image-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.4), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .image-gallery:hover .image-overlay {
            opacity: 1;
        }
        
        .pricing-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .pricing-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(147, 51, 234, 0.1), transparent);
            transition: left 0.5s;
        }
        
        .pricing-card:hover::before {
            left: 100%;
        }
        
        .pricing-card:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 25px -5px rgba(147, 51, 234, 0.2);
        }
        
        .availability-indicator {
            position: relative;
            overflow: hidden;
        }
        
        .availability-indicator::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shimmer 2s infinite;
        }
        
        .no-results {
            animation: bounceIn 0.8s ease-out;
        }
        
        .loading-skeleton {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        p, span, div, a, button {
            font-family: 'Poppins', sans-serif !important;
           
        }
           /* Modern Sidebar Navigation */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .sidebar-logo {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }
        
        .sidebar-title {
            font-family: 'Inter', sans-serif;
            font-weight: 800;
            font-size: 1.25rem;
            color: white;
            line-height: 1.2;
        }
        
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
        }
        
        .sidebar-user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }
        
        .sidebar-user-info {
            flex: 1;
            min-width: 0;
        }
        
        .sidebar-user-name {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.875rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sidebar-user-role {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .sidebar-nav {
            flex: 1;
            padding: 1.5rem 1rem;
            overflow-y: auto;
        }
        
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        
        .sidebar-nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            margin-bottom: 0.5rem;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: white;
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }
        
        .sidebar-nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(4px);
        }
        
        .sidebar-nav-item:hover::before {
            transform: scaleY(1);
        }
        
        .sidebar-nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            font-weight: 600;
        }
        
        .sidebar-nav-item.active::before {
            transform: scaleY(1);
        }
        
        .sidebar-nav-item i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-nav-item.logout {
            background: rgba(220, 38, 38, 0.15);
            color: #fca5a5;
            margin-top: auto;
        }
        
        .sidebar-nav-item.logout:hover {
            background: rgba(220, 38, 38, 0.3);
            color: #fecaca;
        }
        
        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Mobile Toggle Button */
        .sidebar-toggle {
            position: fixed;
            top: 1.25rem;
            left: 1.25rem;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border: none;
            border-radius: 12px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transition: all 0.3s ease;
        }
        
        .sidebar-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.5);
        }
        
        .sidebar-toggle i {
            color: white;
            font-size: 1.25rem;
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Main Content Wrapper */
        .main-wrapper {
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: flex;
            }
            
            .main-wrapper {
                margin-left: 0;
                padding-top: 80px;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
            }
            
            .sidebar-toggle {
                top: 1rem;
                left: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen">
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Mobile Toggle Button -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Modern Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="sidebar-logo">
                    <i class="fas fa-building text-white text-xl"></i>
                </div>
                <h1 class="sidebar-title"><?php echo SITE_NAME; ?></h1>
            </div>
            
            <!-- User Info -->
            <div class="sidebar-user">
                <div class="sidebar-user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <div class="sidebar-user-role">User</div>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Navigation -->
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-nav-item">
                <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
            <a href="facilities.php" class="sidebar-nav-item active">
                <i class="fas fa-building"></i>
                    <span>Facilities</span>
                </a>
            <a href="my_reservations.php" class="sidebar-nav-item">
                <i class="fas fa-calendar-check"></i>
                            <span>My Reservations</span>
                        </a>
            <a href="archived_reservations.php" class="sidebar-nav-item">
                <i class="fas fa-archive"></i>
                <span>Archived</span>
            </a>
            <a href="usage_history.php" class="sidebar-nav-item">
                <i class="fas fa-history"></i>
                <span>Usage History</span>
            </a>
        </nav>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <a href="auth/logout.php" class="sidebar-nav-item logout" onclick="return confirmLogout()">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
                    </a>
                </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="main-wrapper">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb Navigation -->
        <nav class="mb-8 animate-fade-in">
            <ol class="flex items-center space-x-2 text-sm text-gray-600">
                <li>
                    <a href="index.php" class="hover:text-blue-600 transition-colors duration-200">
                        <i class="fas fa-home mr-1"></i>Home
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li class="text-blue-600 font-medium">
                    <i class="fas fa-building mr-1"></i>Facilities
                </li>
            </ol>
        </nav>

        

        <!-- Search and Filter Section -->
        <div class="enhanced-card p-6 mb-8 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-blue-100">
                <h3 class="text-lg font-semibold text-blue-800">
                    <i class="fas fa-search mr-2"></i>
                    Find Your Perfect Facility
                </h3>
                <p class="text-sm text-blue-600 mt-1">Search and filter facilities to find exactly what you need</p>
            </div>
            
            <div class="p-6">
                <form method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Search Input -->
                        <div class="md:col-span-2">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-search mr-1"></i>Search Facilities
                            </label>
                            <div class="relative">
                                <input type="text" 
                                       id="search" 
                                       name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Search by facility name or description..."
                                       class="search-input w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-black placeholder-gray-500">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Category Filter -->
                        <div>
                            <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-filter mr-1"></i>Category
                            </label>
                            <select id="category_id" 
                                    name="category_id" 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-black">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" 
                                            <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Sort Options -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-sort mr-1"></i>Sort By
                            </label>
                            <select id="sort_by" 
                                    name="sort_by" 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-black">
                                <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>Name</option>
                                <option value="capacity" <?php echo $sort_by == 'capacity' ? 'selected' : ''; ?>>Capacity</option>
                                <option value="hourly_rate" <?php echo $sort_by == 'hourly_rate' ? 'selected' : ''; ?>>Price</option>
                                <option value="created_at" <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-sort-amount-down mr-1"></i>Order
                            </label>
                            <select id="sort_order" 
                                    name="sort_order" 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-black">
                                <option value="asc" <?php echo $sort_order == 'asc' ? 'selected' : ''; ?>>Ascending</option>
                                <option value="desc" <?php echo $sort_order == 'desc' ? 'selected' : ''; ?>>Descending</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-search mr-2"></i>Search Facilities
                        </button>
                        
                        <a href="facilities.php" 
                           class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg text-center">
                            <i class="fas fa-refresh mr-2"></i>Reset Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results Summary -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8 animate-fade-in">
            <div class="mb-4 sm:mb-0">
                <h2 class="text-2xl font-bold text-gray-900">
                    <?php echo count($facilities); ?> 
                    <?php echo count($facilities) == 1 ? 'Facility' : 'Facilities'; ?> 
                    Found
                </h2>
                <?php if (!empty($search) || !empty($category_id)): ?>
                    <p class="text-gray-600 mt-1">
                        <?php if (!empty($search)): ?>
                            Searching for "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                        <?php if (!empty($search) && !empty($category_id)): ?> • <?php endif; ?>
                        <?php if (!empty($category_id)): ?>
                            <?php 
                            $selected_category = array_filter($categories, function($cat) use ($category_id) {
                                return $cat['id'] == $category_id;
                            });
                            $selected_category = reset($selected_category);
                            echo 'Category: ' . htmlspecialchars($selected_category['name']);
                            ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Quick Category Filters -->
            <div class="flex flex-wrap gap-2">
                <a href="facilities.php" 
                   class="category-badge px-4 py-2 rounded-full text-sm font-medium transition-all duration-200 <?php echo empty($category_id) ? 'bg-blue-500 text-white shadow-lg' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                    All
                </a>
                <?php foreach ($categories as $category): ?>
                    <a href="facilities.php?category_id=<?php echo $category['id']; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="category-badge px-4 py-2 rounded-full text-sm font-medium transition-all duration-200 <?php echo $category_id == $category['id'] ? 'bg-blue-500 text-white shadow-lg' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                        <?php echo htmlspecialchars($category['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Facilities Grid -->
        <?php if (!empty($facilities)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 animate-slide-up">
                <?php foreach ($facilities as $index => $facility): ?>
                    <div class="facility-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 animate-slide-up" 
                         style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        
                        <!-- Facility Image -->
                        <div class="h-48 bg-gradient-to-br from-green-400 to-blue-500 flex items-center justify-center relative overflow-hidden">
                            <?php if ($facility['image_url'] && file_exists($facility['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                     class="w-full h-full object-cover">
                                <div class="image-overlay"></div>
                                <div class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                    <button onclick="viewFullImage('<?php echo htmlspecialchars($facility['image_url']); ?>', '<?php echo htmlspecialchars($facility['name']); ?>')" 
                                            class="bg-white/90 hover:bg-white text-gray-800 px-3 py-1 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                                        <i class="fas fa-expand-arrows-alt mr-1"></i>View
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                                <div class="relative z-10 text-center">
                                    <i class="fas fa-building text-white text-5xl mb-2"></i>
                                    <p class="text-white text-sm font-medium">No Image</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Status Badge -->
                            <div class="absolute top-3 left-3">
                                <?php if ($facility['is_currently_closed']): ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-400 text-red-900">
                                        <i class="fas fa-times mr-1"></i>
                                        Closed for Event
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-400 text-green-900">
                                        <i class="fas fa-check mr-1"></i>
                                        Available
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Category Badge -->
                            <div class="absolute bottom-3 left-3">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-blue-400 text-blue-900">
                                    <i class="fas fa-tag mr-1"></i>
                                    <?php echo htmlspecialchars($facility['category_name']); ?>
                                </span>
                            </div>
                            
                            <!-- Pricing Options Badge -->
                            <?php if (!empty($facility['pricing_options'])): ?>
                            <div class="absolute bottom-3 right-3">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-purple-400 text-purple-900">
                                    <i class="fas fa-tags mr-1"></i>
                                    <?php echo count($facility['pricing_options']); ?> Options
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Facility Content -->
                        <div class="p-6">
                            <div class="mb-4">
                                <h3 class="text-xl font-bold text-gray-900 mb-2">
                                    <?php echo htmlspecialchars($facility['name']); ?>
                                </h3>
                                <p class="text-gray-600 text-sm leading-relaxed">
                                    <?php echo htmlspecialchars(substr($facility['description'], 0, 120)) . (strlen($facility['description']) > 120 ? '...' : ''); ?>
                                </p>
                                
                                <!-- Closure Notice -->
                                <?php if ($facility['is_currently_closed']): ?>
                                    <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                                        <div class="flex items-start">
                                            <i class="fas fa-exclamation-triangle text-red-500 mr-2 mt-0.5"></i>
                                            <div>
                                                <h4 class="text-sm font-semibold text-red-800">Facility Closed for Event</h4>
                                                <p class="text-sm text-red-700 mt-1">
                                                    <strong>Reason:</strong> <?php echo htmlspecialchars($facility['closure_reason']); ?>
                                                </p>
                                                <?php if ($facility['closure_end_date']): ?>
                                                    <p class="text-sm text-red-700">
                                                        <strong>Reopens:</strong> <?php echo date('M j, Y', strtotime($facility['closure_end_date'])); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Facility Details -->
                            <div class="space-y-3 mb-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-users text-blue-500 mr-2"></i>
                                        <span class="text-sm text-gray-600">Capacity</span>
                                    </div>
                                    <span class="font-semibold text-gray-900"><?php echo $facility['capacity']; ?> people</span>
                                </div>
                                
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-clock text-green-500 mr-2"></i>
                                        <span class="text-sm text-gray-600">Operating Hours</span>
                                    </div>
                                    <span class="font-semibold text-gray-900">8:00 AM - 10:00 PM</span>
                                </div>
                                
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-tag text-purple-500 mr-2"></i>
                                        <span class="text-sm text-gray-600">
                                            Base Rate
                                        </span>
                                    </div>
                                    <span class="font-semibold text-gray-900">
                                        ₱<?php echo number_format($facility['hourly_rate'], 0); ?>/hr
                                    </span>
                                </div>
                                
                                <!-- Rating Information -->
                                <?php if ($facility['total_ratings'] > 0): ?>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-star text-yellow-500 mr-2"></i>
                                        <span class="text-sm text-gray-600">Rating</span>
                                    </div>
                                    <div class="flex items-center">
                                        <div class="flex items-center mr-2">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star text-xs <?php echo $i <= $facility['average_rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="font-semibold text-gray-900">
                                            <?php echo number_format($facility['average_rating'], 1); ?> 
                                            <span class="text-xs text-gray-500">(<?php echo $facility['total_ratings']; ?>)</span>
                                        </span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-star text-gray-400 mr-2"></i>
                                        <span class="text-sm text-gray-600">Rating</span>
                                    </div>
                                    <span class="text-sm text-gray-500">No ratings yet</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Enhanced Pricing Options Display -->
                            <?php if (!empty($facility['pricing_options'])): ?>
                                <div class="mb-6">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-semibold text-gray-700 flex items-center">
                                            <i class="fas fa-tags mr-2 text-purple-500"></i>Prices
                                        </h4>
                                        <span class="bg-gradient-to-r from-purple-500 to-purple-600 text-white px-2 py-1 rounded-full text-xs font-bold">
                                            <?php echo count($facility['pricing_options']); ?> Options
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-600 mb-3 bg-purple-50 border border-purple-200 rounded p-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        These prices are in addition to the base hourly rate of ₱<?php echo number_format($facility['hourly_rate'], 0); ?>/hr
                                    </div>
                                    <div class="space-y-2">
                                        <?php foreach (array_slice($facility['pricing_options'], 0, 3) as $index => $option): ?>
                                            <div class="pricing-card bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg p-3 border border-purple-200 hover:border-purple-300 transition-all duration-200">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center">
                                                        <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center mr-2">
                                                            <i class="fas fa-tag text-purple-600 text-xs"></i>
                                                        </div>
                                                        <div>
                                                            <span class="text-sm font-medium text-purple-800"><?php echo htmlspecialchars($option['name']); ?></span>
                                                            <?php if ($option['description']): ?>
                                                                <div class="text-xs text-purple-600 truncate max-w-[120px]"><?php echo htmlspecialchars($option['description']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="text-sm font-bold text-purple-900">₱<?php echo number_format($option['price_per_unit'] ?: $option['price_per_hour'] ?: 0, 0); ?></span>
                                                        <?php if ($index === 0): ?>
                                                            <div class="text-xs text-green-600 font-medium">Starting</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($facility['pricing_options']) > 3): ?>
                                            <div class="text-center">
                                                <div class="inline-flex items-center px-3 py-1 bg-gradient-to-r from-gray-100 to-gray-200 rounded-full text-xs text-gray-600 font-medium">
                                                    <i class="fas fa-plus mr-1"></i>
                                                    <?php echo count($facility['pricing_options']) - 3; ?> more options available
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                   
                                </div>
                            <?php else: ?>
                                <!-- Standard Pricing Display -->
                                <div class="mb-6">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-semibold text-gray-700 flex items-center">
                                            <i class="fas fa-tag mr-2 text-blue-500"></i>Standard Pricing
                                        </h4>
                                        <span class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-2 py-1 rounded-full text-xs font-bold">
                                            Fixed Rate
                                        </span>
                                    </div>
                                    <div class="pricing-card bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-3 border border-blue-200">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center mr-2">
                                                    <i class="fas fa-clock text-blue-600 text-xs"></i>
                                                </div>
                                                <span class="text-sm font-medium text-blue-800">Hourly Rate</span>
                                            </div>
                                            <span class="text-sm font-bold text-blue-900">₱<?php echo number_format($facility['hourly_rate'], 0); ?>/hr</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Action Buttons -->
                            <div class="space-y-3">
                                <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" 
                                   class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg text-center block">
                                    <i class="fas fa-eye mr-2"></i>View Details
                                </a>
                                
                                <?php if ($_SESSION['role'] !== 'admin'): ?>
                                    <?php if ($facility['is_currently_closed']): ?>
                                        <button disabled 
                                                class="w-full bg-gray-400 text-white px-4 py-3 rounded-lg font-semibold cursor-not-allowed shadow-lg text-center block">
                                            <i class="fas fa-times mr-2"></i>Closed for Event
                                        </button>
                                    <?php else: ?>
                                        <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                                           class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-lg font-semibold transition-all duration-300 transform hover:scale-105 shadow-lg text-center block book-now-link"
                                           data-facility-id="<?php echo $facility['id']; ?>">
                                            <i class="fas fa-calendar-plus mr-2"></i>Book Now
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- No Results -->
            <div class="no-results enhanced-card p-12 text-center animate-slide-up">
                <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-search text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-4">No Facilities Found</h3>
                <p class="text-gray-600 mb-6 max-w-md mx-auto">
                    <?php if (!empty($search) || !empty($category_id)): ?>
                        We couldn't find any facilities matching your search criteria. Try adjusting your filters or search terms.
                    <?php else: ?>
                        There are currently no facilities available. Please check back later or contact the administrator.
                    <?php endif; ?>
                </p>
                <div class="space-y-3">
                    <?php if (!empty($search) || !empty($category_id)): ?>
                        <a href="facilities.php" 
                           class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg font-semibold transition-colors duration-200">
                            <i class="fas fa-refresh mr-2"></i>Clear Filters
                        </a>
                    <?php endif; ?>
                    <a href="index.php" 
                       class="inline-flex items-center px-6 py-3 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-semibold transition-colors duration-200 ml-3">
                        <i class="fas fa-home mr-2"></i>Back to Home
                    </a>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            function toggleSidebar() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                
                // Change icon
                const icon = sidebarToggle.querySelector('i');
                if (sidebar.classList.contains('active')) {
                    icon.className = 'fas fa-times';
                } else {
                    icon.className = 'fas fa-bars';
                }
            }
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', toggleSidebar);
            }
            
            // Close sidebar on navigation (mobile)
            const sidebarLinks = document.querySelectorAll('.sidebar-nav-item');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 1024) {
                        sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        const icon = sidebarToggle.querySelector('i');
                        icon.className = 'fas fa-bars';
                    }
                });
            });
            
            // Add smooth scrolling to all links
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
            
            // Add intersection observer for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observe all cards for animation
            document.querySelectorAll('.enhanced-card, .facility-card, .stats-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
            
            // Enhanced search functionality
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    // Add visual feedback for search
                    if (this.value.length > 0) {
                        this.style.borderColor = '#3b82f6';
                        this.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.1)';
                    } else {
                        this.style.borderColor = '#d1d5db';
                        this.style.boxShadow = 'none';
                    }
                });
            }
            
            // Form auto-submit on filter change
            const filterSelects = document.querySelectorAll('select[name="category_id"], select[name="sort_by"], select[name="sort_order"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // Add loading state
                    const form = this.closest('form');
                    if (form) {
                        const submitButton = form.querySelector('button[type="submit"]');
                        if (submitButton) {
                            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Searching...';
                            submitButton.disabled = true;
                        }
                        form.submit();
                    }
                });
            });

            // Wire pricing selection to Book Now links
            const selects = document.querySelectorAll('.pricing-select');
            const bookLinks = document.querySelectorAll('.book-now-link');

            function getSelectedPricingForFacility(facilityId) {
                const select = document.querySelector(`.pricing-select[data-facility-id="${facilityId}"]`);
                return select ? select.value : null;
            }

            bookLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const facilityId = this.getAttribute('data-facility-id');
                    const poId = getSelectedPricingForFacility(facilityId);
                    if (poId) {
                        const url = new URL(this.href, window.location.origin);
                        url.searchParams.set('pricing_option_id', poId);
                        this.href = url.toString();
                    }
                });
            });
        });
        
        // Enhanced facility image viewing function
        function viewFullImage(imageUrl, facilityName) {
            ModalSystem.show({
                title: facilityName + ' - Full Image View',
                content: `
                    <div class="text-center">
                        <div class="loading-enhanced mb-4">
                            <div class="spinner-enhanced spinner-large-enhanced"></div>
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
        
        // Add hover effects to facility cards
        document.addEventListener('DOMContentLoaded', function() {
            const facilityCards = document.querySelectorAll('.facility-card');
            facilityCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again.');
        }
    </script>
</body>
</html>
