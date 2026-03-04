# Secure File Storage Implementation Guide

## HomeEstate Realty — Capstone System

> **Date:** March 2026  
> **Scope:** Protecting all uploaded files (property images, profile pictures, sale documents) from unauthorized direct access and ensuring data integrity.

---

## Table of Contents

1. [Why You Need This](#1-why-you-need-this)
2. [Current State — What's Happening Now](#2-current-state--whats-happening-now)
3. [Risk Assessment — What Could Go Wrong](#3-risk-assessment--what-could-go-wrong)
4. [The Solution — Overview](#4-the-solution--overview)
5. [Classification of Files: Public vs. Private](#5-classification-of-files-public-vs-private)
6. [Implementation Steps](#6-implementation-steps)
   - [Step 1: Create the Private Storage Directory](#step-1-create-the-private-storage-directory)
   - [Step 2: Add .htaccess Protection to Public Uploads](#step-2-add-htaccess-protection-to-public-uploads)
   - [Step 3: Move Sale Documents to Private Storage](#step-3-move-sale-documents-to-private-storage)
   - [Step 4: Update Sale Document Upload Code](#step-4-update-sale-document-upload-code)
   - [Step 5: Create a Secure File Serving Script](#step-5-create-a-secure-file-serving-script)
   - [Step 6: Update the Existing download_document.php](#step-6-update-the-existing-download_documentphp)
   - [Step 7: Update Admin Sale Approvals Preview (JS)](#step-7-update-admin-sale-approvals-preview-js)
   - [Step 8: Harden All Upload Handlers](#step-8-harden-all-upload-handlers)
   - [Step 9: Add Anti-Directory-Listing .htaccess to Public Uploads](#step-9-add-anti-directory-listing-htaccess-to-public-uploads)
7. [File-by-File Change Reference](#7-file-by-file-change-reference)
8. [Security Checklist](#8-security-checklist)
9. [Testing Plan](#9-testing-plan)
10. [Summary](#10-summary)

---

## 1. Why You Need This

### The Core Problem

Right now, **every uploaded file** in your system is stored inside the public web root (`htdocs/capstoneSystem/`). This means:

- Anyone who knows or guesses a file URL can access it directly.
- **Sale documents** (contracts, IDs, deeds of sale — PDFs, Word docs) are accessible at `http://localhost/capstoneSystem/sale_documents/14/admin_xyz.pdf` with **zero authentication**.
- Directory listing may expose all filenames inside a folder.
- A malicious user could enumerate property IDs and download all private legal documents.

### Why It Matters

| Concern | Impact |
|---|---|
| **Privacy/Legal** | Sale documents may contain personal information (buyer ID, addresses, contract amounts). Exposing them violates data privacy laws (e.g., Philippine Data Privacy Act / RA 10173). |
| **Academic/Capstone** | Demonstrating awareness of file security is a strong grading point. Ignoring it is a common critique from panelists. |
| **Integrity** | Without proper access control, files can be hotlinked, scraped, or tampered with. |
| **Professional Standard** | Any production real estate system would be required to protect client documents. |

### When It's OK to Leave Files Public

Property listing photos are **intentionally public** — they're meant to be seen by all visitors. These can stay in the public `uploads/` folder (with some hardening). But **sale documents, IDs, contracts** must never be directly accessible.

---

## 2. Current State — What's Happening Now

### Upload Directories (all inside web root)

| Directory | Contents | Currently Public? |
|---|---|---|
| `uploads/` | Property featured images (`prop_*.jpg`) | YES — anyone can type the URL |
| `uploads/agents/` | Agent profile pictures | YES |
| `uploads/admins/` | Admin profile pictures | YES |
| `uploads/floors/{property_id}/floor_{n}/` | Floor plan images | YES |
| `sale_documents/{property_id}/` | Contracts, IDs, deeds (PDF, DOCX, JPG) | **YES — CRITICAL RISK** |

### Files That Handle Uploads (write to disk)

| File | What It Uploads | Destination |
|---|---|---|
| `save_property.php` | Featured images + floor images | `uploads/` and `uploads/floors/` |
| `save_agent.php` | Agent profile picture | `uploads/agents/` |
| `save_admin_info.php` | Admin profile picture | `uploads/admins/` |
| `agent_info_form.php` | Agent profile picture (registration) | `uploads/agents/` |
| `update_featured_photo.php` | Replace a featured image | `uploads/` |
| `update_floor_photo.php` | Replace a floor image | `uploads/floors/` |
| `agent_pages/add_property_process.php` | Featured + floor images | `uploads/` and `uploads/floors/` |
| `agent_pages/upload_property_image.php` | Add new featured images | `uploads/` (via `../uploads/`) |
| `agent_pages/upload_floor_image.php` | Add new floor images | `uploads/floors/` (via `../uploads/floors/`) |
| `agent_pages/save_agent_profile.php` | Agent profile picture update | `uploads/agents/` (via `../uploads/agents/`) |
| `admin_mark_as_sold_process.php` | Sale documents | `sale_documents/{property_id}/` |
| `agent_pages/mark_as_sold_process.php` | Sale documents | `sale_documents/{property_id}/` (via `../sale_documents/`) |

### Files That Serve / Display Files (read from disk)

| File | What It Serves | Access Control |
|---|---|---|
| `download_document.php` | Sale verification documents | Admin-only session check ✅ but file is also accessible directly via URL ❌ |
| `get_property_photos.php` | Returns photo URLs as JSON | Admin-only session check ✅ |
| `admin_property_sale_approvals.php` | Previews sale documents inline | Constructs direct URL to `sale_documents/` folder ❌ |
| All user_pages (`index.php`, `property_details.php`, `search_results.php`, `agent_profile.php`) | Display property images via `<img src="../uploads/...">` | No check (public, by design) ✅ |

### Files That Delete Files (unlink from disk)

| File | What It Deletes | Path Traversal Protection |
|---|---|---|
| `delete_featured_photo.php` | Featured property images | Uses `realpath()` check ✅ |
| `delete_floor_photo.php` | Floor plan images | Uses `realpath()` check ✅ |
| `agent_pages/delete_property_image.php` | Agent's own property images | Ownership check + unlink ✅ |
| `agent_pages/delete_floor_image.php` | Agent's own floor images | Ownership check + unlink ✅ |

---

## 3. Risk Assessment — What Could Go Wrong

### CRITICAL Vulnerabilities

| # | Vulnerability | Affected Files | Severity |
|---|---|---|---|
| 1 | **Sale documents publicly accessible** — no authentication needed to download contracts, IDs | `sale_documents/` directory | **CRITICAL** |
| 2 | **Direct URL to sale doc in JS preview** — `admin_property_sale_approvals.php` constructs `sale_documents/{id}/{file}` URL for `<img>` and `<iframe>` | `admin_property_sale_approvals.php` line ~2923 | **HIGH** |
| 3 | **No `.htaccess` on any upload folder** — directory listing may be enabled; all files browsable | All upload directories | **HIGH** |
| 4 | **`save_admin_info.php` uses client-supplied MIME** — `$_FILES['profile_picture']['type']` is user-controlled and can be spoofed | `save_admin_info.php` line ~78 | **MEDIUM** |
| 5 | **`agent_pages/upload_floor_image.php` validates extension, not MIME** — relies on file extension from user-supplied filename | `agent_pages/upload_floor_image.php` line ~80 | **MEDIUM** |
| 6 | **`agent_pages/upload_property_image.php` validates extension, not MIME** — same as above | `agent_pages/upload_property_image.php` line ~78 | **MEDIUM** |
| 7 | **`admin_mark_as_sold_process.php` validates by `$_FILES['type']`** — user-controlled, can be spoofed | `admin_mark_as_sold_process.php` line ~113 | **MEDIUM** |
| 8 | **`agent_pages/mark_as_sold_process.php` validates by `$_FILES['type']`** — same issue | `agent_pages/mark_as_sold_process.php` line ~107 | **MEDIUM** |
| 9 | **`mkdir 0777`** in `save_agent.php`, `agent_info_form.php`, `save_agent_profile.php` — overly permissive directory permissions | Multiple files | **LOW** |

---

## 4. The Solution — Overview

```
BEFORE (everything public):
───────────────────────────────
htdocs/capstoneSystem/
├── uploads/              ← public (OK for property images)
│   ├── agents/           ← public (OK for profile pics)
│   ├── admins/           ← public (OK for profile pics)
│   └── floors/           ← public (OK for floor plan images)
├── sale_documents/       ← public ← ❌ DANGER
│   ├── 1/
│   ├── 14/
│   └── ...

AFTER (private files moved out):
─────────────────────────────────
C:\xampp\private_storage\          ← OUTSIDE web root (Apache can't serve it)
└── sale_documents/
    ├── 1/
    ├── 14/
    └── ...

htdocs/capstoneSystem/
├── uploads/                       ← stays public (with .htaccess hardening)
│   ├── .htaccess                  ← blocks directory listing + restricts to images
│   ├── agents/
│   ├── admins/
│   └── floors/
├── serve_file.php                 ← NEW gatekeeper script
└── sale_documents/                ← EMPTY or removed (no longer used)
```

**Key Principle:** Sensitive files live *outside* the web root. A PHP script (`serve_file.php`) acts as a gatekeeper — it checks the user's session and permissions, then reads the file from the private folder and streams it to the browser.

---

## 5. Classification of Files: Public vs. Private

| File Type | Classification | Reason | Action |
|---|---|---|---|
| Property featured photos | **PUBLIC** | Intentionally viewable by all visitors | Keep in `uploads/`, add `.htaccess` hardening |
| Floor plan images | **PUBLIC** | Part of property listing | Keep in `uploads/floors/`, same hardening |
| Agent profile pictures | **PUBLIC** | Displayed on public agent profile page | Keep in `uploads/agents/`, same hardening |
| Admin profile pictures | **SEMI-PRIVATE** | Only visible in admin dashboard | Keep in `uploads/admins/` (low risk, admin-only content) |
| Sale documents (contracts, IDs, deeds) | **PRIVATE** | Contains legally sensitive personal information | **Move to private storage outside web root** |

---

## 6. Implementation Steps

### Step 1: Create the Private Storage Directory

Create a directory **outside** the XAMPP web root:

```
C:\xampp\private_storage\
└── sale_documents\
```

**Why outside `htdocs/`?**  
Apache/XAMPP only serves files inside `htdocs/`. Anything outside it is invisible to the web server — no URL can reach it.

**How to do it:**

```bash
mkdir C:\xampp\private_storage
mkdir C:\xampp\private_storage\sale_documents
```

Then create a config constant so all your PHP files know where to find it:

**File to create: `config/storage_paths.php`**

```php
<?php
/**
 * Centralized storage path configuration.
 * Private files are stored OUTSIDE the web root so they cannot
 * be accessed directly via URL.
 */

// Private storage root — OUTSIDE htdocs
define('PRIVATE_STORAGE_PATH', 'C:/xampp/private_storage');

// Specific subdirectories
define('SALE_DOCUMENTS_PATH', PRIVATE_STORAGE_PATH . '/sale_documents');

// Public uploads root — inside web root (for property images, profiles)
define('PUBLIC_UPLOADS_PATH', __DIR__ . '/../uploads');
```

---

### Step 2: Add .htaccess Protection to Public Uploads

**File to create: `uploads/.htaccess`**

This prevents directory listing and restricts served files to images only:

```apache
# Disable directory browsing
Options -Indexes

# Only allow image files to be served
<FilesMatch "\.(?i:jpe?g|png|gif|webp)$">
    Require all granted
</FilesMatch>

# Block everything else (PHP, HTML, JS, etc.)
<FilesMatch "\.(?i:php|phtml|php[345s]|html?|js|sh|pl|py|cgi|exe|bat)$">
    Require all denied
</FilesMatch>

# Prevent PHP execution in this directory
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>

# Disable script execution as a fallback
AddHandler default-handler .php .phtml .php3 .php4 .php5 .phps
```

> Copy this same `.htaccess` file into `uploads/agents/`, `uploads/admins/`, and `uploads/floors/` (or it will inherit from the parent — but being explicit is safer).

---

### Step 3: Move Sale Documents to Private Storage

**File to create: `migrate_sale_documents.php`** (one-time migration script)

```php
<?php
/**
 * ONE-TIME MIGRATION SCRIPT
 * Moves existing sale_documents from public web root
 * to the private storage directory.
 *
 * Run this ONCE from the command line:
 *   php migrate_sale_documents.php
 *
 * Then delete this file.
 */

require_once __DIR__ . '/config/storage_paths.php';

$source_dir = __DIR__ . '/sale_documents';
$target_dir = SALE_DOCUMENTS_PATH;

if (!is_dir($source_dir)) {
    echo "Source directory not found: {$source_dir}\n";
    exit(1);
}

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

// Recursively copy all files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($iterator as $item) {
    $target_path = $target_dir . '/' . $iterator->getSubPathname();

    if ($item->isDir()) {
        if (!is_dir($target_path)) {
            mkdir($target_path, 0755, true);
        }
    } else {
        if (copy($item->getPathname(), $target_path)) {
            $count++;
            echo "Copied: {$iterator->getSubPathname()}\n";
        } else {
            echo "FAILED: {$iterator->getSubPathname()}\n";
        }
    }
}

echo "\nDone. Migrated {$count} files.\n";
echo "Verify the files in {$target_dir}, then delete the old sale_documents/ folder.\n";
```

> **After running:** Verify the files, update the database paths if needed, then delete the old `sale_documents/` directory from the web root.

---

### Step 4: Update Sale Document Upload Code

Two files write sale documents. Both must be updated to save to the private directory.

#### File: `admin_mark_as_sold_process.php`

**BEFORE (lines ~95-118):**
```php
$upload_base = __DIR__ . '/sale_documents';
// ...
$upload_dir = $upload_base . '/' . $property_id;
// ...
$relative_path = 'sale_documents/' . $property_id . '/' . $unique_name;
```

**AFTER:**
```php
require_once __DIR__ . '/config/storage_paths.php';

// Store in PRIVATE directory (outside web root)
$upload_base = SALE_DOCUMENTS_PATH;
if (!file_exists($upload_base)) {
    if (!mkdir($upload_base, 0755, true)) {
        respond_json(false, 'Failed to create upload directory.');
    }
}

$upload_dir = $upload_base . '/' . $property_id;
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        respond_json(false, 'Failed to create property upload directory.');
    }
}

// ...inside the loop...
$file_path = $upload_dir . '/' . $unique_name;
// Store relative path for database (relative to PRIVATE_STORAGE_PATH)
$relative_path = 'sale_documents/' . $property_id . '/' . $unique_name;
```

Also **fix the MIME validation** — use `finfo` instead of `$_FILES['type']`:

```php
// BEFORE (insecure — user-controllable):
$file_type = $files['type'][$i];
if (!in_array($file_type, $allowed_types)) { ... }

// AFTER (server-side MIME detection):
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$file_type = finfo_file($finfo, $file_tmp);
finfo_close($finfo);
if (!in_array($file_type, $allowed_types)) { ... }
```

#### File: `agent_pages/mark_as_sold_process.php`

Apply the **exact same changes** as above, adjusting the require path:

```php
require_once __DIR__ . '/../config/storage_paths.php';

$upload_base = SALE_DOCUMENTS_PATH;
// (same pattern as admin version)
```

And fix the MIME validation the same way.

---

### Step 5: Create a Secure File Serving Script

**File to create: `serve_file.php`**

This is the gatekeeper. When the admin wants to preview or download a sale document, they go through this script — not a direct URL.

```php
<?php
/**
 * serve_file.php — Secure file serving gateway.
 *
 * Reads a private file and streams it to the authenticated user.
 * Supports both inline preview (images, PDFs) and forced download.
 *
 * Usage:
 *   Preview:  serve_file.php?type=sale_doc&id=123
 *   Download: serve_file.php?type=sale_doc&id=123&download=1
 */

session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/storage_paths.php';

// ── 1. Authentication ──
if (!isset($_SESSION['account_id'])) {
    http_response_code(403);
    echo 'Access denied. Please log in.';
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$account_id = (int) $_SESSION['account_id'];

// ── 2. Parse request ──
$type = $_GET['type'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$force_download = isset($_GET['download']) && $_GET['download'] == '1';

if ($id <= 0 || $type === '') {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// ── 3. Resolve file based on type ──
$file_path = null;
$original_name = null;
$mime_type = null;

switch ($type) {
    case 'sale_doc':
        // Only admins and the agent who owns the property can view sale docs
        $sql = "
            SELECT svd.file_path, svd.original_filename, svd.mime_type,
                   sv.property_id
            FROM sale_verification_documents svd
            JOIN sale_verifications sv ON svd.verification_id = sv.verification_id
            WHERE svd.document_id = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            http_response_code(404);
            echo 'Document not found.';
            exit;
        }

        // Authorization: admin OR the agent who submitted the sale
        if ($user_role === 'admin') {
            // Admin has full access
        } elseif ($user_role === 'agent') {
            // Check if this agent owns the property
            $own = $conn->prepare(
                "SELECT 1 FROM property_log WHERE property_id = ? AND account_id = ? AND action = 'CREATED' LIMIT 1"
            );
            $own->bind_param('ii', $row['property_id'], $account_id);
            $own->execute();
            $is_owner = $own->get_result()->num_rows > 0;
            $own->close();

            if (!$is_owner) {
                http_response_code(403);
                echo 'You do not have permission to view this document.';
                exit;
            }
        } else {
            http_response_code(403);
            echo 'Access denied.';
            exit;
        }

        // Build absolute path — stored_path is relative like "sale_documents/14/file.pdf"
        $stored_path = $row['file_path'];
        // Normalize: strip any leading "../" from legacy paths
        $stored_path = preg_replace('#^(\.\./)+#', '', $stored_path);
        $file_path = PRIVATE_STORAGE_PATH . '/' . $stored_path;
        $original_name = $row['original_filename'];
        $mime_type = $row['mime_type'];
        break;

    default:
        http_response_code(400);
        echo 'Unknown file type.';
        exit;
}

// ── 4. Security: Validate resolved path is within private storage ──
$real_path = realpath($file_path);
$real_storage = realpath(PRIVATE_STORAGE_PATH);

if (!$real_path || !$real_storage || strpos($real_path, $real_storage) !== 0) {
    http_response_code(403);
    echo 'Access denied — invalid path.';
    exit;
}

if (!is_file($real_path)) {
    http_response_code(404);
    echo 'File not found on server.';
    exit;
}

// ── 5. Detect MIME if not stored ──
if (empty($mime_type)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $real_path);
    finfo_close($finfo);
}

// ── 6. Serve the file ──
// Prevent caching of sensitive documents
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($real_path));

if ($force_download) {
    header('Content-Disposition: attachment; filename="' . basename($original_name ?? 'document') . '"');
} else {
    // Inline display (for image/PDF previews)
    header('Content-Disposition: inline; filename="' . basename($original_name ?? 'document') . '"');
}

// Clear output buffer and send file
if (ob_get_level()) {
    ob_end_clean();
}
readfile($real_path);
exit;
```

---

### Step 6: Update the Existing download_document.php

The current `download_document.php` already has admin-only access control — good. But it reads from the public `sale_documents/` directory. Update it to use the private path.

**File: `download_document.php`**

**BEFORE (lines ~46-48):**
```php
$file_path = str_replace('../sale_documents/', 'sale_documents/', $document['file_path']);
$full_path = __DIR__ . '/' . $file_path;
```

**AFTER:**
```php
require_once __DIR__ . '/config/storage_paths.php';

$stored_path = $document['file_path'];
$stored_path = preg_replace('#^(\.\./)+#', '', $stored_path);
$full_path = PRIVATE_STORAGE_PATH . '/' . $stored_path;

// Security: verify the resolved path is inside private storage
$real_path = realpath($full_path);
$real_storage = realpath(PRIVATE_STORAGE_PATH);
if (!$real_path || !$real_storage || strpos($real_path, $real_storage) !== 0) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied.';
    exit;
}
```

> Alternatively, you can deprecate `download_document.php` entirely and route everything through `serve_file.php?type=sale_doc&id=X&download=1`.

---

### Step 7: Update Admin Sale Approvals Preview (JS)

**File: `admin_property_sale_approvals.php`**

The JavaScript `previewDoc()` function currently builds a direct URL to the file:

**BEFORE (around line 2923):**
```js
function previewDoc(path, mime, name, id) {
    const webPath = path.replace(/^\.\.\/sale_documents\//, 'sale_documents/');
    // ...
    if (mime.startsWith('image/')) {
        c.innerHTML = `<img src="${webPath}" ...>`;
    } else if (mime === 'application/pdf') {
        c.innerHTML = `<iframe src="${webPath}" ...>`;
    }
}
```

**AFTER:**
```js
function previewDoc(path, mime, name, id) {
    // Route through the secure serve_file.php gateway — never use direct file URLs
    const secureUrl = `serve_file.php?type=sale_doc&id=${id}`;
    // ...
    if (mime.startsWith('image/')) {
        c.innerHTML = `<div style="text-align:center;"><img src="${secureUrl}" alt="${esc(name)}" style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:4px;"></div>`;
    } else if (mime === 'application/pdf') {
        c.innerHTML = `<div style="height:70vh;"><iframe src="${secureUrl}" width="100%" height="100%" style="border:none;border-radius:4px;"></iframe></div>`;
    } else {
        c.innerHTML = `<div style="text-align:center;padding:3rem;"><i class="bi bi-file-earmark" style="font-size:3rem;color:var(--text-secondary);"></i><p style="margin-top:1rem;color:var(--text-secondary);">Preview not available. Click Download to view.</p></div>`;
    }
    openModal('previewModal');
}

function downloadDoc(id) {
    window.location.href = 'serve_file.php?type=sale_doc&id=' + id + '&download=1';
}
```

---

### Step 8: Harden All Upload Handlers

Apply these fixes across **all** upload-handling files:

#### Fix A: Always Use `finfo` for MIME Detection (Not `$_FILES['type']`)

The following files use the insecure `$_FILES[...]['type']` which is user-controllable:

| File | Line (approx) | Fix |
|---|---|---|
| `save_admin_info.php` | ~78 | Replace `$_FILES['profile_picture']['type']` with `finfo_file()` |
| `admin_mark_as_sold_process.php` | ~113 | Replace `$files['type'][$i]` with `finfo_file()` |
| `agent_pages/mark_as_sold_process.php` | ~107 | Same fix as above |
| `agent_pages/upload_floor_image.php` | ~80 | Add `finfo` check alongside extension check |
| `agent_pages/upload_property_image.php` | ~78 | Add `finfo` check alongside extension check |

**Example fix for `save_admin_info.php`:**

```php
// BEFORE:
$file_type = $_FILES['profile_picture']['type'];

// AFTER:
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$file_type = finfo_file($finfo, $_FILES['profile_picture']['tmp_name']);
finfo_close($finfo);
```

**Example fix for `agent_pages/upload_property_image.php`:**

```php
// BEFORE:
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) { ... }

// AFTER:
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) { $errors[] = "$name has invalid extension"; continue; }
// Double-check with actual MIME detection
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detected_mime = finfo_file($finfo, $tmp);
finfo_close($finfo);
$allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($detected_mime, $allowed_mimes)) { $errors[] = "$name has invalid content type"; continue; }
```

#### Fix B: Use `0755` Instead of `0777` for `mkdir()`

| File | Current | Change To |
|---|---|---|
| `save_agent.php` line ~102 | `mkdir($upload_dir, 0777, true)` | `mkdir($upload_dir, 0755, true)` |
| `agent_info_form.php` line ~161 | `mkdir($upload_dir, 0777, true)` | `mkdir($upload_dir, 0755, true)` |
| `agent_pages/save_agent_profile.php` line ~173 | `mkdir($upload_dir, 0777, true)` | `mkdir($upload_dir, 0755, true)` |
| `save_admin_info.php` line ~84 | `mkdir($upload_dir, 0777, true)` | `mkdir($upload_dir, 0755, true)` |

`0777` means every user on the system can read/write/execute. `0755` means only the owner (Apache/PHP) can write; everyone else can only read.

#### Fix C: Sanitize Original Filenames Stored in Database

When storing `original_filename` in the database for sale documents, ensure it's sanitized:

```php
// Strip path components and special characters
$safe_original_name = preg_replace('/[^a-zA-Z0-9._\- ]/', '', basename($file_name));
```

---

### Step 9: Add Anti-Directory-Listing .htaccess to Public Uploads

Even though property images are public, you should prevent people from *browsing the directory* and seeing all filenames.

**File to create: `sale_documents/.htaccess`** (if the old directory still exists, block everything)

```apache
# Block ALL access — this directory should no longer contain files
Require all denied
```

---

## 7. File-by-File Change Reference

Here is a complete checklist of every file that needs modification, what to change, and why:

| # | File | Action | What Changes |
|---|---|---|---|
| 1 | `config/storage_paths.php` | **CREATE** | Define `PRIVATE_STORAGE_PATH` and `SALE_DOCUMENTS_PATH` constants |
| 2 | `serve_file.php` | **CREATE** | New secure file gateway script (authentication + authorization + streaming) |
| 3 | `migrate_sale_documents.php` | **CREATE** | One-time script to move existing sale docs to private storage |
| 4 | `uploads/.htaccess` | **CREATE** | Disable directory listing, restrict to image files, disable PHP execution |
| 5 | `sale_documents/.htaccess` | **CREATE** | Deny all direct access (block legacy URLs) |
| 6 | `download_document.php` | **MODIFY** | Change file path resolution to read from `PRIVATE_STORAGE_PATH` + add `realpath()` check |
| 7 | `admin_mark_as_sold_process.php` | **MODIFY** | Change upload destination to `SALE_DOCUMENTS_PATH` + fix MIME validation to use `finfo` |
| 8 | `agent_pages/mark_as_sold_process.php` | **MODIFY** | Same changes as admin version |
| 9 | `admin_property_sale_approvals.php` | **MODIFY** | Change JS `previewDoc()` and `downloadDoc()` to route through `serve_file.php` |
| 10 | `save_admin_info.php` | **MODIFY** | Replace `$_FILES['type']` with `finfo_file()` for MIME detection |
| 11 | `agent_pages/upload_property_image.php` | **MODIFY** | Add `finfo` MIME check alongside existing extension check |
| 12 | `agent_pages/upload_floor_image.php` | **MODIFY** | Add `finfo` MIME check alongside existing extension check |
| 13 | `save_agent.php` | **MODIFY** | Change `mkdir 0777` to `0755` |
| 14 | `agent_info_form.php` | **MODIFY** | Change `mkdir 0777` to `0755` |
| 15 | `agent_pages/save_agent_profile.php` | **MODIFY** | Change `mkdir 0777` to `0755` |
| 16 | `save_admin_info.php` | **MODIFY** | Change `mkdir 0777` to `0755` |

**Files that need NO changes (already secure or intentionally public):**

- `save_property.php` — already uses `finfo_file()` ✅
- `agent_pages/add_property_process.php` — already uses `finfo_file()` ✅
- `update_featured_photo.php` — already uses `finfo_file()` ✅
- `update_floor_photo.php` — already uses `finfo_file()` ✅
- `agent_info_form.php` — already uses `finfo_file()` for MIME ✅ (only `mkdir` needs fix)
- `agent_pages/save_agent_profile.php` — already uses `finfo_file()` ✅ (only `mkdir` needs fix)
- `delete_featured_photo.php` — has `realpath()` traversal check ✅
- `delete_floor_photo.php` — has `realpath()` traversal check ✅
- `agent_pages/delete_property_image.php` — has ownership check ✅
- All `user_pages/` — display public images only, no changes needed ✅

---

## 8. Security Checklist

After implementation, verify each item:

- [ ] **Private storage directory exists** at `C:\xampp\private_storage\sale_documents\`
- [ ] **No sale documents remain** in `htdocs/capstoneSystem/sale_documents/`
- [ ] **`.htaccess` exists** in `uploads/` — directory listing disabled, PHP execution disabled
- [ ] **`.htaccess` exists** in `sale_documents/` (legacy) — all access denied
- [ ] **`serve_file.php` rejects** unauthenticated requests (test without logging in)
- [ ] **`serve_file.php` rejects** agents trying to view other agents' sale docs
- [ ] **`serve_file.php` allows** admin access to all sale docs
- [ ] **`serve_file.php` allows** agent access to their own property's sale docs
- [ ] **Admin preview works** via `serve_file.php` (images display inline, PDFs load in iframe)
- [ ] **Admin download works** via `serve_file.php?download=1`
- [ ] **Path traversal blocked** — `realpath()` check prevents `../../etc/passwd` attacks
- [ ] **MIME validation uses `finfo`** in all upload handlers (not `$_FILES['type']`)
- [ ] **All `mkdir()` calls use `0755`**, not `0777`
- [ ] **Sale documents upload to private path**, not `sale_documents/` under web root
- [ ] **Database paths work correctly** — `sale_documents/{id}/{file}` resolved against `PRIVATE_STORAGE_PATH`
- [ ] **No direct file URLs** remain in JavaScript for sale documents (all go through `serve_file.php`)

---

## 9. Testing Plan

### Test 1: Direct URL Access (Should Fail)

1. Upload a sale document normally.
2. Find its old-style URL: `http://localhost/capstoneSystem/sale_documents/14/admin_xyz.pdf`
3. Try opening it in a browser **without logging in**.
4. **Expected:** 403 Forbidden (blocked by `.htaccess`).

### Test 2: Serve File Gateway — Unauthenticated

1. Open an incognito/private browser window (not logged in).
2. Visit: `http://localhost/capstoneSystem/serve_file.php?type=sale_doc&id=1`
3. **Expected:** "Access denied. Please log in."

### Test 3: Serve File Gateway — Wrong Agent

1. Log in as Agent A.
2. Visit: `serve_file.php?type=sale_doc&id=X` where X belongs to Agent B's property.
3. **Expected:** "You do not have permission to view this document."

### Test 4: Serve File Gateway — Authorized Admin

1. Log in as Admin.
2. Visit: `serve_file.php?type=sale_doc&id=X`
3. **Expected:** File displays inline (image/PDF) or downloads.

### Test 5: Admin Preview in Sale Approvals Page

1. Log in as Admin.
2. Go to Property Sale Approvals.
3. Click a document to preview.
4. **Expected:** Image/PDF loads inside the modal — sourced from `serve_file.php`, NOT a direct URL.

### Test 6: Path Traversal Attack

1. Try: `serve_file.php?type=sale_doc&id=1` where you've manually edited the DB `file_path` to `../../etc/passwd`.
2. **Expected:** "Access denied — invalid path." (realpath check blocks it).

### Test 7: Upload MIME Spoofing

1. Rename a `.php` file to `.jpg`.
2. Try uploading it as a sale document or property image.
3. **Expected:** Rejected — `finfo_file()` detects the real MIME type, not the extension.

### Test 8: Directory Listing

1. Visit: `http://localhost/capstoneSystem/uploads/`
2. **Expected:** 403 Forbidden (not a file listing page).

### Test 9: Public Property Images Still Work

1. Visit any property listing page as a guest.
2. **Expected:** Property images load normally from `uploads/` — no authentication needed.

---

## 10. Summary

| What | Status Before | Status After |
|---|---|---|
| Sale documents | Publicly accessible via URL | Private, served through `serve_file.php` with auth |
| Property images | Public (intentional) | Public + hardened (no dir listing, no PHP execution) |
| Profile pictures | Public | Public + hardened |
| MIME validation | Mixed (`finfo` in some, `$_FILES['type']` in others) | Consistently using `finfo_file()` everywhere |
| Directory permissions | `0777` in some places | `0755` everywhere |
| Path traversal | Protected in delete ops | Protected everywhere including `serve_file.php` |
| Directory listing | Enabled by default | Disabled via `.htaccess` |

**Priority order for implementation:**

1. **CRITICAL** — Steps 1-3 + Step 5 (move sale docs out of web root + create gateway)
2. **HIGH** — Steps 4, 6, 7 (update upload code + download + JS preview)
3. **MEDIUM** — Step 8 (harden MIME checks + fix permissions)
4. **LOW** — Step 9 (belt-and-suspenders `.htaccess` on legacy folder)

---

*This guide was generated based on a thorough analysis of every file-handling script in the HomeEstate Realty capstone system. All file names, line numbers, and code snippets reference the actual current codebase.*
