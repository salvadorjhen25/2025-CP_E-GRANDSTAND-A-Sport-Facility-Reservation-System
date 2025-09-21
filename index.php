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
// Get currently active reservations and usage from both tables
$stmt = $pdo->query("
    SELECT 
        r.id as reservation_id,
        r.start_time,
        r.end_time,
        r.total_amount,
        r.purpose,
        r.attendees,
        r.usage_started_at,
        r.status as reservation_status,
        u.full_name as user_name,
        f.name as facility_name,
        f.hourly_rate,
        c.name as category_name,
        ul.status as usage_status,
        ul.action as usage_action,
        ul.started_at as usage_started_at,
        TIMESTAMPDIFF(MINUTE, NOW(), r.end_time) as minutes_remaining,
        TIMESTAMPDIFF(MINUTE, COALESCE(ul.started_at, r.usage_started_at), NOW()) as usage_duration_minutes,
        CASE 
            WHEN ul.status = 'active' THEN 'in_use'
            WHEN ul.status = 'ready' THEN 'confirmed'
            ELSE r.status
        END as display_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN facilities f ON r.facility_id = f.id
    LEFT JOIN categories c ON f.category_id = c.id
    LEFT JOIN usage_logs ul ON r.id = ul.reservation_id AND ul.status IN ('ready', 'active')
    WHERE (
        (r.status IN ('confirmed', 'in_use') AND r.start_time <= NOW() AND r.end_time >= NOW())
        OR 
        (ul.status IN ('ready', 'active') AND r.end_time >= NOW())
    )
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
    
    <!-- Google Fonts - Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/modern-ui.css">
    <link rel="stylesheet" href="assets/css/enhanced-ui.css?v=2.0.0">
    <link rel="stylesheet" href="assets/css/mobile-responsive.css?v=1.0.0">
    <link rel="stylesheet" href="assets/css/icon-fixes.css?v=1.0.0">
    
    <!-- Cache Control -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'poppins': ['Poppins', 'sans-serif'],
                    },
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
                    }
                }
            }
        }
    </script>
    <style>
        /* Global Poppins Font */
        * {
            font-family: 'Poppins', sans-serif !important;
        }
        
        /* Enhanced Text Visibility */
        body {
            font-family: 'Poppins', sans-serif !important;
            color: #1f2937 !important;
            line-height: 1.6 !important;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 700 !important;
            color: #111827 !important;
        }
        
        p, span, div, a, button {
            font-family: 'Poppins', sans-serif !important;
            color: #374151 !important;
        }
        
        /* Enhanced Hero Section */
        .hero-section {
            background: linear-gradient(135deg, #1e40af, #3b82f6, #8b5cf6) !important;
            padding: 8rem 0 !important;
            min-height: 90vh !important;
            display: flex !important;
            align-items: center !important;
            position: relative !important;
            overflow: hidden !important;
        }
        
        .hero-section::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') !important;
            opacity: 0.3 !important;
        }
        
        .hero-title {
            font-family: 'Poppins', sans-serif !important;
            font-size: clamp(3rem, 8vw, 5rem) !important;
            font-weight: 900 !important;
            color: white !important;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3) !important;
            margin-bottom: 1.5rem !important;
            position: relative !important;
            z-index: 2 !important;
        }
        
        .hero-subtitle {
            font-family: 'Poppins', sans-serif !important;
            font-size: clamp(1.25rem, 4vw, 1.75rem) !important;
            font-weight: 400 !important;
            color: rgba(255, 255, 255, 0.95) !important;
            margin-bottom: 2rem !important;
            position: relative !important;
            z-index: 2 !important;
        }
        
        /* Enhanced Navigation */
        .nav-bar {
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid #e5e7eb !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 100 !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        .nav-container {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 0 2rem !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            height: 80px !important;
        }
        
        .nav-title {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 800 !important;
            color: #1e40af !important;
            font-size: 1.5rem !important;
        }
        
        .nav-user-name {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            color: #374151 !important;
        }
        
        /* Enhanced Buttons */
        .btn-hero {
            position: relative !important;
            padding: 1.25rem 2.5rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 1.125rem !important;
            font-weight: 600 !important;
            border-radius: 1rem !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            overflow: hidden !important;
            min-width: 220px !important;
            justify-content: center !important;
            text-transform: none !important;
        }
        
        .btn-primary.btn-hero {
            background: linear-gradient(135deg, white, #f8fafc) !important;
            color: #1e40af !important;
            border: 2px solid white !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1) !important;
        }
        
        .btn-primary.btn-hero:hover {
            transform: translateY(-4px) scale(1.02) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
        
        .btn-secondary.btn-hero {
            background: rgba(255, 255, 255, 0.2) !important;
            color: white !important;
            border: 2px solid rgba(255, 255, 255, 0.3) !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .btn-secondary.btn-hero:hover {
            background: rgba(255, 255, 255, 0.3) !important;
            transform: translateY(-4px) scale(1.02) !important;
        }
        
        /* Enhanced Cards */
        .facility-card.enhanced {
            background: white !important;
            border-radius: 1.5rem !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid #e5e7eb !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            overflow: hidden !important;
        }
        
        .facility-card.enhanced:hover {
            transform: translateY(-8px) scale(1.02) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
        
        .facility-title {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 700 !important;
            color: #111827 !important;
            font-size: 1.25rem !important;
        }
        
        .facility-description {
            font-family: 'Poppins', sans-serif !important;
            color: #6b7280 !important;
            line-height: 1.6 !important;
        }
        
        /* Enhanced Filter Section */
        .enhanced-filter-section {
            background: white !important;
            border-radius: 2rem !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid #e5e7eb !important;
            padding: 3rem !important;
            margin-bottom: 3rem !important;
            transition: all 0.3s ease !important;
        }
        
        .filter-title {
            font-family: 'Poppins', sans-serif !important;
            font-size: 2.25rem !important;
            font-weight: 800 !important;
            color: #111827 !important;
            margin: 0 !important;
        }
        
        .enhanced-form-control {
            width: 100% !important;
            padding: 1rem 1.25rem !important;
            border: 2px solid #e5e7eb !important;
            border-radius: 1rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 1rem !important;
            font-weight: 500 !important;
            color: #374151 !important;
            transition: all 0.3s ease !important;
            background: white !important;
            cursor: pointer !important;
        }
        
        .enhanced-form-control:focus {
            outline: none !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1) !important;
            transform: translateY(-2px) !important;
        }
        
        /* Status Cards */
        .facility-status-card {
            background: white !important;
            border-radius: 1.5rem !important;
            padding: 2rem !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1) !important;
            border: 2px solid #e5e7eb !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            overflow: hidden !important;
        }
        
        .facility-status-card:hover {
            transform: translateY(-8px) scale(1.02) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
        
        /* Animations */
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.05);
            }
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        @keyframes gradientShift {
            0%, 100% {
                background: linear-gradient(90deg, #10b981, #3b82f6, #f59e0b);
            }
            33% {
                background: linear-gradient(90deg, #3b82f6, #f59e0b, #10b981);
            }
            66% {
                background: linear-gradient(90deg, #f59e0b, #10b981, #3b82f6);
            }
        }
        
        .status-badge.status-active {
            animation: pulse 2s infinite;
        }
        
        .countdown-timer {
            transition: all 0.3s ease;
            font-family: 'Poppins', monospace !important;
            font-weight: 700 !important;
        }
        
        .usage-timer {
            transition: all 0.3s ease;
            font-family: 'Poppins', monospace !important;
            font-weight: 600 !important;
        }
        
        /* Enhanced responsive design */
        @media (max-width: 768px) {
            .hero-section {
                padding: 4rem 0 !important;
                min-height: 70vh !important;
            }
            
            .hero-title {
                font-size: 2.5rem !important;
            }
            
            .hero-subtitle {
                font-size: 1.125rem !important;
            }
            
            .btn-hero {
                padding: 1rem 1.5rem !important;
                font-size: 1rem !important;
                min-width: 180px !important;
            }
            
            .nav-container {
                padding: 0 1rem !important;
                height: 70px !important;
            }
        }
        
        /* Text visibility improvements */
        .text-visible {
            color: #111827 !important;
            font-weight: 500 !important;
        }
        
        .text-muted-visible {
            color: #6b7280 !important;
            font-weight: 400 !important;
        }
        
        /* Enhanced shadows and effects */
        .shadow-enhanced {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
        }
        
        .shadow-enhanced:hover {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
    </style>
</head>
<body>
    <!-- Enhanced Navigation -->
    <nav class="nav-bar">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="nav-logo">
                    <i class="fas fa-building text-white"></i>
                </div>
                <h1 class="nav-title"><?php echo SITE_NAME; ?></h1>
            </div>
            
            <!-- Desktop Navigation -->
            <div class="nav-menu">
                <?php if ($auth->isLoggedIn()): ?>
                    <?php if ($auth->isAdmin()): ?>
                        <div class="nav-user-info">
                            <i class="fas fa-user-shield nav-user-icon"></i>
                            <span class="nav-user-name">Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        </div>
                        <a href="admin/dashboard.php" class="btn-secondary nav-btn">
                            <i class="fas fa-cog"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php else: ?>
                        <div class="nav-user-info">
                            <i class="fas fa-user nav-user-icon"></i>
                            <span class="nav-user-name">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        </div>
                        <a href="my_reservations.php" class="btn-primary nav-btn">
                            <i class="fas fa-calendar"></i>
                            <span>My Reservations</span>
                        </a>
                    <?php endif; ?>
                    <a href="auth/logout.php" class="btn-danger nav-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn-primary nav-btn">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Login</span>
                    </a>
                    <a href="auth/register.php" class="btn-success nav-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>Register</span>
                    </a>
                <?php endif; ?>
            </div>
            
            <!-- Enhanced Mobile menu button -->
            <div class="nav-menu-mobile">
                <button id="mobile-menu-button" class="mobile-menu-btn" aria-label="Toggle mobile menu">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
            </div>
        </div>
            <!-- Mobile Navigation -->
        <div id="mobile-menu" class="hidden mobile-menu">
            <div class="container" style="padding: 1rem 0;">
                <?php if ($auth->isLoggedIn()): ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if ($auth->isAdmin()): ?>
                            <div style="color: var(--text-light); padding: 0.75rem; background: var(--gray-50); border-radius: 8px; font-weight: 500;">
                                <i class="fas fa-user-shield" style="margin-right: 0.5rem;"></i>Admin: <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            </div>
                            <a href="admin/dashboard.php" class="btn-secondary" style="display: block; text-align: center;">
                                <i class="fas fa-cog"></i>Admin Panel
                            </a>
                        <?php else: ?>
                            <div style="color: var(--text-light); padding: 0.75rem; background: var(--gray-50); border-radius: 8px; font-weight: 500;">
                                <i class="fas fa-user" style="margin-right: 0.5rem;"></i>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            </div>
                            <a href="my_reservations.php" class="btn-primary" style="display: block; text-align: center;">
                                <i class="fas fa-calendar"></i>My Reservations
                            </a>
                        <?php endif; ?>
                        <a href="auth/logout.php" class="btn-danger" style="display: block; text-align: center;">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <a href="auth/login.php" class="btn-primary" style="display: block; text-align: center;">
                            <i class="fas fa-sign-in-alt"></i>Login
                        </a>
                        <a href="auth/register.php" class="btn-success" style="display: block; text-align: center;">
                            <i class="fas fa-user-plus"></i>Register
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="max-w-6xl mx-auto text-center px-4 hero-content" style="position: relative; z-index: 2;">
            <!-- Enhanced background with animated elements -->
            <div class="hero-background-elements" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1;">
                <div class="floating-shape shape-1" style="position: absolute; top: 20%; left: 10%; width: 100px; height: 100px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 6s ease-in-out infinite;"></div>
                <div class="floating-shape shape-2" style="position: absolute; top: 60%; right: 15%; width: 80px; height: 80px; background: rgba(255, 255, 255, 0.08); border-radius: 50%; animation: float 8s ease-in-out infinite reverse;"></div>
                <div class="floating-shape shape-3" style="position: absolute; bottom: 20%; left: 20%; width: 60px; height: 60px; background: rgba(255, 255, 255, 0.06); border-radius: 50%; animation: float 10s ease-in-out infinite;"></div>
            </div>
            
            <h1 class="hero-title">
                Welcome to <?php echo SITE_NAME; ?>
            </h1>
            <p class="hero-subtitle">
                Reserve your perfect facility with ease. Modern spaces for your events, meetings, and activities.
            </p>
            
            <!-- Enhanced CTA buttons with better visual feedback -->
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="hero-cta-container" style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap; margin-top: 3rem;">
                    <a href="auth/login.php" class="btn-primary btn-hero">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Get Started</span>
                    </a>
                    <a href="auth/register.php" class="btn-secondary btn-hero">
                        <i class="fas fa-user-plus"></i>
                        <span>Create Account</span>
                    </a>
                </div>
            <?php else: ?>
                <div class="hero-cta-container" style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap; margin-top: 3rem;">
                    <a href="my_reservations.php" class="btn-primary btn-hero">
                        <i class="fas fa-calendar"></i>
                        <span>My Reservations</span>
                    </a>
                    <a href="#facilities" class="btn-secondary btn-hero">
                        <i class="fas fa-building"></i>
                        <span>Browse Facilities</span>
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Quick stats for logged-in users -->
            <?php if ($auth->isLoggedIn()): ?>
                <div class="hero-stats" style="display: flex; justify-content: center; gap: 3rem; margin-top: 4rem; flex-wrap: wrap;">
                    <div class="stat-item" style="text-align: center; background: rgba(255, 255, 255, 0.15); padding: 1.5rem 2rem; border-radius: 1rem; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div class="stat-number" style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 900; color: white; margin-bottom: 0.5rem;"><?php echo count($facilities); ?></div>
                        <div class="stat-label" style="font-family: 'Poppins', sans-serif; font-size: 1rem; font-weight: 500; color: rgba(255, 255, 255, 0.9);">Available Facilities</div>
                    </div>
                    <div class="stat-item" style="text-align: center; background: rgba(255, 255, 255, 0.15); padding: 1.5rem 2rem; border-radius: 1rem; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div class="stat-number" style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 900; color: white; margin-bottom: 0.5rem;"><?php echo count($active_reservations); ?></div>
                        <div class="stat-label" style="font-family: 'Poppins', sans-serif; font-size: 1rem; font-weight: 500; color: rgba(255, 255, 255, 0.9);">Currently Active</div>
                    </div>
                    <div class="stat-item" style="text-align: center; background: rgba(255, 255, 255, 0.15); padding: 1.5rem 2rem; border-radius: 1rem; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div class="stat-number" style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 900; color: white; margin-bottom: 0.5rem;"><?php echo count($upcoming_reservations); ?></div>
                        <div class="stat-label" style="font-family: 'Poppins', sans-serif; font-size: 1rem; font-weight: 500; color: rgba(255, 255, 255, 0.9);">Upcoming Today</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- No-Show Policy Alert -->
    <div class="container" style="padding: 2rem 0; max-width: 1200px; margin: 0 auto;">
        <div class="alert alert-warning" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.2);">
            <div style="display: flex; align-items: flex-start;">
                <div style="flex-shrink: 0; margin-right: 1rem;">
                    <i class="fas fa-exclamation-triangle" style="color: #d97706; font-size: 1.5rem;"></i>
                </div>
                <div>
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 1.25rem; font-weight: 700; color: #92400e; margin-bottom: 0.75rem;">Important: No-Show Policy</h3>
                    <div style="margin-top: 0.5rem;">
                        <p style="font-family: 'Poppins', sans-serif; color: #92400e; font-weight: 500; line-height: 1.6; margin-bottom: 0.75rem;">Please note that if you pay for a facility but do not show up for your scheduled booking, your reservation will be marked as "no-show" and the payment is non-refundable.</p>
                        <a href="#no-show-policy" style="font-family: 'Poppins', sans-serif; font-weight: 600; text-decoration: underline; color: #d97706; display: inline-block; transition: color 0.3s ease;">Learn more about our policy</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Main Content -->
    <div class="container" style="padding: 3rem 0; max-width: 1200px; margin: 0 auto;">
        <?php if ($auth->isLoggedIn()): ?>

            <!-- Enhanced Currently Used Facilities Section -->
            <div style="margin-bottom: 4rem;">
                <div style="background: linear-gradient(135deg, #1e40af, #3b82f6, #60a5fa); padding: 3rem; border-radius: 2rem; margin-bottom: 2rem; position: relative; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(59, 130, 246, 0.3);">
                    <!-- Animated background elements -->
                    <div style="position: absolute; top: -50%; right: -20%; width: 200px; height: 200px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 6s ease-in-out infinite;"></div>
                    <div style="position: absolute; bottom: -30%; left: -10%; width: 150px; height: 150px; background: rgba(255, 255, 255, 0.08); border-radius: 50%; animation: float 8s ease-in-out infinite reverse;"></div>
                    
                    <div style="position: relative; z-index: 1;">
                        <h2 style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800; color: white; margin-bottom: 1rem; display: flex; align-items: center; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                            <i class="fas fa-bolt" style="margin-right: 1rem; font-size: 2.5rem; animation: pulse 2s infinite;"></i>
                            Live Facility Status
                        </h2>
                        <p style="font-family: 'Poppins', sans-serif; color: rgba(255, 255, 255, 0.95); font-size: 1.25rem; margin-bottom: 2rem; font-weight: 400;">
                            Real-time monitoring of currently occupied facilities with live countdown timers
                        </p>
                        
                        <!-- Live Status Indicators -->
                        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
                            <div style="background: rgba(255, 255, 255, 0.2); padding: 1rem 1.5rem; border-radius: 25px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 12px; height: 12px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite;"></div>
                                    <span style="font-family: 'Poppins', sans-serif; color: white; font-weight: 600; font-size: 1rem;"><?php echo count($active_reservations); ?> Active</span>
                                </div>
                            </div>
                            <div style="background: rgba(255, 255, 255, 0.2); padding: 1rem 1.5rem; border-radius: 25px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <i class="fas fa-clock" style="color: white; font-size: 1rem;"></i>
                                    <span style="font-family: 'Poppins', sans-serif; color: white; font-weight: 600; font-size: 1rem;">Live Updates</span>
                                </div>
                            </div>
                            <div style="background: rgba(255, 255, 255, 0.2); padding: 1rem 1.5rem; border-radius: 25px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <i class="fas fa-sync-alt" style="color: white; font-size: 1rem;"></i>
                                    <span style="font-family: 'Poppins', sans-serif; color: white; font-weight: 600; font-size: 1rem;">Auto-refresh</span>
                                </div>
                            </div>
                            <div style="background: rgba(255, 255, 255, 0.2); padding: 1rem 1.5rem; border-radius: 25px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <i class="fas fa-wifi" style="color: white; font-size: 1rem;"></i>
                                    <span style="font-family: 'Poppins', sans-serif; color: white; font-weight: 600; font-size: 1rem;" id="lastUpdateTime">Last updated: Just now</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($active_reservations)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3" style="gap: 1.5rem;">
                        <?php foreach ($active_reservations as $reservation): ?>
                            <div class="facility-status-card" style="background: white; border-radius: 1.5rem; padding: 2rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); border: 2px solid #e5e7eb; position: relative; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform: translateY(0);" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='0 25px 50px rgba(0, 0, 0, 0.25)'" onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 10px 25px rgba(0, 0, 0, 0.1)'">
                                <!-- Status indicator bar -->
                                <div style="position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(90deg, #10b981, #3b82f6, #f59e0b); animation: gradientShift 3s ease-in-out infinite;"></div>
                                
                                <!-- Facility header -->
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; margin-top: 0.5rem;">
                                    <div>
                                        <h3 style="font-family: 'Poppins', sans-serif; font-weight: 800; color: #111827; font-size: 1.375rem; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                        <p style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 0.95rem; font-weight: 500;">
                                            <i class="fas fa-tag" style="margin-right: 0.5rem; color: #3b82f6;"></i>
                                            <?php echo htmlspecialchars($reservation['category_name'] ?? 'General'); ?>
                                        </p>
                                    </div>
                                    <div style="background: linear-gradient(135deg, <?php echo $reservation['display_status'] === 'in_use' ? '#10b981, #059669' : '#3b82f6, #1d4ed8'; ?>); color: white; padding: 0.75rem 1.25rem; border-radius: 25px; font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 0.875rem; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 15px rgba(<?php echo $reservation['display_status'] === 'in_use' ? '16, 185, 129' : '59, 130, 246'; ?>, 0.3);">
                                        <div style="width: 8px; height: 8px; background: white; border-radius: 50%; animation: pulse 2s infinite;"></div>
                                        <?php echo strtoupper(str_replace('_', ' ', $reservation['display_status'])); ?>
                                    </div>
                                </div>
                                
                                <!-- User and time info -->
                                <div style="background: #f8fafc; padding: 1.5rem; border-radius: 1rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                        <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <p style="font-family: 'Poppins', sans-serif; font-weight: 700; color: #111827; margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($reservation['user_name']); ?></p>
                                            <p style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 0.875rem; margin: 0; font-weight: 500;">Current User</p>
                                        </div>
                                    </div>
                                    
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div>
                                            <p style="font-family: 'Poppins', sans-serif; font-weight: 700; color: #111827; margin: 0; font-size: 1rem;">
                                                <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                            </p>
                                            <p style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 0.875rem; margin: 0; font-weight: 500;">Reservation Time</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Enhanced countdown timer -->
                                <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 1.5rem; border-radius: 1rem; border: 2px solid #f59e0b; margin-bottom: 1rem; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                        <span style="font-family: 'Poppins', sans-serif; font-size: 1rem; color: #92400e; font-weight: 700;">
                                            <i class="fas fa-hourglass-end" style="margin-right: 0.5rem;"></i>Ends in:
                                        </span>
                                        <i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 1.25rem;"></i>
                                    </div>
                                    <div class="countdown-timer" 
                                         data-end-time="<?php echo $reservation['end_time']; ?>" 
                                         style="font-family: 'Poppins', monospace; font-weight: 800; color: #92400e; font-size: 1.75rem; text-align: center; padding: 1rem; background: rgba(255, 255, 255, 0.6); border-radius: 0.75rem; border: 1px solid rgba(245, 158, 11, 0.3); box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);">
                                        <?php 
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
                                    </div>
                                </div>
                                

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card" style="padding: 4rem; text-align: center; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 3px solid #bbf7d0; border-radius: 2rem; box-shadow: 0 20px 40px rgba(16, 185, 129, 0.15);">
                        <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);">
                            <i class="fas fa-check-circle" style="color: white; font-size: 3rem;"></i>
                        </div>
                        <h3 style="font-family: 'Poppins', sans-serif; font-size: 2rem; font-weight: 800; color: #065f46; margin-bottom: 1.5rem;">All Facilities Available!</h3>
                        <p style="font-family: 'Poppins', sans-serif; color: #047857; font-size: 1.25rem; margin-bottom: 2rem; line-height: 1.6; font-weight: 500;">No facilities are currently in use. You can book any facility now and start using it immediately!</p>
                        <div style="display: flex; justify-content: center; gap: 1.5rem; flex-wrap: wrap;">
                            <div style="background: rgba(16, 185, 129, 0.15); padding: 1rem 1.5rem; border-radius: 25px; border: 1px solid rgba(16, 185, 129, 0.3);">
                                <i class="fas fa-clock" style="color: #10b981; margin-right: 0.75rem; font-size: 1.125rem;"></i>
                                <span style="font-family: 'Poppins', sans-serif; color: #065f46; font-weight: 700; font-size: 1rem;">Instant Booking</span>
                            </div>
                            <div style="background: rgba(16, 185, 129, 0.15); padding: 1rem 1.5rem; border-radius: 25px; border: 1px solid rgba(16, 185, 129, 0.3);">
                                <i class="fas fa-check" style="color: #10b981; margin-right: 0.75rem; font-size: 1.125rem;"></i>
                                <span style="font-family: 'Poppins', sans-serif; color: #065f46; font-weight: 700; font-size: 1rem;">No Waiting</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Upcoming Reservations Section -->
            <?php if (!empty($upcoming_reservations)): ?>
                <div style="margin-bottom: 4rem;">
                    <h2 style="font-family: 'Poppins', sans-serif; font-size: 2.25rem; font-weight: 800; color: #111827; margin-bottom: 2rem; display: flex; align-items: center;">
                        <i class="fas fa-calendar-alt" style="color: #3b82f6; margin-right: 1rem; font-size: 2rem;"></i>Upcoming Today
                    </h2>
                    <div class="card" style="padding: 2rem; background: white; border-radius: 1.5rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3" style="gap: 1.5rem;">
                            <?php foreach ($upcoming_reservations as $reservation): ?>
                                <div style="background: #f8fafc; border-radius: 1rem; padding: 1.5rem; border: 1px solid #e2e8f0; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 10px 25px rgba(0, 0, 0, 0.1)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                        <h3 style="font-family: 'Poppins', sans-serif; font-weight: 700; color: #111827; font-size: 1.125rem;"><?php echo htmlspecialchars($reservation['facility_name']); ?></h3>
                                        <span class="status-badge status-upcoming" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 0.5rem 1rem; border-radius: 20px; font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 0.875rem;">Upcoming</span>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                        <p style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 0.95rem; font-weight: 500;">
                                            <i class="fas fa-user" style="margin-right: 0.75rem; color: #3b82f6;"></i><?php echo htmlspecialchars($reservation['user_name']); ?>
                                        </p>
                                        <p style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 0.95rem; font-weight: 500;">
                                            <i class="fas fa-clock" style="margin-right: 0.75rem; color: #3b82f6;"></i>
                                            <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Enhanced Filter Section -->
            <div class="enhanced-filter-section">
                <div class="filter-header" style="text-align: center; margin-bottom: 3rem;">
                    <div class="filter-title-container" style="display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                        <div class="filter-icon" style="width: 60px; height: 60px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);">
                            <i class="fas fa-filter" style="color: white; font-size: 1.5rem;"></i>
                        </div>
                        <h3 class="filter-title">Filter Facilities</h3>
                    </div>
                    <p class="filter-subtitle" style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 1.125rem; font-weight: 500;">Find the perfect facility by filtering by category, price, and capacity</p>
                </div>
                
                <div class="filter-controls" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
                    <div class="filter-group">
                        <label class="enhanced-form-label" style="font-family: 'Poppins', sans-serif; font-weight: 600; color: #374151; margin-bottom: 0.75rem; display: flex; align-items: center; font-size: 1rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px; margin-right: 0.5rem; color: #3b82f6;">
                                <path fill-rule="evenodd" d="M4.5 2A2.5 2.5 0 0 0 2 4.5v2.879a2.5 2.5 0 0 0 .732 1.767l4.5 4.5a2.5 2.5 0 0 0 3.536 0l2.878-2.878a2.5 2.5 0 0 0 0-3.536l-4.5-4.5A2.5 2.5 0 0 0 7.38 2H4.5ZM5 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                            </svg>
                            Category
                        </label>
                        <select id="categoryFilter" class="enhanced-form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="enhanced-form-label" style="font-family: 'Poppins', sans-serif; font-weight: 600; color: #374151; margin-bottom: 0.75rem; display: flex; align-items: center; font-size: 1rem;">
                            <i class="fas fa-dollar-sign" style="margin-right: 0.5rem; color: #3b82f6;"></i>
                            Price Range
                        </label>
                        <select id="priceFilter" class="enhanced-form-control">
                            <option value="">All Prices</option>
                            <option value="0-25">₱0 - ₱25</option>
                            <option value="26-50">₱26 - ₱50</option>
                            <option value="51-100">₱51 - ₱100</option>
                            <option value="101+">₱101+</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="enhanced-form-label" style="font-family: 'Poppins', sans-serif; font-weight: 600; color: #374151; margin-bottom: 0.75rem; display: flex; align-items: center; font-size: 1rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px; margin-right: 0.5rem; color: #3b82f6;">
                                <path d="M8 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3.156 11.763c.16-.629.44-1.21.813-1.72a2.5 2.5 0 0 0-2.725 1.377c-.136.287.102.58.418.58h1.449c.01-.077.025-.156.045-.237ZM12.847 11.763c.02.08.036.16.046.237h1.446c.316 0 .554-.293.417-.579a2.5 2.5 0 0 0-2.722-1.378c.374.51.653 1.09.813 1.72ZM14 7.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM5 13c-.552 0-1.013-.455-.876-.99a4.002 4.002 0 0 1 7.753 0c.136.535-.324.99-.877.99H5Z" />
                            </svg>
                            Capacity
                        </label>
                        <select id="capacityFilter" class="enhanced-form-control">
                            <option value="">All Capacities</option>
                            <option value="1-10">1 - 10 people</option>
                            <option value="11-25">11 - 25 people</option>
                            <option value="26-50">26 - 50 people</option>
                            <option value="51+">51+ people</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions" style="display: flex; justify-content: center; align-items: center; gap: 1rem;">
                    <button onclick="clearFilters()" class="btn-secondary btn-clear-filters" style="font-family: 'Poppins', sans-serif; font-weight: 600; padding: 1rem 2rem; border-radius: 1rem; background: #f3f4f6; color: #374151; border: 2px solid #e5e7eb; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-undo"></i>
                        <span>Clear Filters</span>
                    </button>
                    <div class="active-filters" id="activeFilters"></div>
                </div>
            </div>
            <!-- Enhanced Facilities Grid -->
            <div id="facilities" class="facilities-section" style="margin-top: 4rem;">
                <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
                    <h2 class="section-title" style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800; color: #111827; margin-bottom: 1rem; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-building section-icon" style="margin-right: 1rem; color: #3b82f6; font-size: 2.25rem;"></i>
                        Available Facilities
                    </h2>
                    <p class="section-subtitle" style="font-family: 'Poppins', sans-serif; color: #6b7280; font-size: 1.25rem; font-weight: 500;">Choose from our wide range of modern facilities for your next event</p>
                </div>
                
                <div class="facilities-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem;">
                    <?php foreach ($facilities as $facility): ?>
                        <div class="facility-card enhanced" 
                             data-category="<?php echo htmlspecialchars($facility['category_name']); ?>"
                             data-price="<?php echo $facility['hourly_rate']; ?>"
                             data-capacity="<?php echo $facility['capacity']; ?>"
                             style="background: white; border-radius: 1.5rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden;"
                             onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='0 25px 50px rgba(0, 0, 0, 0.25)'"
                             onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 10px 25px rgba(0, 0, 0, 0.1)'">
                            
                            <!-- Enhanced Facility Image -->
                            <div class="facility-image-container">
                                <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($facility['name']); ?>"
                                         class="facility-image">
                                <?php else: ?>
                                    <div class="facility-image-placeholder">
                                        <i class="fas fa-building"></i>
                                        <p>No Image Available</p>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Enhanced Category Badge -->
                                <div class="category-badge">
                                    <span class="badge-text">
                                        <?php echo htmlspecialchars($facility['category_name']); ?>
                                    </span>
                                </div>
                                
                                <!-- Quick Info Overlay -->
                                <div class="facility-overlay">
                                    <div class="overlay-content">
                                        <div class="overlay-stat">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px;">
                                                <path d="M8 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3.156 11.763c.16-.629.44-1.21.813-1.72a2.5 2.5 0 0 0-2.725 1.377c-.136.287.102.58.418.58h1.449c.01-.077.025-.156.045-.237ZM12.847 11.763c.02.08.036.16.046.237h1.446c.316 0 .554-.293.417-.579a2.5 2.5 0 0 0-2.722-1.378c.374.51.653 1.09.813 1.72ZM14 7.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM5 13c-.552 0-1.013-.455-.876-.99a4.002 4.002 0 0 1 7.753 0c.136.535-.324.99-.877.99H5Z" />
                                            </svg>
                                            <span><?php echo $facility['capacity']; ?> people</span>
                                        </div>
                                        <div class="overlay-stat">
                                            <i class="fas fa-clock"></i>
                                            <span>₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Enhanced Facility Content -->
                            <div class="facility-content" style="padding: 2rem;">
                                <div class="facility-header" style="margin-bottom: 1.5rem;">
                                    <h3 class="facility-title" style="font-family: 'Poppins', sans-serif; font-weight: 800; color: #111827; font-size: 1.5rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($facility['name']); ?></h3>
                                    <div class="facility-meta" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                        <div class="price-tag enhanced" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 0.75rem 1.25rem; border-radius: 25px; font-family: 'Poppins', sans-serif; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);">
                                            <i class="fas fa-tag"></i>
                                            <span>₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr</span>
                                        </div>
                                        <div class="capacity-badge enhanced" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 0.75rem 1.25rem; border-radius: 25px; font-family: 'Poppins', sans-serif; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px;">
                                                <path d="M8 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3.156 11.763c.16-.629.44-1.21.813-1.72a2.5 2.5 0 0 0-2.725 1.377c-.136.287.102.58.418.58h1.449c.01-.077.025-.156.045-.237ZM12.847 11.763c.02.08.036.16.046.237h1.446c.316 0 .554-.293.417-.579a2.5 2.5 0 0 0-2.722-1.378c.374.51.653 1.09.813 1.72ZM14 7.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM3.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM5 13c-.552 0-1.013-.455-.876-.99a4.002 4.002 0 0 1 7.753 0c.136.535-.324.99-.877.99H5Z" />
                                            </svg>
                                            <span><?php echo $facility['capacity']; ?> people</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <p class="facility-description" style="font-family: 'Poppins', sans-serif; color: #6b7280; line-height: 1.6; margin-bottom: 2rem; font-size: 1rem; font-weight: 500;"><?php echo htmlspecialchars($facility['description']); ?></p>
                                
                                <!-- Enhanced Action Buttons -->
                                <div class="facility-actions" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                    <a href="facility_details.php?facility_id=<?php echo $facility['id']; ?>" 
                                       class="btn-secondary btn-outline" style="font-family: 'Poppins', sans-serif; font-weight: 600; padding: 1rem 1.5rem; border-radius: 1rem; background: #f3f4f6; color: #374151; border: 2px solid #e5e7eb; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; flex: 1; justify-content: center;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px;">
                                            <path fill-rule="evenodd" d="M15 8A7 7 0 1 1 1 8a7 7 0 0 1 14 0ZM9 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM6.75 8a.75.75 0 0 0 0 1.5h.75v1.75a.75.75 0 0 0 1.5 0v-2.5A.75.75 0 0 0 8.25 8h-1.5Z" clip-rule="evenodd" />
                                        </svg>
                                        <span>View Details</span>
                                    </a>
                                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                                    <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                                       class="btn-primary btn-filled" style="font-family: 'Poppins', sans-serif; font-weight: 600; padding: 1rem 1.5rem; border-radius: 1rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; flex: 1; justify-content: center; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4" style="width: 16px; height: 16px;">
                                            <path d="M3.75 2a.75.75 0 0 0-.75.75v10.5a.75.75 0 0 0 1.28.53L8 10.06l3.72 3.72a.75.75 0 0 0 1.28-.53V2.75a.75.75 0 0 0-.75-.75h-8.5Z" />
                                        </svg>
                                        <span>Book Now</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- No Results Message -->
            <div id="noResults" class="hidden" style="text-align: center; padding: 4rem 0;">
                <i class="fas fa-search" style="color: #9ca3af; font-size: 4rem; margin-bottom: 1.5rem;"></i>
                <h3 style="font-family: 'Poppins', sans-serif; font-size: 1.875rem; font-weight: 700; color: #6b7280; margin-bottom: 1rem;">No facilities found</h3>
                <p style="font-family: 'Poppins', sans-serif; color: #6b7280; margin-bottom: 2rem; font-size: 1.125rem; font-weight: 500;">Try adjusting your filters to see more results.</p>
                <button onclick="clearFilters()" class="btn-primary" style="font-family: 'Poppins', sans-serif; font-weight: 600; padding: 1rem 2rem; border-radius: 1rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; display: flex; align-items: center; gap: 0.5rem; margin: 0 auto; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);">
                    <i class="fas fa-undo"></i>Clear Filters
                </button>
            </div>
        <?php else: ?>
            <!-- Welcome Message for Non-logged Users -->
            <div style="text-align: center; padding: 6rem 0;">
                <i class="fas fa-lock" style="color: #9ca3af; font-size: 5rem; margin-bottom: 2rem;"></i>
                <h3 style="font-family: 'Poppins', sans-serif; font-size: 2.5rem; font-weight: 800; color: #111827; margin-bottom: 1.5rem;">Please Login to Continue</h3>
                <p style="font-family: 'Poppins', sans-serif; color: #6b7280; margin-bottom: 3rem; font-size: 1.25rem; font-weight: 500;">You need to be logged in to view and book facilities.</p>
                <div style="display: flex; flex-direction: column; align-items: center; gap: 1.5rem;">
                    <a href="auth/login.php" class="btn-primary" style="font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 1.125rem; padding: 1.25rem 2.5rem; border-radius: 1rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; text-decoration: none; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);">
                        <i class="fas fa-sign-in-alt"></i>Login
                    </a>
                    <a href="auth/register.php" class="btn-success" style="font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 1.125rem; padding: 1.25rem 2.5rem; border-radius: 1rem; background: linear-gradient(135deg, #10b981, #059669); color: white; text-decoration: none; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);">
                        <i class="fas fa-user-plus"></i>Register
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <!-- Footer -->
    <footer class="footer" style="padding: 5rem 0; margin-top: 6rem; background: linear-gradient(135deg, #1f2937, #374151);">
        <div class="footer-container" style="max-width: 1200px; margin: 0 auto; padding: 0 2rem;">
            <div class="footer-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 3rem; margin-bottom: 3rem;">
                <!-- Company Info -->
                <div class="footer-section" style="text-align: center;">
                    <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                        <div class="nav-logo" style="width: 50px; height: 50px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 10px 25px rgba(59, 130, 246, 0.3);">
                            <i class="fas fa-building" style="color: white; font-size: 1.5rem;"></i>
                        </div>
                        <h3 style="font-family: 'Poppins', sans-serif; font-size: 1.5rem; font-weight: 800; color: white;"><?php echo SITE_NAME; ?></h3>
                    </div>
                    <p style="font-family: 'Poppins', sans-serif; color: #d1d5db; line-height: 1.6; font-size: 1rem; font-weight: 500;">Your trusted partner for facility reservations. Easy booking, reliable service, and exceptional experiences.</p>
                </div>
                <!-- Quick Links -->
                <div class="footer-section" style="text-align: center;">
                    <h4 style="font-family: 'Poppins', sans-serif; font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: white;">Quick Links</h4>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <a href="#facilities" style="font-family: 'Poppins', sans-serif; color: #d1d5db; text-decoration: none; transition: color 0.3s ease; font-weight: 500; font-size: 1rem;">Browse Facilities</a>
                        <a href="auth/login.php" style="font-family: 'Poppins', sans-serif; color: #d1d5db; text-decoration: none; transition: color 0.3s ease; font-weight: 500; font-size: 1rem;">Login</a>
                        <a href="auth/register.php" style="font-family: 'Poppins', sans-serif; color: #d1d5db; text-decoration: none; transition: color 0.3s ease; font-weight: 500; font-size: 1rem;">Register</a>
                        <a href="#no-show-policy" style="font-family: 'Poppins', sans-serif; color: #d1d5db; text-decoration: none; transition: color 0.3s ease; font-weight: 500; font-size: 1rem;">No-Show Policy</a>
                    </div>
                </div>
                <!-- Contact Info -->
                <div class="footer-section" style="text-align: center;">
                    <h4 style="font-family: 'Poppins', sans-serif; font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: white;">Contact Us</h4>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <p style="font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-weight: 500; font-size: 1rem;">
                            <i class="fas fa-envelope" style="margin-right: 0.75rem; color: #3b82f6; font-size: 1.125rem;"></i>
                            support@facilityreservation.com
                        </p>
                        <p style="font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-weight: 500; font-size: 1rem;">
                            <i class="fas fa-phone" style="margin-right: 0.75rem; color: #3b82f6; font-size: 1.125rem;"></i>
                            +63 912 345 6789
                        </p>
                        <p style="font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-weight: 500; font-size: 1rem;">
                            <i class="fas fa-map-marker-alt" style="margin-right: 0.75rem; color: #3b82f6; font-size: 1.125rem;"></i>
                            Manila, Philippines
                        </p>
                    </div>
                </div>
            </div>
            <!-- Bottom Section -->
            <div class="footer-bottom" style="text-align: center; padding-top: 2rem; border-top: 1px solid #4b5563;">
                <p style="font-family: 'Poppins', sans-serif; color: #9ca3af; font-size: 1rem; font-weight: 500;">&copy; 2024 <?php echo SITE_NAME; ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>
    <script>
        // Enhanced Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (mobileMenuButton && mobileMenu) {
                // Toggle mobile menu
                mobileMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    mobileMenu.classList.toggle('hidden');
                    mobileMenu.classList.toggle('show');
                    mobileMenuButton.classList.toggle('active');
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                    }
                });
                
                // Close mobile menu when clicking on a link
                const mobileMenuLinks = mobileMenu.querySelectorAll('a');
                mobileMenuLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                    });
                });
                
                // Handle window resize
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                    }
                });
                
                // Handle escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                    }
                });
            }
            // Filter functionality
            const categoryFilter = document.getElementById('categoryFilter');
            const priceFilter = document.getElementById('priceFilter');
            const capacityFilter = document.getElementById('capacityFilter');
            const facilityCards = document.querySelectorAll('.facility-card');
            const noResults = document.getElementById('noResults');
            function filterFacilities() {
                const selectedCategory = categoryFilter.value;
                const selectedPrice = priceFilter.value;
                const selectedCapacity = capacityFilter.value;
                let visibleCount = 0;
                facilityCards.forEach((card) => {
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
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                // Show/hide no results message
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
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
            // Add event listeners
            if (categoryFilter && priceFilter && capacityFilter) {
                categoryFilter.addEventListener('change', filterFacilities);
                priceFilter.addEventListener('change', filterFacilities);
                capacityFilter.addEventListener('change', filterFacilities);
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
            // Enhanced real-time countdown timers for active facilities
            function updateCountdownTimers() {
                const countdownElements = document.querySelectorAll('.countdown-timer');
                const usageElements = document.querySelectorAll('.usage-timer');
                
                countdownElements.forEach(element => {
                    const endTime = new Date(element.dataset.endTime);
                    const now = new Date();
                    const timeLeft = endTime - now;
                    
                    if (timeLeft <= 0) {
                        element.textContent = 'TIME EXPIRED!';
                        element.style.color = '#dc2626';
                        element.style.fontWeight = 'bold';
                        element.style.background = 'rgba(220, 38, 38, 0.1)';
                        element.style.border = '2px solid #dc2626';
                        element.classList.add('urgent');
                        
                        // Add visual alert for expired timers
                        const card = element.closest('.facility-status-card');
                        if (card) {
                            card.style.border = '3px solid #dc2626';
                            card.style.animation = 'pulse 1s infinite';
                        }
                        return;
                    }
                    
                    const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                    
                    let timeString;
                    if (hours > 0) {
                        timeString = `${hours}h ${minutes}m ${seconds}s`;
                    } else if (minutes > 0) {
                        timeString = `${minutes}m ${seconds}s`;
                    } else {
                        timeString = `${seconds}s`;
                    }
                    
                    element.textContent = timeString;
                    
                    // Enhanced warning system with visual feedback
                    element.classList.remove('warning', 'urgent');
                    element.style.background = '';
                    element.style.border = '';
                    
                    if (timeLeft <= 5 * 60 * 1000) { // 5 minutes or less
                        element.style.color = '#dc2626';
                        element.style.background = 'rgba(220, 38, 38, 0.1)';
                        element.style.border = '2px solid #dc2626';
                        element.classList.add('urgent');
                        
                        // Flash the entire card for urgent warnings
                        const card = element.closest('.facility-status-card');
                        if (card) {
                            card.style.border = '3px solid #dc2626';
                        }
                    } else if (timeLeft <= 15 * 60 * 1000) { // 15 minutes or less
                        element.style.color = '#f59e0b';
                        element.style.background = 'rgba(245, 158, 11, 0.1)';
                        element.style.border = '2px solid #f59e0b';
                        element.classList.add('warning');
                        
                        // Highlight the card for warnings
                        const card = element.closest('.facility-status-card');
                        if (card) {
                            card.style.border = '2px solid #f59e0b';
                        }
                    } else {
                        element.style.color = '#92400e';
                        element.style.background = 'rgba(255, 255, 255, 0.5)';
                        element.style.border = '1px solid rgba(245, 158, 11, 0.3)';
                        
                        // Reset card styling
                        const card = element.closest('.facility-status-card');
                        if (card) {
                            card.style.border = '2px solid #e5e7eb';
                        }
                    }
                });
                
                usageElements.forEach(element => {
                    const startTime = new Date(element.dataset.startTime);
                    const now = new Date();
                    const duration = now - startTime;
                    
                    const hours = Math.floor(duration / (1000 * 60 * 60));
                    const minutes = Math.floor((duration % (1000 * 60 * 60)) / 1000);
                    const seconds = Math.floor((duration % (1000 * 60)) / 1000);
                    
                    let timeString;
                    if (hours > 0) {
                        timeString = `${hours}h ${minutes}m ${seconds}s`;
                    } else if (minutes > 0) {
                        timeString = `${minutes}m ${seconds}s`;
                    } else {
                        timeString = `${seconds}s`;
                    }
                    
                    element.textContent = timeString;
                    
                    // Add visual feedback for usage duration
                    if (duration > 2 * 60 * 60 * 1000) { // More than 2 hours
                        element.style.color = '#059669';
                        element.style.fontWeight = '900';
                    } else if (duration > 60 * 60 * 1000) { // More than 1 hour
                        element.style.color = '#10b981';
                        element.style.fontWeight = '800';
                    }
                });
                
                // Update progress bars if they exist
                updateProgressBars();
            }
            
            // Function to update progress bars
            function updateProgressBars() {
                const progressBars = document.querySelectorAll('.progress-bar-fill');
                progressBars.forEach(bar => {
                    const card = bar.closest('.facility-status-card');
                    if (card) {
                        const countdownElement = card.querySelector('.countdown-timer');
                        if (countdownElement && countdownElement.dataset.endTime) {
                            const endTime = new Date(countdownElement.dataset.endTime);
                            const now = new Date();
                            const timeLeft = endTime - now;
                            
                            if (timeLeft > 0) {
                                // Calculate progress percentage
                                const totalDuration = 60 * 60 * 1000; // Assume 1 hour default
                                const progress = Math.max(0, Math.min(100, ((totalDuration - timeLeft) / totalDuration) * 100));
                                bar.style.width = progress + '%';
                                
                                // Update progress color based on time remaining
                                if (timeLeft <= 5 * 60 * 1000) {
                                    bar.style.background = 'linear-gradient(90deg, #dc2626, #ef4444)';
                                } else if (timeLeft <= 15 * 60 * 1000) {
                                    bar.style.background = 'linear-gradient(90deg, #f59e0b, #fbbf24)';
                                    bar.style.background = 'linear-gradient(90deg, #f59e0b, #fbbf24)';
                                } else {
                                    bar.style.background = 'linear-gradient(90deg, #10b981, #3b82f6)';
                                }
                            }
                        }
                    }
                });
            }
            // Update timers every second
            setInterval(updateCountdownTimers, 1000);
            // Initial update
            updateCountdownTimers();
            
            // Function to update last update time
            function updateLastUpdateTime() {
                const lastUpdateElement = document.getElementById('lastUpdateTime');
                if (lastUpdateElement) {
                    const now = new Date();
                    const timeString = now.toLocaleTimeString();
                    lastUpdateElement.textContent = `Last updated: ${timeString}`;
                }
            }
            
            // Update last update time every 30 seconds
            setInterval(updateLastUpdateTime, 30000);
            updateLastUpdateTime(); // Initial update
            
            // Auto-refresh page every 5 minutes to get updated data
            setTimeout(() => {
                location.reload();
            }, 5 * 60 * 1000);
        });
    </script>
</body>
</html>
