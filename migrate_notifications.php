<?php
/**
 * Migration: Redesign notifications table for richer admin notifications
 * Run this once to update the database schema.
 */
require_once 'connection.php';

$queries = [];

// 1. Add new columns to the notifications table
$queries[] = "ALTER TABLE `notifications` 
    ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `item_type`,
    ADD COLUMN `category` ENUM('request','update','alert','system') NOT NULL DEFAULT 'update' AFTER `message`,
    ADD COLUMN `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER `category`,
    ADD COLUMN `action_url` VARCHAR(500) DEFAULT NULL AFTER `priority`,
    ADD COLUMN `icon` VARCHAR(50) DEFAULT NULL AFTER `action_url`";

// 2. Update existing notifications with appropriate titles & categories
$queries[] = "UPDATE `notifications` SET 
    title = CASE 
        WHEN item_type = 'agent' THEN 'Agent Profile Submission'
        WHEN item_type = 'tour' THEN 'New Tour Request'
        WHEN item_type = 'property' THEN 'Property Update'
        WHEN item_type = 'property_sale' THEN 'Sale Verification'
        ELSE 'Notification'
    END,
    category = CASE 
        WHEN item_type = 'agent' THEN 'request'
        WHEN item_type = 'tour' THEN 'request'
        WHEN item_type = 'property_sale' THEN 'request'
        ELSE 'update'
    END,
    priority = CASE 
        WHEN item_type = 'agent' THEN 'high'
        WHEN item_type = 'tour' THEN 'normal'
        WHEN item_type = 'property_sale' THEN 'high'
        ELSE 'normal'
    END,
    action_url = CASE
        WHEN item_type = 'agent' THEN CONCAT('review_agent_details.php?id=', item_id)
        WHEN item_type = 'tour' THEN CONCAT('admin_tour_request_details.php?id=', item_id)
        WHEN item_type = 'property' THEN CONCAT('view_property.php?id=', item_id)
        WHEN item_type = 'property_sale' THEN CONCAT('admin_property_sale_approvals.php')
        ELSE NULL
    END,
    icon = CASE
        WHEN item_type = 'agent' THEN 'bi-person-badge'
        WHEN item_type = 'tour' THEN 'bi-calendar-check'
        WHEN item_type = 'property' THEN 'bi-building'
        WHEN item_type = 'property_sale' THEN 'bi-cash-stack'
        ELSE 'bi-bell'
    END
    WHERE title = '' OR title IS NULL";

// 3. Add index for category + priority filtering
$queries[] = "ALTER TABLE `notifications` ADD INDEX `idx_category_priority` (`category`, `priority`)";

$success = 0;
$errors = [];

foreach ($queries as $i => $sql) {
    if ($conn->query($sql) === TRUE) {
        $success++;
        echo "Query " . ($i + 1) . " executed successfully.<br>";
    } else {
        $errors[] = "Query " . ($i + 1) . " failed: " . $conn->error;
        echo "Query " . ($i + 1) . " failed: " . $conn->error . "<br>";
    }
}

echo "<br><strong>Migration complete: $success/" . count($queries) . " queries succeeded.</strong>";
if (!empty($errors)) {
    echo "<br><br><strong>Errors:</strong><br>" . implode("<br>", $errors);
}

$conn->close();
?>
