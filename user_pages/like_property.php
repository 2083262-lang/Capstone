<?php
include '../connection.php'; // Your database connection

header('Content-Type: application/json');

// Check if the property_id is sent via POST
if (isset($_POST['property_id'])) {
    $property_id = (int)$_POST['property_id'];
    $action = isset($_POST['action']) ? $_POST['action'] : 'like';

    if ($property_id > 0) {
        // Start a transaction to ensure data integrity
        $conn->begin_transaction();
        try {
            if ($action === 'unlike') {
                // Decrement likes but never go below 0
                $update_sql = "UPDATE property SET Likes = GREATEST(Likes - 1, 0) WHERE property_ID = ?";
            } else {
                // Increment likes
                $update_sql = "UPDATE property SET Likes = Likes + 1 WHERE property_ID = ?";
            }

            $stmt_update = $conn->prepare($update_sql);
            $stmt_update->bind_param("i", $property_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Fetch the new, updated like count
            $select_sql = "SELECT Likes FROM property WHERE property_ID = ?";
            $stmt_select = $conn->prepare($select_sql);
            $stmt_select->bind_param("i", $property_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            $new_likes = $result->fetch_assoc()['Likes'];
            $stmt_select->close();
            
            // Commit the transaction
            $conn->commit();

            // Return the new like count as a JSON response
            echo json_encode(['success' => true, 'likes' => (int)$new_likes]);

        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid property ID.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No property ID provided.']);
}

$conn->close();
?>