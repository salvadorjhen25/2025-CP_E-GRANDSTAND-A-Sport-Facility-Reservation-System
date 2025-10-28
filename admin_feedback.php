<?php
require_once 'config/database.php';
require_once 'auth/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pdo = getDBConnection();

// Initialize statistics array with defaults first (before any POST processing)
$stats = [
    'total_ratings' => 0,
    'average_rating' => 0,
    'positive_ratings' => 0,
    'negative_ratings' => 0
];

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'admin_reply') {
        $rating_id = (int)$_POST['rating_id'];
        $reply_text = trim($_POST['reply_text'] ?? '');
        
        if ($reply_text && $rating_id) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO feedback_replies (rating_id, user_id, reply_text, is_facility_reply)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([$rating_id, $_SESSION['user_id'], $reply_text]);
                
                $_SESSION['success_message'] = 'Admin reply submitted successfully!';
                header("Location: admin_feedback.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Failed to submit admin reply. Please try again.';
            }
        }
    }
    
    if ($_POST['action'] === 'delete_rating') {
        $rating_id = (int)$_POST['rating_id'];
        
        if ($rating_id) {
            try {
                // Delete associated votes first
                $stmt = $pdo->prepare("DELETE FROM feedback_votes WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Delete associated replies
                $stmt = $pdo->prepare("DELETE FROM feedback_replies WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Delete the rating
                $stmt = $pdo->prepare("DELETE FROM facility_ratings WHERE id = ?");
                $stmt->execute([$rating_id]);
                
                // Update facility rating summary
                $facility_id = $_POST['facility_id'] ?? null;
                if ($facility_id) {
                    updateFacilityRatingSummary($pdo, $facility_id);
                }
                
                $_SESSION['success_message'] = 'Rating deleted successfully!';
                header("Location: admin_feedback.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Failed to delete rating. Please try again.';
            }
        }
    }
    
    if ($_POST['action'] === 'delete_reply') {
        $reply_id = (int)$_POST['reply_id'];
        
        if ($reply_id) {
            try {
                // Delete associated votes first
                $stmt = $pdo->prepare("DELETE FROM feedback_votes WHERE reply_id = ?");
                $stmt->execute([$reply_id]);
                
                // Delete the reply
                $stmt = $pdo->prepare("DELETE FROM feedback_replies WHERE id = ?");
                $stmt->execute([$reply_id]);
                
                $_SESSION['success_message'] = 'Reply deleted successfully!';
                header("Location: admin_feedback.php");
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Failed to delete reply. Please try again.';
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
        $rating_stats = $stmt->fetch();
        
        $breakdown = [
            '5' => (int)($rating_stats['rating_5'] ?? 0),
            '4' => (int)($rating_stats['rating_4'] ?? 0),
            '3' => (int)($rating_stats['rating_3'] ?? 0),
            '2' => (int)($rating_stats['rating_2'] ?? 0),
            '1' => (int)($rating_stats['rating_1'] ?? 0)
        ];
        
        // Update facility table
        $stmt = $pdo->prepare("
            UPDATE facilities 
            SET average_rating = ?, total_ratings = ?, rating_breakdown = ?
            WHERE id = ?
        ");
        $stmt->execute([
            round($rating_stats['avg_rating'] ?? 0, 2),
            (int)($rating_stats['total_ratings'] ?? 0),
            json_encode($breakdown),
            $facility_id
        ]);
    } catch (PDOException $e) {
        error_log("Failed to update facility rating summary: " . $e->getMessage());
    }
}

// Get filter parameters
$category_filter = $_GET['category'] ?? '';
$facility_filter = $_GET['facility'] ?? '';

// Handle AJAX request for facilities
if (isset($_GET['get_facilities'])) {
    $category_id = $_GET['category_id'] ?? '';
    
    try {
        if (!empty($category_id)) {
            $facilities_stmt = $pdo->prepare("SELECT id, name FROM facilities WHERE category_id = ? ORDER BY name");
            $facilities_stmt->execute([$category_id]);
        } else {
            $facilities_stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
        }
        $facilities_data = $facilities_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['facilities' => $facilities_data]);
        exit();
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['facilities' => [], 'error' => $e->getMessage()]);
        exit();
    }
}

// Get categories for filter
try {
    // Try without is_active filter first to see if the column exists
    $categories_stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Get facilities for filter
try {
    if (!empty($category_filter)) {
        $facilities_stmt = $pdo->prepare("SELECT id, name FROM facilities WHERE category_id = ? ORDER BY name");
        $facilities_stmt->execute([$category_filter]);
        $facilities = $facilities_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $facilities_stmt = $pdo->query("SELECT id, name FROM facilities ORDER BY name");
        $facilities = $facilities_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching facilities: " . $e->getMessage());
    $facilities = [];
}

// Get all ratings with facility and user information
try {
    $where_clause = "1=1";
    $params = [];
    
    if (!empty($category_filter)) {
        $where_clause .= " AND f.category_id = ?";
        $params[] = $category_filter;
    }
    
    if (!empty($facility_filter)) {
        $where_clause .= " AND f.id = ?";
        $params[] = $facility_filter;
    }
    
    $stmt = $pdo->prepare("
        SELECT fr.*, f.name as facility_name, f.id as facility_id, f.category_id,
               c.name as category_name,
               u.full_name, u.email,
               COUNT(fr2.id) as reply_count,
               SUM(CASE WHEN fv.vote_type = 'upvote' THEN 1 ELSE 0 END) as upvotes,
               SUM(CASE WHEN fv.vote_type = 'downvote' THEN 1 ELSE 0 END) as downvotes
        FROM facility_ratings fr
        JOIN facilities f ON fr.facility_id = f.id
        LEFT JOIN categories c ON f.category_id = c.id
        JOIN users u ON fr.user_id = u.id
        LEFT JOIN feedback_replies fr2 ON fr.id = fr2.rating_id
        LEFT JOIN feedback_votes fv ON fr.id = fv.rating_id
        WHERE $where_clause
        GROUP BY fr.id
        ORDER BY fr.created_at DESC
    ");
    $stmt->execute($params);
    $all_ratings = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_ratings = [];
}

// Get replies for each rating
$rating_replies = [];
foreach ($all_ratings as $rating) {
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

// Get statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_ratings,
            COALESCE(AVG(rating), 0) as average_rating,
            SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive_ratings,
            SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative_ratings
        FROM facility_ratings
    ");
    $stats_stmt->execute();
    $result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $stats['total_ratings'] = (int)$result['total_ratings'];
        $stats['average_rating'] = (float)$result['average_rating'];
        $stats['positive_ratings'] = (int)($result['positive_ratings'] ?? 0);
        $stats['negative_ratings'] = (int)($result['negative_ratings'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    // Keep default values
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Feedback Management | <?php echo SITE_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include 'admin/includes/sidebar-styles.php'; ?>
    <style>
        .rating-card {
            transition: all 0.3s ease;
        }
        
        .rating-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .admin-reply {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-left: 4px solid #2196f3;
        }
        
        .user-reply {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php 
    // Get current page for active state
    $current_page = 'admin_feedback.php';
    ?>
    <?php include 'admin/includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="p-8">
            <!-- Fix navigation paths for sidebar included from root directory -->
            <script>
            // Since admin_feedback.php is in the root directory, but the sidebar links assume they're in the admin/ directory,
            // we need to prepend 'admin/' to the relative paths
            document.addEventListener('DOMContentLoaded', function() {
                // Fix Feedback & Reviews link (from ../admin_feedback.php to admin_feedback.php)
                const feedbackLink = document.querySelector('#sidebar .nav-item[href="../admin_feedback.php"]');
                if (feedbackLink) {
                    feedbackLink.setAttribute('href', 'admin_feedback.php');
                }
                
                // Fix brand/home link (from ../index.php to index.php)
                const brandLink = document.querySelector('#sidebar .sidebar-header a[href="../index.php"]');
                if (brandLink) {
                    brandLink.setAttribute('href', 'index.php');
                }
                
                // Fix logout link (from ../auth/logout.php to auth/logout.php)
                const logoutLink = document.querySelector('#sidebar .sidebar-footer a[href="../auth/logout.php"]');
                if (logoutLink) {
                    logoutLink.setAttribute('href', 'auth/logout.php');
                }
                
                // Fix all admin navigation links (dashboard, facilities, users, etc.)
                const sidebarLinks = document.querySelectorAll('#sidebar .nav-item');
                sidebarLinks.forEach(function(link) {
                    const href = link.getAttribute('href');
                    // Only fix relative admin links (not those starting with ../ or external URLs)
                    if (href && !href.startsWith('../') && !href.startsWith('http') && href !== 'admin_feedback.php' && !href.includes('admin/')) {
                        link.setAttribute('href', 'admin/' + href);
                    }
                });
            });
            </script>
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 mb-2">Feedback & Reviews Management</h1>
                        <p class="text-gray-600">Manage user feedback, reviews, and replies</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="text-right">
                            <div class="text-2xl font-bold text-blue-600"><?php echo isset($stats['total_ratings']) ? $stats['total_ratings'] : 0; ?></div>
                            <div class="text-sm text-gray-600">Total Reviews</div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-green-600"><?php echo number_format(isset($stats['average_rating']) ? $stats['average_rating'] : 0, 1); ?></div>
                            <div class="text-sm text-gray-600">Average Rating</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-star text-blue-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo isset($stats['total_ratings']) ? $stats['total_ratings'] : 0; ?></div>
                            <div class="text-sm text-gray-600">Total Reviews</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-thumbs-up text-green-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo isset($stats['positive_ratings']) ? $stats['positive_ratings'] : 0; ?></div>
                            <div class="text-sm text-gray-600">Positive (4-5★)</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-thumbs-down text-red-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo isset($stats['negative_ratings']) ? $stats['negative_ratings'] : 0; ?></div>
                            <div class="text-sm text-gray-600">Negative (1-2★)</div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center mr-4">
                            <i class="fas fa-chart-line text-yellow-600"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900"><?php echo number_format(isset($stats['average_rating']) ? $stats['average_rating'] : 0, 1); ?></div>
                            <div class="text-sm text-gray-600">Average Rating</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
                <!-- Debug info -->
                <div class="mb-4 text-xs text-gray-500">
                    Debug: Categories loaded: <?php echo count($categories); ?>, Facilities loaded: <?php echo count($facilities); ?>
                </div>
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <label for="category_filter" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tags mr-1"></i>Category
                        </label>
                        <select id="category_filter" name="category" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Categories</option>
                            <?php if (!empty($categories)): ?>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="flex-1 min-w-[200px]">
                        <label for="facility_filter" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-building mr-1"></i>Facility
                        </label>
                        <select id="facility_filter" name="facility" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Facilities</option>
                            <?php if (!empty($facilities)): ?>
                                <?php foreach ($facilities as $facility): ?>
                                    <option value="<?php echo $facility['id']; ?>" <?php echo $facility_filter == $facility['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($facility['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="flex gap-2">
                        <button onclick="applyFilters()" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-filter mr-1"></i>Apply Filters
                        </button>
                        <a href="admin_feedback.php" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300 transition-colors">
                            <i class="fas fa-times mr-1"></i>Clear
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($category_filter) || !empty($facility_filter)): ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="text-sm text-gray-600">Active filters:</span>
                        <?php if (!empty($category_filter)): ?>
                            <?php 
                            $cat_name = '';
                            foreach ($categories as $cat) {
                                if ($cat['id'] == $category_filter) {
                                    $cat_name = $cat['name'];
                                    break;
                                }
                            }
                            ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($cat_name); ?>
                                <a href="?category=&facility=<?php echo htmlspecialchars($facility_filter); ?>" class="ml-2 hover:text-blue-600">
                                    <i class="fas fa-times"></i>
                                </a>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($facility_filter)): ?>
                            <?php 
                            $fac_name = '';
                            foreach ($facilities as $fac) {
                                if ($fac['id'] == $facility_filter) {
                                    $fac_name = $fac['name'];
                                    break;
                                }
                            }
                            ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($fac_name); ?>
                                <a href="?category=<?php echo htmlspecialchars($category_filter); ?>&facility=" class="ml-2 hover:text-green-600">
                                    <i class="fas fa-times"></i>
                                </a>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Reviews List -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-xl font-semibold text-gray-900">All Reviews</h2>
                    <span class="text-sm text-gray-600">
                        <?php echo count($all_ratings); ?> review<?php echo count($all_ratings) !== 1 ? 's' : ''; ?>
                    </span>
                </div>
                
                <div class="p-6">
                    <?php if (!empty($all_ratings)): ?>
                        <div class="space-y-6">
                            <?php foreach ($all_ratings as $rating): ?>
                                <div class="rating-card bg-gray-50 rounded-lg p-6 border border-gray-200">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center">
                                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold mr-4">
                                                <?php echo strtoupper(substr($rating['is_anonymous'] ? 'Anonymous' : $rating['full_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <h5 class="font-semibold text-gray-900">
                                                    <?php echo $rating['is_anonymous'] ? 'Anonymous User' : htmlspecialchars($rating['full_name']); ?>
                                                </h5>
                                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($rating['email']); ?></p>
                                                <div class="flex items-center mt-1">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star text-xs <?php echo $i <= $rating['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                                    <?php endfor; ?>
                                                    <span class="ml-2 text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($rating['created_at'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <?php if (!empty($rating['category_name'])): ?>
                                                <span class="bg-purple-100 text-purple-800 px-2 py-1 rounded-full text-xs font-medium">
                                                    <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($rating['category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">
                                                <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($rating['facility_name']); ?>
                                            </span>
                                            <div class="flex items-center space-x-1">
                                                <button class="text-gray-400 hover:text-green-500 transition-colors">
                                                    <i class="fas fa-thumbs-up"></i>
                                                    <span class="ml-1 text-sm"><?php echo $rating['upvotes'] ?: 0; ?></span>
                                                </button>
                                                <button class="text-gray-400 hover:text-red-500 transition-colors">
                                                    <i class="fas fa-thumbs-down"></i>
                                                    <span class="ml-1 text-sm"><?php echo $rating['downvotes'] ?: 0; ?></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($rating['review_title']): ?>
                                        <h6 class="font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($rating['review_title']); ?></h6>
                                    <?php endif; ?>
                                    
                                    <?php if ($rating['review_text']): ?>
                                        <p class="text-gray-700 leading-relaxed mb-4"><?php echo nl2br(htmlspecialchars($rating['review_text'])); ?></p>
                                    <?php endif; ?>
                                    
                                    <!-- Admin Reply Form -->
                                    <div class="mt-4">
                                        <button class="admin-reply-btn text-blue-600 hover:text-blue-700 text-sm font-medium" data-rating-id="<?php echo $rating['id']; ?>">
                                            <i class="fas fa-reply mr-1"></i>Reply as Admin
                                        </button>
                                        
                                        <form class="admin-reply-form hidden mt-3" data-rating-id="<?php echo $rating['id']; ?>">
                                            <input type="hidden" name="action" value="admin_reply">
                                            <input type="hidden" name="rating_id" value="<?php echo $rating['id']; ?>">
                                            <textarea name="reply_text" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Write an admin reply..."></textarea>
                                            <div class="mt-2 flex justify-end space-x-2">
                                                <button type="button" class="cancel-admin-reply-btn text-gray-600 hover:text-gray-700 text-sm">Cancel</button>
                                                <button type="submit" class="bg-blue-600 text-white px-4 py-1 rounded text-sm hover:bg-blue-700">Reply as Admin</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Replies -->
                                    <?php if (!empty($rating_replies[$rating['id']])): ?>
                                        <div class="mt-4 space-y-3">
                                            <?php foreach ($rating_replies[$rating['id']] as $reply): ?>
                                                <div class="<?php echo $reply['is_facility_reply'] ? 'admin-reply' : 'user-reply'; ?> rounded-lg p-4">
                                                    <div class="flex items-start justify-between mb-2">
                                                        <div class="flex items-center">
                                                            <div class="w-8 h-8 <?php echo $reply['is_facility_reply'] ? 'bg-gradient-to-br from-blue-500 to-purple-600' : 'bg-gradient-to-br from-green-500 to-blue-600'; ?> rounded-full flex items-center justify-center text-white font-bold text-sm mr-2">
                                                                <?php echo strtoupper(substr($reply['full_name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <h6 class="font-medium text-gray-900 text-sm">
                                                                    <?php echo htmlspecialchars($reply['full_name']); ?>
                                                                    <?php if ($reply['is_facility_reply']): ?>
                                                                        <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs ml-2">Admin</span>
                                                                    <?php endif; ?>
                                                                </h6>
                                                                <span class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($reply['created_at'])); ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <button class="text-gray-400 hover:text-green-500 transition-colors">
                                                                <i class="fas fa-thumbs-up text-xs"></i>
                                                                <span class="ml-1 text-xs"><?php echo $reply['upvotes'] ?: 0; ?></span>
                                                            </button>
                                                            <button class="text-gray-400 hover:text-red-500 transition-colors">
                                                                <i class="fas fa-thumbs-down text-xs"></i>
                                                                <span class="ml-1 text-xs"><?php echo $reply['downvotes'] ?: 0; ?></span>
                                                            </button>
                                                            <?php if (!$reply['is_facility_reply']): ?>
                                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this reply?')">
                                                                    <input type="hidden" name="action" value="delete_reply">
                                                                    <input type="hidden" name="reply_id" value="<?php echo $reply['id']; ?>">
                                                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <p class="text-gray-700 text-sm"><?php echo nl2br(htmlspecialchars($reply['reply_text'])); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Admin Actions -->
                                    <div class="mt-4 pt-4 border-t border-gray-200 flex justify-end space-x-2">
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this review? This action cannot be undone.')">
                                            <input type="hidden" name="action" value="delete_rating">
                                            <input type="hidden" name="rating_id" value="<?php echo $rating['id']; ?>">
                                            <input type="hidden" name="facility_id" value="<?php echo $rating['facility_id']; ?>">
                                            <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700">
                                                <i class="fas fa-trash mr-1"></i>Delete Review
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-comments text-4xl mb-4"></i>
                            <p>No reviews found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'admin/includes/sidebar-script.php'; ?>
    <script>
        // Filter function
        function applyFilters() {
            const category = document.getElementById('category_filter').value;
            const facility = document.getElementById('facility_filter').value;
            
            let url = 'admin_feedback.php?';
            if (category) url += 'category=' + category + '&';
            if (facility) url += 'facility=' + facility;
            
            // Remove trailing & or ?
            url = url.replace(/[?&]$/, '');
            
            window.location.href = url;
        }
        
        // Auto-fill facility dropdown when category changes
        document.addEventListener('DOMContentLoaded', function() {
            const categoryFilter = document.getElementById('category_filter');
            const facilityFilter = document.getElementById('facility_filter');
            
            categoryFilter.addEventListener('change', function() {
                const categoryId = this.value;
                const facilityId = facilityFilter.value;
                
                // Disable facility filter while loading
                facilityFilter.disabled = true;
                facilityFilter.innerHTML = '<option value="">Loading...</option>';
                
                // Fetch facilities for selected category
                fetch(`admin_feedback.php?get_facilities=1&category_id=${categoryId}`)
                    .then(response => response.json())
                    .then(data => {
                        facilityFilter.innerHTML = '<option value="">All Facilities</option>';
                        
                        data.facilities.forEach(facility => {
                            const option = document.createElement('option');
                            option.value = facility.id;
                            option.textContent = facility.name;
                            // Keep selected facility if it's still in the list
                            if (facility.id == facilityId) {
                                option.selected = true;
                            }
                            facilityFilter.appendChild(option);
                        });
                        
                        facilityFilter.disabled = false;
                    })
                    .catch(error => {
                        console.error('Error fetching facilities:', error);
                        facilityFilter.innerHTML = '<option value="">Error loading facilities</option>';
                        facilityFilter.disabled = false;
                    });
            });
        });
        
        // Admin reply functionality
        document.addEventListener('DOMContentLoaded', function() {
            const adminReplyButtons = document.querySelectorAll('.admin-reply-btn');
            const cancelAdminReplyButtons = document.querySelectorAll('.cancel-admin-reply-btn');
            
            adminReplyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const ratingId = this.dataset.ratingId;
                    const form = document.querySelector(`.admin-reply-form[data-rating-id="${ratingId}"]`);
                    if (form) {
                        form.classList.remove('hidden');
                        this.style.display = 'none';
                    }
                });
            });
            
            cancelAdminReplyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const form = this.closest('.admin-reply-form');
                    const ratingId = form.dataset.ratingId;
                    const replyBtn = document.querySelector(`.admin-reply-btn[data-rating-id="${ratingId}"]`);
                    
                    form.classList.add('hidden');
                    if (replyBtn) replyBtn.style.display = 'inline-flex';
                    form.querySelector('textarea').value = '';
                });
            });
            
            // Admin reply form submission
            const adminReplyForms = document.querySelectorAll('.admin-reply-form');
            adminReplyForms.forEach(form => {
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
                            alert('Failed to submit admin reply. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to submit admin reply. Please try again.');
                    });
                });
            });
        });
    </script>
</body>
</html>
