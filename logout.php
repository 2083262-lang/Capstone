<?php
session_start();
include 'connection.php'; // Include the database connection

// Log admin logout before destroying the session
if (isset($_SESSION['account_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    $admin_id = $_SESSION['account_id'];

    // Prepare and execute the log insert statement
    $log_stmt = $conn->prepare("INSERT INTO admin_logs (admin_account_id, action) VALUES (?, 'logout')");
    $log_stmt->bind_param("i", $admin_id);
    $log_stmt->execute();
    $log_stmt->close();
}

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

// Redirect to the login page
header('location: login.php');
exit();
?>