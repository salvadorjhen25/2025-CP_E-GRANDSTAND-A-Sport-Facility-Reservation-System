<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth.php';
$auth = new Auth();
$auth->requireAdminOrStaff();
$pdo = getDBConnection();
// Ensure pricing tables exist (idempotent)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_pricing_options (
        id INT PRIMARY KEY AUTO_INCREMENT,
        facility_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        price_per_hour DECIMAL(10,2) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_pricing_selections (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reservation_id INT NOT NULL,
        pricing_option_id INT NOT NULL,
        quantity INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
        FOREIGN KEY (pricing_option_id) REFERENCES facility_pricing_options(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {
    // Silent fail: pricing is optional at runtime; UI will still work without tables
}
// Get statistics for notification badges
$stats = [];
// Total users (excluding admins)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
$result = $stmt->fetch();
    $stats['users'] = $result ? $result['count'] : 0;
// Total facilities
$stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities WHERE is_active = 1");
$result = $stmt->fetch();
    $stats['facilities'] = $result ? $result['count'] : 0;
// Total reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations");
$result = $stmt->fetch();
    $stats['reservations'] = $result ? $result['count'] : 0;
// Pending reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
$result = $stmt->fetch();
    $stats['pending'] = $result ? $result['count'] : 0;
// No-show reservations
$stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'no_show'");
$result = $stmt->fetch();
    $stats['no_shows'] = $result ? $result['count'] : 0;
// Active usage count for notifications
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usage_logs WHERE status = 'active'");
    $result = $stmt->fetch();
    $stats['active_usages'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['active_usages'] = 0;
}
// Pending payments count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'confirmed' AND payment_status = 'pending'");
    $result = $stmt->fetch();
    $stats['pending_payments'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['pending_payments'] = 0;
}
// New users count (last 7 days)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $result = $stmt->fetch();
    $stats['new_users'] = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $stats['new_users'] = 0;
}
// Handle facility operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_facility':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $hourly_rate = floatval($_POST['hourly_rate']);
                $capacity = !empty($_POST['capacity']) ? intval($_POST['capacity']) : null;
                $category_id = intval($_POST['category_id']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $pricing_options_json = $_POST['pricing_options_json'] ?? '';
                $pricing_options = [];
                if (!empty($pricing_options_json)) {
                    $decoded = json_decode($pricing_options_json, true);
                    if (is_array($decoded)) { $pricing_options = $decoded; }
                }
                if (empty($name) || empty($description) || $hourly_rate <= 0 || ($capacity !== null && $capacity <= 0)) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        $image_url = null;
                        // Handle image upload
                        if (isset($_FILES['facility_image']) && $_FILES['facility_image']['error'] === UPLOAD_ERR_OK) {
                            $upload_dir = '../uploads/facilities/';
                            $file_extension = strtolower(pathinfo($_FILES['facility_image']['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            if (in_array($file_extension, $allowed_extensions)) {
                                $filename = 'facility_' . time() . '_' . uniqid() . '.' . $file_extension;
                                $upload_path = $upload_dir . $filename;
                                if (move_uploaded_file($_FILES['facility_image']['tmp_name'], $upload_path)) {
                                    $image_url = 'uploads/facilities/' . $filename;
                                } else {
                                    $error_message = 'Failed to upload image. Please try again.';
                                    break;
                                }
                            } else {
                                $error_message = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                                break;
                            }
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO facilities (name, description, hourly_rate, capacity, category_id, image_url, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$name, $description, $hourly_rate, $capacity, $category_id, $image_url, $is_active]);
                        $new_facility_id = (int)$pdo->lastInsertId();
                        if (!empty($pricing_options) && $new_facility_id > 0) {
                            $insertPo = $pdo->prepare("INSERT INTO facility_pricing_options (facility_id, name, description, price_per_hour, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                            foreach ($pricing_options as $po) {
                                $po_name = isset($po['name']) ? trim($po['name']) : '';
                                $po_desc = isset($po['description']) ? trim($po['description']) : null;
                                $po_price = isset($po['price_per_hour']) ? floatval($po['price_per_hour']) : 0;
                                $po_order = isset($po['sort_order']) ? intval($po['sort_order']) : 0;
                                $po_active = !empty($po['is_active']) ? 1 : 0;
                                if (!empty($po_name) && $po_price > 0) {
                                    $insertPo->execute([$new_facility_id, $po_name, $po_desc, $po_price, $po_order, $po_active]);
                                }
                            }
                        }
                        $success_message = 'Facility added successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to add facility. Please try again.';
                    }
                }
                break;
            case 'update_facility':
                $facility_id = intval($_POST['facility_id']);
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $hourly_rate = floatval($_POST['hourly_rate']);
                $capacity = !empty($_POST['capacity']) ? intval($_POST['capacity']) : null;
                $category_id = intval($_POST['category_id']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                if (empty($name) || empty($description) || $hourly_rate <= 0 || ($capacity !== null && $capacity <= 0)) {
                    $error_message = 'Please fill in all required fields with valid values.';
                } else {
                    try {
                        // Get current image URL
                        $stmt = $pdo->prepare("SELECT image_url FROM facilities WHERE id = ?");
                        $stmt->execute([$facility_id]);
                        $current_facility = $stmt->fetch();
                        $image_url = $current_facility['image_url'];
                        // Handle image upload
                        if (isset($_FILES['facility_image']) && $_FILES['facility_image']['error'] === UPLOAD_ERR_OK) {
                            $upload_dir = '../uploads/facilities/';
                            $file_extension = strtolower(pathinfo($_FILES['facility_image']['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            if (in_array($file_extension, $allowed_extensions)) {
                                $filename = 'facility_' . time() . '_' . uniqid() . '.' . $file_extension;
                                $upload_path = $upload_dir . $filename;
                                if (move_uploaded_file($_FILES['facility_image']['tmp_name'], $upload_path)) {
                                    // Delete old image if exists
                                    if ($image_url && file_exists('../' . $image_url)) {
                                        unlink('../' . $image_url);
                                    }
                                    $image_url = 'uploads/facilities/' . $filename;
                                } else {
                                    $error_message = 'Failed to upload image. Please try again.';
                                    break;
                                }
                            } else {
                                $error_message = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                                break;
                            }
                        }
                        $stmt = $pdo->prepare("
                            UPDATE facilities 
                            SET name = ?, description = ?, hourly_rate = ?, capacity = ?, category_id = ?, image_url = ?, is_active = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $description, $hourly_rate, $capacity, $category_id, $image_url, $is_active, $facility_id]);
                        if (!empty($pricing_options)) {
                            $updatePo = $pdo->prepare("UPDATE facility_pricing_options SET name = ?, description = ?, price_per_hour = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ? AND facility_id = ?");
                            $insertPo = $pdo->prepare("INSERT INTO facility_pricing_options (facility_id, name, description, price_per_hour, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                            $providedIds = [];
                            foreach ($pricing_options as $po) {
                                $po_id = isset($po['id']) ? intval($po['id']) : 0;
                                $po_name = isset($po['name']) ? trim($po['name']) : '';
                                $po_desc = isset($po['description']) ? trim($po['description']) : null;
                                $po_price = isset($po['price_per_hour']) ? floatval($po['price_per_hour']) : 0;
                                $po_order = isset($po['sort_order']) ? intval($po['sort_order']) : 0;
                                $po_active = !empty($po['is_active']) ? 1 : 0;
                                if (empty($po_name) || $po_price <= 0) { continue; }
                                if ($po_id > 0) {
                                    $updatePo->execute([$po_name, $po_desc, $po_price, $po_order, $po_active, $po_id, $facility_id]);
                                    $providedIds[] = $po_id;
                                } else {
                                    $insertPo->execute([$facility_id, $po_name, $po_desc, $po_price, $po_order, $po_active]);
                                }
                            }
                            // Deactivate pricing options not provided in payload
                            try {
                                if (count($providedIds) > 0) {
                                    $placeholders = implode(',', array_fill(0, count($providedIds), '?'));
                                    $params = $providedIds;
                                    $params[] = $facility_id;
                                    $sqlDeactivate = "UPDATE facility_pricing_options SET is_active = 0, updated_at = NOW() WHERE id NOT IN ($placeholders) AND facility_id = ?";
                                    $pdo->prepare($sqlDeactivate)->execute($params);
                                } else {
                                    $pdo->prepare("UPDATE facility_pricing_options SET is_active = 0, updated_at = NOW() WHERE facility_id = ?")->execute([$facility_id]);
                                }
                            } catch (Exception $e) { /* ignore deactivation errors */ }
                        }
                        $success_message = 'Facility updated successfully!';
                    } catch (Exception $e) {
                        $error_message = 'Failed to update facility. Please try again.';
                    }
                }
                break;
            case 'delete_facility':
                $facility_id = intval($_POST['facility_id']);
                // First check if the facility exists
                $stmt = $pdo->prepare("SELECT id, name FROM facilities WHERE id = ?");
                $stmt->execute([$facility_id]);
                $facility_exists = $stmt->fetch();
                if (!$facility_exists) {
                    $error_message = "Facility with ID {$facility_id} not found.";
                    break;
                }
                try {
                    // Check if facility has any active reservations (not completed or cancelled)
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ? AND status NOT IN ('completed', 'cancelled')");
                    $stmt->execute([$facility_id]);
                    $active_reservation_count = $stmt->fetch()['count'];
                    // Check total reservations for display purposes
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ?");
                    $stmt->execute([$facility_id]);
                    $total_reservation_count = $stmt->fetch()['count'];
                    // Check for any other references to this facility
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM waitlist WHERE facility_id = ?");
                    $stmt->execute([$facility_id]);
                    $waitlist_count = $stmt->fetch()['count'];
                    if ($waitlist_count > 0) {
                        $error_message = "Cannot delete facility with existing waitlist entries. This facility has {$waitlist_count} waitlist entry(ies).";
                        break;
                    }
                    if ($active_reservation_count > 0) {
                        // Get more details about the active reservations
                        $stmt = $pdo->prepare("
                            SELECT r.id, u.full_name as user_name, f.name as facility_name, 
                                   DATE(r.start_time) as reservation_date, r.start_time, r.end_time, r.status
                            FROM reservations r 
                            JOIN users u ON r.user_id = u.id
                            JOIN facilities f ON r.facility_id = f.id
                            WHERE r.facility_id = ? AND r.status NOT IN ('completed', 'cancelled')
                            ORDER BY r.start_time DESC
                        ");
                        $stmt->execute([$facility_id]);
                        $active_reservations = $stmt->fetchAll();
                        $error_message = "Cannot delete facility with active reservations. This facility has {$active_reservation_count} active reservation(s).";
                        if ($active_reservation_count <= 5) {
                            $error_message .= " Please complete or cancel the following reservations first:";
                            foreach ($active_reservations as $reservation) {
                                $error_message .= "<br>• Reservation #{$reservation['id']} - {$reservation['user_name']} on {$reservation['reservation_date']} ({$reservation['start_time']} - {$reservation['end_time']}) - Status: " . ucfirst($reservation['status']);
                            }
                        } else {
                            $error_message .= " Please complete or cancel all active reservations before deleting this facility.";
                        }
                        if ($total_reservation_count > $active_reservation_count) {
                            $completed_count = $total_reservation_count - $active_reservation_count;
                            $error_message .= "<br><br><strong>Note:</strong> This facility also has {$completed_count} completed/cancelled reservation(s) that will be automatically removed when the facility is deleted.";
                        }
                    } else {
                        // Get facility image URL before deletion
                        $stmt = $pdo->prepare("SELECT image_url FROM facilities WHERE id = ?");
                        $stmt->execute([$facility_id]);
                        $facility = $stmt->fetch();
                        // Check if facility exists
                        if (!$facility) {
                            throw new Exception("Facility with ID {$facility_id} not found");
                        }
                        // Delete the facility
                        $stmt = $pdo->prepare("DELETE FROM facilities WHERE id = ?");
                        $result = $stmt->execute([$facility_id]);
                        if (!$result) {
                            throw new Exception("Database deletion failed");
                        }
                        $rowCount = $stmt->rowCount();
                        if ($rowCount === 0) {
                            throw new Exception("No facility was deleted. Facility ID {$facility_id} may not exist.");
                        }
                        // Delete the image file if it exists
                        if ($facility['image_url']) {
                            $imagePath = '../' . $facility['image_url'];
                            if (file_exists($imagePath)) {
                                if (!unlink($imagePath)) {
                                    // Silent fail for image deletion
                                }
                            }
                        }
                        $success_message = 'Facility deleted successfully!';
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to delete facility. Please try again.';
                }
                break;
            case 'toggle_status':
                $facility_id = intval($_POST['facility_id']);
                $new_status = intval($_POST['new_status']);
                // If trying to deactivate, check for active reservations
                if ($new_status == 0) {
                    try {
                        // Check if facility has any active reservations (not completed or cancelled)
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE facility_id = ? AND status NOT IN ('completed', 'cancelled')");
                        $stmt->execute([$facility_id]);
                        $active_reservation_count = $stmt->fetch()['count'];
                        if ($active_reservation_count > 0) {
                            // Get more details about the active reservations
                            $stmt = $pdo->prepare("
                                SELECT r.id, u.full_name as user_name, f.name as facility_name, 
                                       DATE(r.start_time) as reservation_date, r.start_time, r.end_time, r.status
                                FROM reservations r 
                                JOIN users u ON r.user_id = u.id
                                JOIN facilities f ON r.facility_id = f.id
                                WHERE r.facility_id = ? AND r.status NOT IN ('completed', 'cancelled')
                                ORDER BY r.start_time DESC
                            ");
                            $stmt->execute([$facility_id]);
                            $active_reservations = $stmt->fetchAll();
                            $error_message = "Cannot deactivate facility with active reservations. This facility has {$active_reservation_count} active reservation(s).";
                            if ($active_reservation_count <= 5) {
                                $error_message .= " Please complete or cancel the following reservations first:";
                                foreach ($active_reservations as $reservation) {
                                    $error_message .= "<br>• Reservation #{$reservation['id']} - {$reservation['user_name']} on {$reservation['reservation_date']} ({$reservation['start_time']} - {$reservation['end_time']}) - Status: " . ucfirst($reservation['status']);
                                }
                            } else {
                                $error_message .= " Please complete or cancel all active reservations before deactivating this facility.";
                            }
                            break;
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to check active reservations. Please try again.';
                        break;
                    }
                }
                try {
                    $stmt = $pdo->prepare("UPDATE facilities SET is_active = ?, updated_at = NOW() WHERE id = ?");
                    $result = $stmt->execute([$new_status, $facility_id]);
                    if ($result) {
                        $rowCount = $stmt->rowCount();
                        if ($rowCount > 0) {
                            $status_text = $new_status == 1 ? 'activated' : 'deactivated';
                            $success_message = "Facility {$status_text} successfully!";
                        } else {
                            $error_message = 'No changes were made. Facility may not exist.';
                        }
                    } else {
                        $error_message = 'Failed to update facility status. Please try again.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to update facility status. Please try again.';
                }
                break;
        }
    }
}
// Get all facilities with categories
$stmt = $pdo->query("
    SELECT f.*, c.name as category_name 
    FROM facilities f 
    LEFT JOIN categories c ON f.category_id = c.id 
    ORDER BY f.created_at DESC
");
$facilities = $stmt->fetchAll();
// Attach pricing options per facility for inline editing
foreach ($facilities as &$facItem) {
    try {
        $stmtPo = $pdo->prepare("SELECT id, name, description, price_per_hour, is_active, sort_order FROM facility_pricing_options WHERE facility_id = ? ORDER BY sort_order ASC, name ASC");
        $stmtPo->execute([$facItem['id']]);
        $facItem['pricing_options'] = $stmtPo->fetchAll();
    } catch (Exception $e) {
        $facItem['pricing_options'] = [];
    }
}
// Get categories for form
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Facilities - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com">
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
        });
        // Mobile table enhancements
        document.addEventListener("DOMContentLoaded", function() {
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
      <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-navigation-fix.css">
    <script src="../assets/js/modal-system.js">
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
        });
        // Mobile table enhancements
        document.addEventListener("DOMContentLoaded", function() {
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
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
                        'bounce-in': 'bounceIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s infinite',
                        'float': 'float 3s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
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
                        }
                    }
                }
            }
        }
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
        });
        // Mobile table enhancements
        document.addEventListener("DOMContentLoaded", function() {
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
    <style>
        /* Global Styles with Poppins Font */
        * {
            font-family: 'Poppins', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #F3E2D4 0%, #C5B0CD 100%);
            color: #17313E;
        }
        .facility-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .facility-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .modal {
            transition: all 0.3s ease;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .action-button {
            transition: all 0.2s ease;
        }
        .action-button:hover {
            transform: scale(1.05);
        }
        .upload-zone {
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            border-color: #3B82F6;
            background-color: #F0F9FF;
        }
        .upload-zone.dragover {
            border-color: #3B82F6;
            background-color: #E0F2FE;
            transform: scale(1.02);
        }
        /* Ensure modal is properly positioned and clickable */
        .modal {
            pointer-events: auto;
        }
        .modal.show {
            pointer-events: auto;
        }
        /* Ensure all interactive elements are clickable */
        button, a, input, select, textarea {
            pointer-events: auto !important;
        }
        .facility-card {
            pointer-events: auto !important;
        }
        .facility-card * {
            pointer-events: auto !important;
        }
        /* Enhanced Prettier Navbar Design (Copied from Dashboard) */
        .nav-container {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.95) 0%, rgba(197, 176, 205, 0.95) 50%, rgba(65, 94, 114, 0.1) 100%);
            border-bottom: 3px solid rgba(65, 94, 114, 0.3);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(23, 49, 62, 0.15);
            position: sticky;
            top: 0;
            z-index: 50;
            transition: all 0.3s ease;
            min-height: 80px;
        }
        .nav-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD, #415E72, #17313E, #415E72, #C5B0CD, #F3E2D4);
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        /* Enhanced Navigation Layout */
        .nav-links-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #415E72 rgba(243, 226, 212, 0.3);
            flex-wrap: wrap;
            min-height: 60px;
        }
        .nav-links-container::-webkit-scrollbar {
            height: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(90deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(90deg, #17313E, #415E72);
        }
        .nav-link {
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 16px;
            padding: 10px 16px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #17313E;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid transparent;
            backdrop-filter: blur(15px);
            font-family: "Poppins", sans-serif;
            font-size: 0.9rem;
            white-space: nowrap;
            min-width: fit-content;
            flex-shrink: 0;
        }
        .nav-link::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(197, 176, 205, 0.4), transparent);
            transition: left 0.6s ease;
        }
        .nav-link:hover::before {
            left: 100%;
        }
        .nav-link:hover {
            color: #415E72;
            background: rgba(197, 176, 205, 0.4);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .nav-link.active {
            color: white;
            background: linear-gradient(135deg, #415E72, #17313E);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.4);
            border-color: #415E72;
            transform: translateY(-2px);
        }
        .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: -3px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 4px;
            background: linear-gradient(90deg, #F3E2D4, #C5B0CD);
            border-radius: 2px;
            box-shadow: 0 2px 6px rgba(243, 226, 212, 0.5);
        }
        .nav-link i {
            font-size: 16px;
            transition: all 0.4s ease;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .nav-link:hover i {
            transform: scale(1.15) rotate(5deg);
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.2));
        }
        .nav-link.active i {
            transform: scale(1.1);
            filter: drop-shadow(0 3px 6px rgba(255,255,255,0.3));
        }
        .nav-link span {
            font-size: 0.85rem;
            font-weight: 500;
        }
        /* Enhanced Brand and Admin Badge */
        .nav-brand {
            font-family: "Poppins", sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
            background: linear-gradient(135deg, #415E72, #17313E, #C5B0CD);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 4px rgba(23, 49, 62, 0.1);
            transition: all 0.3s ease;
        }
        .nav-brand:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }
        .admin-badge {
            background: linear-gradient(135deg, #415E72, #17313E);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.3);
            font-family: "Poppins", sans-serif;
            border: 2px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .admin-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.4);
            border-color: rgba(243, 226, 212, 0.5);
        }
        .admin-badge i {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        /* Enhanced Mobile Navigation */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.4s ease;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(15px);
            border: 2px solid rgba(197, 176, 205, 0.3);
            box-shadow: 0 4px 15px rgba(23, 49, 62, 0.1);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.4);
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(23, 49, 62, 0.2);
            border-color: rgba(197, 176, 205, 0.6);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: linear-gradient(90deg, #17313E, #415E72);
            margin: 3px 0;
            transition: 0.4s;
            border-radius: 2px;
            box-shadow: 0 2px 4px rgba(23, 49, 62, 0.2);
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
            background: linear-gradient(90deg, #C5B0CD, #415E72);
        }
        /* Enhanced Mobile Layout */
        @media (max-width: 1024px) {
            .nav-links-container {
                gap: 6px;
                padding: 10px 0;
            }
            .nav-link {
                padding: 8px 12px;
                font-size: 0.8rem;
                gap: 6px;
            }
            .nav-link span {
                font-size: 0.8rem;
            }
            .nav-link i {
                font-size: 14px;
            }
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                display: none;
            }
            .nav-links-container.show {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: linear-gradient(180deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.95) 100%);
                border-top: 3px solid rgba(65, 94, 114, 0.3);
                box-shadow: 0 12px 35px rgba(23, 49, 62, 0.2);
                z-index: 40;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
                backdrop-filter: blur(20px);
                padding: 20px;
                gap: 12px;
            }
            .nav-links-container .nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 16px 20px;
                border-radius: 12px;
                margin-bottom: 8px;
                font-size: 1rem;
                gap: 12px;
            }
            .nav-links-container .nav-link:hover {
                transform: translateX(8px);
                border-left: 4px solid #415E72;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #F3E2D4;
            }
        }
        /* Enhanced Navbar animations on scroll */
        .nav-container.scrolled {
            background: linear-gradient(135deg, rgba(243, 226, 212, 0.98) 0%, rgba(197, 176, 205, 0.98) 100%);
            box-shadow: 0 12px 40px rgba(23, 49, 62, 0.2);
            border-bottom: 3px solid rgba(65, 94, 114, 0.4);
        }
        /* Enhanced scrollbar for mobile menu */
        .nav-links-container::-webkit-scrollbar {
            width: 6px;
        }
        .nav-links-container::-webkit-scrollbar-track {
            background: rgba(243, 226, 212, 0.3);
        }
        .nav-links-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #415E72, #17313E);
            border-radius: 3px;
        }
        .nav-links-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #17313E, #415E72);
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        /* Enhanced Mobile Navigation Styles */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }
        .mobile-menu-toggle:hover {
            background: rgba(197, 176, 205, 0.3);
            transform: scale(1.05);
        }
        .mobile-menu-toggle span {
            width: 28px;
            height: 3px;
            background: #17313E;
            margin: 3px 0;
            transition: 0.3s;
            border-radius: 2px;
        }
        .mobile-menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-6px, 7px);
        }
        .mobile-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .mobile-menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-6px, -7px);
        }
        .nav-links-container {
            transition: all 0.3s ease;
        }
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            .nav-links-container {
                position: fixed;
                top: 80px;
                left: 0;
                right: 0;
                background: white;
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                z-index: 30;
                max-height: calc(100vh - 80px);
                overflow-y: auto;
            }
            .nav-links-container.show {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }
            .nav-links-container .nav-link {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-radius: 0;
                border-bottom: 1px solid #f3f4f6;
                margin: 0;
                width: 100%;
            }
            .nav-links-container .nav-link:hover {
                background: rgba(59, 130, 246, 0.05);
                transform: none;
            }
            .nav-links-container .nav-link.active {
                background: linear-gradient(135deg, #3B82F6, #1E40AF);
                color: white;
                border-bottom: 1px solid #3B82F6;
            }
            .nav-links-container .nav-link.active::before {
                display: none;
            }
            .nav-links-container .nav-link span {
                font-size: 16px;
                font-weight: 500;
            }
            .nav-links-container .nav-link i {
                font-size: 18px;
            }
            .nav-divider {
                height: 1px;
                background: #e5e7eb;
                margin: 8px 20px;
            }
            .brand-text {
                display: none;
            }
            .brand-text-mobile {
                display: block;
            }
        }
        @media (min-width: 769px) {
            .brand-text {
                display: block;
            }
            .brand-text-mobile {
                display: none;
            }
        }
        /* Enhanced Mobile Responsiveness */
        @media (max-width: 640px) {
            /* Mobile-first grid adjustments */
            .grid {
                grid-template-columns: 1fr !important;
            }
            /* Mobile card adjustments */
            .stat-card, .facility-card, .category-card {
                margin-bottom: 1rem;
            }
            /* Mobile table responsiveness */
            .overflow-x-auto {
                font-size: 0.875rem;
            }
            /* Mobile form adjustments */
            .space-y-4 > * {
                margin-bottom: 1rem;
            }
            /* Mobile button adjustments */
            .btn, button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            /* Mobile modal adjustments */
            .modal-content {
                margin: 1rem;
                max-height: calc(100vh - 2rem);
            }
            /* Mobile navigation improvements */
            .nav-links-container {
                padding: 0;
            }
            .nav-links-container .nav-link {
                padding: 1rem 1.25rem;
                font-size: 1rem;
            }
            /* Mobile header adjustments */
            .text-3xl, .text-4xl {
                font-size: 1.5rem !important;
            }
            .text-2xl {
                font-size: 1.25rem !important;
            }
            /* Mobile spacing adjustments */
            .p-6, .p-8 {
                padding: 1rem !important;
            }
            .mb-8, .mb-6 {
                margin-bottom: 1rem !important;
            }
            /* Mobile filter adjustments */
            .filter-section {
                flex-direction: column;
            }
            .filter-section > * {
                margin-bottom: 0.5rem;
            }
            /* Mobile table cell adjustments */
            .px-6 {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            .py-4 {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
        }
        @media (max-width: 768px) {
            /* Tablet adjustments */
            .grid-cols-2 {
                grid-template-columns: repeat(2, 1fr);
            }
            .grid-cols-3, .grid-cols-4 {
                grid-template-columns: repeat(2, 1fr);
            }
            /* Tablet navigation */
            .nav-links-container {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .nav-links-container .nav-link {
                flex: 1 1 auto;
                min-width: 120px;
            }
        }
        @media (max-width: 1024px) {
            /* Small desktop adjustments */
            .max-w-7xl {
                max-width: 100%;
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            /* Touch device optimizations */
            .nav-link, .btn, button, a {
                min-height: 44px;
                min-width: 44px;
            }
            .nav-link {
                padding: 12px 16px;
            }
            /* Larger touch targets for mobile */
            .mobile-menu-toggle {
                min-height: 44px;
                min-width: 44px;
                padding: 12px;
            }
            /* Improved touch feedback */
            .nav-link:active, .btn:active, button:active {
                transform: scale(0.95);
            }
        }
        /* Accessibility improvements */
        .nav-link:focus, .btn:focus, button:focus {
            outline: 2px solid #3B82F6;
            outline-offset: 2px;
        }
        /* Loading states for mobile */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        /* Mobile-specific animations */
        @media (max-width: 768px) {
            .animate-slide-up {
                animation: slideUpMobile 0.3s ease-out;
            }
            @keyframes slideUpMobile {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        }
        /* Mobile Table Enhancements */
        @media (max-width: 768px) {
            /* Mobile table card layout */
            .mobile-table-card {
                display: block;
            }
            .mobile-table-card .table-row {
                display: block;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
                background: white;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            .mobile-table-card .table-row > * {
                display: block;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .mobile-table-card .table-row > *:last-child {
                border-bottom: none;
            }
            .mobile-table-card .table-header {
                display: none;
            }
            /* Mobile table labels */
            .mobile-table-card .table-row > *::before {
                content: attr(data-label) ": ";
                font-weight: 600;
                color: #6b7280;
                display: inline-block;
                min-width: 100px;
            }
            /* Hide regular table on mobile */
            .table-responsive {
                display: none;
            }
            .mobile-table-card {
                display: block;
            }
        }
        @media (min-width: 769px) {
            /* Show regular table on desktop */
            .table-responsive {
                display: block;
            }
            .mobile-table-card {
                display: none;
            }
        }
        /* Mobile Filter Enhancements */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-section > * {
                width: 100%;
            }
            .filter-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .filter-buttons > * {
                width: 100%;
            }
            /* Mobile date inputs */
            input[type="date"], input[type="datetime-local"] {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            /* Mobile select dropdowns */
            select {
                font-size: 16px;
                padding: 0.75rem;
            }
            /* Mobile search inputs */
            input[type="search"], input[type="text"] {
                font-size: 16px;
                padding: 0.75rem;
            }
        }
        /* Mobile Modal Enhancements */
        @media (max-width: 768px) {
            .modal-content {
                margin: 1rem;
                max-width: calc(100vw - 2rem);
                max-height: calc(100vh - 2rem);
                overflow-y: auto;
            }
            .modal-content .p-6 {
                padding: 1rem;
            }
            /* Mobile form in modals */
            .modal-content form {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }
            .modal-content form > * {
                width: 100%;
            }
            .modal-content .flex.space-x-3 {
                flex-direction: column;
                gap: 0.5rem;
            }
            .modal-content .flex.space-x-3 > * {
                width: 100%;
            }
        }
        /* Mobile Button Enhancements */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-buttons > * {
                width: 100%;
                justify-content: center;
            }
            /* Mobile card actions */
            .card-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .card-actions > * {
                width: 100%;
            }
        }
        /* Mobile Statistics Cards */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 1rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .stat-card .text-3xl {
                font-size: 1.5rem !important;
            }
            .stat-card .text-lg {
                font-size: 1rem !important;
            }
        }
        /* Mobile Navigation Final Touches */
        @media (max-width: 768px) {
            .nav-links-container .nav-link {
                border-radius: 0;
                margin: 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .nav-links-container .nav-link:last-child {
                border-bottom: none;
            }
            .nav-links-container .nav-link.active {
                border-left: 4px solid #3B82F6;
                background: linear-gradient(90deg, #3B82F6, #1E40AF);
            }
            /* Mobile brand text */
            .brand-text-mobile {
                font-size: 1rem;
            }
        }
        /* Enhanced Notification Badge Styles - Fixed Visibility */
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: pulse-notification 2s infinite;
            z-index: 9999;
            transition: all 0.3s ease;
            pointer-events: none;
        }
        .notification-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
        }
        .notification-badge.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .notification-badge.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }
        @keyframes pulse-notification {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            }
            50% {
                transform: scale(1.15);
                box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
            }
        }
        .nav-link {
            position: relative;
            overflow: visible !important;
        }
        .nav-links-container {
            position: relative;
            z-index: 1000;
        }
        .nav-link .notification-badge {
            animation: pulse-notification 2s infinite;
            z-index: 9999;
        }
        .nav-link:hover .notification-badge {
            animation: none;
            transform: scale(1.2);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.6);
        }
        .nav-link:hover .notification-badge.warning {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.6);
        }
        .nav-link:hover .notification-badge.info {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.6);
        }
        .nav-link:hover .notification-badge.success {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.6);
        }
        /* Ensure nav-container doesn't clip badges */
        .nav-container {
            overflow: visible !important;
        }
        /* Mobile responsive notification badge */
        @media (max-width: 768px) {
            .notification-badge {
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
                top: -5px;
                right: -5px;
                border-width: 2px;
            }
        }
        /* Enhanced nav-link hover effects */
        .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 49, 62, 0.15);
        }
        .nav-link.active {
            box-shadow: 0 4px 16px rgba(65, 94, 114, 0.3);
        }
        .notification-badge {
            /* Force visibility */
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-green-50 via-white to-blue-50 min-h-screen">
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'includes/sidebar-styles.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 sm:py-8">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="text-center mb-6">
                <h1 class="text-4xl font-bold text-gray-900 mb-2 flex items-center justify-center">
                    <i class="fas fa-building text-primary mr-3"></i>Manage Facilities
                </h1>
                <p class="text-gray-600 text-lg">Add, edit, and manage facility information with ease</p>
            </div>
        </div>
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-green-100 text-sm font-medium">Total Facilities</p>
                        <p class="text-3xl font-bold"><?php echo count($facilities); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-building text-2xl"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-gradient-to-br from-[#415E72] to-[#17313E] text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm font-medium">Active Facilities</p>
                        <p class="text-3xl font-bold"><?php echo count(array_filter($facilities, function($f) { return $f['is_active']; })); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-gradient-to-br from-orange-500 to-orange-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-orange-100 text-sm font-medium">Categories</p>
                        <p class="text-3xl font-bold"><?php echo count($categories); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tags text-2xl"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-6 shadow-lg animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-purple-100 text-sm font-medium">Avg. Hourly Rate</p>
                        <p class="text-3xl font-bold">₱<?php echo number_format(array_sum(array_column($facilities, 'hourly_rate')) / max(count($facilities), 1), 2); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add Facility Button -->
        <div class="text-center mb-8">
            <button onclick="openAddModal()" class="bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg text-lg" style="pointer-events: auto;">
                <i class="fas fa-plus mr-3"></i>Add New Facility
            </button>
        </div>
        <!-- Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-gradient-to-r from-green-100 to-green-200 border border-green-300 text-green-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="bg-gradient-to-r from-red-100 to-red-200 border border-red-300 text-red-800 px-6 py-4 rounded-xl mb-6 animate-bounce-in shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl mr-3"></i>
                    <span class="font-medium"><?php echo $error_message; ?></span>
                </div>
            </div>
        <?php endif; ?>
        <!-- Facilities Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($facilities as $index => $facility): ?>
                <div class="facility-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 animate-slide-up" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                    <!-- Header -->
                    <div class="h-40 bg-gradient-to-br from-green-400 to-blue-500 flex items-center justify-center relative overflow-hidden">
                        <?php if ($facility['image_url']): ?>
                            <img src="../<?php echo htmlspecialchars($facility['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($facility['name']); ?>" 
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="absolute inset-0 bg-black bg-opacity-20"></div>
                            <div class="relative z-10 text-center">
                                <i class="fas fa-building text-white text-5xl mb-2"></i>
                                <p class="text-white text-sm font-medium">No Image</p>
                            </div>
                        <?php endif; ?>
                        <div class="absolute top-3 right-3">
                            <span class="status-badge inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?php echo $facility['is_active'] ? 'bg-green-400 text-green-900' : 'bg-red-400 text-red-900'; ?>">
                                <i class="<?php echo $facility['is_active'] ? 'fas fa-check' : 'fas fa-times'; ?> mr-1"></i>
                                <?php echo $facility['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="absolute bottom-3 left-3">
                            <span class="bg-black/50 text-white px-2 py-1 rounded-lg text-xs font-medium">
                                <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($facility['category_name']); ?>
                            </span>
                        </div>
                    </div>
                    <!-- Content -->
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($facility['name']); ?></h3>
                            <div class="text-right">
                                <div class="bg-gradient-to-r from-[#415E72] to-[#17313E] text-white px-3 py-1 rounded-full text-xs font-bold mb-1">
                                    ₱<?php echo number_format($facility['hourly_rate'], 2); ?>/hr
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4 text-sm leading-relaxed"><?php echo htmlspecialchars($facility['description']); ?></p>
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-6 p-3 bg-gray-50 rounded-xl">
                            <span class="flex items-center">
                                <i class="fas fa-users text-blue-500 mr-2"></i>
                                <span class="font-semibold"><?php echo $facility['capacity'] ? $facility['capacity'] . ' people' : 'No limit'; ?></span>
                            </span>
                            <span class="flex items-center">
                                <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                                <span class="font-semibold">Available</span>
                            </span>
                        </div>
                        <!-- Actions -->
                        <div class="space-y-2">
                            <div class="grid grid-cols-3 gap-2">
                                <button onclick='openEditModal(<?php echo json_encode($facility, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'
                                        class="action-button bg-gradient-to-r from-[#415E72] to-[#17313E] hover:from-blue-600 hover:to-blue-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </button>
                                <button onclick="window.location.href='pricing_options.php?facility_id=<?php echo $facility['id']; ?>'" 
                                        class="action-button bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                    <i class="fas fa-tag mr-1"></i>Pricing
                                </button>
                                <button type="button" onclick="toggleStatus(<?php echo $facility['id']; ?>, <?php echo $facility['is_active'] ? 0 : 1; ?>)" 
                                        class="action-button <?php echo $facility['is_active'] ? 'bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700' : 'bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700'; ?> text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105" style="pointer-events: auto;">
                                    <i class="<?php echo $facility['is_active'] ? 'fas fa-pause' : 'fas fa-play'; ?> mr-1"></i>
                                    <?php echo $facility['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </div>
                            <button onclick="deleteFacility(<?php echo $facility['id']; ?>)" 
                                    class="action-button w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white py-2 px-3 rounded-lg text-sm font-semibold transition duration-200 transform hover:scale-105">
                                <i class="fas fa-trash mr-1"></i>Delete Facility
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Empty State -->
        <?php if (empty($facilities)): ?>
            <div class="text-center py-16 animate-fade-in">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-building text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-2xl font-semibold text-gray-600 mb-2">No facilities found</h3>
                <p class="text-gray-500 mb-6">Get started by adding your first facility to the system.</p>
                <button onclick="openAddModal()" class="bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg text-lg">
                    <i class="fas fa-plus mr-3"></i>Add First Facility
                </button>
            </div>
        <?php endif; ?>
    </div>
    <!-- Hidden form for status toggle -->
    <form id="toggleStatusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="facility_id" id="toggle_facility_id">
        <input type="hidden" name="new_status" id="toggle_new_status">
    </form>
    <!-- Add/Edit Facility Modal -->
    <div id="facilityModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-7xl w-full mx-2 lg:mx-6" style="max-height: 85vh; overflow-y: auto;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-8">
                    <h2 id="modalTitle" class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-building text-primary mr-3"></i>Add New Facility
                    </h2>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                <form id="facilityForm" method="POST" class="space-y-6" enctype="multipart/form-data">
                    <input type="hidden" id="action" name="action" value="add_facility">
                    <input type="hidden" id="facility_id" name="facility_id">
                    <input type="hidden" id="pricing_options_json" name="pricing_options_json">
                    
                    <!-- Basic Information Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">🏢 Facility Name *</label>
                            <input type="text" id="name" name="name" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        </div>
                        <div>
                            <label for="category_id" class="block text-sm font-semibold text-gray-700 mb-2">🏷️ Category *</label>
                            <select id="category_id" name="category_id" required 
                                    class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">📝 Description *</label>
                        <textarea id="description" name="description" rows="3" required
                                  class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white"></textarea>
                    </div>
                    
                    <!-- Pricing and Capacity Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="hourly_rate" class="block text-sm font-semibold text-gray-700 mb-2">⏰ Hourly Rate (₱) *</label>
                            <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" required 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white">
                        </div>
                        <div>
                            <label for="capacity" class="block text-sm font-semibold text-gray-700 mb-2">👥 Capacity (Optional)</label>
                            <input type="number" id="capacity" name="capacity" min="1" 
                                   class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-200 bg-white"
                                   placeholder="Leave empty if no capacity limit">
                        </div>
                    </div>
                    <!-- Image Upload and Status Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <label for="facility_image" class="block text-sm font-semibold text-gray-700 mb-2">📸 Facility Image</label>
                            <div class="upload-zone mt-1 flex justify-center px-6 pt-6 pb-6 border-2 border-gray-200 border-dashed rounded-xl hover:border-primary transition duration-200 bg-gray-50">
                                <div class="space-y-3 text-center">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <i class="fas fa-cloud-upload-alt text-blue-500 text-xl"></i>
                                    </div>
                                    <div class="flex text-sm text-gray-600 justify-center">
                                        <label for="facility_image" class="relative cursor-pointer bg-white rounded-lg font-semibold text-primary hover:text-secondary focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-primary px-3 py-2 border border-primary hover:bg-primary hover:text-white transition duration-200">
                                            <span>📁 Upload</span>
                                            <input id="facility_image" name="facility_image" type="file" class="sr-only" accept="image/*">
                                        </label>
                                        <p class="pl-2 py-2">or drag & drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">PNG, JPG, GIF, WebP up to 10MB</p>
                                </div>
                            </div>
                            <div id="imagePreview" class="mt-4 hidden">
                                <div class="relative">
                                    <img id="previewImg" src="" alt="Preview" class="w-full h-32 object-cover rounded-xl shadow-lg">
                                    <button type="button" onclick="removeImage()" class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition duration-200">
                                        <i class="fas fa-times text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-center">
                            <div class="flex items-center p-6 bg-blue-50 rounded-xl border border-blue-200 w-full">
                                <input type="checkbox" id="is_active" name="is_active" class="h-6 w-6 text-primary focus:ring-primary border-gray-300 rounded">
                                <label for="is_active" class="ml-4 block text-sm font-semibold text-gray-700">
                                    <i class="fas fa-check-circle text-green-500 mr-2"></i>Active (available for booking)
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Options Section -->
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <label class="block text-sm font-semibold text-gray-700">💲 Pricing Options</label>
                            <button type="button" onclick="addPricingRow()" class="bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-sm font-semibold">Add Option</button>
                        </div>
                        <div id="pricingOptionsContainer" class="space-y-2"></div>
                        <p class="text-xs text-gray-500 mt-2">Add different packages like Lights On/Off, With Aircon, etc.</p>
                    </div>
                    <div class="flex space-x-4 pt-6">
                        <button type="submit" id="submitBtn" 
                                class="flex-1 bg-gradient-to-r from-green-500 to-blue-600 hover:from-green-600 hover:to-blue-700 text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-save mr-2"></i>Save Facility
                        </button>
                        <button type="button" onclick="closeModal()" 
                                class="flex-1 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white py-3 px-6 rounded-xl font-semibold transition duration-200 transform hover:scale-105 shadow-lg">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-500 mr-3"></i>Delete Facility
                    </h2>
                    <button onclick="closeDeleteConfirmModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                <div class="mb-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-building text-red-500 text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-gray-700 text-center mb-2 text-lg">Are you sure you want to delete this facility?</p>
                    <p class="text-sm text-gray-500 text-center mb-4">This action cannot be undone and will permanently remove the facility from the system.</p>
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-red-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-red-700 mb-1">Important Notes:</p>
                                <ul class="text-sm text-red-600 space-y-1">
                                    <li>• All facility images will be permanently deleted</li>
                                    <li>• Facility information will be completely removed</li>
                                    <li>• This action cannot be reversed</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-yellow-700 mb-1">Restriction:</p>
                                <p class="text-sm text-yellow-600">Facilities with existing reservations cannot be deleted. You must cancel or complete all reservations first.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <form id="deleteConfirmForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="delete_facility">
                    <input type="hidden" id="delete_facility_id" name="facility_id">
                    <div class="flex space-x-4">
                        <button type="button" onclick="closeDeleteConfirmModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 bg-gradient-to-r from-[#C5B0CD] to-[#415E72] hover:from-[#415E72] hover:to-[#C5B0CD] text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-trash mr-2"></i>Delete Facility
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Deactivate Warning Modal -->
    <div id="deactivateWarningModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center opacity-0 invisible">
        <div class="modal-content bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-3"></i>Deactivate Facility
                    </h2>
                    <button onclick="closeDeactivateWarningModal()" class="text-gray-400 hover:text-gray-600 transition duration-200">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                <div class="mb-6">
                    <div class="flex items-center justify-center mb-4">
                        <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-pause text-yellow-500 text-2xl"></i>
                        </div>
                    </div>
                    <p class="text-gray-700 text-center mb-2 text-lg">Are you sure you want to deactivate this facility?</p>
                    <p class="text-sm text-gray-500 text-center mb-4">This will make the facility unavailable for new reservations.</p>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-yellow-700 mb-1">What happens when deactivated:</p>
                                <ul class="text-sm text-yellow-600 space-y-1">
                                    <li>• Facility will not appear in booking options</li>
                                    <li>• No new reservations can be made</li>
                                    <li>• Existing reservations remain unaffected</li>
                                    <li>• Can be reactivated at any time</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                        <div class="flex items-start">
                            <i class="fas fa-shield-alt text-blue-500 mt-1 mr-3"></i>
                            <div>
                                <p class="text-sm font-semibold text-blue-700 mb-1">Safety Check:</p>
                                <p class="text-sm text-blue-600">The system will automatically check for active reservations and prevent deactivation if any exist.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <form id="deactivateWarningForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" id="deactivate_facility_id" name="facility_id">
                    <input type="hidden" id="deactivate_new_status" name="new_status" value="0">
                    <div class="flex space-x-4">
                        <button type="button" onclick="closeDeactivateWarningModal()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-white py-3 px-4 rounded-xl font-semibold transition duration-200 transform hover:scale-105">
                            <i class="fas fa-pause mr-2"></i>Deactivate Facility
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function openAddModal() {
            const modal = document.getElementById('facilityModal');
            document.getElementById('modalTitle').textContent = 'Add New Facility';
            document.getElementById('action').value = 'add_facility';
            document.getElementById('facilityForm').reset();
            document.getElementById('facility_id').value = '';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Save Facility';
            document.getElementById('imagePreview').classList.add('hidden');
            // reset pricing options
            pricingOptions = [];
            renderPricingOptions();
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        function openEditModal(facility) {
            const modal = document.getElementById('facilityModal');
            document.getElementById('modalTitle').textContent = 'Edit Facility';
            document.getElementById('action').value = 'update_facility';
            document.getElementById('facility_id').value = facility.id;
            document.getElementById('name').value = facility.name;
            document.getElementById('description').value = facility.description;
            document.getElementById('hourly_rate').value = facility.hourly_rate;
            document.getElementById('capacity').value = facility.capacity || '';
            document.getElementById('category_id').value = facility.category_id;
            document.getElementById('is_active').checked = facility.is_active == 1;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>Update Facility';
            // populate pricing options
            pricingOptions = Array.isArray(facility.pricing_options) ? facility.pricing_options.map(function(po){
                return {
                    id: po.id || null,
                    name: po.name || '',
                    description: po.description || '',
                    price_per_hour: po.price_per_hour || 0,
                    sort_order: po.sort_order || 0,
                    is_active: (po.is_active == 1)
                };
            }) : [];
            renderPricingOptions();
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        function closeModal() {
            const modal = document.getElementById('facilityModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        function toggleStatus(facilityId, newStatus) {
            if (newStatus == 0) {
                // Deactivating - show warning modal
                showDeactivateWarningModal(facilityId);
            } else {
                // Activating - simple confirmation
                const confirmed = confirm('Are you sure you want to activate this facility?');
                if (confirmed) {
                    submitToggleStatus(facilityId, newStatus);
                }
            }
        }
        function showDeactivateWarningModal(facilityId) {
            // First check if there are active reservations
            checkActiveReservations(facilityId).then(hasActiveReservations => {
                if (hasActiveReservations) {
                    alert('Cannot deactivate facility with active reservations. Please complete or cancel all active reservations first.');
                    return;
                }
                // No active reservations, show the warning modal
                document.getElementById('deactivate_facility_id').value = facilityId;
                const modal = document.getElementById('deactivateWarningModal');
                modal.classList.add('show');
                modal.style.pointerEvents = 'auto';
            }).catch(error => {
                console.error('Error checking active reservations:', error);
                // Fallback: show modal anyway, server-side validation will handle it
                document.getElementById('deactivate_facility_id').value = facilityId;
                const modal = document.getElementById('deactivateWarningModal');
                modal.classList.add('show');
                modal.style.pointerEvents = 'auto';
            });
        }
        async function checkActiveReservations(facilityId) {
            try {
                const response = await fetch('check_active_reservations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `facility_id=${facilityId}`
                });
                const data = await response.json();
                return data.hasActiveReservations;
            } catch (error) {
                console.error('Error checking active reservations:', error);
                return false; // Default to allowing deactivation if check fails
            }
        }
        function closeDeactivateWarningModal() {
            const modal = document.getElementById('deactivateWarningModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        function submitToggleStatus(facilityId, newStatus) {
            document.getElementById('toggle_facility_id').value = facilityId;
            document.getElementById('toggle_new_status').value = newStatus;
            document.getElementById('toggleStatusForm').submit();
        }
        function deleteFacility(facilityId) {
            // Set the facility ID in the modal form
            document.getElementById('delete_facility_id').value = facilityId;
            // Show the custom delete confirmation modal
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.add('show');
            modal.style.pointerEvents = 'auto';
        }
        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
            modal.style.pointerEvents = 'none';
        }
        // Close facility modal when clicking outside
        document.getElementById('facilityModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        // Close delete confirmation modal when clicking outside
        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirmModal();
            }
        });
        // Close deactivate warning modal when clicking outside
        document.getElementById('deactivateWarningModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeactivateWarningModal();
            }
        });
        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteConfirmModal();
                closeDeactivateWarningModal();
            }
        });
        // Image preview functionality
        document.getElementById('facility_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
        function removeImage() {
            document.getElementById('facility_image').value = '';
            document.getElementById('imagePreview').classList.add('hidden');
        }
        // Drag and drop functionality
        const dropZone = document.querySelector('.upload-zone');
        const fileInput = document.getElementById('facility_image');
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
        // Ensure all buttons are clickable
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize page functionality
            if (!window.ModalSystem) {
                console.warn('ModalSystem not found on DOMContentLoaded');
            }
            // hook form submit to serialize pricing options
            const facilityForm = document.getElementById('facilityForm');
            if (facilityForm) {
                facilityForm.addEventListener('submit', function(){
                    const hidden = document.getElementById('pricing_options_json');
                    if (hidden) { hidden.value = JSON.stringify(pricingOptions); }
                });
            }
        });
        // Mobile menu functionality
        function toggleMobileMenu() {
            const mobileToggle = document.querySelector(".mobile-menu-toggle");
            const navLinksContainer = document.querySelector(".nav-links-container");
            mobileToggle.classList.toggle("active");
            navLinksContainer.classList.toggle("show");
        }
        // Close mobile menu when clicking on a link
        document.addEventListener('DOMContentLoaded', function() {
            const mobileNavLinks = document.querySelectorAll(".nav-links-container .nav-link");
            mobileNavLinks.forEach(link => {
                link.addEventListener('click', function() {
                    const mobileToggle = document.querySelector('.mobile-menu-toggle');
                    const navContainer = document.querySelector('.nav-links-container');
                    mobileToggle.classList.remove('active');
                    navContainer.classList.remove('show');
                });
            });
            // Close mobile menu when clicking outside
            document.addEventListener('click', function(e) {
                const mobileToggle = document.querySelector('.mobile-menu-toggle');
                const navContainer = document.querySelector('.nav-links-container');
                if (!mobileToggle.contains(e.target) && !navContainer.contains(e.target)) {
                    mobileToggle.classList.remove('active');
                    navContainer.classList.remove('show');
                }
            });
        });
        // Pricing options dynamic UI
        let pricingOptions = [];
        function renderPricingOptions() {
            const container = document.getElementById('pricingOptionsContainer');
            if (!container) return;
            container.innerHTML = '';
            pricingOptions.forEach(function(po, index){
                const row = document.createElement('div');
                row.className = 'grid grid-cols-1 md:grid-cols-12 gap-2 items-center';
                row.innerHTML = `
                    <input type="hidden" value="${po.id ? po.id : ''}" data-index="${index}" />
                    <div class="md:col-span-3">
                        <input type="text" class="w-full border-2 border-gray-200 rounded-lg px-3 py-2" placeholder="Name (e.g., With Aircon)" value="${escapeHtml(po.name)}" oninput="updatePo(${index}, 'name', this.value)" />
                    </div>
                    <div class="md:col-span-4">
                        <input type="text" class="w-full border-2 border-gray-200 rounded-lg px-3 py-2" placeholder="Description" value="${escapeHtml(po.description)}" oninput="updatePo(${index}, 'description', this.value)" />
                    </div>
                    <div class="md:col-span-2">
                        <input type="number" step="0.01" min="0" class="w-full border-2 border-gray-200 rounded-lg px-3 py-2" placeholder="Price/hr" value="${po.price_per_hour}" oninput="updatePo(${index}, 'price_per_hour', this.value)" />
                    </div>
                    <div class="md:col-span-1">
                        <input type="number" class="w-full border-2 border-gray-200 rounded-lg px-3 py-2" placeholder="#" value="${po.sort_order}" oninput="updatePo(${index}, 'sort_order', this.value)" />
                    </div>
                    <div class="md:col-span-1 flex items-center justify-center">
                        <input type="checkbox" ${po.is_active ? 'checked' : ''} onchange="updatePo(${index}, 'is_active', this.checked)" />
                    </div>
                    <div class="md:col-span-1 flex items-center justify-end">
                        <button type="button" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-sm" onclick="removePricingRow(${index})"><i class="fas fa-trash"></i></button>
                    </div>
                `;
                container.appendChild(row);
            });
        }
        function addPricingRow() {
            pricingOptions.push({ id: null, name: '', description: '', price_per_hour: 0, sort_order: pricingOptions.length + 1, is_active: true });
            renderPricingOptions();
        }
        function removePricingRow(index) {
            pricingOptions.splice(index, 1);
            renderPricingOptions();
        }
        function updatePo(index, field, value) {
            if (!pricingOptions[index]) return;
            if (field === 'price_per_hour') { value = parseFloat(value) || 0; }
            if (field === 'sort_order') { value = parseInt(value) || 0; }
            if (field === 'is_active') { value = !!value; }
            pricingOptions[index][field] = value;
        }
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        // Enhanced mobile functionality
        document.addEventListener("DOMContentLoaded", function() {
            // Improve mobile table scrolling
            const tables = document.querySelectorAll(".overflow-x-auto");
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.overflowX = "auto";
                    table.style.webkitOverflowScrolling = "touch";
                }
            });
            // Mobile form improvements
            const forms = document.querySelectorAll("form");
            forms.forEach(form => {
                const inputs = form.querySelectorAll("input, select, textarea");
                inputs.forEach(input => {
                    input.addEventListener("focus", function() {
                        // Scroll to input on mobile
                        if (window.innerWidth <= 768) {
                            setTimeout(() => {
                                this.scrollIntoView({ behavior: "smooth", block: "center" });
                            }, 300);
                        }
                    });
                });
            });
            // Mobile modal improvements
            const modals = document.querySelectorAll(".modal");
            modals.forEach(modal => {
                modal.addEventListener("click", function(e) {
                    if (e.target === this) {
                        // Close modal on outside click
                        const closeBtn = this.querySelector("[onclick*=\"close\"]");
                        if (closeBtn) {
                            closeBtn.click();
                        }
                    }
                });
            });
        });
        // Mobile table enhancements
        document.addEventListener("DOMContentLoaded", function() {
            // Convert tables to mobile-friendly cards on small screens
            function convertTablesToCards() {
                const tables = document.querySelectorAll("table");
                tables.forEach(table => {
                    if (window.innerWidth <= 768) {
                        // Create mobile card wrapper if it doesn't exist
                        if (!table.parentElement.classList.contains("mobile-table-card")) {
                            const wrapper = document.createElement("div");
                            wrapper.className = "mobile-table-card";
                            table.parentNode.insertBefore(wrapper, table);
                            wrapper.appendChild(table);
                        }
                    }
                });
            }
            // Initialize table conversion
            convertTablesToCards();
            // Re-convert on window resize
            window.addEventListener("resize", convertTablesToCards);
            // Add data labels to table cells for mobile
            const tableRows = document.querySelectorAll("tbody tr");
            tableRows.forEach(row => {
                const cells = row.querySelectorAll("td");
                const headers = row.parentElement.parentElement.querySelectorAll("th");
                cells.forEach((cell, index) => {
                    if (headers[index]) {
                        cell.setAttribute("data-label", headers[index].textContent.trim());
                    }
                });
            });
            // Mobile filter improvements
            const filterSections = document.querySelectorAll(".filter-section, .filters");
            filterSections.forEach(section => {
                section.classList.add("filter-grid");
            });
            // Mobile button improvements
            const actionButtons = document.querySelectorAll(".flex.space-x-2, .flex.gap-2");
            actionButtons.forEach(buttonGroup => {
                if (buttonGroup.querySelectorAll("button, a").length > 1) {
                    buttonGroup.classList.add("action-buttons");
                }
            });
        });
    </script>
    
    <!-- Include Sidebar Script -->
    <?php include 'includes/sidebar-script.php'; ?>
    </div> <!-- Close main-content -->
</body>
</html>
