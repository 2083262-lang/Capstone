# Toast Notification System

Themed toast notifications matching the admin panel design (`--gold` / `--blue` accent).  
Drop the three blocks below into any admin page and you're done.

---

## 1 — CSS

Paste inside the page's `<style>` block.

```css
/* ===== TOAST NOTIFICATIONS ===== */
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

## 2 — HTML container

Place this **once**, just before `</body>` (or right before your main `<script>` tag).  
It must come **before** the JS block below.

```html
<!-- Toast Container -->
<div id="toastContainer"></div>
```

---

## 3 — JavaScript

Paste inside the page's `<script>` block (after Bootstrap JS is loaded).

```js
// ===== TOAST =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    const container = document.getElementById('toastContainer');
    const icons = {
        success: 'bi-check-circle-fill',
        error:   'bi-x-circle-fill',
        info:    'bi-info-circle-fill'
    };
    const toast = document.createElement('div');
    toast.className = `app-toast toast-${type}`;
    toast.innerHTML = `
        <div class="app-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
        <div class="app-toast-body">
            <div class="app-toast-title">${title}</div>
            <div class="app-toast-msg">${message}</div>
        </div>
        <button class="app-toast-close" onclick="dismissToast(this.closest('.app-toast'))">&times;</button>
        <div class="app-toast-progress" style="animation: toast-progress ${duration}ms linear forwards;"></div>
    `;
    container.appendChild(toast);
    const timer = setTimeout(() => dismissToast(toast), duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(() => toast.remove(), 320);
}
```

---

## 4 — Usage

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

Add this block right after the opening `<div class="admin-content">`.  
The `DOMContentLoaded` callback runs after `showToast` is defined, so ordering is safe.

```php
<?php if ($success_message || $error_message): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($success_message): ?>
        showToast('success', '<?= $toast_title /* set this in PHP */ ?>', '<?= addslashes(htmlspecialchars($success_message)) ?>', 5000);
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

## 5 — Types at a glance

| Type      | Accent colour | Icon                    | Typical use                        |
|-----------|--------------|-------------------------|------------------------------------|
| `success` | Gold `#d4af37` | `bi-check-circle-fill` | Saved, approved, finalized         |
| `error`   | Red `#ef4444`  | `bi-x-circle-fill`     | Failed, validation error, rejected |
| `info`    | Blue `#2563eb` | `bi-info-circle-fill`  | Neutral notices, reminders         |

---

## 6 — Checklist for a new page

- [ ] Bootstrap Icons CDN loaded (`bootstrap-icons`)
- [ ] Toast CSS added to `<style>`
- [ ] `<div id="toastContainer"></div>` placed before `</body>`
- [ ] `showToast` + `dismissToast` functions inside `<script>`
- [ ] PHP `$success_message` / `$error_message` variables initialised at top of file
- [ ] `DOMContentLoaded` script block added after `<div class="admin-content">`
- [ ] Remove any legacy `<div class="alert alert-success">` / `<div class="alert alert-danger">` banners
