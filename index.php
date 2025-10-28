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
// Get facilities with categories and pricing options
$stmt = $pdo->query("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    WHERE f.is_active = 1 
    ORDER BY f.name
");
$facilities = $stmt->fetchAll();

// Get pricing options for each facility
foreach ($facilities as &$facility) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM facility_pricing_options 
            WHERE facility_id = ? AND is_active = 1 
            ORDER BY sort_order ASC, name ASC
            LIMIT 3
        ");
        $stmt->execute([$facility['id']]);
        $facility['pricing_options'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        // If tables don't exist yet, set empty array
        $facility['pricing_options'] = [];
    }
}
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
            background: #1e40af !important;
            border-bottom: 1px solid #1d4ed8 !important;
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
            color: white !important;
            font-size: 1.5rem !important;
        }
        
        .nav-user-name {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            color: white !important;
        }
        
        /* Unified Navigation Buttons */
        .nav-btn {
            background: rgba(253, 253, 253, 0.37) !important;
            color: white !important;
            border: 1px solid rgb(2, 2, 2) !important;
            padding: 0.75rem 1.5rem !important;
            border-radius: 0.5rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            transition: all 0.3s ease !important;
            min-width: 140px !important;
            justify-content: center !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }
        
        .nav-btn.logout-btn {
            background: rgba(220, 38, 38, 0.8) !important;
            border: 1px solid rgba(220, 38, 38, 0.9) !important;
        }
        
        .nav-btn.logout-btn:hover {
            background: rgba(220, 38, 38, 1) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3) !important;
        }
        
        /* Mobile menu specific button styling */
        .mobile-menu .nav-btn {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8) !important;
            color: white !important;
            border: 2px solid rgba(255, 255, 255, 0.3) !important;
            padding: 1rem 1.5rem !important;
            border-radius: 1rem !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 700 !important;
            font-size: 1rem !important;
            text-decoration: none !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.75rem !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            margin-bottom: 0.75rem !important;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3) !important;
        }
        
        .mobile-menu .nav-btn:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af) !important;
            transform: translateY(-2px) scale(1.02) !important;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4) !important;
            color: white !important;
        }
        
        .mobile-menu .nav-btn.logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626) !important;
            border: 2px solid rgba(255, 255, 255, 0.3) !important;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3) !important;
        }
        
        .mobile-menu .nav-btn.logout-btn:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4) !important;
        }
        
        .nav-user-info {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
            background: rgba(255, 255, 255, 0.1) !important;
            padding: 0.75rem 1rem !important;
            border-radius: 0.5rem !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            backdrop-filter: blur(10px) !important;
        }
        
        .nav-user-icon {
            color: white !important;
            font-size: 1rem !important;
        }
        
        /* Navigation Menu Visibility */
        .nav-menu {
            display: flex !important;
            align-items: center !important;
            gap: 1rem !important;
        }
        
        .nav-menu-mobile {
            display: none !important;
        }
        
        @media (max-width: 768px) {
            .nav-menu {
                display: none !important;
            }
            
            .nav-menu-mobile {
                display: block !important;
            }
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
                padding: 3rem 0 !important;
                min-height: 60vh !important;
            }
            
            .hero-title {
                font-size: 2.25rem !important;
                line-height: 1.2 !important;
                margin-bottom: 1rem !important;
            }
            
            .hero-subtitle {
                font-size: 1rem !important;
                line-height: 1.5 !important;
                margin-bottom: 1.5rem !important;
            }
            
            .btn-hero {
                padding: 0.875rem 1.25rem !important;
                font-size: 0.95rem !important;
                min-width: 160px !important;
                margin: 0.5rem !important;
            }
            
            .nav-container {
                padding: 0 1rem !important;
                height: 65px !important;
            }
            
            .hero-cta-container {
                flex-direction: column !important;
                gap: 1rem !important;
                align-items: center !important;
            }
            
            .hero-stats {
                flex-direction: column !important;
                gap: 1rem !important;
                margin-top: 2rem !important;
            }
            
            .stat-item {
                padding: 1rem 1.5rem !important;
                width: 100% !important;
                max-width: 280px !important;
            }
            
            .stat-number {
                font-size: 2rem !important;
            }
            
            .stat-label {
                font-size: 0.875rem !important;
            }
        }
        
        /* Extra small mobile devices */
        @media (max-width: 480px) {
            .hero-section {
                padding: 2rem 0 !important;
                min-height: 50vh !important;
            }
            
            .hero-title {
                font-size: 1.75rem !important;
            }
            
            .hero-subtitle {
                font-size: 0.9rem !important;
            }
            
            .btn-hero {
                padding: 0.75rem 1rem !important;
                font-size: 0.875rem !important;
                min-width: 140px !important;
            }
            
            .nav-container {
                padding: 0 0.75rem !important;
                height: 60px !important;
            }
            
            .nav-title {
                font-size: 1.125rem !important;
            }
            
            .stat-item {
                padding: 0.875rem 1.25rem !important;
            }
            
            .stat-number {
                font-size: 1.75rem !important;
            }
            
            .stat-label {
                font-size: 0.8rem !important;
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
        
        /* Mobile User Profile Enhancements */
        .mobile-user-profile {
            animation: slideInDown 0.6s ease-out !important;
        }
        
        .mobile-nav-links .nav-btn {
            animation: slideInUp 0.6s ease-out !important;
        }
        
        .mobile-nav-links .nav-btn:nth-child(1) { animation-delay: 0.1s; }
        .mobile-nav-links .nav-btn:nth-child(2) { animation-delay: 0.2s; }
        .mobile-nav-links .nav-btn:nth-child(3) { animation-delay: 0.3s; }
        .mobile-nav-links .nav-btn:nth-child(4) { animation-delay: 0.4s; }
        .mobile-nav-links .nav-btn:nth-child(5) { animation-delay: 0.5s; }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Mobile Menu Touch Enhancements */
        .mobile-menu .nav-btn:active {
            transform: scale(0.98) !important;
            transition: transform 0.1s ease !important;
        }
        
        /* Mobile Menu Icons */
        .mobile-menu .nav-btn i {
            font-size: 1.125rem !important;
            width: 20px !important;
            text-align: center !important;
        }
        
        /* Mobile Menu Responsive Adjustments */
        @media (max-width: 480px) {
            .mobile-menu .nav-btn {
                padding: 0.875rem 1.25rem !important;
                font-size: 0.95rem !important;
            }
            
            .mobile-user-profile {
                padding: 1.25rem !important;
            }
            
            .mobile-user-profile div {
                width: 70px !important;
                height: 70px !important;
            }
            
            .mobile-user-profile i {
                font-size: 1.75rem !important;
            }
        }
        
        /* Mobile-specific enhancements */
        @media (max-width: 768px) {
            .mobile-facility-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
                padding: 0 0.5rem !important;
            }
            
            .mobile-facility-card {
                padding: 1.25rem !important;
                margin-bottom: 1rem !important;
                border-radius: 1.25rem !important;
            }
            
            .mobile-facility-card h3 {
                font-size: 1.125rem !important;
                line-height: 1.3 !important;
            }
            
            .mobile-facility-card .countdown-timer {
                font-size: 1.25rem !important;
                padding: 0.75rem !important;
            }
            
            .mobile-facility-card .usage-timer {
                font-size: 1rem !important;
            }
            
            /* Mobile navigation improvements */
            .mobile-menu {
                position: fixed !important;
                top: 65px !important;
                left: 0 !important;
                right: 0 !important;
                background: linear-gradient(135deg, #1e40af, #3b82f6) !important;
                backdrop-filter: blur(20px) !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.2) !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
                z-index: 999 !important;
                transform: translateY(-100%) !important;
                opacity: 0 !important;
                visibility: hidden !important;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            }
            
            .mobile-menu.show {
                transform: translateY(0) !important;
                opacity: 1 !important;
                visibility: visible !important;
            }
            
            .mobile-menu-btn {
                display: flex !important;
                background: none !important;
                border: none !important;
                padding: 0.5rem !important;
                border-radius: 0.5rem !important;
                cursor: pointer !important;
                flex-direction: column !important;
                gap: 4px !important;
                transition: all 0.3s ease !important;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: transparent !important;
            }
            
            .mobile-menu-btn:hover {
                background: rgba(255, 255, 255, 0.1) !important;
            }
            
            .mobile-menu-btn.active {
                background: rgba(255, 255, 255, 0.2) !important;
            }
            
            .hamburger-line {
                width: 24px !important;
                height: 3px !important;
                background: white !important;
                border-radius: 2px !important;
                transition: all 0.3s ease !important;
            }
            
            .mobile-menu-btn.active .hamburger-line:nth-child(1) {
                transform: rotate(45deg) translate(6px, 6px) !important;
            }
            
            .mobile-menu-btn.active .hamburger-line:nth-child(2) {
                opacity: 0 !important;
            }
            
            .mobile-menu-btn.active .hamburger-line:nth-child(3) {
                transform: rotate(-45deg) translate(6px, -6px) !important;
            }
            
            /* Mobile status indicators */
            .mobile-status-indicators {
                flex-direction: column !important;
                gap: 0.75rem !important;
            }
            
            /* Mobile hero stats for logged-in users */
            .hero-stats {
                flex-direction: column !important;
                gap: 1rem !important;
                margin-top: 2rem !important;
                padding: 0 1rem !important;
            }
            
            .hero-stats .stat-item {
                padding: 1rem 1.5rem !important;
                border-radius: 1.25rem !important;
                margin-bottom: 0.75rem !important;
            }
            
            .hero-stats .stat-number {
                font-size: 2rem !important;
                margin-bottom: 0.25rem !important;
            }
            
            .hero-stats .stat-label {
                font-size: 0.875rem !important;
            }
            
            /* Mobile currently used facilities */
            .mobile-currently-used {
                padding: 1rem !important;
                margin-bottom: 2rem !important;
            }
            
            .mobile-currently-used h2 {
                font-size: 1.5rem !important;
                margin-bottom: 1rem !important;
                text-align: center !important;
            }
            
            .mobile-currently-used .facility-card {
                margin-bottom: 1rem !important;
                padding: 1.25rem !important;
            }
            
            .mobile-status-indicators > div {
                padding: 0.75rem 1rem !important;
                font-size: 0.875rem !important;
            }
            
            /* Mobile facility header */
            .mobile-facility-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.75rem !important;
            }
            
            .mobile-facility-header > div:last-child {
                align-self: flex-end !important;
            }
            
            /* Mobile user info */
            .mobile-user-info {
                flex-direction: column !important;
                gap: 0.75rem !important;
            }
            
            .mobile-user-info > div {
                flex-direction: row !important;
                align-items: center !important;
                gap: 0.75rem !important;
            }
            
            /* Mobile countdown timer */
            .mobile-countdown-container {
                padding: 1rem !important;
                margin-bottom: 1rem !important;
            }
            
            .mobile-countdown-timer {
                font-size: 1.25rem !important;
                padding: 0.75rem !important;
                text-align: center !important;
            }
            
            /* Mobile upcoming reservations */
            .mobile-upcoming-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
            }
            
            .mobile-upcoming-card {
                padding: 1.25rem !important;
                border-radius: 1rem !important;
            }
            
            /* Mobile alert improvements */
            .mobile-alert {
                margin: 1rem 0.5rem !important;
                padding: 1rem !important;
                border-radius: 1rem !important;
            }
            
            .mobile-alert h3 {
                font-size: 1.125rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            .mobile-alert p {
                font-size: 0.875rem !important;
                line-height: 1.5 !important;
            }
        }
        
        /* Extra small mobile devices */
        @media (max-width: 480px) {
            .mobile-facility-card {
                padding: 1rem !important;
                border-radius: 1rem !important;
            }
            
            .mobile-facility-card h3 {
                font-size: 1rem !important;
            }
            
            .mobile-countdown-timer {
                font-size: 1.125rem !important;
                padding: 0.625rem !important;
            }
            
            .mobile-menu {
                top: 60px !important;
            }
            
            .mobile-status-indicators > div {
                padding: 0.625rem 0.875rem !important;
                font-size: 0.8rem !important;
            }
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
                        <a href="admin/dashboard.php" class="nav-btn">
                            <span>Admin Panel</span>
                        </a>
                    <?php else: ?>
                        <div class="nav-user-info">
                            <i class="fas fa-user nav-user-icon"></i>
                            <span class="nav-user-name">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        </div>
                        
                    <?php endif; ?>
                    <a href="auth/logout.php" class="nav-btn logout-btn" onclick="return confirmLogout()">
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
                <button id="mobile-menu-button" class="mobile-menu-btn" aria-label="Toggle mobile menu" aria-expanded="false">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
            </div>
        </div>
            <!-- Mobile Navigation -->
        <div id="mobile-menu" class="hidden mobile-menu">
            <div class="container" style="padding: 1.5rem 1rem;">
                <?php if ($auth->isLoggedIn()): ?>
                    <!-- Mobile User Profile Section -->
                    <div class="mobile-user-profile" style="text-align: center; margin-bottom: 2rem; padding: 1.5rem; background: rgba(255, 255, 255, 0.15); border-radius: 1.5rem; border: 2px solid rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px);">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);">
                            <i class="fas fa-<?php echo $auth->isAdmin() ? 'user-shield' : 'user'; ?>" style="color: white; font-size: 2rem;"></i>
                        </div>
                        <h3 style="color: white; font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 1.25rem; margin-bottom: 0.5rem;">
                            <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </h3>
                        <p style="color: rgba(255, 255, 255, 0.8); font-family: 'Poppins', sans-serif; font-weight: 500; font-size: 0.9rem;">
                            <?php echo $auth->isAdmin() ? 'Administrator' : 'Member'; ?>
                        </p>
                    </div>
                    
                    <!-- Mobile Navigation Links -->
                    <div class="mobile-nav-links" style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if ($auth->isAdmin()): ?>
                            <a href="admin/dashboard.php" class="nav-btn">
                                <i class="fas fa-tachometer-alt"></i>
                                <span>Admin Dashboard</span>
                            </a>
                            <a href="admin/facilities.php" class="nav-btn">
                                <i class="fas fa-building"></i>
                                <span>Manage Facilities</span>
                            </a>
                            <a href="admin/reservations.php" class="nav-btn">
                                <i class="fas fa-calendar-check"></i>
                                <span>All Reservations</span>
                            </a>
                            <a href="admin/users.php" class="nav-btn">
                                <i class="fas fa-users"></i>
                                <span>User Management</span>
                            </a>
                        <?php else: ?>
                            <a href="facilities.php" class="nav-btn">
                                <i class="fas fa-building"></i>
                                <span>Browse Facilities</span>
                            </a>
                            <a href="my_reservations.php" class="nav-btn">
                                <i class="fas fa-calendar-alt"></i>
                                <span>My Reservations</span>
                            </a>
                            <a href="usage_history.php" class="nav-btn">
                                <i class="fas fa-history"></i>
                                <span>Usage History</span>
                            </a>
                            <a href="manage_reservation.php" class="nav-btn">
                                <i class="fas fa-cog"></i>
                                <span>Manage Bookings</span>
                            </a>
                        <?php endif; ?>
                        
                        <!-- Logout Button -->
                        <a href="auth/logout.php" class="nav-btn logout-btn" onclick="return confirmLogout()">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Guest User Menu -->
                    <div class="mobile-guest-menu" style="text-align: center;">
                        <div style="margin-bottom: 2rem;">
                            <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #6b7280, #4b5563); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 8px 25px rgba(107, 114, 128, 0.3);">
                                <i class="fas fa-user" style="color: white; font-size: 2rem;"></i>
                            </div>
                            <h3 style="color: white; font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 1.25rem; margin-bottom: 0.5rem;">
                                Welcome Guest
                            </h3>
                            <p style="color: rgba(255, 255, 255, 0.8); font-family: 'Poppins', sans-serif; font-weight: 500; font-size: 0.9rem;">
                                Please login to access all features
                            </p>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <a href="auth/login.php" class="nav-btn">
                                <i class="fas fa-sign-in-alt"></i>
                                <span>Login</span>
                            </a>
                            <a href="auth/register.php" class="nav-btn">
                                <i class="fas fa-user-plus"></i>
                                <span>Create Account</span>
                            </a>
                        </div>
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
                    <a href="usage_history.php" class="btn-primary btn-hero">
                        <i class="fas fa-history"></i>
                        <span>Usage History</span>
                    </a>
                    <a href="facilities.php" class="btn-secondary btn-hero">
                        <i class="fas fa-building"></i>
                        <span>Browse Facilities</span>
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Quick stats for logged-in users -->
            <?php if ($auth->isLoggedIn()): ?>
                <div class="hero-stats mobile-hero-stats" style="display: flex; justify-content: center; gap: 3rem; margin-top: 4rem; flex-wrap: wrap;">
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
        <div class="alert alert-warning mobile-alert" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.2);">
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
            <div class="mobile-currently-used" style="margin-bottom: 4rem;">
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
                        <div class="mobile-status-indicators" style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
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
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 mobile-facility-grid" style="gap: 1.5rem;">
                        <?php foreach ($active_reservations as $reservation): ?>
                            <div class="facility-status-card mobile-facility-card" style="background: white; border-radius: 1.5rem; padding: 2rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); border: 2px solid #e5e7eb; position: relative; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform: translateY(0);" onmouseover="this.style.transform='translateY(-8px) scale(1.02)'; this.style.boxShadow='0 25px 50px rgba(0, 0, 0, 0.25)'" onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.boxShadow='0 10px 25px rgba(0, 0, 0, 0.1)'">
                                <!-- Status indicator bar -->
                                <div style="position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(90deg, #10b981, #3b82f6, #f59e0b); animation: gradientShift 3s ease-in-out infinite;"></div>
                                
                                <!-- Facility header -->
                                <div class="mobile-facility-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; margin-top: 0.5rem;">
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
                                <div class="mobile-user-info" style="background: #f8fafc; padding: 1.5rem; border-radius: 1rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0;">
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
                                <?php 
                                    $start_time = strtotime($reservation['start_time']);
                                    $end_time = strtotime($reservation['end_time']);
                                    $now = time();
                                    $has_started = $now >= $start_time;
                                    
                                    if ($has_started) {
                                        // Reservation has started - show time remaining
                                        $time_left = $end_time - $now;
                                        $hours = floor($time_left / 3600);
                                        $minutes = floor(($time_left % 3600) / 60);
                                        $countdown_text = "Ends in:";
                                        $countdown_icon = "fas fa-hourglass-end";
                                        $timer_bg = "linear-gradient(135deg, #fef3c7, #fde68a)";
                                        $timer_border = "#f59e0b";
                                        $timer_color = "#92400e";
                                    } else {
                                        // Reservation hasn't started - show time until start
                                        $time_left = $start_time - $now;
                                        $hours = floor($time_left / 3600);
                                        $minutes = floor(($time_left % 3600) / 60);
                                        $countdown_text = "Starts in:";
                                        $countdown_icon = "fas fa-clock";
                                        $timer_bg = "linear-gradient(135deg, #dbeafe, #bfdbfe)";
                                        $timer_border = "#3b82f6";
                                        $timer_color = "#1e40af";
                                    }
                                ?>
                                <div class="mobile-countdown-container" style="background: <?php echo $timer_bg; ?>; padding: 1.5rem; border-radius: 1rem; border: 2px solid <?php echo $timer_border; ?>; margin-bottom: 1rem; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                        <span style="font-family: 'Poppins', sans-serif; font-size: 1rem; color: <?php echo $timer_color; ?>; font-weight: 700;">
                                            <i class="<?php echo $countdown_icon; ?>" style="margin-right: 0.5rem;"></i><?php echo $countdown_text; ?>
                                        </span>
                                        <i class="fas fa-<?php echo $has_started ? 'exclamation-triangle' : 'play-circle'; ?>" style="color: <?php echo $timer_border; ?>; font-size: 1.25rem;"></i>
                                    </div>
                                    <div class="countdown-timer mobile-countdown-timer" 
                                         data-end-time="<?php echo $reservation['end_time']; ?>" 
                                         data-start-time="<?php echo $reservation['start_time']; ?>"
                                         style="font-family: 'Poppins', monospace; font-weight: 800; color: <?php echo $timer_color; ?>; font-size: 1.75rem; text-align: center; padding: 1rem; background: rgba(255, 255, 255, 0.6); border-radius: 0.75rem; border: 1px solid <?php echo $timer_border; ?>; box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);">
                                        <?php 
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
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 mobile-upcoming-grid" style="gap: 1.5rem;">
                            <?php foreach ($upcoming_reservations as $reservation): ?>
                                <div class="mobile-upcoming-card" style="background: #f8fafc; border-radius: 1rem; padding: 1.5rem; border: 1px solid #e2e8f0; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 10px 25px rgba(0, 0, 0, 0.1)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
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
           
            <!-- Enhanced Facilities Grid -->
           
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

   
    <script>
        // Enhanced Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (mobileMenuButton && mobileMenu) {
                // Toggle mobile menu
                mobileMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    
                    const isHidden = mobileMenu.classList.contains('hidden');
                    
                    if (isHidden) {
                        mobileMenu.classList.remove('hidden');
                        mobileMenu.classList.add('show');
                        mobileMenuButton.classList.add('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'true');
                        
                        // Prevent body scroll when menu is open
                        document.body.style.overflow = 'hidden';
                    } else {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'false');
                        
                        // Restore body scroll
                        document.body.style.overflow = '';
                    }
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                        if (!mobileMenu.classList.contains('hidden')) {
                            mobileMenu.classList.add('hidden');
                            mobileMenu.classList.remove('show');
                            mobileMenuButton.classList.remove('active');
                            mobileMenuButton.setAttribute('aria-expanded', 'false');
                            document.body.style.overflow = '';
                        }
                    }
                });
                
                // Close mobile menu when clicking on a link
                const mobileMenuLinks = mobileMenu.querySelectorAll('a');
                mobileMenuLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'false');
                        document.body.style.overflow = '';
                    });
                });
                
                // Handle window resize
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'false');
                        document.body.style.overflow = '';
                    }
                });
                
                // Handle escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'false');
                        document.body.style.overflow = '';
                    }
                });
                
                // Touch events for better mobile interaction
                let touchStartY = 0;
                let touchEndY = 0;
                
                mobileMenu.addEventListener('touchstart', function(e) {
                    touchStartY = e.changedTouches[0].screenY;
                }, { passive: true });
                
                mobileMenu.addEventListener('touchend', function(e) {
                    touchEndY = e.changedTouches[0].screenY;
                    handleSwipe();
                }, { passive: true });
                
                function handleSwipe() {
                    const swipeThreshold = 50;
                    const diff = touchStartY - touchEndY;
                    
                    // Swipe up to close menu
                    if (diff > swipeThreshold) {
                        mobileMenu.classList.add('hidden');
                        mobileMenu.classList.remove('show');
                        mobileMenuButton.classList.remove('active');
                        mobileMenuButton.setAttribute('aria-expanded', 'false');
                        document.body.style.overflow = '';
                    }
                }
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
                    const startTime = new Date(element.dataset.startTime);
                    const now = new Date();
                    
                    // Determine if reservation has started
                    const hasStarted = now >= startTime;
                    let timeLeft, timeString;
                    
                    if (hasStarted) {
                        // Reservation has started - countdown to end
                        timeLeft = endTime - now;
                        
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
                        
                        if (hours > 0) {
                            timeString = `${hours}h ${minutes}m ${seconds}s`;
                        } else if (minutes > 0) {
                            timeString = `${minutes}m ${seconds}s`;
                        } else {
                            timeString = `${seconds}s`;
                        }
                    } else {
                        // Reservation hasn't started - countdown to start
                        timeLeft = startTime - now;
                        
                        if (timeLeft <= 0) {
                            element.textContent = 'STARTING NOW!';
                            element.style.color = '#059669';
                            element.style.fontWeight = 'bold';
                            element.style.background = 'rgba(5, 150, 105, 0.1)';
                            element.style.border = '2px solid #059669';
                            element.classList.add('starting');
                            
                            // Add visual alert for starting timers
                            const card = element.closest('.facility-status-card');
                            if (card) {
                                card.style.border = '3px solid #059669';
                                card.style.animation = 'pulse 1s infinite';
                            }
                            return;
                        }
                        
                        const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                        const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                        
                        if (hours > 0) {
                            timeString = `${hours}h ${minutes}m ${seconds}s`;
                        } else if (minutes > 0) {
                            timeString = `${minutes}m ${seconds}s`;
                        } else {
                            timeString = `${seconds}s`;
                        }
                    }
                    
                    element.textContent = timeString;
                    
                    // Enhanced warning system with visual feedback
                    element.classList.remove('warning', 'urgent', 'starting');
                    element.style.background = '';
                    element.style.border = '';
                    
                    if (hasStarted) {
                        // Reservation has started - show end time warnings
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
                    } else {
                        // Reservation hasn't started - show start time warnings
                        if (timeLeft <= 5 * 60 * 1000) { // 5 minutes or less until start
                            element.style.color = '#059669';
                            element.style.background = 'rgba(5, 150, 105, 0.1)';
                            element.style.border = '2px solid #059669';
                            element.classList.add('starting');
                            
                            // Highlight the card for starting soon
                            const card = element.closest('.facility-status-card');
                            if (card) {
                                card.style.border = '3px solid #059669';
                            }
                        } else if (timeLeft <= 15 * 60 * 1000) { // 15 minutes or less until start
                            element.style.color = '#3b82f6';
                            element.style.background = 'rgba(59, 130, 246, 0.1)';
                            element.style.border = '2px solid #3b82f6';
                            element.classList.add('warning');
                            
                            // Highlight the card for starting soon
                            const card = element.closest('.facility-status-card');
                            if (card) {
                                card.style.border = '2px solid #3b82f6';
                            }
                        } else {
                            element.style.color = '#1e40af';
                            element.style.background = 'rgba(255, 255, 255, 0.5)';
                            element.style.border = '1px solid rgba(59, 130, 246, 0.3)';
                            
                            // Reset card styling
                            const card = element.closest('.facility-status-card');
                            if (card) {
                                card.style.border = '2px solid #e5e7eb';
                            }
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
            
            // Mobile-specific optimizations
            if (window.innerWidth <= 768) {
                // Reduce animation frequency on mobile for better performance
                const originalUpdateInterval = setInterval(updateCountdownTimers, 1000);
                clearInterval(originalUpdateInterval);
                setInterval(updateCountdownTimers, 2000); // Update every 2 seconds on mobile
                
                // Optimize scroll performance
                let ticking = false;
                function updateOnScroll() {
                    if (!ticking) {
                        requestAnimationFrame(() => {
                            // Mobile scroll optimizations can be added here
                            ticking = false;
                        });
                        ticking = true;
                    }
                }
                
                window.addEventListener('scroll', updateOnScroll, { passive: true });
                
                // Touch-friendly interactions
                const touchElements = document.querySelectorAll('.facility-status-card, .mobile-facility-card');
                touchElements.forEach(element => {
                    element.addEventListener('touchstart', function() {
                        this.style.transform = 'scale(0.98)';
                    }, { passive: true });
                    
                    element.addEventListener('touchend', function() {
                        this.style.transform = '';
                    }, { passive: true });
                });
                
                // Prevent zoom on double tap for better UX
                let lastTouchEnd = 0;
                document.addEventListener('touchend', function (event) {
                    const now = (new Date()).getTime();
                    if (now - lastTouchEnd <= 300) {
                        event.preventDefault();
                    }
                    lastTouchEnd = now;
                }, false);
            }
        });
        
        // Logout confirmation function
        function confirmLogout() {
            return confirm('⚠️ Are you sure you want to logout?\n\nThis will end your current session and you will need to login again to access your reservations.');
        }
    </script>
</body>
</html>

