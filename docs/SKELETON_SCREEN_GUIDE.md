# Skeleton Screens — CSR / Progressive Hydration Guide

> **Purpose:** Step-by-step implementation guide for adding skeleton loading screens to any page in this system. Covers both the **Admin Panel (light theme)** and the **Agent Portal (dark theme)**.
> - Admin reference: `admin_property_sale_approvals.php`
> - Agent Portal reference: `agent_pages/agent_dashboard.php`

---

## Table of Contents

1. [What This Technique Does](#1-what-this-technique-does)
2. [Why It Feels Faster](#2-why-it-feels-faster)
3. [Architecture Overview](#3-architecture-overview)
4. [The Three Parts](#4-the-three-parts)
   - [Part A — CSS (Skeleton Styles + Shimmer)](#part-a--css-skeleton-styles--shimmer)
   - [Part B — HTML (Skeleton + Real Content Wrapper)](#part-b--html-skeleton--real-content-wrapper)
   - [Part C — JS (Hydration Script)](#part-c--js-hydration-script)
   - [Part D — Deferring Toast Notifications](#part-d--deferring-toast-notifications-and-other-post-load-ui)
5. [Step-by-Step: How to Apply to Any Page](#5-step-by-step-how-to-apply-to-any-page)
6. [Skeleton Component Recipes](#6-skeleton-component-recipes)
   - [Page Header Skeleton](#page-header-skeleton)
   - [KPI / Stat Card Grid Skeleton](#kpi--stat-card-grid-skeleton)
   - [Action Bar Skeleton](#action-bar-skeleton)
   - [Status Tabs Skeleton](#status-tabs-skeleton)
   - [Card Grid Skeleton](#card-grid-skeleton)
   - [Pagination Bar Skeleton](#pagination-bar-skeleton)
   - [Table Skeleton](#table-skeleton)
   - [Sidebar + Main Content Split](#sidebar--main-content-split)
7. [Pages With Pagination](#7-pages-with-pagination)
   - [Scenario A — Server-Side Pagination (Full Page Reload)](#scenario-a--server-side-pagination-full-page-reload)
   - [Scenario B — AJAX Pagination (Grid-Only Reload)](#scenario-b--ajax-pagination-grid-only-reload)
8. [Do's and Don'ts](#8-dos-and-donts)
9. [Checklist](#9-checklist)
10. [Troubleshooting](#10-troubleshooting)
11. [Agent Portal — Dark Theme Variant](#11-agent-portal--dark-theme-variant)

---

## 1. What This Technique Does

Instead of a blank white screen (or half-rendered content) when navigating to a page, the user instantly sees **placeholder shapes with a shimmer animation** that mirror the real page layout. The real PHP-rendered content is hidden until the DOM is fully parsed, then it **cross-fades in** as the skeleton fades out.

```
User clicks link
      │
      ▼
Browser receives HTML from server
      │
      ├── Skeleton HTML renders IMMEDIATELY (first paint)
      │   → User sees shimmering card/grid shapes
      │
      ├── DOMContentLoaded fires (DOM parsed — almost instant)
      │   → Invisible setup code runs (event listeners, etc.)
      │   → Skeleton stays on screen
      │
      ├── External resources finish loading (CSS, fonts, images)
      │   → window 'load' fires
      │   → MIN_SKELETON_MS enforced (400ms minimum display)
      │
      └── scheduleHydration() runs
          → Skeleton fades OUT  (0.35 s)
          → Real content fades IN (0.42 s)
          → Skeleton removed from DOM (memory freed)
          → 'skeleton:hydrated' event dispatched
          → Toasts / deferred UI can now fire
```

---

## 2. Why It Feels Faster

| Traditional | With Skeleton |
|---|---|
| Blank white flash while CSS/fonts load | Skeleton visible immediately — no blank flash |
| Content pops in abruptly | Content cross-fades in smoothly |
| User doesn't know if page loaded | Shimmer signals "loading" intent |
| Layout shifts when content appears | No layout shift — skeleton dimensions match real content |

The page **actual load time** is unchanged. What changes is **perceived performance** — the human experience of waiting. Skeleton screens are the same UX technique used by Facebook, LinkedIn, YouTube, and Spotify.

---

## 3. Architecture Overview

```
<div class="admin-content">

    <!-- NO-JS FALLBACK (noscript tag) -->
    <noscript>
        <style>
            #sk-screen   { display: none !important; }
            #page-content { display: block !important; }
        </style>
    </noscript>

    <!-- SKELETON SCREEN ───────────────────────── -->
    <!-- Renders on first paint. Removed by JS.    -->
    <div id="sk-screen" aria-hidden="true">
        <!-- Mirror the page layout with shimmer boxes -->
        ...skeleton HTML...
    </div>

    <!-- REAL CONTENT ──────────────────────────── -->
    <!-- Server-rendered by PHP. Starts hidden.    -->
    <!-- Revealed by hydration script below.       -->
    <div id="page-content">
        ...all existing PHP page content...
    </div>

</div><!-- /.admin-content -->

<!-- HYDRATION SCRIPT (separate <script> before </body>) -->
<script>
    (function() { ...hydrate()... }());
</script>
```

**Key constraint:** `#sk-screen` and `#page-content` must be **siblings** inside the same `.admin-content` container. The skeleton renders in normal document flow. The real content starts as `display:none` and is swapped in by JS.

---

## 4. The Three Parts

### Part A — CSS (Skeleton Styles + Shimmer)

Add this block **inside your `<style>` tag, just before `</style>`**. Copy it verbatim — only adjust class names if your page uses different grid layouts.

```css
/* ================================================================
   SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
   + Progressive Hydration
   ================================================================ */

/* ── Core shimmer animation ── */
@keyframes sk-shimmer {
    0%   { background-position: -800px 0; }
    100% { background-position:  800px 0; }
}
.sk-shimmer {
    background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
    background-size: 1600px 100%;
    animation: sk-shimmer 1.6s ease-in-out infinite;
    border-radius: 4px;
}

/* ── Real content: hidden until hydration reveals it ── */
#page-content {
    display: none; /* Revealed by JS on window load */
}

/* ── Skeleton component base styles ── */
/* (Customize widths/heights to match your real layout) */

.sk-page-header {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    padding: 2rem 2.5rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.sk-page-header::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
}

.sk-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr); /* Match your real grid */
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.sk-kpi-card {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.sk-kpi-icon { width: 48px; height: 48px; border-radius: 4px; flex-shrink: 0; }

.sk-action-bar {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    padding: 0.85rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    gap: 0.75rem;
    align-items: center;
    position: relative;
    overflow: hidden;
}
.sk-action-bar::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
}

.sk-tabs {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    margin-bottom: 1.5rem;
    padding: 0 1rem;
    display: flex;
    gap: 0.75rem;
    align-items: center;
    height: 54px;
    position: relative;
    overflow: hidden;
}
.sk-tabs::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
}

.sk-content-wrap {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    overflow: hidden;
}
.sk-sales-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1.25rem;
    padding: 1.5rem;
}
.sk-sale-card {
    background: #fff;
    border: 1px solid rgba(37,99,235,0.1);
    border-radius: 4px;
    overflow: hidden;
}
.sk-card-img    { height: 180px; width: 100%; }
.sk-card-body   { padding: 1rem 1.25rem; }
.sk-card-footer { padding: 0 1.25rem 1.25rem; }
.sk-line        { display: block; border-radius: 4px; }

/* ── Responsive ── */
@media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px)  {
    .sk-kpi-grid   { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    .sk-sales-grid { grid-template-columns: 1fr; }
}
@media (max-width: 576px)  { .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; } }
```

---

### Part B — HTML (Skeleton + Real Content Wrapper)

**Where to put it:** Right after the opening `<div class="admin-content">` tag.

```html
<div class="admin-content">

    <!-- NO-JS FALLBACK -->
    <noscript><style>
        #sk-screen    { display: none !important; }
        #page-content { display: block !important; opacity: 1 !important; }
    </style></noscript>

    <!-- SKELETON SCREEN -->
    <div id="sk-screen" role="presentation" aria-hidden="true">
        <!-- ← Paste skeleton component HTML here (see Section 6) -->
    </div><!-- /#sk-screen -->

    <!-- REAL CONTENT (hidden until hydrated) -->
    <div id="page-content">

        <!-- ← ALL existing PHP page content stays here, unchanged -->

    </div><!-- /#page-content -->

</div><!-- /.admin-content -->
```

> **Important:** The closing `</div><!-- /#page-content -->` must come just before the closing `</div><!-- /.admin-content -->`. Everything between them is your existing PHP content — you do NOT need to change the existing content at all.

---

### Part C — JS (Hydration Script)

**Where to put it:** As a **separate `<script>` block**, placed after the existing large JS block, just before `</body>`. Do NOT put it inside the existing script.

> **Critical timing:** The script uses `window 'load'` (not `DOMContentLoaded`). This ensures the skeleton stays visible while external CSS, fonts, and images are still loading — which is the main source of perceived delay on PHP pages.

```html
    </script>  <!-- ← closing tag of your existing JS block -->

    <!-- SKELETON HYDRATION — Progressive Content Reveal -->
    <script>
    (function () {
        'use strict';

        /* ── Configuration ────────────────────────────────────────── */
        var MIN_SKELETON_MS = 400;   /* Minimum time skeleton stays visible (ms).
                                        Prevents a jarring flash on fast local loads.
                                        Increase to 600–800 for extra-smooth feel. */

        /* Record when the skeleton first rendered */
        var skeletonStart = Date.now();

        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');

            if (!pc) return;
            if (!sk) {
                pc.style.cssText = 'display:block;opacity:1;';
                document.dispatchEvent(new Event('skeleton:hydrated'));
                return;
            }

            /* Step 1: Make real content visible but transparent */
            pc.style.display  = 'block';
            pc.style.opacity  = '0';

            /* Step 2: Animate on next frame */
            requestAnimationFrame(function () {
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity    = '0';  /* skeleton fades out */

                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function () {
                    pc.style.opacity = '1';  /* real content fades in */
                });
            });

            /* Step 3: Remove skeleton & dispatch event */
            window.setTimeout(function () {
                if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
                pc.style.transition = '';
                pc.style.opacity    = '';

                /* Signal — toasts and other deferred UI can now fire */
                document.dispatchEvent(new Event('skeleton:hydrated'));
            }, 520);
        }

        /* Enforce minimum display time before hydrating */
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);

            if (remaining > 0) {
                window.setTimeout(hydrate, remaining);
            } else {
                hydrate();
            }
        }

        /*
         * Trigger: window 'load' — fires only after ALL sub-resources
         * (CSS, fonts, images, JS) finish loading.  This keeps the
         * skeleton visible the entire time external assets load.
         */
        if (document.readyState === 'complete') {
            scheduleHydration();
        } else {
            window.addEventListener('load', scheduleHydration);
        }

    }());
    </script>
</body>
</html>
```

#### Key design decisions

| Decision | Why |
|---|---|
| `window 'load'` instead of `DOMContentLoaded` | PHP pages parse the DOM in milliseconds (all HTML is in the response). The real delay is external CSS/fonts/images loading. `DOMContentLoaded` fires too early — skeleton just flashes. `load` waits for everything. |
| `MIN_SKELETON_MS = 400` | Even on fast local connections where `load` fires quickly, the skeleton stays on screen long enough for the user to perceive a smooth transition instead of a jarring flash. |
| `skeleton:hydrated` custom event | Decouples the skeleton from dependent UI (toasts, tooltips, popovers). Nothing else fires until the real page is fully visible. |

---

### Part D — Deferring Toast Notifications (and other post-load UI)

Any UI that should only appear **after the real content is visible** (toast notifications, popover tooltips, auto-focus on inputs, etc.) must listen for the `skeleton:hydrated` event instead of `DOMContentLoaded`.

**Before (broken — toasts fire while skeleton is still visible):**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    showToast('success', 'Saved', 'Record saved successfully.', 5000);
});
```

**After (correct — toasts fire only when page content is visible):**
```javascript
document.addEventListener('skeleton:hydrated', function() {
    showToast('success', 'Saved', 'Record saved successfully.', 5000);
});
```

**In PHP pages with conditional toasts:**
```html
<script>
document.addEventListener('skeleton:hydrated', function() {
    <?php if ($success_message): ?>
        showToast('success', 'Success', '<?= addslashes(htmlspecialchars($success_message)) ?>', 5000);
    <?php endif; ?>
    <?php if ($error_message): ?>
        showToast('error', 'Error', '<?= addslashes(htmlspecialchars($error_message)) ?>', 6000);
    <?php endif; ?>
});
</script>
```

> **Rule of thumb:** If it was previously on `DOMContentLoaded` and it produces visual output the user should see → move it to `skeleton:hydrated`.
> If it's invisible setup code (event listeners, data init) → keep it on `DOMContentLoaded`.

---

## 5. Step-by-Step: How to Apply to Any Page

Follow these 9 steps in order for any admin PHP page:

### Step 1 — Copy the CSS block
Open the target PHP file. Find the closing `</style>` tag in `<head>`. Paste the entire **Part A CSS block** directly before `</style>`.

### Step 2 — Identify the content container
Find the `<div class="admin-content">` opening tag. This is where the skeleton goes.

### Step 3 — Add the noscript fallback
Immediately after `<div class="admin-content">`, paste:
```html
<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>
```

### Step 4 — Build the skeleton HTML
Look at your page and identify the main layout sections:
- Does it have a **page header**? → Use `.sk-page-header`
- Does it have **KPI stat cards**? → Use `.sk-kpi-grid` with `.sk-kpi-card`
- Does it have an **action bar** (search + buttons)? → Use `.sk-action-bar`
- Does it have **status tabs**? → Use `.sk-tabs`
- Does it have a **card grid**? → Use `.sk-content-wrap` + `.sk-sales-grid` + `.sk-sale-card`
- Does it have a **data table**? → See the Table Skeleton recipe in Section 6

Wrap these components in `<div id="sk-screen" role="presentation" aria-hidden="true">`.

### Step 5 — Open the real content wrapper
After closing `</div><!-- /#sk-screen -->`, open:
```html
<div id="page-content">
```

### Step 6 — Leave existing PHP content unchanged
Do not touch any existing PHP content. It stays exactly where it was — just now inside `#page-content`.

### Step 7 — Close the real content wrapper
Find the closing `</div>` that closes `.admin-content`. Just before it, add:
```html
        </div><!-- /#page-content -->
```
The structure should be:
```html
        <!-- last real content element closes here -->
    </div><!-- /#page-content -->
</div><!-- /.admin-content -->
```

### Step 8 — Add the hydration script
Find the closing `</script>` of your main JS block (near `</body>`). After it, paste the entire **Part C hydration script**.

### Step 9 — Defer toast notifications
Search your page for any `DOMContentLoaded` listeners that produce visible output (toast notifications, tooltips, auto-focus). Change them to `skeleton:hydrated` — see **Part D** for examples.

---

## 6. Skeleton Component Recipes

Copy-paste the component you need into your `#sk-screen` div.

---

### Page Header Skeleton

```html
<div class="sk-page-header">
    <div class="sk-line sk-shimmer" style="width:200px;height:22px;margin-bottom:10px;"></div>
    <div class="sk-line sk-shimmer" style="width:340px;height:13px;"></div>
</div>
```

**Customize:** Adjust `width` to roughly match your real heading and subtitle widths.

---

### KPI / Stat Card Grid Skeleton

```html
<!-- 4-column KPI grid — change grid-template-columns in CSS if you have 3 or 2 -->
<div class="sk-kpi-grid">
    <!-- Repeat this block for each KPI card (usually 3–5) -->
    <div class="sk-kpi-card">
        <div class="sk-kpi-icon sk-shimmer"></div>
        <div style="flex:1;">
            <div class="sk-line sk-shimmer" style="width:65%;height:10px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:40%;height:20px;"></div>
        </div>
    </div>
    <!-- ...repeat for each card... -->
</div>
```

**Customize:** Change `grid-template-columns: repeat(4, 1fr)` in `.sk-kpi-grid` to match the number of real KPI cards.

---

### Action Bar Skeleton

```html
<div class="sk-action-bar">
    <!-- Search input placeholder -->
    <div class="sk-shimmer" style="flex:1;height:36px;border-radius:4px;"></div>
    <!-- Button placeholders -->
    <div class="sk-shimmer" style="width:90px;height:36px;border-radius:4px;flex-shrink:0;"></div>
    <div class="sk-shimmer" style="width:90px;height:36px;border-radius:4px;flex-shrink:0;"></div>
</div>
```

---

### Status Tabs Skeleton

```html
<div class="sk-tabs">
    <div class="sk-shimmer" style="width:75px;height:20px;border-radius:3px;"></div>
    <div class="sk-shimmer" style="width:85px;height:20px;border-radius:3px;"></div>
    <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
    <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
</div>
```

**Customize:** Add or remove `<div>` elements to match the number of real tabs.

---

### Card Grid Skeleton

```html
<div class="sk-content-wrap">
    <div class="sk-sales-grid">

        <!-- Repeat this block 3–4 times for a realistic preview -->
        <div class="sk-sale-card">
            <!-- Image area -->
            <div class="sk-card-img sk-shimmer"></div>
            <!-- Body text -->
            <div class="sk-card-body">
                <div class="sk-line sk-shimmer" style="width:80%;height:16px;margin-bottom:8px;"></div>
                <div class="sk-line sk-shimmer" style="width:52%;height:12px;margin-bottom:12px;"></div>
                <div style="display:flex;gap:6px;margin-bottom:12px;">
                    <div class="sk-shimmer" style="width:82px;height:10px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:95px;height:10px;border-radius:3px;"></div>
                </div>
                <div class="sk-line sk-shimmer" style="width:60%;height:10px;margin-bottom:16px;"></div>
            </div>
            <!-- Footer button -->
            <div class="sk-card-footer">
                <div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div>
            </div>
        </div>

    </div>
</div>
```

**Customize:** Change `minmax(340px, 1fr)` in `.sk-sales-grid` to match your real card `minmax()` value.

---

### Table Skeleton

Use this in place of a data table `<table>` that lists rows of records.

```html
<div class="sk-content-wrap">
    <!-- Table header row -->
    <div style="display:flex;gap:1rem;padding:0.85rem 1.25rem;border-bottom:1px solid #f1f5f9;">
        <div class="sk-shimmer" style="width:18%;height:12px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:22%;height:12px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:15%;height:12px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:20%;height:12px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:12%;height:12px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:13%;height:12px;border-radius:3px;"></div>
    </div>
    <!-- Repeat 6–8 data rows -->
    <div style="display:flex;gap:1rem;padding:0.85rem 1.25rem;border-bottom:1px solid #f8f9fa;align-items:center;">
        <div class="sk-shimmer" style="width:18%;height:14px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:22%;height:14px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:15%;height:14px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:20%;height:14px;border-radius:3px;"></div>
        <div class="sk-shimmer" style="width:55px;height:22px;border-radius:10px;"></div>
        <div class="sk-shimmer" style="width:75px;height:28px;border-radius:4px;"></div>
    </div>
    <!-- ...repeat row block ~6 times... -->
</div>
```

---

### Pagination Bar Skeleton

Always place this **directly beneath the card grid or table skeleton**, mirroring where your real pagination bar sits.

```html
<!-- Pagination bar skeleton -->
<div style="display:flex;align-items:center;justify-content:space-between;
            padding:1rem 1.5rem;border-top:1px solid #f1f5f9;">

    <!-- Left: "Showing X–Y of Z results" label -->
    <div class="sk-shimmer" style="width:160px;height:13px;border-radius:3px;"></div>

    <!-- Center/Right: page number buttons -->
    <div style="display:flex;gap:0.4rem;align-items:center;">
        <!-- Prev button -->
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <!-- Page number pills (show 5) -->
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        <!-- Next button -->
        <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
    </div>

</div>
```

**Usage:** Drop this block immediately after `</div><!-- close sk-sales-grid -->` or after the table skeleton rows, still inside `.sk-content-wrap`.

```html
<!-- Full example: card grid + pagination in one wrapper -->
<div class="sk-content-wrap">
    <div class="sk-sales-grid">
        <!-- ...3-4 sk-sale-card blocks... -->
    </div>
    <!-- Pagination bar sits here, inside the same wrapper -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:1rem 1.5rem;border-top:1px solid #f1f5f9;">
        <div class="sk-shimmer" style="width:160px;height:13px;border-radius:3px;"></div>
        <div style="display:flex;gap:0.4rem;">
            <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
            <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
            <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
            <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
            <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;"></div>
        </div>
    </div>
</div>
```

---

### Sidebar + Main Content Split

For pages with a left sidebar and main content area (e.g., agent profile, property detail):

```html
<div style="display:grid;grid-template-columns:280px 1fr;gap:1.5rem;">
    <!-- Sidebar skeleton -->
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="sk-shimmer" style="width:100%;height:200px;border-radius:4px;"></div>
        <div class="sk-page-header" style="padding:1.25rem;">
            <div class="sk-line sk-shimmer" style="width:70%;height:14px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:90%;height:10px;margin-bottom:6px;"></div>
            <div class="sk-line sk-shimmer" style="width:80%;height:10px;"></div>
        </div>
    </div>
    <!-- Main content skeleton -->
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="sk-page-header">
            <div class="sk-line sk-shimmer" style="width:200px;height:20px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:320px;height:12px;"></div>
        </div>
        <div class="sk-content-wrap" style="padding:1.5rem;">
            <div class="sk-line sk-shimmer" style="width:60%;height:16px;margin-bottom:12px;"></div>
            <div class="sk-line sk-shimmer" style="width:100%;height:10px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:95%;height:10px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:85%;height:10px;"></div>
        </div>
    </div>
</div>
```

---

## 7. Pages With Pagination

Pagination introduces **two distinct scenarios** — choose based on how your page loads new data:

---

### Scenario A — Server-Side Pagination (Full Page Reload)

**What happens:** Clicking page 2 sends a `?page=2` request. The full PHP page reloads.

**This is the simplest case.** The skeleton screen you already built handles it automatically — every page load (page 1, 2, 3…) shows the skeleton first, then hydrates. No extra work needed.

**Only one addition:** Include the [Pagination Bar Skeleton](#pagination-bar-skeleton) inside your `#sk-screen` so the skeleton layout matches the real page layout at all times.

```
User clicks "Page 2"
      │
      ▼
Full page reload (?page=2)
      │
      ├── Skeleton renders on first paint (same as initial load)
      ├── PHP renders page 2 content inside #page-content
      └── Hydration script runs → skeleton fades out, content fades in
```

**PHP side — standard pagination query:**
```php
$perPage  = 12;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

$total    = $conn->query("SELECT COUNT(*) FROM sale_verifications")->fetch_row()[0];
$totalPgs = (int)ceil($total / $perPage);

$stmt = $conn->prepare("SELECT ... FROM sale_verifications LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
```

**HTML pagination bar (real content, inside `#page-content`):**
```html
<?php if ($totalPgs > 1): ?>
<div style="display:flex;align-items:center;justify-content:space-between;
            padding:1rem 1.5rem;border-top:1px solid #e2e8f0;">

    <span style="font-size:0.8rem;color:#6c757d;">
        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?>
        of <?= $total ?> results
    </span>

    <nav style="display:flex;gap:0.35rem;">
        <!-- Prev -->
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="pg-btn">‹</a>
        <?php endif; ?>

        <!-- Page numbers -->
        <?php for ($p = max(1,$page-2); $p <= min($totalPgs,$page+2); $p++): ?>
            <a href="?page=<?= $p ?>" class="pg-btn <?= $p===$page?'active':'' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>

        <!-- Next -->
        <?php if ($page < $totalPgs): ?>
            <a href="?page=<?= $page + 1 ?>" class="pg-btn">›</a>
        <?php endif; ?>
    </nav>

</div>
<?php endif; ?>
```

**Minimal pagination button CSS** (add to your `<style>` block):
```css
.pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 34px; height: 34px; padding: 0 0.4rem;
    border: 1px solid #e2e8f0; border-radius: 4px;
    font-size: 0.82rem; font-weight: 600;
    color: var(--text-secondary); text-decoration: none;
    background: #fff; transition: all 0.15s;
}
.pg-btn:hover  { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.04); }
.pg-btn.active { background: var(--blue); color: #fff; border-color: var(--blue); }
```

---

### Scenario B — AJAX Pagination (Grid-Only Reload)

**What happens:** Clicking page 2 fires a `fetch()` request. Only the **card grid** replaces its content — the header, KPI cards, tabs, and pagination controls stay in place.

This is more complex because you now have **two types of skeleton**:

| When | What to skeleton | How |
|---|---|---|
| Initial page load | Full page (header + KPIs + cards + pagination) | Full `#sk-screen` (same as always) |
| Each pagination click | Grid area only | Inject a partial skeleton into the grid container, then replace with real data |

---

#### HTML — Mark the swappable region

Give the card grid container a stable ID so JS can target it:

```html
<!-- Inside #page-content, in your tab-content / sales-grid section -->
<div id="results-region">
    <div class="sales-grid" id="salesGrid">
        <?php foreach ($rows as $row): ?>
            <!-- ...existing PHP card rendering... -->
        <?php endforeach; ?>
    </div>
    <div id="paginationBar">
        <!-- ...existing PHP pagination bar rendering... -->
    </div>
</div>
```

---

#### CSS — Partial skeleton overlay for pagination clicks

Add this to your `<style>` block. This creates a shimmer overlay that covers only the grid, not the whole page:

```css
/* Grid-area skeleton used during AJAX pagination transitions */
#results-region {
    position: relative; /* needed for the overlay to position correctly */
    min-height: 200px;  /* prevents region from collapsing during load */
}
.sk-grid-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.88);
    z-index: 10;
    display: none;
    flex-direction: column;
    gap: 1.25rem;
    padding: 1.5rem;
    backdrop-filter: blur(1px);
    pointer-events: all; /* block clicks during load */
    border-radius: 4px;
}
.sk-grid-overlay.show {
    display: flex;
}
```

---

#### JS — AJAX pagination fetch with partial skeleton

Replace your pagination link `href` clicks with this JS pattern. Add it inside your existing `<script>` block or the hydration script:

```javascript
// ── AJAX PAGINATION with partial skeleton ──────────────────────────
(function () {

    // The container whose content gets replaced
    var region = document.getElementById('results-region');
    if (!region) return;

    // Partial skeleton HTML to inject while fetching
    // (mirrors 3 card rows; adjust to match your perPage count)
    var GRID_SKELETON_HTML = `
        <div class="sk-grid-overlay show" id="ajaxSkOverlay">
            ${[1,2,3].map(() => `
                <div class="sk-sale-card" style="border-radius:4px;overflow:hidden;">
                    <div class="sk-card-img sk-shimmer"></div>
                    <div class="sk-card-body">
                        <div class="sk-line sk-shimmer" style="width:80%;height:16px;margin-bottom:8px;"></div>
                        <div class="sk-line sk-shimmer" style="width:52%;height:12px;margin-bottom:12px;"></div>
                        <div style="display:flex;gap:6px;margin-bottom:12px;">
                            <div class="sk-shimmer" style="width:82px;height:10px;border-radius:3px;"></div>
                            <div class="sk-shimmer" style="width:95px;height:10px;border-radius:3px;"></div>
                        </div>
                    </div>
                    <div class="sk-card-footer">
                        <div class="sk-line sk-shimmer" style="width:100%;height:34px;border-radius:4px;"></div>
                    </div>
                </div>
            `).join('')}
        </div>
    `;

    // Intercept clicks on pagination links inside #results-region
    document.addEventListener('click', function (e) {
        var link = e.target.closest('#paginationBar a.pg-btn');
        if (!link) return;
        e.preventDefault();

        var url = link.href;

        // 1. Show partial skeleton over the grid region
        var overlay = document.createElement('div');
        overlay.innerHTML = GRID_SKELETON_HTML;
        region.style.position = 'relative';
        region.appendChild(overlay.firstElementChild);

        // 2. Scroll to top of region smoothly
        region.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // 3. Fetch the new page (requests full HTML, extracts the region)
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                // Parse the response and extract the updated results-region
                var parser  = new DOMParser();
                var doc     = parser.parseFromString(html, 'text/html');
                var newRegion = doc.getElementById('results-region');

                if (newRegion) {
                    // Cross-fade: fade old out, swap, fade new in
                    region.style.opacity = '0';
                    region.style.transition = 'opacity 0.2s ease';

                    setTimeout(function () {
                        region.innerHTML = newRegion.innerHTML;
                        region.style.opacity = '0';
                        requestAnimationFrame(function () {
                            region.style.opacity = '1';
                        });
                        // Update browser URL without reload
                        history.pushState(null, '', url);
                    }, 200);
                }
            })
            .catch(function () {
                // On network error, fall back to full page navigation
                window.location.href = url;
            });
    });

}());
```

---

#### Sequence for AJAX pagination

```
User clicks page 2
      │
      ├── JS intercepts click, prevents default
      ├── sk-grid-overlay appears over #results-region (shimmer)
      ├── fetch(?page=2) fires in background
      ├── Response arrives → DOMParser extracts #results-region HTML
      ├── region fades out (0.2s)
      ├── region.innerHTML swapped with new content
      ├── region fades in (0.2s)
      ├── history.pushState updates the URL bar
      └── overlay removed automatically (was inside old innerHTML)
```

---

#### When to use Scenario A vs Scenario B

| | Scenario A (Server-side) | Scenario B (AJAX) |
|---|---|---|
| **Best for** | Most admin pages | Pages with heavy JS state (filters, sort, search that already uses JS) |
| **Complexity** | Simple — no extra JS | Medium — requires fetch + DOM extraction |
| **URL updates** | Native browser back/forward works | Needs `history.pushState` |
| **SEO** | ✅ Full page, crawlable | ❌ Dynamic — not crawlable without extra work |
| **Recommendation** | **Use this by default** | Only use when avoiding full-reload is a strict UX requirement |

> **Recommendation for this system:** Use **Scenario A** (server-side) for all admin pages. It's simpler, SEO-friendly, and the skeleton screen already makes it feel instant. Scenario B is only worth the complexity on very heavy pages where re-rendering the full page header/sidebar on every click is noticeably slow.

---

## 8. Do's and Don'ts

| ✅ Do | ❌ Don't |
|---|---|
| Match skeleton dimensions closely to the real content (same grid columns, same card heights) | Make skeleton cards much taller or wider than real cards — causes jarring shift |
| Use 3–4 skeleton cards in a grid even if only 1 real card exists | Show 0 skeleton cards — user sees nothing and wonders if page loaded |
| Keep `#sk-screen` outside `#page-content` as a sibling | Nest `#sk-screen` inside `#page-content` — skeleton would be hidden too early |
| Add `aria-hidden="true"` to `#sk-screen` | Let screen-readers announce skeleton placeholders as content |
| Always include the `<noscript>` fallback | Forget it — users with JS disabled get a permanently blank page |
| Place the hydration `<script>` AFTER the existing big `<script>` block near `</body>` | Put it in `<head>` — the DOM IDs don't exist yet at that point |
| Use `window 'load'` trigger in the hydration script | Use `DOMContentLoaded` — fires too early on PHP pages, skeleton just flashes |
| Move toast notifications to `skeleton:hydrated` event | Leave toasts on `DOMContentLoaded` — they fire while skeleton is still visible |
| Test by throttling CPU/Network in Chrome DevTools → Performance tab | Only test on a fast local machine — skeleton is most visible on slow connections |

---

## 9. Checklist

Use this when applying to a new page:

- [ ] **CSS** — Skeleton CSS block added before `</style>`
- [ ] **CSS** — `.sk-kpi-grid` `grid-template-columns` matches the real KPI count
- [ ] **CSS** — `.sk-sales-grid` `minmax()` value matches the real card grid
- [ ] **CSS** — Responsive breakpoints updated to match page
- [ ] **HTML** — `<noscript>` fallback present right after `<div class="admin-content">`
- [ ] **HTML** — `<div id="sk-screen" role="presentation" aria-hidden="true">` opened
- [ ] **HTML** — Skeleton components added for all major page sections
- [ ] **HTML** — `</div><!-- /#sk-screen -->` closed
- [ ] **HTML** — `<div id="page-content">` opened
- [ ] **HTML** — All existing PHP content untouched inside `#page-content`
- [ ] **HTML** — `</div><!-- /#page-content -->` closed before admin-content closes
- [ ] **JS** — Hydration script added as a separate `<script>` block before `</body>`
- [ ] **JS** — Hydration uses `window 'load'` trigger (NOT `DOMContentLoaded`)
- [ ] **JS** — Toast/notification scripts changed from `DOMContentLoaded` to `skeleton:hydrated`
- [ ] **Test** — Open page in browser: skeleton appears then fades out
- [ ] **Test** — Open page with JS disabled (`<noscript>`): content visible immediately
- [ ] **Test** — Throttle CPU in DevTools: skeleton visible for ~1–2s then cross-fade

**Additional checks for pages with pagination:**
- [ ] **CSS** — `#results-region { position: relative; }` added if using AJAX pagination
- [ ] **CSS** — `.sk-grid-overlay` and `.sk-grid-overlay.show` styles added for AJAX pagination
- [ ] **HTML** — Pagination bar skeleton included in `#sk-screen` (matching real pagination bar position)
- [ ] **HTML** — Real grid container has `id="results-region"` and pagination has `id="paginationBar"` (AJAX only)
- [ ] **JS** — AJAX pagination fetch script added (Scenario B only)
- [ ] **Test** — Click page 2: grid shows shimmer overlay → new cards fade in
- [ ] **Test** — Browser back button: previous page shows correctly (history.pushState works)
- [ ] **Test** — Network failure during pagination click: falls back to full page navigation

---

## 10. Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| Skeleton shows but real content never appears | `#page-content` ID is missing or misspelled | Check the HTML, ensure `id="page-content"` is on the wrapper div |
| Real content shows instantly (no skeleton) | `#sk-screen` ID is missing | Check the skeleton div has `id="sk-screen"` |
| Page is blank with JS disabled | Missing `<noscript>` block | Add the `<noscript>` CSS fallback after `<div class="admin-content">` |
| Layout jump when skeleton disappears | Skeleton dimensions don't match real content | Adjust `height`, `minmax()`, and grid columns in skeleton CSS to match real layout |
| Skeleton never removed from DOM | Hydration script placed inside the existing `<script>` block, not after it | Move the hydration `<script>` to after the closing `</script>` of the existing JS |
| Shimmer not visible / elements look blank | `.sk-shimmer` class missing on skeleton elements | Ensure every placeholder div has both `sk-shimmer` and a size class / inline style |
| Content appears but partially transparent | `pc.style.opacity` still `'0'` | Check there's no other CSS rule on `#page-content` overriding opacity |
| Everything works but there is a white flash first | External CSS (Bootstrap/FontAwesome) is render-blocking | Consider `<link rel="preload">` for large CSS; this is a separate optimization |
| **Skeleton flashes briefly** then disappears too fast | Hydration uses `DOMContentLoaded` instead of `window 'load'` | Change trigger to `window.addEventListener('load', scheduleHydration)` — see Part C. PHP pages parse DOM instantly; real delay is external resources. |
| **Toasts fire while skeleton is visible** | Toast listener is on `DOMContentLoaded` | Change to `document.addEventListener('skeleton:hydrated', ...)` — see Part D |
| **Pagination click shows full skeleton** instead of partial | You are on Scenario A (server-side) — this is correct behaviour | No fix needed; Scenario A always does a full page load + full skeleton |
| **AJAX pagination**: overlay appears but never disappears | `fetch()` failed silently | Add `.catch` fallback — see Scenario B JS. Check network tab for errors |
| **AJAX pagination**: clicking page 2, then browser Back shows blank `#results-region` | Missing `history.pushState` or not restoring state on `popstate` | Add `window.addEventListener('popstate', ...)` to re-fetch on back navigation |
| **AJAX pagination**: pagination bar itself doesn't update (still shows「Page 1 active」) | Pagination bar HTML is outside `#results-region` | Move the `<div id="paginationBar">` inside `#results-region` so it gets swapped with the new content |
| **Partial skeleton**: grid collapses to 0 height during load | `#results-region` has no `min-height` | Add `min-height: 200px` to `#results-region` CSS |
| **Partial skeleton**: overlay doesn't cover the grid | `#results-region` is missing `position: relative` | Add `position: relative` to `#results-region` CSS |

---

*Reference implementation: [`admin_property_sale_approvals.php`](../admin_property_sale_approvals.php)*

---

## 11. Agent Portal — Dark Theme Variant

All pages inside `agent_pages/` use a **black + gold + blue dark theme**. The skeleton must match — dark card backgrounds, barely-visible shimmer on dark surfaces, and gold/blue accent top bars.

Everything from Sections 1–10 still applies. This section documents **only what is different**.

---

### 11.1 — What Changes vs. the Admin (Light) Theme

| Property | Admin (Light) | Agent Portal (Dark) |
|---|---|---|
| `body` background | `#f5f5f5` / white | `#0a0a0a` (deep black) |
| Skeleton card background | `#fff` | `linear-gradient(135deg, rgba(26,26,26,0.8), rgba(10,10,10,0.9))` |
| Shimmer gradient | `#f0f0f0 → #e8e8e8 → #f0f0f0` | `rgba(white,0.03) → rgba(white,0.06) → rgba(white,0.03)` |
| Skeleton card border | `rgba(37,99,235,0.1)` | `rgba(37,99,235,0.15)` |
| Top accent bar | `gold → blue` linear gradient | Same `gold → blue` |
| Content container class | `.admin-content` | `.dashboard-content` |
| Noscript fallback targets | Same `#sk-screen` / `#page-content` | Same |
| JS hydration script | Identical | Identical |

---

### 11.2 — Part A (CSS) — Dark Shimmer + Dark Cards

Replace the light shimmer and card backgrounds with the dark equivalents. Paste this block before `</style>` **instead of** the light Part A CSS.

```css
/* ================================================================
   SKELETON SCREEN SYSTEM — Dark Agent Portal Theme
   CSR / Progressive Hydration
   ================================================================ */

/* ── Core shimmer animation (dark surfaces) ── */
@keyframes sk-shimmer {
    0%   { background-position: -800px 0; }
    100% { background-position:  800px 0; }
}
.sk-shimmer {
    /* Very subtle white shimmer — visible on dark backgrounds */
    background: linear-gradient(
        90deg,
        rgba(255,255,255,0.03) 25%,
        rgba(255,255,255,0.06) 50%,
        rgba(255,255,255,0.03) 75%
    );
    background-size: 1600px 100%;
    animation: sk-shimmer 1.6s ease-in-out infinite;
    border-radius: 4px;
}

/* ── Real content: hidden until hydration reveals it ── */
#page-content { display: none; }

/* ── Welcome hero skeleton (matches .welcome-hero) ── */
.sk-welcome-hero {
    background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
    border: 1px solid rgba(37,99,235,0.15);
    border-radius: 4px;
    padding: 2.5rem 3rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}
.sk-welcome-hero::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #d4af37, #2563eb, transparent);
}

/* ── KPI grid ── */
.sk-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.sk-kpi-card {
    background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
    border: 1px solid rgba(37,99,235,0.15);
    border-radius: 4px;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
}
.sk-kpi-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
.sk-kpi-icon   { width: 48px; height: 48px; border-radius: 4px; flex-shrink: 0; }

/* ── Panel (matches .panel) ── */
.sk-panel {
    background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
    border: 1px solid rgba(37,99,235,0.15);
    border-radius: 4px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.sk-panel-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid rgba(37,99,235,0.1);
}
.sk-panel-body { padding: 1.5rem; }

/* ── Quick action grid ── */
.sk-qa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
.sk-qa-btn {
    background: linear-gradient(135deg, rgba(26,26,26,0.9) 0%, rgba(15,15,15,0.95) 100%);
    border: 1px solid rgba(212,175,55,0.15);
    border-radius: 4px; padding: 1.5rem 1rem;
    display: flex; flex-direction: column; align-items: center; gap: 0.75rem;
}

/* ── Tour / list item rows ── */
.sk-tour-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1rem; border-radius: 4px; border: 1px solid rgba(37,99,235,0.08);
    margin-bottom: 0.75rem;
}
.sk-tour-item:last-child { margin-bottom: 0; }

/* ── Property card grid (2-col) ── */
.sk-prop-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
.sk-prop-card { background: rgba(26,26,26,0.6); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; }

/* ── Portfolio stat rows ── */
.sk-stat-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.75rem 0; border-bottom: 1px solid rgba(37,99,235,0.08);
}
.sk-stat-row:last-child { border-bottom: none; }

/* ── Top-performing rows ── */
.sk-top-prop-item { display: flex; gap: 1rem; padding: 1rem; border-radius: 4px; border: 1px solid rgba(37,99,235,0.08); margin-bottom: 0.75rem; }
.sk-top-prop-item:last-child { margin-bottom: 0; }

/* ── Activity timeline rows ── */
.sk-activity-item { display: flex; gap: 1rem; padding: 0.75rem 0; border-bottom: 1px solid rgba(37,99,235,0.06); }
.sk-activity-item:last-child { border-bottom: none; }

.sk-line { display: block; border-radius: 4px; }

/* ── Responsive ── */
@media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px) {
    .sk-kpi-grid     { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .sk-qa-grid      { grid-template-columns: repeat(2, 1fr); }
    .sk-welcome-hero { padding: 1.5rem; }
    .sk-prop-grid    { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .sk-kpi-grid { grid-template-columns: 1fr; }
    .sk-qa-grid  { grid-template-columns: 1fr 1fr; }
}
```

---

### 11.3 — Part B (HTML) — Container Class

Agent portal pages use `.dashboard-content` instead of `.admin-content`. The `#sk-screen` / `#page-content` IDs are unchanged:

```html
<div class="dashboard-content">

    <noscript><style>
        #sk-screen    { display: none !important; }
        #page-content { display: block !important; opacity: 1 !important; }
    </style></noscript>

    <div id="sk-screen" role="presentation" aria-hidden="true">
        <!-- ← Dark skeleton components from Section 11.4 -->
    </div><!-- /#sk-screen -->

    <div id="page-content">
        <!-- ← All existing PHP content stays here, unchanged -->
    </div><!-- /#page-content -->

</div><!-- /.dashboard-content -->
```

---

### 11.4 — Dark Component Recipes

Use these inside `#sk-screen` for agent portal pages.

#### Welcome Hero

```html
<div class="sk-welcome-hero">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem;">
        <div>
            <div class="sk-line sk-shimmer" style="width:320px;height:28px;margin-bottom:10px;"></div>
            <div class="sk-line sk-shimmer" style="width:280px;height:14px;margin-bottom:8px;"></div>
            <div class="sk-line sk-shimmer" style="width:240px;height:12px;"></div>
        </div>
        <div style="display:flex;align-items:center;gap:1rem;">
            <div>
                <div class="sk-line sk-shimmer" style="width:140px;height:18px;margin-bottom:6px;margin-left:auto;"></div>
                <div class="sk-line sk-shimmer" style="width:80px;height:10px;margin-left:auto;"></div>
            </div>
            <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
        </div>
    </div>
</div>
```

#### KPI Grid (4 cards)

```html
<div class="sk-kpi-grid">
    <!-- Repeat the block below once per KPI card -->
    <div class="sk-kpi-card">
        <div class="sk-kpi-header">
            <div class="sk-line sk-shimmer" style="width:90px;height:10px;"></div>
            <div class="sk-kpi-icon sk-shimmer"></div>
        </div>
        <div class="sk-line sk-shimmer" style="width:60px;height:28px;margin-bottom:8px;"></div>
        <div class="sk-line sk-shimmer" style="width:130px;height:11px;"></div>
    </div>
    <!-- × 3 more -->
</div>
```

#### Generic Panel

```html
<div class="sk-panel">
    <div class="sk-panel-header">
        <div class="sk-line sk-shimmer" style="width:140px;height:16px;"></div>
        <div class="sk-line sk-shimmer" style="width:65px;height:13px;"></div>
    </div>
    <div class="sk-panel-body">
        <!-- Insert tour rows, prop grid, stat rows, etc. here -->
    </div>
</div>
```

#### Tour Row

```html
<div class="sk-tour-item">
    <div style="display:flex;gap:1rem;align-items:center;flex:1;">
        <div class="sk-shimmer" style="width:52px;height:52px;border-radius:4px;flex-shrink:0;"></div>
        <div>
            <div class="sk-line sk-shimmer" style="width:180px;height:14px;margin-bottom:6px;"></div>
            <div class="sk-line sk-shimmer" style="width:140px;height:11px;"></div>
        </div>
    </div>
    <div style="display:flex;gap:0.5rem;">
        <div class="sk-shimmer" style="width:75px;height:26px;border-radius:2px;"></div>
        <div class="sk-shimmer" style="width:70px;height:26px;border-radius:2px;"></div>
    </div>
</div>
```

#### Property Card Grid (2-col)

```html
<div class="sk-prop-grid">
    <div class="sk-prop-card">
        <div class="sk-shimmer" style="width:100%;height:180px;"></div>
        <div style="padding:1.25rem;">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                <div class="sk-line sk-shimmer" style="width:100px;height:18px;"></div>
                <div class="sk-shimmer" style="width:55px;height:20px;border-radius:2px;"></div>
            </div>
            <div class="sk-line sk-shimmer" style="width:85%;height:12px;margin-bottom:10px;"></div>
            <div style="display:flex;gap:1rem;">
                <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                <div class="sk-line sk-shimmer" style="width:60px;height:11px;"></div>
            </div>
        </div>
    </div>
    <!-- × 1 more -->
</div>
```

#### Portfolio Stat Rows

```html
<div class="sk-stat-row">
    <div class="sk-line sk-shimmer" style="width:90px;height:12px;"></div>
    <div class="sk-line sk-shimmer" style="width:30px;height:14px;"></div>
</div>
<!-- Repeat for each stat (7 rows on agent dashboard) -->
```

#### Top-Performing Property Rows

```html
<div class="sk-top-prop-item">
    <div class="sk-shimmer" style="width:80px;height:60px;border-radius:4px;flex-shrink:0;"></div>
    <div style="flex:1;">
        <div class="sk-line sk-shimmer" style="width:85%;height:13px;margin-bottom:6px;"></div>
        <div class="sk-line sk-shimmer" style="width:60%;height:11px;margin-bottom:6px;"></div>
        <div style="display:flex;gap:0.75rem;">
            <div class="sk-line sk-shimmer" style="width:40px;height:10px;"></div>
            <div class="sk-line sk-shimmer" style="width:35px;height:10px;"></div>
            <div class="sk-line sk-shimmer" style="width:65px;height:10px;"></div>
        </div>
    </div>
</div>
<!-- × 3 total -->
```

#### Activity Timeline Rows

```html
<div class="sk-activity-item">
    <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
    <div style="flex:1;">
        <div class="sk-line sk-shimmer" style="width:140px;height:13px;margin-bottom:5px;"></div>
        <div class="sk-line sk-shimmer" style="width:180px;height:11px;margin-bottom:4px;"></div>
        <div class="sk-line sk-shimmer" style="width:120px;height:10px;"></div>
    </div>
</div>
<!-- × 4 total -->
```

---

### 11.5 — Part C (JS) — Hydration Script

The script is **identical** to the admin version (Section 4, Part C). Copy it verbatim. Only the comment label differs:

```html
<!-- SKELETON HYDRATION — Progressive Content Reveal (Agent Portal) -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart = Date.now();
    /* ... identical body to admin version ... */
}());
</script>
```

---

### 11.6 — Step-by-Step Differences for Agent Portal

| Step | Admin | Agent Portal |
|---|---|---|
| Step 1 — CSS | Light Part A (Section 4) | **Dark Part A** (Section 11.2) |
| Step 2 — Container | `<div class="admin-content">` | `<div class="dashboard-content">` |
| Step 4 — Skeleton HTML | Light recipes (Section 6) | **Dark recipes** (Section 11.4) |
| Step 7 — Close wrapper | Before `</div><!-- /.admin-content -->` | Before `</div><!-- /.dashboard-content -->` |
| Steps 3, 5, 6, 8, 9 | Identical | Identical |

---

### 11.7 — Agent Portal Checklist

- [ ] **CSS** — Dark shimmer (`rgba(255,255,255,0.03→0.06→0.03)`) used instead of light `#f0f0f0`
- [ ] **CSS** — Skeleton card backgrounds use `rgba(26,26,26)` / `rgba(10,10,10)` not `#fff`
- [ ] **CSS** — `.sk-kpi-grid` column count matches real KPI count
- [ ] **CSS** — Responsive breakpoints match agent page
- [ ] **HTML** — `<noscript>` fallback after `<div class="dashboard-content">`
- [ ] **HTML** — `<div id="sk-screen" role="presentation" aria-hidden="true">` present
- [ ] **HTML** — Dark component recipes used for all major sections
- [ ] **HTML** — `#sk-screen` and `#page-content` are siblings inside `.dashboard-content`
- [ ] **JS** — Hydration script is a separate `<script>` block after the main script, before `</body>`
- [ ] **JS** — `window 'load'` trigger (not `DOMContentLoaded`)
- [ ] **JS** — KPI counter animations and IntersectionObserver on `skeleton:hydrated`
- [ ] **JS** — Toast notifications staggered with `setTimeout` inside `skeleton:hydrated`
- [ ] **Test** — Dark skeleton shapes visible on black background (not invisible)
- [ ] **Test** — Shimmer animation perceptible on dark surface
- [ ] **Test** — No white flash between skeleton fade-out and real content fade-in
- [ ] **Test** — JS disabled: content shows immediately via `<noscript>`

---

*Reference implementations:*
- Admin (light): [`admin_property_sale_approvals.php`](../admin_property_sale_approvals.php)
- Agent Portal (dark): [`agent_pages/agent_dashboard.php`](../agent_pages/agent_dashboard.php)
