<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();

// Redirect admin users to admin dashboard
if ($auth->isLoggedIn() && $auth->isAdmin()) {
    header('Location: admin/dashboard.php');
    exit();
}

$pdo = getDBConnection();

// Get facilities with categories
$stmt = $pdo->query("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE f.is_active = 1 
    ORDER BY f.name
");
$facilities = $stmt->fetchAll();

// Get categories for filter
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

// Get currently active reservations (confirmed and in progress)
$stmt = $pdo->query("
    SELECT r.*, u.full_name as user_name, f.name as facility_name, f.hourly_rate, c.name as category_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    WHERE r.status IN ('confirmed', 'pending')
    AND r.start_time <= NOW()
    AND r.end_time >= NOW()
    ORDER BY r.start_time ASC
");
$active_reservations = $stmt->fetchAll();

// Get upcoming reservations for today
$stmt = $pdo->query("
    SELECT r.*, u.full_name as user_name, f.name as facility_name, f.hourly_rate, c.name as category_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    WHERE r.status IN ('confirmed', 'pending')
    AND DATE(r.start_time) = CURDATE()
    AND r.start_time > NOW()
    ORDER BY r.start_time ASC
    LIMIT 5
");
$upcoming_reservations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
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
                        secondary: '#1E40AF',
                        accent: '#8B5CF6',
                        success: '#10B981',
                        warning: '#F59E0B',
                        danger: '#EF4444'
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'slide-up': 'slideUp 0.5s ease-out',
                        'slide-down': 'slideDown 0.5s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                        'pulse-slow': 'pulse 3s infinite',
                        'float': 'float 3s ease-in-out infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'shimmer': 'shimmer 2s linear infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(30px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        slideDown: {
                            '0%': { transform: 'translateY(-30px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        bounceIn: {
                            '0%': { transform: 'scale(0.3)', opacity: '0' },
                            '50%': { transform: 'scale(1.05)' },
                            '70%': { transform: 'scale(0.9)' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        glow: {
                            '0%': { boxShadow: '0 0 5px rgba(59, 130, 246, 0.5)' },
                            '100%': { boxShadow: '0 0 20px rgba(59, 130, 246, 0.8)' },
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-200% 0' },
                            '100%': { backgroundPosition: '200% 0' },
                        }
                    },
                    backgroundImage: {
                        'gradient-radial': 'radial-gradient(var(--tw-gradient-stops))',
                        'gradient-conic': 'conic-gradient(from 180deg at 50% 50%, var(--tw-gradient-stops))',
                    }
                }
            }
        }
    </script>
    <style>
        /* Enhanced Loading Animation */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* Enhanced Card Animations */
        .facility-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .facility-card:hover::before {
            left: 100%;
        }
        .facility-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        /* Enhanced Mobile Menu */
        .mobile-menu-enter {
            transform: translateX(-100%);
            opacity: 0;
        }
        .mobile-menu-enter-active {
            transform: translateX(0);
            opacity: 1;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .mobile-menu-exit {
            transform: translateX(0);
            opacity: 1;
        }
        .mobile-menu-exit-active {
            transform: translateX(-100%);
            opacity: 0;
            transition: all 0.3s ease-in;
        }
        
        /* Enhanced Button Styles */
        .btn-enhanced {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }
        
        /* Enhanced Navigation */
        .nav-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Enhanced Hero Section */
        .hero-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            position: relative;
        }
        .hero-gradient::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        /* Enhanced Status Badges */
        .status-badge {
            position: relative;
            overflow: hidden;
        }
        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        .status-badge:hover::before {
            left: 100%;
        }
        
        /* Enhanced Filter Section */
        .filter-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .hero-content {
                padding: 2rem 1rem;
            }
            .facility-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            .nav-glass {
                background: rgba(255, 255, 255, 0.98);
            }
        }
        @media (max-width: 640px) {
            .hero-title {
                font-size: 2.5rem;
                line-height: 3rem;
            }
            .hero-subtitle {
                font-size: 1.125rem;
                line-height: 1.75rem;
            }
            .facility-card:hover {
                transform: translateY(-4px) scale(1.01);
            }
        }
        
        /* Enhanced Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, #3B82F6, #8B5CF6);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, #1E40AF, #7C3AED);
        }
        
        /* Enhanced Focus States */
        .focus-enhanced:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }
        
        /* Enhanced Loading States */
        .loading-enhanced {
            position: relative;
            overflow: hidden;
        }
        .loading-enhanced::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 1.5s infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-white bg-opacity-90 z-50 flex items-center justify-center transition-opacity duration-300">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
            <p class="text-gray-600">Loading...</p>
        </div>
    </div>

    <!-- Enhanced Navigation -->
    <nav class="nav-glass shadow-lg sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary to-accent rounded-xl flex items-center justify-center mr-3 animate-float">
                        <i class="fas fa-building text-white text-xl"></i>
                    </div>
                    <h1 class="text-xl font-bold bg-gradient-to-r from-primary to-accent bg-clip-text text-transparent"><?php echo SITE_NAME; ?></h1>
                </div>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex items-center space-x-4">
                    <?php if ($auth->isLoggedIn()): ?>
                        <?php if ($auth->isAdmin()): ?>
                            <span class="text-gray-700">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <a href="admin/dashboard.php" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-cog mr-2"></i>Admin Panel
                            </a>
                        <?php else: ?>
                            <span class="text-gray-700">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <a href="my_reservations.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-calendar mr-2"></i>My Reservations
                            </a>
                        <?php endif; ?>
                        <a href="auth/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-sign-in-alt mr-2"></i>Login
                        </a>
                        <a href="auth/register.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-user-plus mr-2"></i>Register
                        </a>
                    <?php endif; ?>
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
                <?php if ($auth->isLoggedIn()): ?>
                    <div class="space-y-2 pt-4">
                        <?php if ($auth->isAdmin()): ?>
                            <div class="text-gray-700 py-2 px-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-user-shield mr-2"></i>Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            </div>
                            <a href="admin/dashboard.php" class="block bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-cog mr-2"></i>Admin Panel
                            </a>
                        <?php else: ?>
                            <div class="text-gray-700 py-2 px-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-user mr-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            </div>
                            <a href="my_reservations.php" class="block bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105">
                                <i class="fas fa-calendar mr-2"></i>My Reservations
                            </a>
                        <?php endif; ?>
                        <a href="auth/logout.php" class="block bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105 mt-2">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-2 pt-4">
                        <a href="auth/login.php" class="block bg-primary hover:bg-secondary text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105">
                            <i class="fas fa-sign-in-alt mr-2"></i>Login
                        </a>
                        <a href="auth/register.php" class="block bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg transition duration-200 transform hover:scale-105 mt-2">
                            <i class="fas fa-user-plus mr-2"></i>Register
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Enhanced Hero Section -->
    <div class="hero-gradient text-white py-24 md:py-32 relative overflow-hidden">
        <!-- Enhanced animated background elements -->
        <div class="absolute inset-0">
            <div class="absolute top-10 left-10 w-24 h-24 bg-white bg-opacity-10 rounded-full animate-bounce"></div>
            <div class="absolute top-20 right-20 w-20 h-20 bg-white bg-opacity-10 rounded-full animate-pulse"></div>
            <div class="absolute bottom-10 left-1/4 w-16 h-16 bg-white bg-opacity-10 rounded-full animate-spin"></div>
            <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-32 h-32 bg-white bg-opacity-5 rounded-full animate-float"></div>
            <div class="absolute bottom-20 right-1/4 w-12 h-12 bg-white bg-opacity-15 rounded-full animate-pulse" style="animation-delay: 1s;"></div>
        </div>
        
        <div class="max-w-6xl mx-auto text-center relative z-10 px-4">
            <div class="animate-bounce-in">
                <h1 class="text-4xl md:text-6xl lg:text-7xl font-bold mb-6 bg-gradient-to-r from-white to-blue-100 bg-clip-text text-transparent">
                    Welcome to <?php echo SITE_NAME; ?>
                </h1>
                <p class="text-lg md:text-xl lg:text-2xl mb-8 text-white/90 max-w-3xl mx-auto leading-relaxed">Book your perfect facility for meetings, events, and activities with our enhanced reservation system</p>
                
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mb-12">
                        <a href="auth/login.php" class="btn-enhanced bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary text-white px-8 py-4 text-lg font-semibold rounded-xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-glow">
                            <i class="fas fa-sign-in-alt mr-3"></i>Login
                        </a>
                        <a href="auth/register.php" class="btn-enhanced bg-gradient-to-r from-success to-green-600 hover:from-green-600 hover:to-success text-white px-8 py-4 text-lg font-semibold rounded-xl shadow-lg transform hover:scale-105 transition-all duration-300">
                            <i class="fas fa-user-plus mr-3"></i>Register
                        </a>
                    </div>
                <?php else: ?>
                    <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mb-12">
                        <a href="my_reservations.php" class="btn-enhanced bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary text-white px-8 py-4 text-lg font-semibold rounded-xl shadow-lg transform hover:scale-105 transition-all duration-300 animate-glow">
                            <i class="fas fa-calendar mr-3"></i>My Reservations
                        </a>
                        <a href="#facilities" class="btn-enhanced bg-gradient-to-r from-accent to-purple-600 hover:from-purple-600 hover:to-accent text-white px-8 py-4 text-lg font-semibold rounded-xl shadow-lg transform hover:scale-105 transition-all duration-300">
                            <i class="fas fa-building mr-3"></i>Browse Facilities
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Enhanced features showcase -->
            <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
                <div class="enhanced-card bg-white/10 backdrop-blur-sm border border-white/20 p-6 rounded-2xl hover:bg-white/15 transition-all duration-300 transform hover:scale-105">
                    <div class="icon-enhanced w-16 h-16 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full mx-auto mb-4 flex items-center justify-center shadow-lg">
                        <i class="fas fa-calendar-check text-2xl text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3 text-white">Easy Booking</h3>
                    <p class="text-white/80 leading-relaxed">Simple and intuitive reservation process with real-time availability tracking</p>
                </div>
                
                <div class="enhanced-card bg-white/10 backdrop-blur-sm border border-white/20 p-6 rounded-2xl hover:bg-white/15 transition-all duration-300 transform hover:scale-105">
                    <div class="icon-enhanced w-16 h-16 bg-gradient-to-br from-purple-400 to-purple-600 rounded-full mx-auto mb-4 flex items-center justify-center shadow-lg">
                        <i class="fas fa-mobile-alt text-2xl text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3 text-white">Mobile Friendly</h3>
                    <p class="text-white/80 leading-relaxed">Fully responsive design that works perfectly on all devices and screen sizes</p>
                </div>
                
                <div class="enhanced-card bg-white/10 backdrop-blur-sm border border-white/20 p-6 rounded-2xl hover:bg-white/15 transition-all duration-300 transform hover:scale-105">
                    <div class="icon-enhanced w-16 h-16 bg-gradient-to-br from-green-400 to-green-600 rounded-full mx-auto mb-4 flex items-center justify-center shadow-lg">
                        <i class="fas fa-bell text-2xl text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3 text-white">Smart Notifications</h3>
                    <p class="text-white/80 leading-relaxed">Instant email notifications and real-time status updates for all bookings</p>
                </div>
            </div>
        </div>
    </div>

    <!-- No-Show Policy Section -->
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-yellow-400 text-xl"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Important: No-Show Policy</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>Please note that if you pay for a facility but do not show up for your scheduled booking, your reservation will be marked as "no-show" and the payment is non-refundable. Repeated no-shows may affect your ability to make future reservations.</p>
                    <a href="#no-show-policy" class="font-medium underline hover:text-yellow-600">Learn more about our policy</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        <?php if ($auth->isLoggedIn()): ?>
            <!-- Currently Used Facilities Section -->
            <div class="mb-8 animate-slide-up">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-clock text-primary mr-3"></i>Currently in Use
                </h2>
                
                <?php if (!empty($active_reservations)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                        <?php foreach ($active_reservations as $reservation): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-red-800"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                    <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs font-medium animate-pulse-slow">In Use</span>
                                </div>
                                <p class="text-sm text-red-700 mb-2">
                                    <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($reservation['user_name']); ?>
                                </p>
                                <p class="text-sm text-red-600">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </p>
                                <p class="text-xs text-red-500 mt-1">
                                    Ends in <?php 
                                        $end_time = strtotime($reservation['end_time']);
                                        $now = time();
                                        $time_left = $end_time - $now;
                                        $hours = floor($time_left / 3600);
                                        $minutes = floor(($time_left % 3600) / 60);
                                        if ($hours > 0) {
                                            echo "{$hours}h {$minutes}m";
                                        } else {
                                            echo "{$minutes}m";
                                        }
                                    ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center hover:shadow-md transition duration-200">
                        <i class="fas fa-check-circle text-green-500 text-3xl mb-3"></i>
                        <h3 class="text-lg font-semibold text-green-800 mb-2">All Facilities Available</h3>
                        <p class="text-green-700">No facilities are currently in use. You can book any facility now!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Reservations Section -->
            <?php if (!empty($upcoming_reservations)): ?>
                <div class="mb-8 animate-slide-up" style="animation-delay: 0.1s;">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                        <i class="fas fa-calendar-alt text-blue-600 mr-3"></i>Upcoming Today
                    </h2>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($upcoming_reservations as $reservation): ?>
                                <div class="bg-white rounded-lg p-3 border border-blue-100 hover:shadow-md transition duration-200">
                                    <div class="flex items-center justify-between mb-2">
                                        <h3 class="font-semibold text-blue-800 text-sm"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">Upcoming</span>
                                    </div>
                                    <p class="text-xs text-blue-700 mb-1">
                                        <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($reservation['user_name']); ?>
                                    </p>
                                    <p class="text-xs text-blue-600">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Enhanced Filter Section -->
            <div class="filter-card rounded-2xl shadow-xl p-6 md:p-8 mb-8 animate-slide-up" style="animation-delay: 0.2s;">
                <h3 class="text-xl font-bold mb-6 flex items-center text-gray-800">
                    <div class="w-8 h-8 bg-gradient-to-br from-primary to-accent rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-filter text-white text-sm"></i>
                    </div>
                    Filter Facilities
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Category</label>
                        <select id="categoryFilter" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white focus-enhanced">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Price Range</label>
                        <select id="priceFilter" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white focus-enhanced">
                            <option value="">All Prices</option>
                            <option value="0-25">₱0 - ₱25</option>
                            <option value="26-50">₱26 - ₱50</option>
                            <option value="51-100">₱51 - ₱100</option>
                            <option value="101+">₱101+</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-1">
                        <label class="block text-sm font-semibold text-gray-700 mb-3">Capacity</label>
                        <select id="capacityFilter" class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white focus-enhanced">
                            <option value="">All Capacities</option>
                            <option value="1-10">1 - 10 people</option>
                            <option value="11-25">11 - 25 people</option>
                            <option value="26-50">26 - 50 people</option>
                            <option value="51+">51+ people</option>
                        </select>
                    </div>
                </div>
                <div class="mt-6 flex justify-center">
                    <button onclick="clearFilters()" class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-2 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                        <i class="fas fa-undo mr-2"></i>Clear Filters
                    </button>
                </div>
            </div>

            <!-- Enhanced Facilities Grid -->
            <div id="facilities" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8 facility-grid">
                <?php foreach ($facilities as $index => $facility): ?>
                    <div class="facility-card bg-white rounded-2xl shadow-lg overflow-hidden hover:shadow-2xl transition duration-300 animate-slide-up" 
                         data-category="<?php echo htmlspecialchars($facility['category_name']); ?>"
                         data-price="<?php echo $facility['hourly_rate']; ?>"
                         data-capacity="<?php echo $facility['capacity']; ?>"
                         style="animation-delay: <?php echo $index * 0.1; ?>s;">
                        
                        <!-- Enhanced Image Section -->
                        <div class="h-40 md:h-56 bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center relative overflow-hidden">
                            <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                     class="w-full h-full object-cover transition-transform duration-300 hover:scale-110">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
                            <?php else: ?>
                                <div class="absolute inset-0 bg-gradient-to-br from-gray-400 to-gray-600"></div>
                                <div class="relative z-10 text-center">
                                    <i class="fas fa-image text-white text-5xl md:text-7xl mb-3 opacity-50"></i>
                                    <p class="text-white text-sm font-medium opacity-75">No Image Available</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Category Badge -->
                            <div class="absolute top-4 left-4">
                                <span class="bg-white/20 backdrop-blur-sm text-white px-3 py-1 rounded-full text-xs font-semibold border border-white/30">
                                    <?php echo htmlspecialchars($facility['category_name']); ?>
                                </span>
                        </div>
                        </div>
                        
                        <!-- Enhanced Content Section -->
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-4">
                                <h3 class="text-xl font-bold text-gray-800 leading-tight"><?php echo htmlspecialchars($facility['name']); ?></h3>
                                <div class="text-right ml-4">
                                    <div class="bg-gradient-to-r from-primary to-secondary text-white px-3 py-1 rounded-full text-xs font-bold mb-2 shadow-lg">
                                        ₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr
                                    </div>
                                    <div class="bg-gradient-to-r from-success to-green-600 text-white px-3 py-1 rounded-full text-xs font-bold shadow-lg">
                                        ₱<?php echo number_format($facility['daily_rate'] ?? 0, 2); ?>/day
                                    </div>
                                </div>
                            </div>
                            
                            <p class="text-gray-600 mb-4 text-sm leading-relaxed line-clamp-3"><?php echo htmlspecialchars($facility['description']); ?></p>
                            
                            <div class="flex items-center justify-between text-sm text-gray-500 mb-6 p-3 bg-gray-50 rounded-xl">
                                <span class="flex items-center font-medium">
                                    <i class="fas fa-users text-primary mr-2"></i><?php echo $facility['capacity']; ?> people
                                </span>
                                <span class="flex items-center font-medium">
                                    <i class="fas fa-tag text-accent mr-2"></i><?php echo htmlspecialchars($facility['category_name']); ?>
                                </span>
                            </div>
                            
                            <!-- Enhanced Action Buttons -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" 
                                   class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white text-center py-3 rounded-xl transition duration-200 text-sm font-semibold transform hover:scale-105 shadow-lg">
                                    <i class="fas fa-eye mr-2"></i>View Details
                                </a>
                                
                                <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                                <button onclick="viewFacilityImage('<?php echo htmlspecialchars($facility['image_url']); ?>', '<?php echo htmlspecialchars($facility['name']); ?>')" 
                                        class="bg-gradient-to-r from-accent to-purple-600 hover:from-purple-600 hover:to-accent text-white text-center py-3 rounded-xl transition duration-200 text-sm font-semibold transform hover:scale-105 shadow-lg"
                                        title="Click to view facility image in full size">
                                    <i class="fas fa-image mr-2"></i>View Image
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($_SESSION['role'] !== 'admin'): ?>
                                <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                                   class="bg-gradient-to-r from-primary to-secondary hover:from-secondary hover:to-primary text-white text-center py-3 rounded-xl transition duration-200 text-sm font-semibold transform hover:scale-105 shadow-lg animate-glow">
                                    <i class="fas fa-calendar-plus mr-2"></i>Book Now
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- No Results Message -->
            <div id="noResults" class="hidden text-center py-12 animate-fade-in">
                <i class="fas fa-search text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No facilities found</h3>
                <p class="text-gray-500">Try adjusting your filters to see more results.</p>
                <button onclick="clearFilters()" class="mt-4 bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg transition duration-200">
                    Clear Filters
                </button>
            </div>

        <?php else: ?>
            <!-- Welcome Message for Non-logged Users -->
            <div class="text-center py-8 md:py-12 animate-fade-in">
                <i class="fas fa-lock text-gray-400 text-4xl md:text-6xl mb-4"></i>
                <h3 class="text-xl md:text-2xl font-semibold text-gray-700 mb-4">Please Login to Continue</h3>
                <p class="text-gray-600 mb-8">You need to be logged in to view and book facilities.</p>
                <div class="flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <a href="auth/login.php" class="bg-primary hover:bg-secondary text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                        <i class="fas fa-sign-in-alt mr-2"></i>Login
                    </a>
                    <a href="auth/register.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg font-semibold transition duration-200 transform hover:scale-105">
                        <i class="fas fa-user-plus mr-2"></i>Register
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Enhanced Footer -->
    <footer class="bg-gradient-to-r from-gray-800 via-gray-900 to-gray-800 text-white py-12 md:py-16 mt-16 md:mt-20 relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-10 left-10 w-20 h-20 bg-white rounded-full animate-pulse"></div>
            <div class="absolute bottom-10 right-10 w-16 h-16 bg-white rounded-full animate-pulse" style="animation-delay: 1s;"></div>
            <div class="absolute top-1/2 left-1/4 w-12 h-12 bg-white rounded-full animate-pulse" style="animation-delay: 2s;"></div>
        </div>
        
        <div class="max-w-7xl mx-auto px-4 relative z-10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
                <!-- Company Info -->
                <div class="text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start mb-4">
                        <div class="w-10 h-10 bg-gradient-to-br from-primary to-accent rounded-xl flex items-center justify-center mr-3">
                            <i class="fas fa-building text-white text-xl"></i>
                        </div>
                        <h3 class="text-xl font-bold bg-gradient-to-r from-primary to-accent bg-clip-text text-transparent"><?php echo SITE_NAME; ?></h3>
                    </div>
                    <p class="text-gray-300 leading-relaxed">Your trusted partner for facility reservations. Easy booking, reliable service, and exceptional experiences.</p>
                </div>
                
                <!-- Quick Links -->
                <div class="text-center">
                    <h4 class="text-lg font-semibold mb-4">Quick Links</h4>
                    <div class="space-y-2">
                        <a href="#facilities" class="block text-gray-300 hover:text-white transition duration-200">Browse Facilities</a>
                        <a href="auth/login.php" class="block text-gray-300 hover:text-white transition duration-200">Login</a>
                        <a href="auth/register.php" class="block text-gray-300 hover:text-white transition duration-200">Register</a>
                        <a href="#no-show-policy" class="block text-gray-300 hover:text-white transition duration-200">No-Show Policy</a>
                    </div>
                </div>
                
                <!-- Contact Info -->
                <div class="text-center md:text-right">
                    <h4 class="text-lg font-semibold mb-4">Contact Us</h4>
                    <div class="space-y-2 text-gray-300">
                        <p class="flex items-center justify-center md:justify-end">
                            <i class="fas fa-envelope mr-2 text-primary"></i>
                            support@facilityreservation.com
                        </p>
                        <p class="flex items-center justify-center md:justify-end">
                            <i class="fas fa-phone mr-2 text-primary"></i>
                            +63 912 345 6789
                        </p>
                        <p class="flex items-center justify-center md:justify-end">
                            <i class="fas fa-map-marker-alt mr-2 text-primary"></i>
                            Manila, Philippines
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Bottom Section -->
            <div class="border-t border-gray-700 pt-8 text-center">
                <p class="text-gray-300">&copy; 2024 <?php echo SITE_NAME; ?>. All rights reserved. | Designed with ❤️ for better facility management</p>
                <div class="flex justify-center space-x-4 mt-4">
                    <a href="#" class="text-gray-400 hover:text-white transition duration-200">
                        <i class="fab fa-facebook text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-white transition duration-200">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-white transition duration-200">
                        <i class="fab fa-instagram text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-white transition duration-200">
                        <i class="fab fa-linkedin text-xl"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

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

        // Mobile menu functionality with improved UX
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');

            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    const isHidden = mobileMenu.classList.contains('hidden');
                    
                    if (isHidden) {
                        mobileMenu.classList.remove('hidden');
                        mobileMenu.classList.add('mobile-menu-enter-active');
                        mobileMenuButton.innerHTML = '<i class="fas fa-times text-xl"></i>';
                    } else {
                        mobileMenu.classList.add('mobile-menu-exit-active');
                        setTimeout(() => {
                            mobileMenu.classList.add('hidden');
                            mobileMenu.classList.remove('mobile-menu-exit-active');
                        }, 300);
                        mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                    }
                });

                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                        mobileMenu.classList.add('hidden');
                        mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                    }
                });

                // Close mobile menu on escape key
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                        mobileMenu.classList.add('hidden');
                        mobileMenuButton.innerHTML = '<i class="fas fa-bars text-xl"></i>';
                    }
                });
            }

            // Enhanced filter functionality with debouncing
            const categoryFilter = document.getElementById('categoryFilter');
            const priceFilter = document.getElementById('priceFilter');
            const capacityFilter = document.getElementById('capacityFilter');
            const facilityCards = document.querySelectorAll('.facility-card');
            const noResults = document.getElementById('noResults');

            let filterTimeout;

            function debouncedFilter() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(filterFacilities, 300);
            }

            function filterFacilities() {
                const selectedCategory = categoryFilter.value;
                const selectedPrice = priceFilter.value;
                const selectedCapacity = capacityFilter.value;

                let visibleCount = 0;

                facilityCards.forEach((card, index) => {
                    const category = card.dataset.category;
                    const price = parseFloat(card.dataset.price);
                    const capacity = parseInt(card.dataset.capacity);

                    let showCard = true;

                    // Category filter
                    if (selectedCategory && category !== selectedCategory) {
                        showCard = false;
                    }

                    // Price filter
                    if (selectedPrice) {
                        const [min, max] = selectedPrice.split('-').map(Number);
                        if (max) {
                            if (price < min || price > max) showCard = false;
                        } else {
                            if (price < min) showCard = false;
                        }
                    }

                    // Capacity filter
                    if (selectedCapacity) {
                        const [min, max] = selectedCapacity.split('-').map(Number);
                        if (max) {
                            if (capacity < min || capacity > max) showCard = false;
                        } else {
                            if (capacity < min) showCard = false;
                        }
                    }

                    if (showCard) {
                        card.style.display = 'block';
                        card.style.animationDelay = `${index * 0.05}s`;
                        card.classList.add('animate-slide-up');
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Show/hide no results message with animation
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
                    noResults.classList.add('animate-fade-in');
                } else {
                    noResults.classList.add('hidden');
                }
            }

            function clearFilters() {
                categoryFilter.value = '';
                priceFilter.value = '';
                capacityFilter.value = '';
                filterFacilities();
            }

            // Add event listeners with debouncing
            if (categoryFilter && priceFilter && capacityFilter) {
                categoryFilter.addEventListener('change', debouncedFilter);
                priceFilter.addEventListener('change', debouncedFilter);
                capacityFilter.addEventListener('change', debouncedFilter);

                // Make clearFilters function globally available
                window.clearFilters = clearFilters;
            }

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

            // Add loading states to buttons
            document.querySelectorAll('a, button').forEach(element => {
                element.addEventListener('click', function() {
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
        });
    </script>

    <!-- No-Show Policy Modal -->
    <div id="no-show-policy" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>No-Show Policy
                    </h3>
                    <button onclick="closeNoShowPolicy()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-6 overflow-auto max-h-96">
                    <div class="space-y-6">
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <h4 class="text-lg font-semibold text-red-800 mb-2">
                                <i class="fas fa-exclamation-circle mr-2"></i>Important Notice
                            </h4>
                            <p class="text-red-700">
                                If you pay for a facility but do not show up for your scheduled booking, your reservation will be marked as "no-show" and the payment is <strong>non-refundable</strong>.
                            </p>
                        </div>

                        <div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-3">What is a No-Show?</h4>
                            <p class="text-gray-700 mb-3">
                                A no-show occurs when a user has a confirmed reservation (payment verified) but fails to arrive at the facility during their scheduled booking time.
                            </p>
                            <ul class="list-disc list-inside text-gray-700 space-y-1 ml-4">
                                <li>Not arriving within 15 minutes of the scheduled start time</li>
                                <li>Not notifying the facility management of late arrival</li>
                                <li>Complete absence without prior cancellation</li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-3">No-Show Consequences</h4>
                            <ul class="list-disc list-inside text-gray-700 space-y-2 ml-4">
                                <li><strong>Payment:</strong> All payments for no-show reservations are non-refundable</li>
                                <li><strong>Account Status:</strong> Repeated no-shows may result in account restrictions</li>
                                <li><strong>Future Bookings:</strong> Multiple no-shows may affect your ability to make future reservations</li>
                                <li><strong>Waitlist Impact:</strong> No-shows prevent other users from accessing the facility</li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-3">How to Avoid No-Shows</h4>
                            <ul class="list-disc list-inside text-gray-700 space-y-2 ml-4">
                                <li>Set reminders for your booking time</li>
                                <li>Plan your travel time to arrive early</li>
                                <li>Contact us immediately if you need to cancel or reschedule</li>
                                <li>Keep your contact information updated</li>
                            </ul>
                        </div>

                        <div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-3">Cancellation Policy</h4>
                            <p class="text-gray-700 mb-3">
                                To avoid being marked as no-show, you must cancel your reservation at least 2 hours before your scheduled start time.
                            </p>
                            <ul class="list-disc list-inside text-gray-700 space-y-1 ml-4">
                                <li>Cancellations made 2+ hours before: Full refund</li>
                                <li>Cancellations made 1-2 hours before: 50% refund</li>
                                <li>Cancellations made less than 1 hour before: No refund</li>
                                <li>No-shows: No refund</li>
                            </ul>
                        </div>

                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h4 class="text-lg font-semibold text-blue-800 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Need Help?
                            </h4>
                            <p class="text-blue-700">
                                If you have questions about this policy or need to discuss special circumstances, please contact our support team. We're here to help ensure a smooth booking experience for everyone.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end p-6 border-t bg-gray-50">
                    <button onclick="closeNoShowPolicy()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition duration-200">
                        I Understand
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closeNoShowPolicy() {
            document.getElementById('no-show-policy').classList.add('hidden');
        }
        
        // Close modal when clicking outside
        document.getElementById('no-show-policy').addEventListener('click', function(e) {
            if (e.target === this) {
                closeNoShowPolicy();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeNoShowPolicy();
            }
        });
        
        // Facility image viewing function
        function viewFacilityImage(imageUrl, facilityName) {
            // Create custom modal for image viewing
            const modalHtml = `
                <div id="image-modal" class="fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-lg shadow-2xl max-w-4xl w-full max-h-screen overflow-hidden">
                        <div class="flex items-center justify-between p-4 border-b">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <i class="fas fa-image text-purple-500 mr-2"></i>${facilityName}
                            </h3>
                            <button onclick="closeImageModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        <div class="p-4 text-center">
                            <div id="image-loading" class="mb-4">
                                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-500 mx-auto"></div>
                                <p class="text-gray-600 mt-2">Loading image...</p>
                            </div>
                            <img src="${imageUrl}" 
                                 alt="${facilityName}" 
                                 class="w-full max-w-3xl mx-auto rounded-lg shadow-lg object-cover hidden"
                                 style="max-height: 70vh;"
                                 onload="this.classList.remove('hidden'); document.getElementById('image-loading').style.display='none';"
                                 onerror="handleImageError(this)">
                        </div>
                        <div class="p-4 border-t bg-gray-50 text-center">
                            <p class="text-sm text-gray-600">
                                <i class="fas fa-info-circle mr-1"></i>
                                Click outside or press Escape to close
                            </p>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('image-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Add event listeners
            const modal = document.getElementById('image-modal');
            
            // Close on backdrop click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeImageModal();
                }
            });
            
            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeImageModal();
                }
            });
        }
        
        function closeImageModal() {
            const modal = document.getElementById('image-modal');
            if (modal) {
                modal.remove();
            }
        }
        
        function handleImageError(img) {
            const loadingDiv = document.getElementById('image-loading');
            if (loadingDiv) {
                loadingDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle text-red-500 text-2xl mb-2"></i>
                    <p class="text-red-600">Failed to load image</p>
                    <p class="text-gray-500 text-sm mt-1">The image may be missing or corrupted</p>
                `;
            }
        }
    </script>
</body>
</html>
