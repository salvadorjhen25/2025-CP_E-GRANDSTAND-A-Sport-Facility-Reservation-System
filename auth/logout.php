<?php
require_once __DIR__ . '/../config/database.php';
require_once 'auth.php';
$auth = new Auth();
$auth->logout();
header('Location: ../index.php');
exit();
?>
