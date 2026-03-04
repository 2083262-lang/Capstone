<?php
/**
 * Agent Notifications API
 * 
 * Handles AJAX requests for:
 *   - fetch       → Get latest notifications + unread count (for navbar dropdown)
 *   - mark_read   → Mark a single notification as read
 *   - mark_all    → Mark all notifications as read
 *   - delete      → Delete a single notification
 */
session_start();
require_once __DIR__ . '/../config/session_timeout.php';
header('Content-Type: application/json');

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../connection.php';
require_once 'agent_notification_helper.php';

$agent_id = (int)$_SESSION['account_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ─── Fetch latest for dropdown ───
    case 'fetch':
        $unread = getAgentUnreadCount($conn, $agent_id);
        $notifications = getAgentLatestNotifications($conn, $agent_id, 8);
        
        // Format for JSON
        $formatted = [];
        foreach ($notifications as $n) {
            list($icon, $color) = getNotifIcon($n['notif_type']);
            $formatted[] = [
                'id'        => (int)$n['notification_id'],
                'type'      => $n['notif_type'],
                'ref_id'    => $n['reference_id'],
                'title'     => $n['title'],
                'message'   => $n['message'],
                'is_read'   => (int)$n['is_read'],
                'time_ago'  => formatNotifTimeAgo($n['created_at']),
                'icon'      => $icon,
                'color'     => $color,
                'created_at'=> $n['created_at'],
            ];
        }
        echo json_encode(['success' => true, 'unread' => $unread, 'notifications' => $formatted]);
        break;

    // ─── Mark single as read ───
    case 'mark_read':
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid <= 0) {
            echo json_encode(['error' => 'Invalid ID']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE agent_notifications SET is_read = 1 WHERE notification_id = ? AND agent_account_id = ?");
        $stmt->bind_param("ii", $nid, $agent_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        break;

    // ─── Mark all as read ───
    case 'mark_all':
        $stmt = $conn->prepare("UPDATE agent_notifications SET is_read = 1 WHERE agent_account_id = ? AND is_read = 0");
        $stmt->bind_param("i", $agent_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        break;

    // ─── Delete single ───
    case 'delete':
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid <= 0) {
            echo json_encode(['error' => 'Invalid ID']);
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM agent_notifications WHERE notification_id = ? AND agent_account_id = ?");
        $stmt->bind_param("ii", $nid, $agent_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}

$conn->close();
?>
