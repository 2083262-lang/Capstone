# Toast Notification System

Themed toast notifications for both the **Admin Panel** (light theme) and **Agent Portal** (dark theme).  
Drop the three blocks below into any page and you're done.

---

## Table of Contents

1. [Admin Panel (Light Theme)](#admin-panel-light-theme)
2. [Agent Portal (Dark Theme)](#agent-portal-dark-theme)
3. [HTML Container](#3--html-container)
4. [JavaScript](#4--javascript)
5. [Usage](#5--usage)
6. [Integration with Skeleton Screens](#6--integration-with-skeleton-screens)
7. [Agent Dashboard Toast Recipes](#7--agent-dashboard-toast-recipes)
8. [Types at a Glance](#8--types-at-a-glance)
9. [Checklist](#9--checklist)

---

## Admin Panel (Light Theme)

### 1A — CSS (Light / Admin)

Paste inside the page's `<style>` block.

```css
/* ===== TOAST NOTIFICATIONS — Admin (Light Theme) ===== */
#toastContainer {
    position: fixed;
    top: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    pointer-events: none;
}
.app-toast {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    background: #ffffff;
    border-radius: 12px;
    padding: 0.9rem 1.1rem;
    min-width: 300px;
    max-width: 380px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06);
    pointer-events: all;
    position: relative;
    overflow: hidden;
    animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
}
@keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
.app-toast.toast-out { animation: toast-out .3s ease forwards; }
@keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }

/* Left accent bar */
.app-toast::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
}
.app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
.app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
.app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }

/* Icon badge */
.app-toast-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
.toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
.toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }

/* Body text */
.app-toast-body      { flex: 1; min-width: 0; }
.app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
.app-toast-msg       { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }

/* Close button */
.app-toast-close {
    background: none; border: none; cursor: pointer;
    color: #9ca3af; font-size: 0.8rem;
    padding: 0; line-height: 1;
    flex-shrink: 0;
    transition: color .2s;
}
.app-toast-close:hover { color: #374151; }

/* Auto-dismiss progress bar */
.app-toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 2px;
    border-radius: 0 0 0 12px;
}
.toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
.toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
.toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
@keyframes toast-progress { from { width: 100%; } to { width: 0%; } }
```

---

## Agent Portal (Dark Theme)

### 1B — CSS (Dark / Agent Portal)

Use this variant for **all agent portal pages** (`agent_pages/`). The dark background, lighter text, and subtle border match the portal's black + gold + blue aesthetic.

```css
/* ===== TOAST NOTIFICATIONS — Agent Portal (Dark Theme) ===== */
#toastContainer {
    position: fixed;
    top: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    pointer-events: none;
}
.app-toast {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    background: linear-gradient(135deg, rgba(26,26,26,0.97) 0%, rgba(10,10,10,0.98) 100%);
    border: 1px solid rgba(37,99,235,0.15);
    border-radius: 12px;
    padding: 0.9rem 1.1rem;
    min-width: 300px;
    max-width: 400px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
    pointer-events: all;
    position: relative;
    overflow: hidden;
    animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
    backdrop-filter: blur(12px);
}
@keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
.app-toast.toast-out { animation: toast-out .3s ease forwards; }
@keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }

/* Left accent bar */
.app-toast::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
}
.app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
.app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
.app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }

/* Icon badge */
.app-toast-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.toast-success .app-toast-icon { background: rgba(212,175,55,0.15); color: #d4af37; }
.toast-error   .app-toast-icon { background: rgba(239,68,68,0.12);  color: #ef4444; }
.toast-info    .app-toast-icon { background: rgba(37,99,235,0.12);  color: #3b82f6; }

/* Body text — light text on dark background */
.app-toast-body      { flex: 1; min-width: 0; }
.app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.2rem; }
.app-toast-msg       { font-size: 0.78rem; color: #9ca4ab; line-height: 1.4; word-break: break-word; }

/* Close button */
.app-toast-close {
    background: none; border: none; cursor: pointer;
    color: #5d6d7d; font-size: 0.8rem;
    padding: 0; line-height: 1;
    flex-shrink: 0;
    transition: color .2s;
}
.app-toast-close:hover { color: #f1f5f9; }

/* Auto-dismiss progress bar */
.app-toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 2px;
    border-radius: 0 0 0 12px;
}
.toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
.toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
.toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
@keyframes toast-progress { from { width: 100%; } to { width: 0%; } }
```

**Differences from the light theme:**

| Property | Light (Admin) | Dark (Agent Portal) |
|---|---|---|
| `.app-toast` background | `#ffffff` | `rgba(26,26,26,0.97)` gradient |
| `.app-toast` border | none (shadow only) | `1px solid rgba(37,99,235,0.15)` |
| `.app-toast` shadow | `rgba(0,0,0,0.16)` | `rgba(0,0,0,0.5)` (deeper) |
| `backdrop-filter` | none | `blur(12px)` |
| `.app-toast-title` color | `#111827` (dark text) | `#f1f5f9` (light text) |
| `.app-toast-msg` color | `#6b7280` | `#9ca4ab` |
| `.app-toast-close` hover | `#374151` | `#f1f5f9` |
| Icon backgrounds | `0.10–0.12` opacity | `0.12–0.15` opacity (slightly brighter) |
| `max-width` | `380px` | `400px` |

---

## 3 — HTML container

Place this **once**, just before `</body>` (or right before your main `<script>` tag).  
It must come **before** the JS block below.

```html
<!-- Toast Container -->
<div id="toastContainer"></div>
```

---

## 4 — JavaScript

Paste inside the page's `<script>` block (after Bootstrap JS is loaded).  
This JS is **identical** for both light and dark themes.

```js
// ===== TOAST =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    var container = document.getElementById('toastContainer');
    var icons = {
        success: 'bi-check-circle-fill',
        error:   'bi-x-circle-fill',
        info:    'bi-info-circle-fill'
    };
    var toast = document.createElement('div');
    toast.className = 'app-toast toast-' + type;
    toast.innerHTML =
        '<div class="app-toast-icon"><i class="bi ' + (icons[type] || icons.info) + '"></i></div>' +
        '<div class="app-toast-body">' +
            '<div class="app-toast-title">' + title + '</div>' +
            '<div class="app-toast-msg">' + message + '</div>' +
        '</div>' +
        '<button class="app-toast-close" onclick="dismissToast(this.closest(\'.app-toast\'))">&times;</button>' +
        '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
    container.appendChild(toast);
    var timer = setTimeout(function() { dismissToast(toast); }, duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(function() { toast.remove(); }, 320);
}
```

---

## 5 — Usage

### From JavaScript (inline actions, AJAX responses)

```js
// Success  — gold accent
showToast('success', 'Saved', 'Record updated successfully.');

// Error    — red accent
showToast('error', 'Failed', 'Something went wrong. Please try again.');

// Info     — blue accent
showToast('info', 'Note', 'Your session will expire in 5 minutes.');

// Custom duration (ms) — default is 4500
showToast('success', 'Commission Finalized', 'Commission calculated: ₱12,500.', 6000);

// HTML in message is supported
showToast('success', 'Done', 'Commission: <strong>₱12,500</strong>');
```

---

### From PHP (on page load — e.g. after a redirect with ?success=...)

**Admin pages** — use `DOMContentLoaded`:
```php
<?php if ($success_message || $error_message): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($success_message): ?>
        showToast('success', '<?= $toast_title ?>', '<?= addslashes(htmlspecialchars($success_message)) ?>', 5000);
    <?php endif; ?>
    <?php if ($error_message): ?>
        showToast('error', 'Error', '<?= addslashes(htmlspecialchars($error_message)) ?>', 6000);
    <?php endif; ?>
});
</script>
<?php endif; ?>
```

**PHP setup pattern** (at the top of the page):

```php
$success_message = '';
$error_message   = '';

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'saved':    $success_message = 'Record saved successfully.';    $toast_title = 'Saved';    break;
        case 'deleted':  $success_message = 'Record deleted successfully.';  $toast_title = 'Deleted';  break;
        // add more cases as needed
    }
}
if (isset($_GET['error'])) {
    $error_message = 'An error occurred. Please try again.';
}
```

---

## 6 — Integration with Skeleton Screens

On pages using the **skeleton screen system** (see `SKELETON_SCREEN_GUIDE.md`), toasts must fire **after** the skeleton hydration — not on `DOMContentLoaded`. Otherwise toasts appear while the skeleton is still visible.

**Rule:** Replace `DOMContentLoaded` with `skeleton:hydrated` on any page that has a skeleton screen.

```javascript
// ❌ Wrong — fires while skeleton is still visible
document.addEventListener('DOMContentLoaded', function() {
    showToast('success', 'Saved', 'Done.');
});

// ✅ Correct — fires after real content is visible
document.addEventListener('skeleton:hydrated', function() {
    showToast('success', 'Saved', 'Done.');
});
```

### Staggering multiple toasts

When showing several notifications at once (e.g., dashboard status toasts), stagger them so they stack smoothly instead of appearing simultaneously:

```javascript
document.addEventListener('skeleton:hydrated', function() {
    var delay = 0;
    var GAP = 600; // ms between each toast

    setTimeout(function() { showToast('info', 'Title 1', 'Message 1', 6000); }, delay);
    delay += GAP;

    setTimeout(function() { showToast('info', 'Title 2', 'Message 2', 5500); }, delay);
    delay += GAP;

    // ...add more as needed
});
```

---

## 7 — Agent Dashboard Toast Recipes

These are the toast notifications implemented on `agent_dashboard.php`. All fire on `skeleton:hydrated` and are staggered 600ms apart. Each only appears when the relevant condition is true.

### Today's Confirmed Tours
```php
<?php if (($today_tours['today_count'] ?? 0) > 0): ?>
showToast('success', 'Today\'s Schedule',
    'You have <?= (int)$today_tours['today_count'] ?> confirmed tour(s) scheduled for today.', 6000);
<?php endif; ?>
```
**Type:** `success` (gold) — Immediate attention needed, positive/actionable  
**Condition:** Confirmed tours where `tour_date = CURDATE()`

### Pending Tour Requests
```php
<?php if (($tour_stats['pending_tours'] ?? 0) > 0): ?>
showToast('info', 'Pending Tours',
    'You have <?= (int)$tour_stats['pending_tours'] ?> tour request(s) awaiting your response.', 6000);
<?php endif; ?>
```
**Type:** `info` (blue) — Action required, not urgent  
**Condition:** `request_status = 'Pending'`

### Properties Pending Approval
```php
<?php if (($stats['pending_approval'] ?? 0) > 0): ?>
showToast('info', 'Pending Approval',
    '<?= (int)$stats['pending_approval'] ?> property/properties pending admin approval.', 5500);
<?php endif; ?>
```
**Type:** `info` (blue) — Informational, no agent action needed  
**Condition:** `approval_status = 'pending'`

### Pending Sale Verifications
```php
<?php if (count($pending_sales) > 0): ?>
showToast('info', 'Sale Verifications',
    '<?= count($pending_sales) ?> sale verification(s) currently under review.', 5500);
<?php endif; ?>
```
**Type:** `info` (blue) — Status update on submitted sales  
**Condition:** `sale_verifications.status = 'Pending'`

### Unpaid Commissions
```php
<?php if (($commissions['unpaid_commission'] ?? 0) > 0): ?>
showToast('success', 'Pending Commissions',
    'You have ₱<?= number_format($commissions['unpaid_commission'], 0) ?> in pending commissions.', 5500);
<?php endif; ?>
```
**Type:** `success` (gold) — Positive news, money-related  
**Condition:** Commissions with `status IN ('pending','calculated')`

### New Agent — No Listings Yet
```php
<?php if (($stats['active_listings'] ?? 0) == 0 && ($stats['total_listings'] ?? 0) == 0): ?>
showToast('info', 'Get Started',
    'Add your first property listing to begin building your portfolio!', 7000);
<?php endif; ?>
```
**Type:** `info` (blue) — Onboarding nudge for new agents  
**Condition:** Zero total listings

---

## 8 — Types at a glance

| Type | Accent colour | Icon | Typical use |
|---|---|---|---|
| `success` | Gold `#d4af37` | `bi-check-circle-fill` | Saved, approved, finalized, commissions, today's tours |
| `error` | Red `#ef4444` | `bi-x-circle-fill` | Failed, validation error, rejected |
| `info` | Blue `#2563eb` | `bi-info-circle-fill` | Pending items, status updates, reminders, onboarding |

---

## 9 — Checklist

### Any page (light or dark)
- [ ] Bootstrap Icons CDN loaded (`bootstrap-icons`)
- [ ] Toast CSS added to `<style>` (use **1A** for admin, **1B** for agent portal)
- [ ] `<div id="toastContainer"></div>` placed before `</body>`
- [ ] `showToast` + `dismissToast` functions inside `<script>`
- [ ] PHP `$success_message` / `$error_message` variables initialised at top of file
- [ ] Remove any legacy `<div class="alert alert-success">` / `<div class="alert alert-danger">` banners

### Pages with skeleton screens
- [ ] Toast triggers use `skeleton:hydrated` event (NOT `DOMContentLoaded`)
- [ ] Multiple toasts are staggered with `setTimeout` (600ms gap recommended)
- [ ] `showToast()` is defined **before** the hydration script (so it exists when the event fires)

### Agent Dashboard specific
- [ ] Today's tours query added (`tour_date = CURDATE()`)
- [ ] All 6 conditional toast blocks present inside `skeleton:hydrated` listener
- [ ] Toasts use staggered delays (`toastDelay += TOAST_GAP`)

*Reference implementations:*
- Admin (light): `admin_property_sale_approvals.php`
- Agent (dark): `agent_pages/agent_dashboard.php`

---

## 10 — Persistent Validation Toasts

A **different variant** of the toast system designed for form validation. Unlike standard toasts, these **do not auto-dismiss** — they stay visible until the user corrects the specific field that caused the error.

Used on: `register.php` (agent registration form).

---

### JS Functions

Add these **before** any field validation logic in the `<script>` block.

```js
// Persistent per-field validation toasts — stay until the field is corrected
var _validationToasts = {};

function showValidationToast(key, title, message) {
    if (_validationToasts[key] && !_validationToasts[key]._dismissed) {
        // Update in-place instead of recreating
        _validationToasts[key].querySelector('.app-toast-title').textContent = title;
        _validationToasts[key].querySelector('.app-toast-msg').textContent = message;
        return;
    }
    var container = document.getElementById('toastContainer');
    var toast = document.createElement('div');
    toast.className = 'app-toast toast-error';
    toast.dataset.validationKey = key;
    toast.innerHTML =
        '<div class="app-toast-icon"><i class="fas fa-exclamation-circle"></i></div>' +
        '<div class="app-toast-body">' +
            '<div class="app-toast-title">' + title + '</div>' +
            '<div class="app-toast-msg">' + message + '</div>' +
        '</div>' +
        '<button class="app-toast-close" aria-label="Dismiss">&times;</button>';
    container.appendChild(toast);
    toast.querySelector('.app-toast-close').addEventListener('click', function() {
        dismissValidationToast(key);
    });
    _validationToasts[key] = toast;
}

function dismissValidationToast(key) {
    var toast = _validationToasts[key];
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    toast.classList.add('toast-out');
    setTimeout(function() { if (toast.parentNode) toast.remove(); delete _validationToasts[key]; }, 320);
}
```

> **Note:** Uses Font Awesome icons (`fas fa-exclamation-circle`), not Bootstrap Icons. Use the **1B dark CSS** from this doc — no changes to the CSS needed.

---

### Wiring to `showError` / `clearError`

Replace the standard inline-feedback pattern with toast calls:

```js
function showError(el, id, message) {
    el.classList.add('is-invalid');          // red border stays
    showValidationToast(id, 'Check your input', message);
}
function clearError(el, id) {
    el.classList.remove('is-invalid');
    dismissValidationToast(id);              // toast dismissed when field is valid
}
```

The field element `id` (e.g. `'emailError'`, `'passwordError'`) is used as the toast `key` so each field maps to exactly one toast.

---

### Firing PHP Server-Side Errors on Page Load

When the page reloads after a failed POST (e.g. duplicate username), fire the error as a persistent toast:

```js
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($error_message): ?>
    showValidationToast('server_error', 'Registration Failed', '<?= addslashes(htmlspecialchars($error_message)) ?>');
    <?php endif; ?>
});
```

---

### Key Differences from Standard `showToast`

| Feature | `showToast` | `showValidationToast` |
|---|---|---|
| Auto-dismiss | Yes (default 4500ms) | **No — persistent** |
| Progress bar | Yes | No |
| Keyed by field | No | **Yes** — one toast per field |
| Update in-place | No | **Yes** — re-fires update existing toast |
| Dismisses when | Timer or user clicks ✕ | **User corrects the field** or clicks ✕ |
| Icon | Varies by type | Always `fa-exclamation-circle` (error) |

---

### Checklist

- [ ] Dark theme toast CSS (1B) added to `<style>`
- [ ] `<div id="toastContainer"></div>` placed before `</body>`
- [ ] `showValidationToast` + `dismissValidationToast` defined **before** any validation logic
- [ ] `showError` / `clearError` wired to use `showValidationToast` / `dismissValidationToast`
- [ ] PHP `$error_message` fired as persistent toast via `DOMContentLoaded`
- [ ] Old `<div class="alert alert-danger">` / `alert-success` HTML blocks **removed**
- [ ] Old `.alert-danger` / `.alert-success` CSS blocks **removed**
- [ ] Font Awesome loaded (uses `fas fa-exclamation-circle`)

*Reference implementation: `register.php`*
