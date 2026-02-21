<?php
// This function checks if the admin has completed their profile
function checkAdminProfileCompletion($conn, $admin_id) {
    $sql = "SELECT profile_completed FROM admin_information WHERE account_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If no record exists or profile_completed is 0, return false (profile not completed)
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    return (bool)$row['profile_completed'];
}
?>