<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'facility_reservation');
define('DB_USER', 'root');
define('DB_PASS', '');
// Create database connection
function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}
// Application configuration
define('SITE_NAME', 'ZAMSPORTS');
define('SITE_URL', 'http://localhost/Facility_reservation');
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
// Session configuration
session_start();
?>
