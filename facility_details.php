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
    SELECT f.*, c.name as category_name,
           CASE 
               WHEN f.is_closed_for_event = 1 AND f.closure_end_date >= CURDATE() THEN 1
               ELSE 0
           END as is_currently_closed,
           f.closure_reason,
           f.closure_end_date
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

// Get pricing options for this facility
try {
    $stmt = $pdo->prepare("
        SELECT id, name, description, pricing_type, price_per_unit, price_per_hour, is_active, sort_order 
        FROM facility_pricing_options 
        WHERE facility_id = ? AND is_active = 1 
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([$facility_id]);
    $pricing_options = $stmt->fetchAll();
} catch (PDOException $e) {
    // If tables don't exist yet, set empty array
    $pricing_options = [];
}

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_rating') {
        $rating = (int)$_POST['rating'];
        $review_title = trim($_POST['review_title'] ?? '');
        $review_text = trim($_POST['review_text'] ?? '');
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        
        if ($rating >= 1 && $rating <= 5) {
            try {
                // Check if user already rated this facility
                $stmt = $pdo->prepare("SELECT id FROM facility_ratings WHERE user_id = ? AND facility_id = ?");
                $stmt->execute([$_SESSION['user_id'], $facility_id]);
                $existing_rating = $stmt->fetch();
                
                if ($existing_rating) {
                    // Update existing rating
                    $stmt = $pdo->prepare("
                        UPDATE facility_ratings 
                        SET rating = ?, review_title = ?, review_text = ?, is_anonymous = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$rating, $review_title, $review_text, $is_anonymous, $existing_rating['id']]);
                } else {
                    // Insert new rating
                    $stmt = $pdo->prepare("
                        INSERT INTO facility_ratings (facility_id, user_id, rating, review_title, review_text, is_anonymous, is_verified)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$facility_id, $_SESSION['user_id'], $rating, $review_title, $review_text, $is_anonymous]);
                }
                
                // Update facility rating summary
                updateFacilityRatingSummary($pdo, $facility_id);
                
                $_SESSION['success_message'] = 'Thank you for your rating and feedback!';
                header("Location: facility_details.php?facility_id=$facility_id");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Failed to submit rating. Please try again.';
            }
        }
    }
    
    if ($_POST['action'] === 'submit_reply') {
        $rating_id = (int)$_POST['rating_id'];
        $reply_text = trim($_POST['reply_text'] ?? '');
        
        if ($reply_text && $rating_id) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO feedback_replies (rating_id, user_id, reply_text, is_facility_reply)
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$rating_id, $_SESSION['user_id'], $reply_text]);
                
                $_SESSION['success_message'] = 'Reply submitted successfully!';
                header("Location: facility_details.php?facility_id=$facility_id");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Failed to submit reply. Please try again.';
            }
        }
    }
    
    if ($_POST['action'] === 'vote_feedback') {
        $rating_id = (int)($_POST['rating_id'] ?? 0);
        $reply_id = (int)($_POST['reply_id'] ?? 0);
        $vote_type = $_POST['vote_type'] ?? '';
        
        if (($rating_id || $reply_id) && in_array($vote_type, ['upvote', 'downvote'])) {
            try {
                // Check if user already voted
                $stmt = $pdo->prepare("
                    SELECT id FROM feedback_votes 
                    WHERE user_id = ? AND (" . ($rating_id ? "rating_id = ?" : "reply_id = ?") . ")
                ");
                $stmt->execute([$_SESSION['user_id'], $rating_id ?: $reply_id]);
                $existing_vote = $stmt->fetch();
                
                if ($existing_vote) {
                    // Update existing vote
                    $stmt = $pdo->prepare("UPDATE feedback_votes SET vote_type = ? WHERE id = ?");
                    $stmt->execute([$vote_type, $existing_vote['id']]);
                } else {
                    // Insert new vote
                    $stmt = $pdo->prepare("
                        INSERT INTO feedback_votes (rating_id, reply_id, user_id, vote_type)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$rating_id ?: null, $reply_id ?: null, $_SESSION['user_id'], $vote_type]);
                }
                
                echo json_encode(['success' => true]);
                exit();
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to vote']);
                exit();
            }
        }
    }
}

// Function to update facility rating summary
function updateFacilityRatingSummary($pdo, $facility_id) {
    try {
        // Get rating statistics
        $stmt = $pdo->prepare("
            SELECT 
                AVG(rating) as avg_rating,
                COUNT(*) as total_ratings,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1
            FROM facility_ratings 
            WHERE facility_id = ?
        ");
        $stmt->execute([$facility_id]);
        $stats = $stmt->fetch();
        
        $breakdown = [
            '5' => (int)$stats['rating_5'],
            '4' => (int)$stats['rating_4'],
            '3' => (int)$stats['rating_3'],
            '2' => (int)$stats['rating_2'],
            '1' => (int)$stats['rating_1']
        ];
        
        // Update facility table
        $stmt = $pdo->prepare("
            UPDATE facilities 
            SET average_rating = ?, total_ratings = ?, rating_breakdown = ?
            WHERE id = ?
        ");
        $stmt->execute([
            round($stats['avg_rating'], 2),
            (int)$stats['total_ratings'],
            json_encode($breakdown),
            $facility_id
        ]);
    } catch (PDOException $e) {
        error_log("Failed to update facility rating summary: " . $e->getMessage());
    }
}

// Get facility ratings and feedback
try {
    $stmt = $pdo->prepare("
        SELECT fr.*, u.full_name, u.email,
               COUNT(fr2.id) as reply_count,
               SUM(CASE WHEN fv.vote_type = 'upvote' THEN 1 ELSE 0 END) as upvotes,
               SUM(CASE WHEN fv.vote_type = 'downvote' THEN 1 ELSE 0 END) as downvotes
        FROM facility_ratings fr
        JOIN users u ON fr.user_id = u.id
        LEFT JOIN feedback_replies fr2 ON fr.id = fr2.rating_id
        LEFT JOIN feedback_votes fv ON fr.id = fv.rating_id
        WHERE fr.facility_id = ?
        GROUP BY fr.id
        ORDER BY fr.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$facility_id]);
    $facility_ratings = $stmt->fetchAll();
} catch (PDOException $e) {
    $facility_ratings = [];
}

// Get replies for each rating
$rating_replies = [];
foreach ($facility_ratings as $rating) {
    try {
        $stmt = $pdo->prepare("
            SELECT fr.*, u.full_name, u.email,
                   SUM(CASE WHEN fv.vote_type = 'upvote' THEN 1 ELSE 0 END) as upvotes,
                   SUM(CASE WHEN fv.vote_type = 'downvote' THEN 1 ELSE 0 END) as downvotes
            FROM feedback_replies fr
            JOIN users u ON fr.user_id = u.id
            LEFT JOIN feedback_votes fv ON fr.id = fv.reply_id
            WHERE fr.rating_id = ?
            GROUP BY fr.id
            ORDER BY fr.created_at ASC
        ");
        $stmt->execute([$rating['id']]);
        $rating_replies[$rating['id']] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $rating_replies[$rating['id']] = [];
    }
}

// Check if current user has rated this facility
$user_rating = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM facility_ratings WHERE user_id = ? AND facility_id = ?");
        $stmt->execute([$_SESSION['user_id'], $facility_id]);
        $user_rating = $stmt->fetch();
    } catch (PDOException $e) {
        $user_rating = null;
    }
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
// Get date filter with validation to prevent past dates
$today = date('Y-m-d');
$requested_date = $_GET['date'] ?? $today;

// Validate that the requested date is not in the past
if ($requested_date < $today) {
    $selected_date = $today;
} else {
    $selected_date = $requested_date;
}

$selected_date_obj = new DateTime($selected_date);
// Filter reservations for selected date
$filtered_reservations = array_filter($reservations, function($reservation) use ($selected_date) {
    return date('Y-m-d', strtotime($reservation['start_time'])) === $selected_date;
});
// Generate time slots for the day (8 AM to 9:30 PM - closing at 10 PM)
$time_slots = [];
$start_hour = 8;
$end_hour = 21; // End at 9 PM to allow 30 min slots
for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
    $time_slots[] = sprintf('%02d:00', $hour);
    $time_slots[] = sprintf('%02d:30', $hour);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($facility['name']); ?> - <?php echo SITE_NAME; ?></title>
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
        .time-slot-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .time-slot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .time-slot-card:hover::before {
            left: 100%;
        }
        .time-slot-card:hover {
            transform: translateY(-4px);
        }
        
        /* Enhanced time slot animations */
        .time-slot-card.available {
            animation: pulse-available 2s infinite;
        }
        
        @keyframes pulse-available {
            0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
        }
        
        /* Enhanced button hover effects */
        .booking-button {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .booking-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }
        
        .booking-button:hover::before {
            width: 300px;
            height: 300px;
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
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
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
        .quick-date-nav {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 transparent;
        }
        .quick-date-nav::-webkit-scrollbar {
            height: 4px;
        }
        .quick-date-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .quick-date-nav::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 2px;
        }
        .quick-date-nav::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
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
                <li>
                    <a href="facilities.php" class="hover:text-blue-600 transition-colors duration-200">
                        Facilities
                    </a>
                </li>
                <li>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </li>
                <li class="text-blue-600 font-medium">
                    <?php echo htmlspecialchars($facility['name']); ?>
                </li>
            </ol>
        </nav>
        
        <!-- Operating Hours Info Banner -->
        <div class="mb-6 animate-fade-in">
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-blue-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-blue-800">Operating Hours</h3>
                            <p class="text-xs text-blue-600">8:00 AM - 10:00 PM • Last booking ends at 9:30 PM</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-blue-500">
                            <span class="font-medium">Today:</span><br>
                            <?php echo $selected_date_obj->format('l, F j'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Facility Details Card -->
        <div class="enhanced-card card-hover p-8 mb-8 animate-slide-up">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Facility Image Section -->
                <div class="lg:col-span-2">
                    <?php if (!empty($facility['image_url']) && file_exists($facility['image_url'])): ?>
                        <div class="image-gallery">
                            <img src="<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                 class="w-full h-80 lg:h-96 object-cover">
                            <div class="image-overlay"></div>
                            <div class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                <button onclick="viewFullImage('<?php echo htmlspecialchars($facility['image_url']); ?>', '<?php echo htmlspecialchars($facility['name']); ?>')" 
                                        class="bg-white/90 hover:bg-white text-gray-800 px-4 py-2 rounded-lg shadow-lg transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-expand-arrows-alt mr-2"></i>View Full
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-gray-400 to-gray-600 h-80 lg:h-96 flex items-center justify-center">
                            <div class="text-center text-white">
                                <i class="fas fa-image text-6xl lg:text-8xl mb-4 opacity-50"></i>
                                <p class="text-xl font-medium">No Image Available</p>
                                <p class="text-sm opacity-75 mt-2">Contact admin to add facility images</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Facility Info Section -->
                <div class="space-y-6">
                    <div class="text-center lg:text-left">
                        <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-2">
                            <?php echo htmlspecialchars($facility['name']); ?>
                        </h1>
                        <div class="flex items-center justify-center lg:justify-start space-x-2 mb-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-tag mr-1"></i>
                                <?php echo htmlspecialchars($facility['category_name']); ?>
                            </span>
                            <?php if ($facility['is_currently_closed']): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-1"></i>
                                    Closed for Event
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Available
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Closure Notice -->
                        <?php if ($facility['is_currently_closed']): ?>
                            <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-1"></i>
                                    <div>
                                        <h4 class="text-lg font-semibold text-red-800 mb-2">Facility Closed for Event</h4>
                                        <p class="text-red-700 mb-2">
                                            <strong>Event:</strong> <?php echo htmlspecialchars($facility['closure_reason']); ?>
                                        </p>
                                        <?php if ($facility['closure_end_date']): ?>
                                            <p class="text-red-700">
                                                <strong>Reopens:</strong> <?php echo date('l, F j, Y', strtotime($facility['closure_end_date'])); ?>
                                            </p>
                                        <?php endif; ?>
                                        <p class="text-sm text-red-600 mt-2">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            This facility is temporarily unavailable for bookings due to a scheduled event.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Pricing Options & Selector -->
                    <div class="space-y-4">
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-4 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm opacity-90">Pricing Options</p>
                                    <?php if (!empty($pricing_options)): ?>
                                        <div class="space-y-1">
                                            <?php foreach (array_slice($pricing_options, 0, 3) as $option): ?>
                                                <p class="text-lg font-semibold"><?php echo htmlspecialchars($option['name']); ?>: ₱<?php echo number_format($option['price_per_unit'] ?: $option['price_per_hour'] ?: 0, 0); ?></p>
                                            <?php endforeach; ?>
                                            <?php if (count($pricing_options) > 3): ?>
                                                <p class="text-sm opacity-75">+<?php echo count($pricing_options) - 3; ?> more options</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-2xl font-bold">₱<?php echo number_format($facility['hourly_rate'], 2); ?></p>
                                    <?php endif; ?>
                                </div>
                                <i class="fas fa-tag text-3xl opacity-80"></i>
                            </div>
                        </div>

                        <?php if (!empty($pricing_options)): ?>
                        <div class="bg-white rounded-xl border border-purple-200 p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-semibold text-gray-800 flex items-center"><i class="fas fa-tags mr-2 text-purple-600"></i>Select a Pricing</h4>
                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-full font-semibold"><?php echo count($pricing_options); ?> pricings</span>
                            </div>
                            <div class="grid grid-cols-1 gap-2" id="pricingOptionsList">
                                <?php foreach ($pricing_options as $idx => $po): ?>
                                <label class="flex items-center justify-between p-3 rounded-lg border <?php echo $idx === 0 ? 'border-purple-400 bg-purple-50' : 'border-gray-200 hover:bg-gray-50'; ?> transition-colors">
                                    <div class="flex items-center">
                                        <input type="radio" name="selected_pricing_option" value="<?php echo $po['id']; ?>" <?php echo $idx === 0 ? 'checked' : ''; ?> class="mr-3">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($po['name']); ?></div>
                                            <?php if (!empty($po['description'])): ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($po['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-sm font-bold text-purple-700">₱<?php echo number_format($po['price_per_unit'] ?: $po['price_per_hour'] ?: 0, 2); ?></div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                           
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Enhanced Facility Details -->
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Capacity</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo $facility['capacity']; ?> people</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Operating Hours</p>
                                <p class="font-bold text-gray-900 text-lg">8:00 AM - 10:00 PM</p>
                                <p class="text-xs text-gray-500">Last booking ends at 9:30 PM</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg border border-purple-200">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar-check text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Booking Policy</p>
                                <p class="font-bold text-gray-900 text-lg">30 min minimum</p>
                                <p class="text-xs text-gray-500">Up to 12 hours max</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3 p-3 bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg border border-orange-200">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-info-circle text-orange-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 font-medium">Status</p>
                                <?php if ($facility['is_currently_closed']): ?>
                                    <p class="font-bold text-red-600 text-lg">Closed for Event</p>
                                    <p class="text-xs text-red-500">Temporarily unavailable</p>
                                <?php else: ?>
                                    <p class="font-bold text-gray-900 text-lg">Available for booking</p>
                                    <p class="text-xs text-gray-500">Real-time availability</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Description -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-900 mb-2">Description</h3>
                        <p class="text-gray-600 leading-relaxed">
                            <?php echo htmlspecialchars($facility['description']); ?>
                        </p>
                    </div>
                    <?php
                    // Calculate basic availability for current day check
                    $is_current_day = $selected_date === date('Y-m-d');
                    $is_fully_booked = false;
                    // Check if current day is fully booked by looking at filtered reservations
                    if ($is_current_day) {
                        $total_booked_hours_today = 0;
                        foreach ($filtered_reservations as $reservation) {
                            $start_time = strtotime($reservation['start_time']);
                            $end_time = strtotime($reservation['end_time']);
                            $total_booked_hours_today += ($end_time - $start_time) / 3600;
                        }
                        // If booked hours >= 13.5 (8 AM to 9:30 PM), consider it fully booked
                        $is_fully_booked = $total_booked_hours_today >= 13.5;
                    }
                    $is_current_day_fully_booked = $is_current_day && $is_fully_booked;
                    ?>
                    <!-- Book Now Button -->
                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                    <div class="pt-4">
                        <?php if ($facility['is_currently_closed']): ?>
                            <div class="w-full bg-gradient-to-r from-red-400 to-red-500 text-white px-6 py-4 rounded-xl font-semibold inline-flex items-center justify-center shadow-lg cursor-not-allowed opacity-75">
                                <i class="fas fa-calendar-times mr-3 text-lg"></i>
                                Closed for Event
                            </div>
                            <div class="mt-3 text-center">
                                <p class="text-sm text-gray-600">
                                    <strong>Event:</strong> <?php echo htmlspecialchars($facility['closure_reason']); ?>
                                </p>
                                <?php if ($facility['closure_end_date']): ?>
                                    <p class="text-sm text-gray-600">
                                        <strong>Reopens:</strong> <?php echo date('l, F j, Y', strtotime($facility['closure_end_date'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($is_current_day_fully_booked): ?>
                            <div class="w-full bg-gradient-to-r from-gray-400 to-gray-500 text-white px-6 py-4 rounded-xl font-semibold inline-flex items-center justify-center shadow-lg cursor-not-allowed opacity-75">
                                <i class="fas fa-calendar-times mr-3 text-lg"></i>
                                Today is Fully Booked
                            </div>
                            <div class="mt-3 text-center">
                                <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium transition-colors duration-200">
                                    <i class="fas fa-calendar-alt mr-1"></i>Check Tomorrow's Availability
                                </a>
                            </div>
                        <?php else: ?>
                            <a id="primaryBookBtn" href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
                               class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-4 rounded-xl font-semibold transition-all duration-300 inline-flex items-center justify-center shadow-lg hover:shadow-xl transform hover:scale-105">
                                <i class="fas fa-calendar-plus mr-3 text-lg"></i>
                                Book This Facility
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Current Day Fully Booked Warning -->
        <?php if ($is_current_day_fully_booked): ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-red-100">
                <h3 class="text-lg font-semibold text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Today is Fully Booked
                </h3>
            </div>
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-calendar-times text-red-500 text-2xl"></i>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-gray-800 mb-2">No Available Slots Today</h4>
                            <p class="text-gray-600 mb-2">This facility is completely booked for today (<?php echo date('l, F j, Y'); ?>).</p>
                            <p class="text-sm text-gray-500">All time slots have been reserved by other users.</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                           class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium transition-colors duration-200 inline-flex items-center">
                            <i class="fas fa-calendar-alt mr-2"></i>Check Tomorrow
                        </a>
                    </div>
                </div>
                <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <h5 class="font-semibold text-blue-800 mb-2">Alternative Options:</h5>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Check availability for tomorrow or future dates</li>
                                <li>• Consider booking a different facility</li>
                                <li>• Contact the administrator for special arrangements</li>
                                <li>• Set up notifications for cancellations</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
       
        <!-- Enhanced Availability Summary -->
        <?php
        // Calculate available time ranges with detailed information
        $available_ranges = [];
        $current_start = null;
        $total_available_hours = 0;
        foreach ($time_slots as $time_slot) {
            $slot_start = $selected_date . ' ' . $time_slot . ':00';
            $slot_end = date('Y-m-d H:i:s', strtotime($slot_start . ' +30 minutes'));
            // Check if this slot is booked
            $is_booked = false;
            $conflicting_booking = null;
            foreach ($filtered_reservations as $reservation) {
                $reservation_start = $reservation['start_time'];
                $reservation_end = $reservation['end_time'];
                if (($slot_start >= $reservation_start && $slot_start < $reservation_end) ||
                    ($slot_end > $reservation_start && $slot_end <= $reservation_end) ||
                    ($slot_start <= $reservation_start && $slot_end >= $reservation_end)) {
                    $is_booked = true;
                    $conflicting_booking = $reservation;
                    break;
                }
            }
            if (!$is_booked) {
                if ($current_start === null) {
                    $current_start = $time_slot;
                }
            } else {
                if ($current_start !== null) {
                    $range_start_time = strtotime($current_start);
                    $range_end_time = strtotime($time_slot);
                    $range_hours = ($range_end_time - $range_start_time) / 3600;
                    $total_available_hours += $range_hours;
                    $available_ranges[] = [
                        'start' => $current_start,
                        'end' => $time_slot,
                        'hours' => $range_hours,
                        'slots' => $range_hours * 2, // 30-minute slots
                        'max_booking_hours' => min($range_hours, 12) // Cap at 12 hours max
                    ];
                    $current_start = null;
                }
            }
        }
        // Add the last range if it ends at the last time slot
        if ($current_start !== null) {
            $range_start_time = strtotime($current_start);
            $range_end_time = strtotime('21:30'); // Last slot is 9:30 PM
            $range_hours = ($range_end_time - $range_start_time) / 3600;
            $total_available_hours += $range_hours;
            $available_ranges[] = [
                'start' => $current_start,
                'end' => '21:30', // Last slot is 9:30 PM
                'hours' => $range_hours,
                'slots' => $range_hours * 2, // 30-minute slots
                'max_booking_hours' => min($range_hours, 12) // Cap at 12 hours max
            ];
        }
        // Calculate booking statistics
        $total_booked_hours = 0;
        foreach ($filtered_reservations as $reservation) {
            $start_time = strtotime($reservation['start_time']);
            $end_time = strtotime($reservation['end_time']);
            $total_booked_hours += ($end_time - $start_time) / 3600;
        }
        $total_day_hours = 13.5; // 8 AM to 9:30 PM (13.5 hours)
        $utilization_percentage = round(($total_booked_hours / $total_day_hours) * 100, 1);
        ?>
        <?php if (!empty($available_ranges)): ?>
        
        <?php else: ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-red-50 to-red-100">
                <h3 class="text-lg font-semibold text-red-800">
                    <i class="fas fa-ban mr-2"></i>
                    No Available Time Slots
                </h3>
                <p class="text-sm text-red-600 mt-1">This facility is fully booked for <?php echo $selected_date_obj->format('l, F j, Y'); ?></p>
            </div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-calendar-times text-red-500 text-3xl"></i>
                </div>
                <h4 class="text-xl font-bold text-gray-800 mb-2">Fully Booked</h4>
                <p class="text-gray-600 mb-4">All time slots for this date have been reserved.</p>
                <a href="?facility_id=<?php echo $facility['id']; ?>&date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?>" 
                   class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200">
                    <i class="fas fa-calendar-alt mr-2"></i>Check Next Day
                </a>
            </div>
        </div>
        <?php endif; ?>
        <!-- Current Bookings Summary -->
        <?php if (!empty($filtered_reservations)): ?>
        <div class="enhanced-card mb-6 animate-slide-up">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-orange-50 to-orange-100">
                <h3 class="text-lg font-semibold text-orange-800">
                    <i class="fas fa-calendar-check mr-2"></i>
                    Current Bookings for <?php echo $selected_date_obj->format('l, F j, Y'); ?>
                </h3>
                <p class="text-sm text-orange-600 mt-1">These time slots are already reserved</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($filtered_reservations as $index => $reservation): ?>
                    <div class="bg-gradient-to-br from-orange-50 to-red-50 border border-orange-200 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-user text-orange-600 text-lg"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-bold text-orange-800">
                                        Booking <?php echo $index + 1; ?>
                                    </h4>
                                    <p class="text-sm text-orange-600"><?php echo htmlspecialchars($reservation['user_name']); ?></p>
                                </div>
                            </div>
                            <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full 
                                <?php echo $reservation['status'] === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst($reservation['status']); ?>
                            </span>
                        </div>
                        <div class="space-y-3 mb-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Time Range:</span>
                                <span class="font-semibold text-gray-800">
                                    <?php echo date('g:i A', strtotime($reservation['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($reservation['end_time'])); ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Duration:</span>
                                <span class="font-semibold text-gray-800">
                                    <?php 
                                    $start_time = strtotime($reservation['start_time']);
                                    $end_time = strtotime($reservation['end_time']);
                                    $duration_hours = ($end_time - $start_time) / 3600;
                                    echo number_format($duration_hours, 1) . ' hours';
                                    ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Purpose:</span>
                                <span class="font-semibold text-gray-800 text-xs"><?php echo htmlspecialchars(substr($reservation['purpose'], 0, 30)) . (strlen($reservation['purpose']) > 30 ? '...' : ''); ?></span>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="text-xs text-gray-600 mb-2">Booking Details:</div>
                            <div class="text-xs text-gray-700">
                                <div class="flex justify-between">
                                    <span>Booking Type:</span>
                                    <span class="font-medium"><?php echo ucfirst($reservation['booking_type'] ?? 'hourly'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Created:</span>
                                    <span class="font-medium"><?php echo date('M j, Y g:i A', strtotime($reservation['created_at'])); ?></span>
                                </div>
                                <?php if ($reservation['payment_status']): ?>
                                <div class="flex justify-between">
                                    <span>Payment:</span>
                                    <span class="font-medium <?php echo $reservation['payment_status'] === 'paid' ? 'text-green-600' : 'text-orange-600'; ?>">
                                        <?php echo ucfirst($reservation['payment_status']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Rating and Reviews Section -->
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-star text-yellow-500 mr-2"></i>
                    Ratings & Reviews
                </h3>
                <?php if ($facility['total_ratings'] > 0): ?>
                    <div class="text-right">
                        <div class="text-3xl font-bold text-gray-900"><?php echo number_format($facility['average_rating'], 1); ?></div>
                        <div class="flex items-center">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?php echo $i <= $facility['average_rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                            <?php endfor; ?>
                            <span class="ml-2 text-sm text-gray-600">(<?php echo $facility['total_ratings']; ?> reviews)</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Rating Form -->
            <?php if (!$user_rating): ?>
                <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h4 class="text-lg font-semibold text-gray-900 mb-4">Rate this facility</h4>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="submit_rating">
                        
                        <!-- Star Rating -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Your Rating</label>
                            <div class="flex items-center space-x-1" id="starRating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star-btn text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="<?php echo $i; ?>">
                                        <i class="fas fa-star"></i>
                                    </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="ratingInput" value="0" required>
                        </div>
                        
                        <!-- Review Title -->
                        <div class="mb-4">
                            <label for="review_title" class="block text-sm font-medium text-gray-700 mb-2">Review Title (Optional)</label>
                            <input type="text" id="review_title" name="review_title" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Summarize your experience">
                        </div>
                        
                        <!-- Review Text -->
                        <div class="mb-4">
                            <label for="review_text" class="block text-sm font-medium text-gray-700 mb-2">Your Review</label>
                            <textarea id="review_text" name="review_text" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Share your experience with this facility..."></textarea>
                        </div>
                        
                        <!-- Anonymous Option -->
                        <div class="mb-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="is_anonymous" class="mr-2">
                                <span class="text-sm text-gray-700">Post anonymously</span>
                            </label>
                        </div>
                        
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            Submit Review
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-blue-600 mr-2"></i>
                        <span class="text-blue-800 font-medium">You have already rated this facility</span>
                    </div>
                    <div class="mt-2 text-sm text-blue-700">
                        Your rating: 
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?php echo $i <= $user_rating['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                        <?php endfor; ?>
                        (<?php echo $user_rating['rating']; ?>/5)
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Reviews List -->
            <?php if (!empty($facility_ratings)): ?>
                <div class="space-y-6">
                    <?php foreach ($facility_ratings as $rating): ?>
                        <div class="bg-white rounded-xl border border-gray-200 p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                        <?php echo strtoupper(substr($rating['is_anonymous'] ? 'Anonymous' : $rating['full_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <h5 class="font-semibold text-gray-900">
                                            <?php echo $rating['is_anonymous'] ? 'Anonymous User' : htmlspecialchars($rating['full_name']); ?>
                                        </h5>
                                        <div class="flex items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star text-xs <?php echo $i <= $rating['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="ml-2 text-xs text-gray-500"><?php echo date('M j, Y', strtotime($rating['created_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button class="vote-btn text-gray-400 hover:text-green-500 transition-colors" data-rating-id="<?php echo $rating['id']; ?>" data-vote-type="upvote">
                                        <i class="fas fa-thumbs-up"></i>
                                        <span class="ml-1 text-sm"><?php echo $rating['upvotes'] ?: 0; ?></span>
                                    </button>
                                    <button class="vote-btn text-gray-400 hover:text-red-500 transition-colors" data-rating-id="<?php echo $rating['id']; ?>" data-vote-type="downvote">
                                        <i class="fas fa-thumbs-down"></i>
                                        <span class="ml-1 text-sm"><?php echo $rating['downvotes'] ?: 0; ?></span>
                                    </button>
                                </div>
                            </div>
                            
                            <?php if ($rating['review_title']): ?>
                                <h6 class="font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($rating['review_title']); ?></h6>
                            <?php endif; ?>
                            
                            <?php if ($rating['review_text']): ?>
                                <p class="text-gray-700 leading-relaxed mb-4"><?php echo nl2br(htmlspecialchars($rating['review_text'])); ?></p>
                            <?php endif; ?>
                            
                            <!-- Reply Form -->
                            <div class="mt-4">
                                <button class="reply-btn text-blue-600 hover:text-blue-700 text-sm font-medium" data-rating-id="<?php echo $rating['id']; ?>">
                                    <i class="fas fa-reply mr-1"></i>Reply
                                </button>
                                
                                <form class="reply-form hidden mt-3" data-rating-id="<?php echo $rating['id']; ?>">
                                    <input type="hidden" name="action" value="submit_reply">
                                    <input type="hidden" name="rating_id" value="<?php echo $rating['id']; ?>">
                                    <textarea name="reply_text" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Write a reply..."></textarea>
                                    <div class="mt-2 flex justify-end space-x-2">
                                        <button type="button" class="cancel-reply-btn text-gray-600 hover:text-gray-700 text-sm">Cancel</button>
                                        <button type="submit" class="bg-blue-600 text-white px-4 py-1 rounded text-sm hover:bg-blue-700">Reply</button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Replies -->
                            <?php if (!empty($rating_replies[$rating['id']])): ?>
                                <div class="mt-4 ml-6 space-y-3">
                                    <?php foreach ($rating_replies[$rating['id']] as $reply): ?>
                                        <div class="<?php echo $reply['is_facility_reply'] ? 'bg-blue-50 border-l-4 border-blue-400' : 'bg-gray-50'; ?> rounded-lg p-4">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex items-center">
                                                    <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-sm mr-2">
                                                        <?php echo strtoupper(substr($reply['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="font-medium text-gray-900 text-sm">
                                                            <?php echo htmlspecialchars($reply['full_name']); ?>
                                                            <?php if ($reply['is_facility_reply']): ?>
                                                                <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs ml-2">Staff</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <span class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($reply['created_at'])); ?></span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <button class="vote-btn text-gray-400 hover:text-green-500 transition-colors" data-reply-id="<?php echo $reply['id']; ?>" data-vote-type="upvote">
                                                        <i class="fas fa-thumbs-up text-xs"></i>
                                                        <span class="ml-1 text-xs"><?php echo $reply['upvotes'] ?: 0; ?></span>
                                                    </button>
                                                    <button class="vote-btn text-gray-400 hover:text-red-500 transition-colors" data-reply-id="<?php echo $reply['id']; ?>" data-vote-type="downvote">
                                                        <i class="fas fa-thumbs-down text-xs"></i>
                                                        <span class="ml-1 text-xs"><?php echo $reply['downvotes'] ?: 0; ?></span>
                                                    </button>
                                                </div>
                                            </div>
                                            <p class="text-gray-700 text-sm"><?php echo nl2br(htmlspecialchars($reply['reply_text'])); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-comments text-4xl mb-4"></i>
                    <p>No reviews yet. Be the first to share your experience!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Floating Action Button -->
    <?php if ($_SESSION['role'] !== 'admin' && !$facility['is_currently_closed']): ?>
    <div class="floating-action">
        <a href="reservation.php?facility_id=<?php echo $facility['id']; ?>" 
           class="w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:scale-110">
            <i class="fas fa-calendar-plus text-xl"></i>
        </a>
    </div>
    <?php endif; ?>
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
            document.querySelectorAll('.enhanced-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });

            // Pricing selection → wire to Book buttons
            const bookWithPackageBtn = document.getElementById('bookWithPackageBtn');
            const primaryBookBtn = document.getElementById('primaryBookBtn');
            const radios = document.querySelectorAll('input[name="selected_pricing_option"]');

            function getSelectedPricingId() {
                const checked = document.querySelector('input[name="selected_pricing_option"]:checked');
                return checked ? checked.value : null;
            }

            function appendPricingToHref(anchor) {
                if (!anchor) return;
                const poId = getSelectedPricingId();
                if (!poId) return;
                const url = new URL(anchor.href, window.location.origin);
                url.searchParams.set('pricing_option_id', poId);
                anchor.href = url.toString();
            }

            if (bookWithPackageBtn) {
                bookWithPackageBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const poId = getSelectedPricingId();
                    if (!poId) {
                        alert('Please select a pricing first.');
                        return;
                    }
                    const baseUrl = 'reservation.php?facility_id=<?php echo $facility['id']; ?>';
                    window.location.href = baseUrl + '&pricing_option_id=' + encodeURIComponent(poId);
                });
            }

            if (primaryBookBtn) {
                primaryBookBtn.addEventListener('click', function() {
                    appendPricingToHref(primaryBookBtn);
                });
            }

            // Also augment any quick booking links to carry pricing_option_id if selected
            const allBookingLinks = document.querySelectorAll('a[href^="reservation.php?facility_id="]');
            allBookingLinks.forEach(a => {
                a.addEventListener('click', function() { appendPricingToHref(a); });
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
        // Add smooth hover effects to time slot cards
        document.addEventListener('DOMContentLoaded', function() {
            const timeSlotCards = document.querySelectorAll('.time-slot-card');
            timeSlotCards.forEach(card => {
                // Add 'available' class to available slots for pulse animation
                if (card.querySelector('.bg-green-100')) {
                    card.classList.add('available');
                }
                
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px) scale(1.02)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
        
        // Rating System JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Star rating functionality
            const starButtons = document.querySelectorAll('.star-btn');
            const ratingInput = document.getElementById('ratingInput');
            
            if (starButtons.length > 0 && ratingInput) {
                starButtons.forEach((star, index) => {
                    star.addEventListener('click', function() {
                        const rating = parseInt(this.dataset.rating);
                        ratingInput.value = rating;
                        
                        // Update star display
                        starButtons.forEach((s, i) => {
                            if (i < rating) {
                                s.classList.remove('text-gray-300');
                                s.classList.add('text-yellow-400');
                            } else {
                                s.classList.remove('text-yellow-400');
                                s.classList.add('text-gray-300');
                            }
                        });
                    });
                    
                    star.addEventListener('mouseenter', function() {
                        const rating = parseInt(this.dataset.rating);
                        starButtons.forEach((s, i) => {
                            if (i < rating) {
                                s.classList.remove('text-gray-300');
                                s.classList.add('text-yellow-400');
                            } else {
                                s.classList.remove('text-yellow-400');
                                s.classList.add('text-gray-300');
                            }
                        });
                    });
                });
                
                // Reset stars on mouse leave
                document.getElementById('starRating').addEventListener('mouseleave', function() {
                    const currentRating = parseInt(ratingInput.value);
                    starButtons.forEach((s, i) => {
                        if (i < currentRating) {
                            s.classList.remove('text-gray-300');
                            s.classList.add('text-yellow-400');
                        } else {
                            s.classList.remove('text-yellow-400');
                            s.classList.add('text-gray-300');
                        }
                    });
                });
            }
            
            // Reply functionality
            const replyButtons = document.querySelectorAll('.reply-btn');
            const cancelButtons = document.querySelectorAll('.cancel-reply-btn');
            
            replyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const ratingId = this.dataset.ratingId;
                    const form = document.querySelector(`.reply-form[data-rating-id="${ratingId}"]`);
                    if (form) {
                        form.classList.remove('hidden');
                        this.style.display = 'none';
                    }
                });
            });
            
            cancelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const form = this.closest('.reply-form');
                    const ratingId = form.dataset.ratingId;
                    const replyBtn = document.querySelector(`.reply-btn[data-rating-id="${ratingId}"]`);
                    
                    form.classList.add('hidden');
                    if (replyBtn) replyBtn.style.display = 'inline-flex';
                    form.querySelector('textarea').value = '';
                });
            });
            
            // Vote functionality
            const voteButtons = document.querySelectorAll('.vote-btn');
            
            voteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const ratingId = this.dataset.ratingId;
                    const replyId = this.dataset.replyId;
                    const voteType = this.dataset.voteType;
                    
                    // Disable button temporarily to prevent double-clicks
                    this.disabled = true;
                    
                    // Send vote request
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'vote_feedback',
                            'rating_id': ratingId || '',
                            'reply_id': replyId || '',
                            'vote_type': voteType
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update vote count display
                            const countSpan = this.querySelector('span');
                            const currentCount = parseInt(countSpan.textContent) || 0;
                            countSpan.textContent = currentCount + 1;
                            
                            // Visual feedback
                            this.classList.add(voteType === 'upvote' ? 'text-green-500' : 'text-red-500');
                            setTimeout(() => {
                                this.classList.remove('text-green-500', 'text-red-500');
                                this.disabled = false;
                            }, 1000);
                        } else {
                            alert('Failed to vote. Please try again.');
                            this.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to vote. Please try again.');
                        this.disabled = false;
                    });
                });
            });
            
            // Form validation
            const ratingForm = document.querySelector('form[action=""]');
            if (ratingForm) {
                ratingForm.addEventListener('submit', function(e) {
                    const rating = parseInt(ratingInput.value);
                    if (rating < 1 || rating > 5) {
                        e.preventDefault();
                        alert('Please select a rating between 1 and 5 stars.');
                        return false;
                    }
                });
            }
            
            // Reply form submission
            const replyForms = document.querySelectorAll('.reply-form');
            replyForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const replyText = formData.get('reply_text').trim();
                    
                    if (!replyText) {
                        alert('Please enter a reply.');
                        return;
                    }
                    
                    // Submit reply
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (response.ok) {
                            location.reload(); // Reload to show new reply
                        } else {
                            alert('Failed to submit reply. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to submit reply. Please try again.');
                    });
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
