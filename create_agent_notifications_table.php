<?php
/**
 * Migration: Create agent_notifications table
 * Run this once to add the table to the database.
 */
require_once 'connection.php';

$sql = "CREATE TABLE IF NOT EXISTS `agent_notifications` (
    `notification_id` INT(11) NOT NULL AUTO_INCREMENT,
    `agent_account_id` INT(11) NOT NULL,
    `notif_type` ENUM(
        'tour_new',
        'tour_cancelled',
        'tour_completed',
        'property_approved',
        'property_rejected',
        'sale_approved',
        'sale_rejected',
        'commission_paid',
        'general'
    ) NOT NULL DEFAULT 'general',
    `reference_id` INT(11) DEFAULT NULL COMMENT 'ID of related item (tour_id, property_id, sale_id, etc.)',
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    PRIMARY KEY (`notification_id`),
    KEY `idx_agent_read_created` (`agent_account_id`, `is_read`, `created_at`),
    KEY `idx_agent_type` (`agent_account_id`, `notif_type`),
    CONSTRAINT `fk_agent_notif_account` FOREIGN KEY (`agent_account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql)) {
    echo "<p style='color:green;font-weight:bold;'>✅ Table `agent_notifications` created successfully.</p>";
} else {
    echo "<p style='color:red;font-weight:bold;'>❌ Error: " . htmlspecialchars($conn->error) . "</p>";
}

$conn->close();
?>
