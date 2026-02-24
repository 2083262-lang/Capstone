<?php
/**
 * Agent Notification Helper
 * 
 * Centralized function for creating agent notifications.
 * Include this file wherever you need to create an agent notification.
 */

/**
 * Create a notification for an agent.
 *
 * @param mysqli $conn         Database connection
 * @param int    $agent_id     The agent's account_id
 * @param string $type         Notification type enum: tour_new, tour_cancelled, tour_completed,
 *                             property_approved, property_rejected, sale_approved, sale_rejected,
 *                             commission_paid, general
 * @param string $title        Short notification title (max 150 chars)
 * @param string $message      Full notification message
 * @param int|null $ref_id     Reference ID (tour_id, property_id, etc.)
 * @return bool                True on success
 */
function createAgentNotification($conn, $agent_id, $type, $title, $message, $ref_id = null) {
    $sql = "INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    
    $stmt->bind_param("isiss", $agent_id, $type, $ref_id, $title, $message);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get unread notification count for an agent.
 *
 * @param mysqli $conn
 * @param int    $agent_id
 * @return int
 */
function getAgentUnreadCount($conn, $agent_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM agent_notifications WHERE agent_account_id = ? AND is_read = 0");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

/**
 * Get latest notifications for the navbar dropdown.
 *
 * @param mysqli $conn
 * @param int    $agent_id
 * @param int    $limit
 * @return array
 */
function getAgentLatestNotifications($conn, $agent_id, $limit = 8) {
    $stmt = $conn->prepare("SELECT notification_id, notif_type, reference_id, title, message, is_read, created_at FROM agent_notifications WHERE agent_account_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $agent_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    return $notifications;
}

/**
 * Format a relative time string from a datetime.
 *
 * @param string $datetime
 * @return string
 */
function formatNotifTimeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', strtotime($datetime));
}

/**
 * Get the icon class for a notification type.
 *
 * @param string $type
 * @return array [icon_class, color_class]
 */
function getNotifIcon($type) {
    $map = [
        'tour_new'          => ['bi bi-calendar-plus', 'tour'],
        'tour_cancelled'    => ['bi bi-calendar-x', 'cancelled'],
        'tour_completed'    => ['bi bi-calendar-check', 'completed'],
        'property_approved' => ['bi bi-check-circle', 'approved'],
        'property_rejected' => ['bi bi-x-circle', 'rejected'],
        'sale_approved'     => ['bi bi-cash-stack', 'sale'],
        'sale_rejected'     => ['bi bi-exclamation-triangle', 'rejected'],
        'commission_paid'   => ['bi bi-wallet2', 'commission'],
        'general'           => ['bi bi-bell', 'general'],
    ];
    return $map[$type] ?? $map['general'];
}
?>
