<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * SESSION TIMEOUT HANDLER
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 * PURPOSE:
 * Automatically logs out inactive users after a specified period of inactivity.
 * This protects user accounts from unauthorized access on unattended devices.
 * 
 * USAGE:
 * Include this file at the TOP of every protected page, AFTER session_start()
 * and BEFORE any authentication checks.
 * 
 * For Admin pages:
 *   session_start();
 *   include 'connection.php';
 *   require_once __DIR__ . '/config/session_timeout.php';
 * 
 * For Agent pages (in agent_pages/ folder):
 *   session_start();
 *   include '../connection.php';
 *   require_once __DIR__ . '/../config/session_timeout.php';
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Session timeout duration in seconds.
 * Default: 1800 seconds = 30 minutes
 * 
 * Common values:
 * - 900  = 15 minutes (stricter security)
 * - 1800 = 30 minutes (balanced)
 * - 3600 = 60 minutes (more lenient)
 */
define('SESSION_TIMEOUT_SECONDS', 1800);

/**
 * Whether to enable session timeout functionality.
 * Set to false to temporarily disable timeout checking.
 */
define('SESSION_TIMEOUT_ENABLED', true);

/**
 * Session key names (do not change unless you know what you're doing)
 */
define('SESSION_LAST_ACTIVITY_KEY', 'last_activity');
define('SESSION_TIMEOUT_FLAG_KEY', 'session_timed_out');

// ═══════════════════════════════════════════════════════════════════════════════
// TIMEOUT LOGIC
// ═══════════════════════════════════════════════════════════════════════════════

// Only process if timeout is enabled and user is authenticated
if (SESSION_TIMEOUT_ENABLED && isset($_SESSION['account_id'])) {
    
    // Get current timestamp
    $current_time = time();
    
    // Check if last_activity timestamp exists
    if (isset($_SESSION[SESSION_LAST_ACTIVITY_KEY])) {
        // Calculate time since last activity
        $inactive_time = $current_time - $_SESSION[SESSION_LAST_ACTIVITY_KEY];
        
        // Check if session has expired
        if ($inactive_time > SESSION_TIMEOUT_SECONDS) {
            // ─────────────────────────────────────────────────────────────────
            // SESSION EXPIRED - Perform logout
            // ─────────────────────────────────────────────────────────────────
            
            // Store role for redirect logic before clearing session
            $user_role = $_SESSION['user_role'] ?? '';
            
            // Clear all session variables
            $_SESSION = array();
            
            // Delete the session cookie if it exists
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            // Destroy the session
            session_destroy();
            
            // Start a new session to set the timeout message
            session_start();
            $_SESSION[SESSION_TIMEOUT_FLAG_KEY] = true;
            
            // ─────────────────────────────────────────────────────────────────
            // REDIRECT TO LOGIN
            // ─────────────────────────────────────────────────────────────────
            
            // Determine redirect path based on current script location
            $current_script = $_SERVER['SCRIPT_NAME'] ?? '';
            
            // Check if we're in a subdirectory (e.g., agent_pages/)
            if (strpos($current_script, '/agent_pages/') !== false) {
                // Redirect from agent_pages to parent login
                header("Location: ../login.php?timeout=1");
            } else {
                // Redirect from root level
                header("Location: login.php?timeout=1");
            }
            exit();
        }
    }
    
    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE LAST ACTIVITY TIMESTAMP
    // ─────────────────────────────────────────────────────────────────────────
    // This runs on every page load for authenticated users, 
    // keeping the session alive as long as they are active.
    $_SESSION[SESSION_LAST_ACTIVITY_KEY] = $current_time;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Get remaining session time in seconds.
 * Useful for displaying countdown timers or warnings.
 * 
 * @return int|null Seconds remaining or null if not applicable
 */
function getSessionTimeRemaining(): ?int {
    if (!SESSION_TIMEOUT_ENABLED || !isset($_SESSION['account_id']) || !isset($_SESSION[SESSION_LAST_ACTIVITY_KEY])) {
        return null;
    }
    
    $elapsed = time() - $_SESSION[SESSION_LAST_ACTIVITY_KEY];
    $remaining = SESSION_TIMEOUT_SECONDS - $elapsed;
    
    return max(0, $remaining);
}

/**
 * Get session timeout duration in minutes.
 * Useful for displaying timeout info to users.
 * 
 * @return int Timeout duration in minutes
 */
function getSessionTimeoutMinutes(): int {
    return (int) floor(SESSION_TIMEOUT_SECONDS / 60);
}

/**
 * Check if the current session is close to expiring.
 * 
 * @param int $warningThreshold Seconds before expiry to trigger warning (default: 300 = 5 minutes)
 * @return bool True if session expires within the threshold
 */
function isSessionExpiringSoon(int $warningThreshold = 300): bool {
    $remaining = getSessionTimeRemaining();
    
    if ($remaining === null) {
        return false;
    }
    
    return $remaining <= $warningThreshold && $remaining > 0;
}

/**
 * Manually refresh the session timestamp.
 * Call this after AJAX requests that should reset the timeout.
 * 
 * @return void
 */
function refreshSessionActivity(): void {
    if (isset($_SESSION['account_id'])) {
        $_SESSION[SESSION_LAST_ACTIVITY_KEY] = time();
    }
}

/**
 * Check if the previous session was terminated due to timeout.
 * Use this on the login page to show a timeout message.
 * 
 * @return bool True if session timed out
 */
function wasSessionTimedOut(): bool {
    // Check both session flag and URL parameter
    $timedOut = isset($_SESSION[SESSION_TIMEOUT_FLAG_KEY]) && $_SESSION[SESSION_TIMEOUT_FLAG_KEY] === true;
    $urlParam = isset($_GET['timeout']) && $_GET['timeout'] == '1';
    
    // Clear the session flag after checking
    if (isset($_SESSION[SESSION_TIMEOUT_FLAG_KEY])) {
        unset($_SESSION[SESSION_TIMEOUT_FLAG_KEY]);
    }
    
    return $timedOut || $urlParam;
}
