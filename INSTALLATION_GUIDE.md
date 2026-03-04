# HomeEstate Realty — Self-Hosted Package Installation Guide

> **Goal:** Replace every CDN link with locally served files so the system loads instantly on XAMPP — no internet required, no lag during your capstone demo, and zero broken modals from flaky Wi-Fi.

---

## Table of Contents

| Phase | Description | Status |
|-------|-------------|--------|
| [Phase 0](#phase-0--prerequisites) | Prerequisites & Pre-flight Checks | ☐ |
| [Phase 1](#phase-1--create-the-folder-structure) | Create the Folder Structure | ☐ |
| [Phase 2](#phase-2--download-bootstrap-533) | Download Bootstrap 5.3.3 | ☐ |
| [Phase 3](#phase-3--download-bootstrap-icons-1113) | Download Bootstrap Icons 1.11.3 | ☐ |
| [Phase 4](#phase-4--download-font-awesome-650) | Download Font Awesome 6.5.0 | ☐ |
| [Phase 5](#phase-5--download-chartjs--adapters) | Download Chart.js & Adapters | ☐ |
| [Phase 6](#phase-6--download-report-export-libraries) | Download Report-Export Libraries (jsPDF, SheetJS) | ☐ |
| [Phase 7](#phase-7--self-host-google-fonts-inter) | Self-Host Google Fonts (Inter) | ☐ |
| [Phase 7B](#phase-7b--download-flaticon-uicons-260) | Download Flaticon UIcons 2.6.0 | ☐ |
| [Phase 7C](#phase-7c--replace-viaplaceholdercom-with-local-svg-placeholders) | Replace via.placeholder.com Placeholders | ☐ |
| [Phase 8](#phase-8--create-the-path-helper) | Create the Path Helper (`BASE_URL`) | ☐ |
| [Phase 9](#phase-9--update-all-php-files-to-use-local-assets) | Update All PHP Files to Use Local Assets | ☐ |
| [Phase 10](#phase-10--verify--test) | Verify & Test | ☐ |
| [Phase 11](#phase-11--bonus--shimmer-skeleton-loader) | Bonus — Shimmer / Skeleton Loader | ☐ |

> **Tip:** Tick each checkbox (☐ → ☑) as you finish a phase. If something breaks, scroll straight to the last checked phase and continue from there.

---

## Dependency Inventory

Below is every external resource detected in the project. All of these will be self-hosted after following this guide.

| # | Library | Version | Type | CDN Currently Used |
|---|---------|---------|------|--------------------|
| 1 | Bootstrap | 5.3.3 | CSS + JS | `cdn.jsdelivr.net/npm/bootstrap@5.3.3` |
| 2 | Bootstrap Icons | 1.11.3 (& 1.13.1 in `add_agent.php`) | Icon Font/CSS | `cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3` |
| 3 | Font Awesome | 6.5.0 | Icon Font/CSS | `cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0` |
| 4 | Chart.js | 4.4.7 (also referenced as 4.4.1 and latest) | JS | `cdn.jsdelivr.net/npm/chart.js` |
| 5 | chartjs-adapter-date-fns | 3.0.0 | JS | `cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0` |
| 6 | jsPDF | 2.5.1 | JS | `cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1` |
| 7 | jsPDF-AutoTable | 3.8.2 | JS | `cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2` |
| 8 | SheetJS (xlsx) | 0.18.5 | JS | `cdn.jsdelivr.net/npm/xlsx@0.18.5` |
| 9 | Google Fonts — Inter | Weights 300-800 | Web Font | `fonts.googleapis.com` |
| 10 | Flaticon UIcons (Regular Straight) | 2.6.0 | Icon Font/CSS | `cdn-uicons.flaticon.com/2.6.0` |
| 11 | Placeholder Images (via.placeholder.com) | N/A | PNG (runtime) | `via.placeholder.com` |
| 12 | PHPMailer | 6.10.0 | PHP (server-side) | Already bundled in `PHPMailer/` — **no action needed** |

---

## Phase 0 — Prerequisites

Before you start, confirm the following:

- [x] XAMPP installed and Apache + MySQL running
- [ ] PHP 8.x (run `php -v` in terminal to confirm)
- [ ] `curl` available in your terminal (comes with Git Bash / Windows 10+)
- [ ] Your project lives at `C:\xampp\htdocs\capstoneSystem\`

### Quick test — open your VS Code terminal and run:

```powershell
php -v
curl --version
```

If both return version info, you're good. If `curl` is missing, install [Git for Windows](https://git-scm.com/) which ships with curl, or use PowerShell's `Invoke-WebRequest` (alternatives given below).

---

## Phase 1 — Create the Folder Structure

Run this **single command** in your VS Code terminal (PowerShell) from the project root:

```powershell
cd C:\xampp\htdocs\capstoneSystem

# Create all asset directories in one go
New-Item -ItemType Directory -Force -Path `
  "assets\css", `
  "assets\js", `
  "assets\fonts\inter", `
  "assets\fonts\bootstrap-icons", `
  "assets\webfonts"
```

### Expected result:

```
capstoneSystem/
├── assets/
│   ├── css/          ← Bootstrap CSS, Bootstrap Icons CSS, Font Awesome CSS, Inter font CSS
│   ├── js/           ← Bootstrap JS, Chart.js, jsPDF, SheetJS
│   ├── fonts/
│   │   ├── inter/            ← Inter .woff2 files
│   │   └── bootstrap-icons/  ← Bootstrap Icons .woff / .woff2
│   └── webfonts/     ← Font Awesome .woff2 / .ttf files
images/
├── placeholder.svg           ← Local fallback for missing property images (Phase 7C)
└── placeholder-avatar.svg    ← Local fallback for missing profile pictures (Phase 7C)
```

> **Checkpoint:** Run `Get-ChildItem assets -Recurse` and verify the folders exist before moving on.

---

## Phase 2 — Download Bootstrap 5.3.3

### CSS

```powershell
curl -L -o "assets\css\bootstrap.min.css" "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
```

### JS (Bundle — includes Popper.js)

```powershell
curl -L -o "assets\js\bootstrap.bundle.min.js" "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
```

### Verify

```powershell
# Should show ~230 KB for CSS and ~80 KB for JS
Get-Item "assets\css\bootstrap.min.css", "assets\js\bootstrap.bundle.min.js" | Select-Object Name, Length
```

> **Troubleshoot:** If the file is 0 bytes or an HTML error page, the URL may have changed. Visit https://getbootstrap.com/docs/5.3/getting-started/download/ and grab the latest 5.3.x direct links.

---

## Phase 3 — Download Bootstrap Icons 1.11.3

Bootstrap Icons requires a CSS file **plus** font files (`.woff`, `.woff2`).

### Step 3a — Download the CSS

```powershell
curl -L -o "assets\css\bootstrap-icons.min.css" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
```

### Step 3b — Download the font files

```powershell
curl -L -o "assets\fonts\bootstrap-icons\bootstrap-icons.woff2" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"

curl -L -o "assets\fonts\bootstrap-icons\bootstrap-icons.woff" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff"
```

### Step 3c — Fix font paths inside the CSS

Open `assets/css/bootstrap-icons.min.css` and find the `@font-face` `url(...)` references. They will point to `./fonts/bootstrap-icons.woff2`. You need to update them to match your folder structure:

**Find:**
```css
url("./fonts/bootstrap-icons.woff2")
```
**Replace with:**
```css
url("../fonts/bootstrap-icons/bootstrap-icons.woff2")
```

Do the same for the `.woff` path. You can do this in one command:

```powershell
(Get-Content "assets\css\bootstrap-icons.min.css" -Raw) `
  -replace '\./fonts/bootstrap-icons', '../fonts/bootstrap-icons/bootstrap-icons' |
  Set-Content "assets\css\bootstrap-icons.min.css" -NoNewline
```

### Verify

```powershell
Select-String -Path "assets\css\bootstrap-icons.min.css" -Pattern "fonts/bootstrap-icons/"
```

Should show the updated path(s).

> **Note:** `add_agent.php` references version **1.13.1**. The self-hosted 1.11.3 file includes all icons used in the project. If you need 1.13.1 features later, repeat this phase with the newer version URL.

---

## Phase 4 — Download Font Awesome 6.5.0

Font Awesome is more complex because the CSS references webfont files. The easiest approach is to download the **official release ZIP** and extract what you need.

### Option A — Download individual files (recommended for minimal footprint)

```powershell
# CSS (all.min.css)
curl -L -o "assets\css\fontawesome-all.min.css" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"

# Webfont files used by all.min.css (fa-solid, fa-regular, fa-brands)
curl -L -o "assets\webfonts\fa-solid-900.woff2"   "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-solid-900.woff2"
curl -L -o "assets\webfonts\fa-solid-900.ttf"      "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-solid-900.ttf"
curl -L -o "assets\webfonts\fa-regular-400.woff2"  "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-regular-400.woff2"
curl -L -o "assets\webfonts\fa-regular-400.ttf"    "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-regular-400.ttf"
curl -L -o "assets\webfonts\fa-brands-400.woff2"   "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-brands-400.woff2"
curl -L -o "assets\webfonts\fa-brands-400.ttf"     "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-brands-400.ttf"
```

### Fix font paths in the CSS

Font Awesome's `all.min.css` expects `../webfonts/` relative to the CSS folder. Since our CSS lives in `assets/css/` and webfonts in `assets/webfonts/`, the `../webfonts/` path is already correct. **No change needed** if your structure matches.

### Verify

```powershell
Get-ChildItem "assets\webfonts" | Select-Object Name, Length
# Should list 6 files (3 × .woff2, 3 × .ttf)
```

### Option B — Download the full ZIP (alternative)

```powershell
curl -L -o "fa.zip" "https://use.fontawesome.com/releases/v6.5.0/fontawesome-free-6.5.0-web.zip"
Expand-Archive "fa.zip" -DestinationPath "fa_temp" -Force

# Copy only what you need
Copy-Item "fa_temp\fontawesome-free-6.5.0-web\css\all.min.css" "assets\css\fontawesome-all.min.css"
Copy-Item "fa_temp\fontawesome-free-6.5.0-web\webfonts\*" "assets\webfonts\" -Force

# Cleanup
Remove-Item "fa.zip", "fa_temp" -Recurse -Force
```

---

## Phase 5 — Download Chart.js & Adapters

```powershell
# Chart.js 4.4.7 (UMD build — works with <script> tags)
curl -L -o "assets\js\chart.umd.min.js" "https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"

# chartjs-adapter-date-fns 3.0.0
curl -L -o "assets\js\chartjs-adapter-date-fns.bundle.min.js" "https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"
```

### Verify

```powershell
Get-Item "assets\js\chart.umd.min.js", "assets\js\chartjs-adapter-date-fns.bundle.min.js" | Select-Object Name, Length
```

---

## Phase 6 — Download Report-Export Libraries

These are used in `reports.php` for PDF and Excel export.

```powershell
# jsPDF 2.5.1
curl -L -o "assets\js\jspdf.umd.min.js" "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"

# jsPDF AutoTable plugin 3.8.2
curl -L -o "assets\js\jspdf.plugin.autotable.min.js" "https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"

# SheetJS (xlsx) 0.18.5
curl -L -o "assets\js\xlsx.full.min.js" "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
```

### Verify

```powershell
Get-Item "assets\js\jspdf.umd.min.js", "assets\js\jspdf.plugin.autotable.min.js", "assets\js\xlsx.full.min.js" | Select-Object Name, Length
```

---

## Phase 7 — Self-Host Google Fonts (Inter)

Google Fonts Inter is loaded on **every page**. Self-hosting it eliminates a render-blocking network request.

### Step 7a — Download the `.woff2` files

Visit [google-webfonts-helper](https://gwfh.mranftl.com/fonts/inter?subsets=latin) or download directly:

```powershell
# Inter 300 (Light)
curl -L -o "assets\fonts\inter\inter-v18-latin-300.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuOKfAZ9hjQ.woff2"

# Inter 400 (Regular)
curl -L -o "assets\fonts\inter\inter-v18-latin-regular.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hjQ.woff2"

# Inter 500 (Medium)
curl -L -o "assets\fonts\inter\inter-v18-latin-500.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuI6fAZ9hjQ.woff2"

# Inter 600 (SemiBold)
curl -L -o "assets\fonts\inter\inter-v18-latin-600.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuGKYAZ9hjQ.woff2"

# Inter 700 (Bold)
curl -L -o "assets\fonts\inter\inter-v18-latin-700.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuFuYAZ9hjQ.woff2"

# Inter 800 (ExtraBold)
curl -L -o "assets\fonts\inter\inter-v18-latin-800.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuDyYAZ9hjQ.woff2"
```

### Step 7b — Create `assets/css/inter-font.css`

Create the file `assets/css/inter-font.css` with these `@font-face` declarations:

```css
/* Inter — Self-Hosted (Latin subset) */

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 300;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-300.woff2') format('woff2');
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-regular.woff2') format('woff2');
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-500.woff2') format('woff2');
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 600;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-600.woff2') format('woff2');
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-700.woff2') format('woff2');
}

@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 800;
  font-display: swap;
  src: url('../fonts/inter/inter-v18-latin-800.woff2') format('woff2');
}
```

### Verify

```powershell
Get-ChildItem "assets\fonts\inter" | Select-Object Name, Length
# Should list 6 .woff2 files
```

---

## Phase 7B — Download Flaticon UIcons 2.6.0

Your `admin_sidebar.php` uses **Flaticon UIcons Regular Straight** icons (e.g., `fi fi-rs-dashboard-monitor`, `fi fi-rs-apartment`, `fi fi-rs-bell`). This icon font is loaded from `cdn-uicons.flaticon.com`.

### Step 7B-a — Download the CSS

```powershell
# Create directory for UIcons fonts
New-Item -ItemType Directory -Force -Path "assets\fonts\uicons"

# Download the CSS
curl -L -o "assets\css\uicons-regular-straight.css" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/css/uicons-regular-straight.css"
```

### Step 7B-b — Download the font files

Flaticon UIcons uses `.woff` and `.woff2` font files. After downloading the CSS, inspect it to find the `@font-face` URLs:

```powershell
# Check what font files the CSS references
Select-String -Path "assets\css\uicons-regular-straight.css" -Pattern "url\("
```

The CSS typically references font files at a relative path. Download them:

```powershell
# Download both woff2 and woff formats
curl -L -o "assets\fonts\uicons\uicons-regular-straight.woff2" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/webfonts/uicons-regular-straight.woff2"
curl -L -o "assets\fonts\uicons\uicons-regular-straight.woff" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/webfonts/uicons-regular-straight.woff"
```

### Step 7B-c — Fix font paths in the CSS

Open `assets/css/uicons-regular-straight.css` and update the `@font-face` `url(...)` references to point to your local font folder:

```powershell
# Adjust the path from the original CDN relative path to your local structure
# The exact replacement depends on the content of the CSS - inspect first, then replace
# Typical pattern: ../webfonts/ -> ../fonts/uicons/
(Get-Content "assets\css\uicons-regular-straight.css" -Raw) `
  -replace '\.\./(webfonts|fonts)/', '../fonts/uicons/' |
  Set-Content "assets\css\uicons-regular-straight.css" -NoNewline
```

> **Note:** If you can't find the exact font file URLs, an alternative is to download the full Flaticon UIcons package from https://www.flaticon.com/uicons and extract the relevant files.

### Verify

```powershell
Get-Item "assets\css\uicons-regular-straight.css", "assets\fonts\uicons\*" | Select-Object Name, Length
```

### Where it's used

| File | Usage |
|------|-------|
| `admin_sidebar.php` | Sidebar navigation icons: `fi fi-rs-dashboard-monitor`, `fi fi-rs-apartment`, `fi fi-rs-employees`, `fi fi-rs-map-marker-home`, `fi fi-rs-sold-house`, `fi fi-rs-bell`, `fi fi-rs-chart-pie-simple-circle-dollar`, `fi fi-rs-sign-out-alt` |

---

## Phase 7C — Replace via.placeholder.com with Local SVG Placeholders

Your project uses `via.placeholder.com` as a fallback for missing profile pictures and property images across **~20 references** in many files. This is an external service that will fail offline.

### Where it's used

| File | What it does |
|------|--------------|
| `admin_navbar.php` | Admin avatar fallback |
| `admin_profile.php` | Admin profile picture fallback |
| `admin_property_card_template.php` | Property card "No Image" fallback |
| `agent_pages/agent_navbar.php` | Agent avatar fallback |
| `agent_pages/agent_profile.php` | Agent profile picture fallback (×3) |
| `agent_pages/property_card_template.php` | Property card "No Image" fallback |
| `view_property.php` | Property hero image fallback, agent avatar |
| `user_pages/property_details_backup.php` | Property gallery fallback, agent avatar (×2) |
| `user_pages/agent_profile.php` | Agent profile picture (×2) |
| `user_pages/agents.php` | Agent listing fallback |
| `add_agent.php` | Company logo, profile circle |

### Step 7C-a — Create a local placeholder image

Create a simple SVG placeholder. Create the file `images/placeholder.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
  <rect fill="#f0f2f5" width="400" height="300"/>
  <text x="50%" y="50%" font-family="Inter,Arial,sans-serif" font-size="18" fill="#94a3b8" dominant-baseline="middle" text-anchor="middle">No Image</text>
</svg>
```

And a profile-sized one at `images/placeholder-avatar.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
  <rect fill="#e2e8f0" width="200" height="200" rx="100"/>
  <text x="50%" y="50%" font-family="Inter,Arial,sans-serif" font-size="48" fill="#94a3b8" dominant-baseline="middle" text-anchor="middle">?</text>
</svg>
```

### Step 7C-b — Find and Replace all `via.placeholder.com` references

Use VS Code's **Find and Replace in Files** (`Ctrl+Shift+H`):

**For property/generic images — search in `*.php`:**

These will need to be handled case-by-case since URLs include different sizes and text parameters. The general approach:

| Find pattern | Replace with |
|---|---|
| `https://via.placeholder.com/1200x600?text=No+Image` | `<?= BASE_URL ?>images/placeholder.svg` |
| `https://via.placeholder.com/1920x1080?text=No+Images+Available` | `<?= BASE_URL ?>images/placeholder.svg` |
| `https://via.placeholder.com/400x280/1a1a1a/333333?text=No+Image` | `<?= BASE_URL ?>images/placeholder.svg` |
| `https://via.placeholder.com/400x280/f0f2f5/94a3b8?text=No+Image` | `<?= BASE_URL ?>images/placeholder.svg` |

**For profile/avatar images:**

| Find pattern | Replace with |
|---|---|
| `https://via.placeholder.com/200` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/150/...` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/100?text=Agent` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/90?text=Agent` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/80?text=...` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/40` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |
| `https://via.placeholder.com/35/...` | `<?= BASE_URL ?>images/placeholder-avatar.svg` |

**For the logo placeholder in add_agent.php:**

| Find | Replace |
|---|---|
| `https://via.placeholder.com/160x60?text=LOGO` | `<?= BASE_URL ?>images/logo.png` (use your actual logo file) |

> **Tip:** Since some of these are inside PHP string concatenation (e.g., appending initials), you may need to adjust the PHP logic slightly. Check each replacement individually.

> **Priority:** This phase is **lower priority** than Phases 2-9 because placeholder images are only shown when a real image is missing. However, replacing them ensures a fully offline-capable system.

---

## Phase 8 — Create the Path Helper

Your project has pages at **different folder depths** (root `/`, `agent_pages/`, `user_pages/`). A single `BASE_URL` constant prevents all broken asset paths.

### Step 8a — Create `config/paths.php`

Create the file `config/paths.php`:

```php
<?php
/**
 * Path Helper — HomeEstate Realty
 * 
 * Detects the project root automatically so asset links never break
 * regardless of which portal folder the page lives in.
 *
 * Usage in any PHP file:
 *   require_once __DIR__ . '/../config/paths.php';   // from a subfolder
 *   require_once __DIR__ . '/config/paths.php';       // from root
 *   
 *   Then in HTML:
 *   <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
 */

if (!defined('BASE_URL')) {
    // Auto-detect the base URL of the project
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Determine project folder from DOCUMENT_ROOT
    $docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    // If this file is inside config/, go up one level
    // If called from root, __DIR__ is already the project root
    if (basename($projectDir) === 'config') {
        $projectDir = dirname($projectDir);
    }

    $basePath = str_replace($docRoot, '', $projectDir);
    $basePath = '/' . trim($basePath, '/') . '/';

    define('BASE_URL',    $protocol . '://' . $host . $basePath);
    define('ASSETS_CSS',  BASE_URL . 'assets/css/');
    define('ASSETS_JS',   BASE_URL . 'assets/js/');
    define('ASSETS_FONTS', BASE_URL . 'assets/fonts/');
}
```

### Step 8b — Test it

Create a quick test file at the project root called `test_paths.php`:

```php
<?php
require_once __DIR__ . '/config/paths.php';
echo "BASE_URL:    " . BASE_URL    . "<br>";
echo "ASSETS_CSS:  " . ASSETS_CSS  . "<br>";
echo "ASSETS_JS:   " . ASSETS_JS   . "<br>";
```

Browse to `http://localhost/capstoneSystem/test_paths.php` — you should see:

```
BASE_URL:    http://localhost/capstoneSystem/
ASSETS_CSS:  http://localhost/capstoneSystem/assets/css/
ASSETS_JS:   http://localhost/capstoneSystem/assets/js/
```

Delete `test_paths.php` when done.

---

## Phase 9 — Update All PHP Files to Use Local Assets

This is the main migration step. For **every PHP file** that has CDN `<link>` and `<script>` tags, you will:

1. Add the path helper include at the top (after `session_start()` or opening `<?php` tag).
2. Replace CDN URLs with local equivalents.

### Step 9a — Add the path helper include

At the **top of each PHP file** (after any existing `<?php`, `session_start()`, and `require 'connection.php'` lines), add:

```php
require_once __DIR__ . '/config/paths.php';        // For ROOT-level files
// OR
require_once __DIR__ . '/../config/paths.php';     // For files in agent_pages/ or user_pages/
```

### Step 9b — Replace CDN links

Use the following **find → replace** mapping across all PHP files:

#### CSS Replacements

| Find (CDN) | Replace (Local) |
|-------------|-----------------|
| `href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"` | `href="<?= ASSETS_CSS ?>bootstrap.min.css"` |
| `href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"` | `href="<?= ASSETS_CSS ?>inter-font.css"` |
| `href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"` | `href="<?= ASSETS_CSS ?>inter-font.css"` |
| `href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"` | `href="<?= ASSETS_CSS ?>fontawesome-all.min.css"` |
| `href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"` | `href="<?= ASSETS_CSS ?>bootstrap-icons.min.css"` |
| `href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"` | `href="<?= ASSETS_CSS ?>bootstrap-icons.min.css"` |
| `href="https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/css/uicons-regular-straight.css"` | `href="<?= ASSETS_CSS ?>uicons-regular-straight.css"` |

#### JS Replacements

| Find (CDN) | Replace (Local) |
|-------------|-----------------|
| `src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"` | `src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"` |
| `src="https://cdn.jsdelivr.net/npm/chart.js"` | `src="<?= ASSETS_JS ?>chart.umd.min.js"` |
| `src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"` | `src="<?= ASSETS_JS ?>chart.umd.min.js"` |
| `src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"` | `src="<?= ASSETS_JS ?>chart.umd.min.js"` |
| `src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"` | `src="<?= ASSETS_JS ?>chartjs-adapter-date-fns.bundle.min.js"` |
| `src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"` | `src="<?= ASSETS_JS ?>jspdf.umd.min.js"` |
| `src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"` | `src="<?= ASSETS_JS ?>jspdf.plugin.autotable.min.js"` |
| `src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"` | `src="<?= ASSETS_JS ?>xlsx.full.min.js"` |

#### Also Remove These (no longer needed)

```html
<!-- DELETE these preconnect hints — they're for CDNs we no longer use -->
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

#### Special: `search_results.php` — Remove lazy-load font trick

This file uses a `media="print" onload` pattern + `<noscript>` for Inter. Replace **both lines** with a single local link:

```html
<!-- DELETE BOTH of these lines: -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"></noscript>

<!-- REPLACE WITH: -->
<link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
```

### Step 9c — Complete file list to update

Below is every file that contains CDN references. Check each one off as you update it:

**Root-level files** (use `require_once __DIR__ . '/config/paths.php';`):

- [ ] `add_agent.php`
- [ ] `add_property.php`
- [ ] `admin_dashboard.php`
- [ ] `admin_navbar.php`
- [ ] `admin_notification_view.php`
- [ ] `admin_notifications.php`
- [ ] `admin_profile.php`
- [ ] `admin_property_sale_approvals.php`
- [ ] `admin_settings.php`
- [ ] `admin_sidebar.php` ← **also has Flaticon UIcons CDN link**
- [ ] `agent.php`
- [ ] `agent_info_form.php`
- [ ] `login.php`
- [ ] `property.php`
- [ ] `property_tour_requests.php`
- [ ] `register.php`
- [ ] `reports.php`
- [ ] `review_agent_details.php`
- [ ] `test_admin_modal.php`
- [ ] `tour_requests.php`
- [ ] `two_factor.php`
- [ ] `view_property.php`

**Included fragment files** (these are `include`d by other pages — they also need CDN links replaced):

- [ ] `admin_navbar.php` ← Bootstrap Icons CDN
- [ ] `admin_sidebar.php` ← Bootstrap Icons CDN + Flaticon UIcons CDN

**`agent_pages/` files** (use `require_once __DIR__ . '/../config/paths.php';`):

- [ ] `agent_commissions.php`
- [ ] `agent_dashboard.php`
- [ ] `agent_notifications.php`
- [ ] `agent_profile.php`
- [ ] `agent_property.php`
- [ ] `agent_tour_requests.php`
- [ ] `view_agent_property.php`

**`user_pages/` files** (use `require_once __DIR__ . '/../config/paths.php';`):

- [ ] `about.php`
- [ ] `agent_profile.php`
- [ ] `agents.php`
- [ ] `index.php`
- [ ] `property_details.php`
- [ ] `property_details_backup.php`
- [ ] `search_results.php`

### Example — Before & After

**Before** (`admin_dashboard.php`):

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
```

**After** (`admin_dashboard.php`):

```html
<link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
<link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
<link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
```

And for JS at the bottom:

```html
<!-- Before -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- After -->
<script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
```

### Pro Tip — Bulk Find & Replace with VS Code

1. Press `Ctrl+Shift+H` (Find and Replace in Files).
2. Enable **Regex** mode (click the `.*` button).
3. Paste the CDN URL in "Find" and the local path in "Replace".
4. In **"files to include"**: `*.php`
5. Review matches, then click **Replace All**.

Do this for each row in the replacement table above.

---

## Phase 10 — Verify & Test

### Step 10a — Check file sizes

```powershell
Get-ChildItem "assets" -Recurse -File | Select-Object FullName, @{N="KB";E={[math]::Round($_.Length/1KB,1)}} | Format-Table -AutoSize
```

All files should have reasonable sizes (not 0 KB or a few bytes — that means the download failed).

### Step 10b — Browser test

1. Open `http://localhost/capstoneSystem/admin_dashboard.php`
2. Press **F12** → **Network** tab
3. Reload the page (`Ctrl+Shift+R` for hard refresh)
4. Check:
   - **NO red entries** (failed loads)
   - All `.css` and `.js` files come from `localhost` (not `cdn.jsdelivr.net`)
   - Load times should be **under 50ms** per file (vs 100-500ms from CDNs)

### Step 10c — Test each portal

| Portal | URL to Test | What to Check |
|--------|-------------|---------------|
| Admin  | `/capstoneSystem/admin_dashboard.php` | Charts render, sidebar icons visible |
| Agent  | `/capstoneSystem/agent_pages/agent_dashboard.php` | Navigation works, Bootstrap Icons show |
| User   | `/capstoneSystem/user_pages/index.php` | Property cards display, Inter font loaded |
| Auth   | `/capstoneSystem/login.php` | Form styled correctly, Font Awesome lock icons |
| Reports | `/capstoneSystem/reports.php` | PDF & Excel export buttons work |

### Step 10d — Common issues and fixes

| Problem | Cause | Fix |
|---------|-------|-----|
| Icons show as □ squares | Font files not downloaded or path wrong | Re-run Phase 3 (Step 3b) or Phase 4; check CSS `@font-face` paths |
| Sidebar icons missing (admin) | Flaticon UIcons not downloaded | Re-run Phase 7B; verify `uicons-regular-straight.css` path |
| Page looks unstyled | `bootstrap.min.css` path is wrong | Verify `config/paths.php` is included; check `ASSETS_CSS` output |
| Modals don't open | `bootstrap.bundle.min.js` missing or 0 bytes | Re-download in Phase 2; verify file size > 0 |
| Charts don't render | `chart.umd.min.js` not loaded | Check Network tab for 404; re-run Phase 5 |
| PDF export fails | jsPDF not loaded | Check Network tab; re-run Phase 6 |
| Font looks different | Inter `.woff2` files corrupted or wrong font loaded | Re-download in Phase 7; clear browser cache |

---

## Phase 11 — Bonus: Shimmer / Skeleton Loader

Make your property cards feel like a modern app (Zillow/Airbnb style) while AJAX data is loading.

### Step 11a — Add to your CSS (e.g., `css/property_style.css`)

```css
/* ============ Shimmer Skeleton Loader ============ */
.skeleton {
  background: #e0e0e0;
  border-radius: 6px;
  position: relative;
  overflow: hidden;
}

.skeleton::after {
  content: '';
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255,255,255,0.4) 50%,
    transparent 100%
  );
  animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

/* Skeleton card that matches your property card layout */
.skeleton-card {
  border: 1px solid #e9ecef;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.skeleton-card .skeleton-image {
  width: 100%;
  height: 200px;
}

.skeleton-card .skeleton-body {
  padding: 16px;
}

.skeleton-card .skeleton-line {
  height: 14px;
  margin-bottom: 10px;
}

.skeleton-card .skeleton-line.title {
  width: 70%;
  height: 20px;
}

.skeleton-card .skeleton-line.price {
  width: 40%;
  height: 24px;
}

.skeleton-card .skeleton-line.short {
  width: 55%;
}

.skeleton-card .skeleton-line.medium {
  width: 80%;
}
```

### Step 11b — HTML skeleton card template

Place this where your property listing grid is, and hide it once AJAX data loads:

```html
<!-- Skeleton Loader — shown while data loads -->
<div id="skeleton-container" class="row g-4">
  <!-- Repeat 3-6 times for a grid of skeleton cards -->
  <div class="col-md-6 col-lg-4">
    <div class="skeleton-card">
      <div class="skeleton skeleton-image"></div>
      <div class="skeleton-body">
        <div class="skeleton skeleton-line price"></div>
        <div class="skeleton skeleton-line title"></div>
        <div class="skeleton skeleton-line medium"></div>
        <div class="skeleton skeleton-line short"></div>
      </div>
    </div>
  </div>
  <!-- Copy the above col-md-6 block 2 more times for 3 skeleton cards -->
  <div class="col-md-6 col-lg-4">
    <div class="skeleton-card">
      <div class="skeleton skeleton-image"></div>
      <div class="skeleton-body">
        <div class="skeleton skeleton-line price"></div>
        <div class="skeleton skeleton-line title"></div>
        <div class="skeleton skeleton-line medium"></div>
        <div class="skeleton skeleton-line short"></div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-lg-4">
    <div class="skeleton-card">
      <div class="skeleton skeleton-image"></div>
      <div class="skeleton-body">
        <div class="skeleton skeleton-line price"></div>
        <div class="skeleton skeleton-line title"></div>
        <div class="skeleton skeleton-line medium"></div>
        <div class="skeleton skeleton-line short"></div>
      </div>
    </div>
  </div>
</div>

<!-- Real property cards container (hidden initially) -->
<div id="property-container" class="row g-4" style="display:none;">
  <!-- AJAX-loaded cards go here -->
</div>
```

### Step 11c — JavaScript to swap skeleton → real data

```javascript
// After your AJAX call to fetch properties:
fetch('get_property_data.php')
  .then(response => response.json())
  .then(data => {
    const container = document.getElementById('property-container');
    // ... build your property cards and append to container ...

    // Hide skeleton, show real cards
    document.getElementById('skeleton-container').style.display = 'none';
    container.style.display = '';
  })
  .catch(error => {
    console.error('Failed to load properties:', error);
    document.getElementById('skeleton-container').innerHTML =
      '<div class="col-12 text-center text-muted py-5">Failed to load properties. Please refresh.</div>';
  });
```

---

## Final Assets Folder Checklist

After completing all phases, your `assets/` folder should look like this:

```
assets/
├── css/
│   ├── bootstrap.min.css              ← Phase 2
│   ├── bootstrap-icons.min.css        ← Phase 3
│   ├── fontawesome-all.min.css        ← Phase 4
│   ├── inter-font.css                 ← Phase 7
│   └── uicons-regular-straight.css    ← Phase 7B
├── js/
│   ├── bootstrap.bundle.min.js        ← Phase 2
│   ├── chart.umd.min.js              ← Phase 5
│   ├── chartjs-adapter-date-fns.bundle.min.js  ← Phase 5
│   ├── jspdf.umd.min.js              ← Phase 6
│   ├── jspdf.plugin.autotable.min.js  ← Phase 6
│   └── xlsx.full.min.js              ← Phase 6
├── fonts/
│   ├── inter/
│   │   ├── inter-v18-latin-300.woff2
│   │   ├── inter-v18-latin-regular.woff2
│   │   ├── inter-v18-latin-500.woff2
│   │   ├── inter-v18-latin-600.woff2
│   │   ├── inter-v18-latin-700.woff2
│   │   └── inter-v18-latin-800.woff2
│   ├── bootstrap-icons/
│   │   ├── bootstrap-icons.woff2
│   │   └── bootstrap-icons.woff
│   └── uicons/
│       ├── uicons-regular-straight.woff2
│       └── uicons-regular-straight.woff
└── webfonts/
    ├── fa-solid-900.woff2
    ├── fa-solid-900.ttf
    ├── fa-regular-400.woff2
    ├── fa-regular-400.ttf
    ├── fa-brands-400.woff2
    └── fa-brands-400.ttf
```

**Total self-hosted files:** ~23 files  
**Estimated total size:** ~3.5-5 MB (one-time, served locally at LAN speed)

---

## Why This Matters for Your Capstone Demo

| Before (CDN) | After (Self-Hosted) |
|---------------|---------------------|
| Requires internet during demo | Works 100% offline on XAMPP |
| 100-500ms per asset (CDN round-trip) | < 5ms per asset (localhost) |
| Red 404s if school Wi-Fi blocks CDNs | Zero failed requests |
| Modals lag on first open (Bootstrap JS still loading) | Modals open instantly |
| Judges see red entries in Network tab | Clean, professional Network tab |
| Charts may flash or delay rendering | Charts render on first paint |

> **When panelists open DevTools → Network tab and see every asset loading from `localhost` in under 5ms with zero errors, it communicates that you understand production deployment — a strong differentiator for a capstone defense.**

---

## Quick Reference — All Download Commands (Copy-Paste Block)

If you want to run everything at once from Phase 2–7, here is the combined script:

```powershell
cd C:\xampp\htdocs\capstoneSystem

# Phase 1 — Folders
New-Item -ItemType Directory -Force -Path "assets\css","assets\js","assets\fonts\inter","assets\fonts\bootstrap-icons","assets\webfonts"

# Phase 2 — Bootstrap
curl -L -o "assets\css\bootstrap.min.css" "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
curl -L -o "assets\js\bootstrap.bundle.min.js" "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"

# Phase 3 — Bootstrap Icons
curl -L -o "assets\css\bootstrap-icons.min.css" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
curl -L -o "assets\fonts\bootstrap-icons\bootstrap-icons.woff2" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"
curl -L -o "assets\fonts\bootstrap-icons\bootstrap-icons.woff" "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff"

# Phase 4 — Font Awesome
curl -L -o "assets\css\fontawesome-all.min.css" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
curl -L -o "assets\webfonts\fa-solid-900.woff2" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-solid-900.woff2"
curl -L -o "assets\webfonts\fa-solid-900.ttf" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-solid-900.ttf"
curl -L -o "assets\webfonts\fa-regular-400.woff2" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-regular-400.woff2"
curl -L -o "assets\webfonts\fa-regular-400.ttf" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-regular-400.ttf"
curl -L -o "assets\webfonts\fa-brands-400.woff2" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-brands-400.woff2"
curl -L -o "assets\webfonts\fa-brands-400.ttf" "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/webfonts/fa-brands-400.ttf"

# Phase 5 — Chart.js
curl -L -o "assets\js\chart.umd.min.js" "https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
curl -L -o "assets\js\chartjs-adapter-date-fns.bundle.min.js" "https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"

# Phase 6 — Export Libraries
curl -L -o "assets\js\jspdf.umd.min.js" "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"
curl -L -o "assets\js\jspdf.plugin.autotable.min.js" "https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"
curl -L -o "assets\js\xlsx.full.min.js" "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"

# Phase 7 — Inter Font
curl -L -o "assets\fonts\inter\inter-v18-latin-300.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuOKfAZ9hjQ.woff2"
curl -L -o "assets\fonts\inter\inter-v18-latin-regular.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hjQ.woff2"
curl -L -o "assets\fonts\inter\inter-v18-latin-500.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuI6fAZ9hjQ.woff2"
curl -L -o "assets\fonts\inter\inter-v18-latin-600.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuGKYAZ9hjQ.woff2"
curl -L -o "assets\fonts\inter\inter-v18-latin-700.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuFuYAZ9hjQ.woff2"
curl -L -o "assets\fonts\inter\inter-v18-latin-800.woff2" "https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuDyYAZ9hjQ.woff2"

# Phase 3 fix — Bootstrap Icons CSS font path
(Get-Content "assets\css\bootstrap-icons.min.css" -Raw) -replace '\./fonts/bootstrap-icons', '../fonts/bootstrap-icons/bootstrap-icons' | Set-Content "assets\css\bootstrap-icons.min.css" -NoNewline

# Phase 7B — Flaticon UIcons Regular Straight
New-Item -ItemType Directory -Force -Path "assets\fonts\uicons"
curl -L -o "assets\css\uicons-regular-straight.css" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/css/uicons-regular-straight.css"
curl -L -o "assets\fonts\uicons\uicons-regular-straight.woff2" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/webfonts/uicons-regular-straight.woff2"
curl -L -o "assets\fonts\uicons\uicons-regular-straight.woff" "https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-straight/webfonts/uicons-regular-straight.woff"
# Fix UIcons font path in CSS
(Get-Content "assets\css\uicons-regular-straight.css" -Raw) -replace '\.\./(webfonts|fonts)/', '../fonts/uicons/' | Set-Content "assets\css\uicons-regular-straight.css" -NoNewline

Write-Host "`n✅ All assets downloaded. Proceed to Phase 8 (Path Helper) and Phase 9 (file updates)." -ForegroundColor Green
```

---

*Guide created for HomeEstate Realty Capstone System — March 2026*
