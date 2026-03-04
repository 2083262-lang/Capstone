# Session Timeout Implementation Guide

## Real Estate System - Security Feature

**Date Created:** March 2026  
**Author:** System Administrator  
**Status:** Ready for Implementation  

---

## Table of Contents

1. [Overview](#overview)
2. [How Session Timeout Works](#how-session-timeout-works)
3. [Files Created](#files-created)
4. [Implementation Steps](#implementation-steps)
   - [Step 1: Admin Pages](#step-1-admin-pages)
   - [Step 2: Agent Pages](#step-2-agent-pages)
   - [Step 3: Login Page Update](#step-3-login-page-update)
5. [Complete File List](#complete-file-list)
6. [Configuration Options](#configuration-options)
7. [Advanced Features](#advanced-features)
8. [Testing Guide](#testing-guide)
9. [Troubleshooting](#troubleshooting)

---

## Overview

Session timeout automatically logs out users who have been **inactive** for a specified period (default: 30 minutes). This is a critical security feature that protects user accounts from unauthorized access when:

- A user forgets to log out
- A user leaves their computer unattended
- A browser tab is left open indefinitely

### Benefits

- **Security**: Reduces risk of session hijacking
- **Compliance**: Meets security best practices
- **User Protection**: Prevents unauthorized access to sensitive data

---

## How Session Timeout Works

```
┌─────────────────────────────────────────────────────────────────────┐
│                         SESSION FLOW                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  User Logs In                                                        │
│       │                                                              │
│       ▼                                                              │
│  ┌─────────────────────────────────┐                                 │
│  │ Set $_SESSION['last_activity']  │ ◄─── Current timestamp          │
│  └─────────────────────────────────┘                                 │
│       │                                                              │
│       ▼                                                              │
│  ┌─────────────────────────────────┐                                 │
│  │    User navigates pages         │                                 │
│  └─────────────────────────────────┘                                 │
│       │                                                              │
│       ▼                                                              │
│  ┌─────────────────────────────────┐                                 │
│  │  Check: Is timeout enabled?     │──No──► Continue normally        │
│  └─────────────────────────────────┘                                 │
│       │ Yes                                                          │
│       ▼                                                              │
│  ┌─────────────────────────────────┐                                 │
│  │  Calculate inactive time        │                                 │
│  │  current_time - last_activity   │                                 │
│  └─────────────────────────────────┘                                 │
│       │                                                              │
│       ▼                                                              │
│  ┌─────────────────────────────────┐                                 │
│  │  Is inactive > 30 minutes?      │                                 │
│  └─────────────────────────────────┘                                 │
│       │                    │                                         │
│   Yes │                    │ No                                      │
│       ▼                    ▼                                         │
│  ┌─────────────────┐  ┌─────────────────────────────────┐            │
│  │ Destroy session │  │ Update $_SESSION['last_activity']│            │
│  │ Redirect login  │  │ Continue to page                 │            │
│  └─────────────────┘  └─────────────────────────────────┘            │
│                                                                       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Files Created

| File | Location | Purpose |
|------|----------|---------|
| `session_timeout.php` | `/config/session_timeout.php` | Main session timeout handler |

---

## Implementation Steps

### Step 1: Admin Pages

Add the session timeout include to **ALL admin pages**. The include must be placed **AFTER** `session_start()` and `connection.php`, but **BEFORE** any authentication checks.

#### Pattern for Admin Pages (Root Directory)

**BEFORE:**
```php
<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/paths.php';

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
```

**AFTER:**
```php
<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';  // ◄─── ADD THIS LINE
require_once __DIR__ . '/config/paths.php';

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
```

#### Admin Pages to Update

Apply the above change to these files in the **root directory**:

| # | File Name | Description |
|---|-----------|-------------|
| 1 | `admin_dashboard.php` | Main admin dashboard |
| 2 | `property.php` | Property management |
| 3 | `agent.php` | Agent management |
| 4 | `tour_requests.php` | Tour request management |
| 5 | `reports.php` | Reports page |
| 6 | `admin_settings.php` | Admin settings |
| 7 | `admin_notifications.php` | Notifications page |
| 8 | `admin_profile.php` | Admin profile |
| 9 | `add_property.php` | Add new property |
| 10 | `view_property.php` | View property details |
| 11 | `add_agent.php` | Add new agent |
| 12 | `review_agent_details.php` | Review agent details |
| 13 | `admin_property_sale_approvals.php` | Property sale approvals |
| 14 | `admin_tour_request_details.php` | Tour request details |
| 15 | `admin_finalize_sale.php` | Finalize sale |

---

### Step 2: Agent Pages

Add the session timeout include to **ALL agent pages** in the `agent_pages/` directory.

#### Pattern for Agent Pages (agent_pages/ Directory)

**BEFORE:**
```php
<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../config/paths.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}
```

**AFTER:**
```php
<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../config/session_timeout.php';  // ◄─── ADD THIS LINE
require_once __DIR__ . '/../config/paths.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}
```

#### Agent Pages to Update

Apply the above change to these files in the **agent_pages/** directory:

| # | File Name | Description |
|---|-----------|-------------|
| 1 | `agent_dashboard.php` | Agent dashboard |
| 2 | `agent_property.php` | Agent property management |
| 3 | `agent_profile.php` | Agent profile |
| 4 | `agent_tour_requests.php` | Agent tour requests |
| 5 | `agent_notifications.php` | Agent notifications |
| 6 | `agent_commissions.php` | Agent commissions |
| 7 | `add_property_process.php` | Add property process |
| 8 | `tour_request_details.php` | Tour request details |
| 9 | `tour_request_accept.php` | Accept tour request |
| 10 | `tour_request_cancel.php` | Cancel tour request |
| 11 | `tour_request_complete.php` | Complete tour request |
| 12 | `mark_as_sold_process.php` | Mark property as sold |

---

### Step 3: Login Page Update

`login.php` has already been updated to detect the `?timeout=1` URL parameter and display the timeout message using the **same `.custom-toast` design** as the rest of the login page's notification system (dark glassmorphism card, gold icon, gold progress bar, 7-second auto-dismiss).

#### What was added (already done — for reference only)

**PHP variable at the top of login.php** (after the other notice variables):
```php
// Session timeout notice
$timeout_notice = '';
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $timeout_notice = "Your session has expired due to inactivity. Please log in again to continue.";
}
```

**Toast HTML block** (placed before `<div class="main-container">`, after the other toast blocks):
```html
<?php if ($timeout_notice): ?>
<div class="toast-container" <?php echo ($registration_notice || $profile_notice) ? 'style="top: 100px;"' : ''; ?>>
    <div class="custom-toast" id="timeoutToast">
        <div class="d-flex align-items-start gap-3">
            <i class="fas fa-clock toast-icon mt-1"></i>
            <div class="flex-grow-1">
                <strong>Session Expired</strong> <?php echo htmlspecialchars($timeout_notice); ?>
            </div>
            <button type="button" class="btn-close ms-2" onclick="dismissToast('timeoutToast')" aria-label="Close"></button>
        </div>
        <div class="toast-progress"></div>
    </div>
</div>
<?php endif; ?>
```

**`timeoutToast` added** to the auto-dismiss array in the JS block:
```js
['registrationToast', 'profileToast', 'timeoutToast'].forEach(function(id) {
    var toast = document.getElementById(id);
    if (toast) {
        setTimeout(function() { dismissToast(id); }, 7000);
    }
});
```

> **Design note:** The `.custom-toast` CSS on the login page already provides the dark glassmorphism background, gold-coloured icon, gold progress bar, and slide-in animation. No inline styles are needed — the toast inherits everything from the existing CSS class.

---

### Step 4: Admin / Agent Pages — Optional Timeout Toast via `showToast()`

On admin and agent dashboard pages (which already have the `showToast()` / `app-toast` system loaded), you can optionally show an in-page toast when the user arrives back at their dashboard right after re-login from a timeout. This is optional — the login page already communicates the timeout.

**Admin pages** (light theme — use inside `DOMContentLoaded` or `skeleton:hydrated`):
```php
<?php if (isset($_GET['timeout']) && $_GET['timeout'] == '1'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast('info', 'Session Expired', 'You were logged out due to inactivity. Welcome back!', 6000);
});
</script>
<?php endif; ?>
```

**Agent pages** (dark theme — if the page uses skeleton screens, swap `DOMContentLoaded` for `skeleton:hydrated`):
```php
<?php if (isset($_GET['timeout']) && $_GET['timeout'] == '1'): ?>
<script>
document.addEventListener('skeleton:hydrated', function() {
    showToast('info', 'Session Expired', 'You were logged out due to inactivity. Welcome back!', 6000);
});
</script>
<?php endif; ?>

---

## Complete File List

### Files That Need Session Timeout (Checklist)

Use this checklist to track your implementation progress:

#### Root Directory (Admin Pages)

- [ ] `admin_dashboard.php`
- [ ] `property.php`
- [ ] `agent.php`
- [ ] `tour_requests.php`
- [ ] `reports.php`
- [ ] `admin_settings.php`
- [ ] `admin_notifications.php`
- [ ] `admin_notification_view.php`
- [ ] `admin_profile.php`
- [ ] `add_property.php`
- [ ] `view_property.php`
- [ ] `add_agent.php`
- [ ] `review_agent_details.php`
- [ ] `review_agent_details_process.php`
- [ ] `admin_property_sale_approvals.php`
- [ ] `admin_tour_request_details.php`
- [ ] `admin_tour_request_accept.php`
- [ ] `admin_tour_request_reject.php`
- [ ] `admin_tour_request_cancel.php`
- [ ] `admin_tour_request_complete.php`
- [ ] `admin_finalize_sale.php`
- [ ] `admin_mark_as_sold_process.php`
- [ ] `save_property.php`
- [ ] `save_agent.php`
- [ ] `save_admin_info.php`
- [ ] `update_property.php`
- [ ] `get_property_data.php`
- [ ] `get_property_photos.php`
- [ ] `admin_settings_api.php`
- [ ] `admin_check_tour_conflict.php`

#### agent_pages/ Directory

- [ ] `agent_dashboard.php`
- [ ] `agent_property.php`
- [ ] `agent_profile.php`
- [ ] `agent_tour_requests.php`
- [ ] `agent_notifications.php`
- [ ] `agent_notifications_api.php`
- [ ] `agent_commissions.php`
- [ ] `add_property_process.php`
- [ ] `save_agent_profile.php`
- [ ] `tour_request_details.php`
- [ ] `tour_request_accept.php`
- [ ] `tour_request_cancel.php`
- [ ] `tour_request_complete.php`
- [ ] `mark_as_sold_process.php`
- [ ] `check_tour_conflict.php`
- [ ] `get_property_tour_requests.php`
- [ ] `delete_property_image.php`
- [ ] `delete_floor_image.php`
- [ ] `reorder_property_images.php`
- [ ] `remove_floor.php`

---

## Configuration Options

The session timeout can be configured by editing `/config/session_timeout.php`:

### Change Timeout Duration

```php
// Default: 30 minutes (1800 seconds)
define('SESSION_TIMEOUT_SECONDS', 1800);

// For 15 minutes:
define('SESSION_TIMEOUT_SECONDS', 900);

// For 1 hour:
define('SESSION_TIMEOUT_SECONDS', 3600);
```

### Disable Timeout (for testing)

```php
// Set to false to disable timeout checking
define('SESSION_TIMEOUT_ENABLED', false);
```

---

## Advanced Features

### 1. Display Session Remaining Time

You can display a countdown or warning to users. Add this to your navbar or footer:

```php
<?php
require_once __DIR__ . '/config/session_timeout.php';

$remaining = getSessionTimeRemaining();
if ($remaining !== null) {
    $minutes = floor($remaining / 60);
    $seconds = $remaining % 60;
    echo "Session expires in: {$minutes}m {$seconds}s";
}
?>
```

### 2. Warning Before Timeout (JavaScript)

Add this JavaScript to your main layout file to warn users before their session expires:

```html
<script>
// Session timeout warning (5 minutes before expiry)
const sessionTimeoutSeconds = <?php echo SESSION_TIMEOUT_SECONDS; ?>;
const warningMinutes = 5;
const warningTime = (sessionTimeoutSeconds - (warningMinutes * 60)) * 1000;

setTimeout(function() {
    if (confirm('Your session will expire in 5 minutes. Click OK to stay logged in.')) {
        // Refresh the page to extend session
        location.reload();
    }
}, warningTime);

// Auto-redirect when session definitely expires
setTimeout(function() {
    alert('Your session has expired. You will be redirected to the login page.');
    window.location.href = 'login.php?timeout=1';
}, sessionTimeoutSeconds * 1000);
</script>
```

### 3. AJAX Session Refresh

If you have AJAX-heavy pages, you can create an endpoint to refresh the session:

Create `/api/refresh_session.php`:
```php
<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';

header('Content-Type: application/json');

if (isset($_SESSION['account_id'])) {
    refreshSessionActivity();
    echo json_encode(['success' => true, 'remaining' => getSessionTimeRemaining()]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
}
```

Call it from JavaScript:
```javascript
// Refresh session every 10 minutes
setInterval(function() {
    fetch('/api/refresh_session.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                window.location.href = 'login.php?timeout=1';
            }
        });
}, 600000); // 10 minutes
```

---

## Testing Guide

### Test 1: Normal Session Activity

1. Log in as admin or agent
2. Navigate between pages
3. Confirm you stay logged in
4. Check that `$_SESSION['last_activity']` updates on each page load

### Test 2: Session Timeout

1. Set a short timeout for testing (e.g., 60 seconds):
   ```php
   define('SESSION_TIMEOUT_SECONDS', 60);
   ```
2. Log in as admin or agent
3. Wait 70 seconds without any activity
4. Try to navigate to another protected page
5. Confirm you are redirected to login with `?timeout=1` parameter
6. Confirm the timeout message displays

### Test 3: Admin vs Agent Redirect

1. Log in as agent
2. Wait for timeout
3. Confirm redirect goes to `../login.php?timeout=1` (correct path from agent_pages/)
4. Repeat for admin and confirm redirect goes to `login.php?timeout=1`

### Test 4: Session Regeneration

1. Log in
2. Note the session ID (from browser dev tools)
3. Navigate to a few pages
4. Session ID should remain the same (no unnecessary regeneration)
5. Log in again after timeout - session ID should be different

---

## Troubleshooting

### Issue: "Headers already sent" error

**Cause:** Output (HTML, whitespace, BOM) was sent before the session timeout redirect.

**Solution:** Ensure there's no whitespace or output before `<?php` in any included file:
```php
<?php  // ← Must be the very first characters in the file
session_start();
```

### Issue: Timeout not working

**Checklist:**
1. Is `SESSION_TIMEOUT_ENABLED` set to `true`?
2. Is the include line in the correct position (after session_start)?
3. Is the user actually authenticated (`$_SESSION['account_id']` exists)?

### Issue: Infinite redirect loop

**Cause:** Session timeout include is on the login page itself.

**Solution:** Never include `session_timeout.php` on:
- `login.php`
- `register.php`
- `two_factor.php`
- `verify_2fa.php`
- Any public page that doesn't require authentication

### Issue: AJAX calls trigger logout

**Cause:** AJAX requests don't update the session activity.

**Solution:** Either:
1. Include session timeout logic in AJAX endpoints
2. Use the AJAX refresh endpoint described in [Advanced Features](#3-ajax-session-refresh)

---

## Security Best Practices Applied

This implementation follows security best practices:

| Practice | Implementation |
|----------|----------------|
| Session Fixation Prevention | Session is destroyed completely on timeout |
| Secure Cookie Handling | Session cookie is properly invalidated |
| No Information Leakage | Generic timeout message (doesn't reveal session details) |
| Fail-Secure | If anything goes wrong, user is logged out |
| Configurable | Easy to adjust timeout duration |

---

## Summary

1. **Created:** `/config/session_timeout.php`
2. **Add include to:** All admin pages (root) and agent pages (agent_pages/)
3. **Update:** `login.php` to show timeout message
4. **Default timeout:** 30 minutes of inactivity
5. **Test thoroughly** before deploying to production

---

**Implementation Time Estimate:** 30-45 minutes to add includes to all files

**Questions?** Refer to the troubleshooting section or check the comments in `session_timeout.php`.
