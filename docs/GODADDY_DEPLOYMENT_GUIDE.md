# HomeEstate Realty — GoDaddy Deployment Guide

**System:** HomeEstate Realty (PHP + MySQL Real Estate Platform)  
**Target Host:** GoDaddy Shared Hosting (cPanel)  
**Migration From:** XAMPP localhost → GoDaddy live server  
**Last Updated:** April 10, 2026

---

## Table of Contents

1. [GoDaddy Plan Selection](#phase-1-godaddy-plan-selection--requirements)
2. [System Requirements](#step-2-verify-godaddy-meets-system-requirements)
3. [Pre-Deployment Preparation (Local)](#phase-2-pre-deployment-preparation-on-your-local-machine)
4. [Server Setup & Deployment](#phase-3-godaddy-server-setup--deployment)
5. [Post-Deployment Testing](#phase-4-post-deployment-testing)
6. [Security Hardening](#phase-5-security-hardening)
7. [Performance Optimization](#phase-6-performance-optimization)
8. [Troubleshooting](#troubleshooting-common-godaddy-issues)
9. [Files to Modify](#files-to-modify-summary)
10. [Verification Checklist](#final-verification-checklist)

---

## Phase 1: GoDaddy Plan Selection & Requirements

### Step 1: Choose the right GoDaddy plan

| GoDaddy Plan | Monthly Cost | Storage | Databases | SSH | Cron | Verdict |
|-------------|-------------|---------|-----------|-----|------|---------|
| **Economy** | ~$5.99/mo | 25GB | 1 MySQL DB | ❌ | ✅ (via cPanel) | ⚠ Works but only 1 database — sufficient since our system uses 1 DB |
| **Deluxe** | ~$8.99/mo | Unlimited | 25 MySQL DBs | ✅ | ✅ | ✅ **RECOMMENDED** — SSH access, unlimited storage, multiple DBs for staging |
| **Ultimate** | ~$16.99/mo | Unlimited | Unlimited DBs | ✅ | ✅ | Overkill for capstone |

> **Recommendation: GoDaddy Deluxe Web Hosting** — gives you SSH access (useful for debugging), unlimited storage for property images/documents, and room to create a staging database. Economy works if budget is tight (HomeEstate only needs 1 database: `realestatesystem`).

> **Domain:** GoDaddy includes a free domain for the first year with annual hosting plans. Otherwise domains are ~$12/year. For capstone demo, you can also use a temporary GoDaddy subdomain.

### Step 2: Verify GoDaddy meets system requirements

| Requirement | Your System Needs | GoDaddy Provides | Status |
|-------------|------------------|-------------------|--------|
| **PHP version** | 8.0+ (8.1 or 8.2 ideal) | PHP 8.1, 8.2, 8.3 (selectable in cPanel) | ✅ |
| **MySQL** | 5.7+ | MySQL 8.0 | ✅ |
| **PHP Extensions** | mysqli, fileinfo, mbstring, hash, filter, session, ctype | All included by default | ✅ |
| **Apache** | 2.4+ with mod_rewrite | Apache 2.4 with mod_rewrite enabled | ✅ |
| **SSL Certificate** | Required (HTTPS) | Free SSL via cPanel (AutoSSL / Let's Encrypt) | ✅ |
| **SMTP Outbound (port 587)** | Required for Gmail PHPMailer | Port 587 open on shared plans | ✅ |
| **Cron Jobs** | Required (lease expiry check daily) | Available via cPanel → Cron Jobs | ✅ |
| **Storage** | 20GB+ recommended | 25GB (Economy) / Unlimited (Deluxe) | ✅ |
| **.htaccess support** | Required (upload directory protection) | Fully supported (Apache) | ✅ |
| **File uploads** | up to 25MB per file | Default PHP 128MB post; configurable via .user.ini | ✅ |

**GoDaddy fully supports all HomeEstate Realty requirements.**

---

## Phase 2: Pre-Deployment Preparation (On Your Local Machine)

Complete these steps **before** uploading to GoDaddy. Steps 3–7 can be done in parallel.

### Step 3: Create a separate database config file

Your current `connection.php` has hardcoded localhost/root credentials. Create a separate config file so you can easily switch between local and production.

**Create `config/db_config.php`:**

```php
<?php
// ============================================================
// DATABASE CONFIGURATION — PRODUCTION (GoDaddy)
// ============================================================
// IMPORTANT: Never commit this file to version control.
//            Add to .gitignore if using Git.
// ============================================================

define('DB_HOST', 'localhost');                  // GoDaddy MySQL uses localhost
define('DB_USERNAME', 'your_cpanel_dbuser');     // Created in Step 10 (cPanel MySQL)
define('DB_PASSWORD', 'YourStrongPassword123!'); // Created in Step 10
define('DB_NAME', 'your_cpanel_prefix_realestatesystem'); // GoDaddy prefixes DB names
```

> **GoDaddy Note:** GoDaddy automatically prefixes database names and usernames with your cPanel username. For example, if your cPanel username is `abc123`, your database will be `abc123_realestatesystem` and your user will be `abc123_homestate`. You'll see the exact names in cPanel → MySQL Databases after creation.

**Update `connection.php`:**

```php
<?php
require_once __DIR__ . '/config/db_config.php';

// Create connection using config constants
$conn = mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // In production, don't expose error details
    error_log("Database connection failed: " . $conn->connect_error);
    die("A system error occurred. Please try again later.");
}
```

### Step 4: Verify path configuration (no changes needed)

Your `config/paths.php` already auto-detects `BASE_URL` dynamically using `$_SERVER['DOCUMENT_ROOT']` — **no changes needed**. It works whether deployed to:
- Domain root: `https://yourdomain.com/` (if files are in `public_html/`)
- Subfolder: `https://yourdomain.com/capstoneSystem/` (if files are in `public_html/capstoneSystem/`)

### Step 5: Enable HTTPS session security

In `login.php` (line 7), **uncomment** the secure cookie setting:

```php
// CHANGE THIS:
// ini_set('session.cookie_secure', 1);     // Uncomment when using HTTPS in production

// TO THIS:
ini_set('session.cookie_secure', 1);        // Enforces HTTPS-only session cookies
```

This ensures session cookies are only transmitted over HTTPS (which GoDaddy provides via free SSL).

### Step 6: Export your local database

1. Open **phpMyAdmin** on XAMPP: `http://localhost/phpmyadmin`
2. Click on the **`realestatesystem`** database in the left sidebar
3. Click the **Export** tab at the top
4. Select **Custom** export method
5. Under **Format**, keep **SQL**
6. Under **Object creation options**, check:
   - ✅ `Add DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT / TRIGGER statement`
   - ✅ `IF NOT EXISTS`
7. Under **Data**, keep defaults (include data)
8. Click **Go** → save the `.sql` file (e.g., `realestatesystem_export.sql`)

> **Keep this file safe** — you'll import it to GoDaddy in Step 11.

### Step 7: Verify upload directories have .htaccess protection

Ensure these directories exist and contain their `.htaccess` files (they should already be there):

| Directory | .htaccess Content | Purpose |
|-----------|------------------|---------|
| `uploads/commission_proofs/` | Blocks PHP/script execution | Commission payment proofs |
| `rental_documents/` | `Deny from all` | Rental verification docs |
| `rental_payment_documents/` | Blocks PHP/script execution + no directory listing | Payment receipts |
| `sale_documents/` | Should have protection (add if missing) | Sale verification docs |
| `logs/` | Should be blocked from web access | Debug logs |

> **If any `.htaccess` is missing,** create one with this content:
> ```apache
> Options -Indexes
> <FilesMatch "\.(php|phtml|php3|php4|php5|phps|phar|sh|bash|cgi|pl)$">
>     Deny from all
> </FilesMatch>
> ```

---

## Phase 3: GoDaddy Server Setup & Deployment

### Step 8: Set PHP version in cPanel

1. Log in to your GoDaddy account → **My Products** → **Web Hosting** → **Manage**
2. Open **cPanel** (button at the top of hosting dashboard)
3. In cPanel, search for **"MultiPHP Manager"** or **"PHP Version"**
4. Select your domain from the list
5. Change PHP version to **PHP 8.1** or **PHP 8.2**
6. Click **Apply**

> **Why PHP 8.1+:** Your system uses `random_int()`, typed parameters, `password_hash()`, and other modern PHP features that work best on 8.1+.

### Step 9: Configure PHP settings via .user.ini

GoDaddy shared hosting doesn't give you direct `php.ini` access. Instead, create a **`.user.ini`** file in your project root (inside `public_html/` or `public_html/capstoneSystem/`):

```ini
; ============================================================
; PHP Settings for HomeEstate Realty (GoDaddy Production)
; ============================================================

; File uploads (your system allows up to 25MB images)
upload_max_filesize = 25M
post_max_size = 30M
max_file_uploads = 20

; Execution limits
max_execution_time = 120
max_input_time = 120
memory_limit = 256M

; Error handling (NEVER show errors to users in production)
display_errors = Off
log_errors = On
error_reporting = E_ALL

; Session settings
session.gc_maxlifetime = 3600
session.cookie_lifetime = 0

; Security
expose_php = Off
```

> **Important:** After creating `.user.ini`, changes may take **up to 5 minutes** to apply on GoDaddy (they cache PHP config). If settings don't seem to apply, wait and refresh.

### Step 10: Create the database on GoDaddy

1. In cPanel, go to **Databases** → **MySQL Databases**
2. **Create a New Database:**
   - Database Name: type `realestatesystem`
   - GoDaddy will create it as: `yourprefix_realestatesystem`
   - Click **Create Database**
3. **Create a Database User:**
   - Username: type `homestate`
   - GoDaddy will create it as: `yourprefix_homestate`
   - Password: click **Password Generator** → use a strong password → **copy and save it**
   - Click **Create User**
4. **Add User to Database:**
   - Select the user (`yourprefix_homestate`) and database (`yourprefix_realestatesystem`)
   - Click **Add**
   - On the privileges page, check: **ALL PRIVILEGES**
   - Click **Make Changes**

> **Write down these 3 values — you need them for `config/db_config.php`:**
> - Database name: `yourprefix_realestatesystem`
> - Username: `yourprefix_homestate`  
> - Password: `(the generated password)`

### Step 11: Import your database

1. In cPanel, go to **Databases** → **phpMyAdmin**
2. In the left sidebar, click on your database (`yourprefix_realestatesystem`)
3. Click the **Import** tab
4. Click **Choose File** → select your `realestatesystem_export.sql` from Step 6
5. Keep format as **SQL**
6. Click **Go**
7. Wait for import to complete — you should see "Import has been successfully finished"
8. **Verify:** Click on the database in the sidebar — you should see 30+ tables (accounts, property, agent_information, tour_requests, etc.)

> **If the SQL file is too large** (over phpMyAdmin's upload limit, usually 50MB on GoDaddy):
> - **Option A:** Go to cPanel → **Databases** → **MySQL Databases** → **phpMyAdmin** and check if there's an upload size setting
> - **Option B (SSH, Deluxe plan):** SSH in and run: `mysql -u yourprefix_homestate -p yourprefix_realestatesystem < realestatesystem_export.sql`
> - **Option C:** Split the SQL file into smaller chunks using a tool like [BigDump](https://www.ozerov.de/bigdump/)

### Step 12: Upload project files to GoDaddy

**Option A — cPanel File Manager (easiest for beginners):**

1. In cPanel, open **File Manager**
2. Navigate to **`public_html/`**
3. **Decide your deployment location:**
   - **Domain root** (recommended): Upload files directly into `public_html/`
   - **Subfolder**: Create `public_html/capstoneSystem/` and upload there
4. Click **Upload** in the toolbar
5. **Zip your project first** (much faster than uploading individual files):
   - On your local machine, select all files inside `d:\xampp\htdocs\capstoneSystem\`
   - Right-click → **Send to** → **Compressed (zipped) folder** → name it `capstoneSystem.zip`
   - **Exclude from zip:** `.git/` folder (if exists), any local test files
6. Upload the `.zip` file to `public_html/`
7. After upload, right-click the `.zip` file → **Extract** → extract to `public_html/` (or `public_html/capstoneSystem/`)
8. Delete the `.zip` file after extraction

**Option B — FTP via FileZilla:**

1. Download and install [FileZilla](https://filezilla-project.org/)
2. Get your FTP credentials from GoDaddy:
   - In cPanel → **Files** → **FTP Accounts** (or use your main cPanel credentials)
   - Host: your domain or GoDaddy's FTP host (e.g., `ftp.yourdomain.com`)
   - Port: **21** (FTP) or **22** (SFTP, Deluxe plan only)
   - Username & Password: your cPanel login
3. Connect in FileZilla
4. Navigate to `public_html/` on the remote side
5. Drag your local project files into `public_html/`
6. Wait for all files to transfer (may take 15–30 minutes depending on images/documents)

**Option C — SSH + Git (Deluxe plan with SSH):**

1. In cPanel → **Security** → **SSH Access** → **Manage SSH Keys** → Generate or import your key
2. SSH into your server: `ssh yourusername@yourdomain.com`
3. Navigate: `cd ~/public_html/`
4. Clone: `git clone https://github.com/your-repo/capstoneSystem.git .`
5. Create config files on server (don't commit credentials)

> **After uploading, verify the file structure looks like this inside `public_html/`:**
> ```
> public_html/
> ├── admin_dashboard.php
> ├── connection.php
> ├── login.php
> ├── config/
> │   ├── db_config.php       ← Updated with GoDaddy credentials
> │   ├── mail_config.php
> │   ├── paths.php
> │   └── session_timeout.php
> ├── agent_pages/
> ├── user_pages/
> ├── uploads/
> ├── rental_documents/
> ├── sale_documents/
> ├── rental_payment_documents/
> ├── PHPMailer/
> ├── assets/
> ├── images/
> └── logs/
> ```

### Step 13: Update config files on GoDaddy

After uploading, edit the configuration files using **cPanel File Manager** → right-click file → **Edit**:

**1. Edit `config/db_config.php` with your GoDaddy database credentials:**

```php
<?php
define('DB_HOST', 'localhost');                              // GoDaddy MySQL is localhost
define('DB_USERNAME', 'yourprefix_homestate');               // From Step 10
define('DB_PASSWORD', 'YourGeneratedStrongPassword123!');    // From Step 10
define('DB_NAME', 'yourprefix_realestatesystem');            // From Step 10
```

**2. Verify `config/mail_config.php`:**

Your existing Gmail SMTP configuration should work on GoDaddy:
```php
define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USERNAME', 'real.estate.system.noreply@gmail.com');
define('MAIL_SMTP_PASSWORD', 'your-gmail-app-password');   // Keep existing app password
```

> **GoDaddy SMTP Note:** GoDaddy shared hosting allows outbound connections on port 587 to Gmail. If you encounter email issues, check:
> - Gmail app password is still valid
> - Two-factor authentication is enabled on the Gmail account
> - Less secure app access is OFF (app passwords are the correct approach)
>
> **Alternative:** GoDaddy also provides its own SMTP:
> ```
> Host: smtpout.secureserver.net
> Port: 465 (SSL) or 587 (TLS)
> Username: your GoDaddy email address
> Password: your GoDaddy email password
> ```

**3. Verify `connection.php` uses the config file** (should already be updated from Step 3):

```php
<?php
require_once __DIR__ . '/config/db_config.php';
$conn = mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("A system error occurred. Please try again later.");
}
```

### Step 14: Enable SSL (HTTPS) on GoDaddy

1. In cPanel, go to **Security** → **SSL/TLS Status** (or **AutoSSL**)
2. Your domain should automatically have a free SSL certificate provisioned
3. If not, click **Run AutoSSL** to generate one
4. Wait 5–15 minutes for the certificate to be issued
5. **Verify:** Visit `https://yourdomain.com` — you should see the padlock icon

> **If AutoSSL fails:**
> - Check that your domain's DNS points to GoDaddy's servers (A record → your hosting IP)
> - Try cPanel → **SSL/TLS** → **Manage SSL Sites** → install manually
> - Contact GoDaddy support — free SSL is included with hosting plans

### Step 15: Set up cron job for lease expiry checking

1. In cPanel, go to **Advanced** → **Cron Jobs**
2. Under **Add New Cron Job:**
   - **Common Settings:** Select "Once Per Day (0 0 * * *)" or set custom:
     - Minute: `0`
     - Hour: `9` (9 AM — matches Philippine business hours)
     - Day: `*`
     - Month: `*`
     - Weekday: `*`
   - **Command:**
     ```
     /usr/local/bin/php ~/public_html/cron_lease_expiry_check.php >> ~/public_html/logs/cron.log 2>&1
     ```
3. Click **Add New Cron Job**

> **GoDaddy Notes:**
> - Use `/usr/local/bin/php` (not `/usr/bin/php`) — this is GoDaddy's PHP binary path
> - `~/` refers to your home directory (same as `/home/yourusername/`)
> - If using a subfolder deployment: `~/public_html/capstoneSystem/cron_lease_expiry_check.php`
> - To test, run the cron command manually via SSH (Deluxe plan) or wait for the scheduled time and check `logs/cron.log`

### Step 16: Set file/folder permissions

In **cPanel File Manager**, right-click on each directory → **Change Permissions:**

| Path | Permission | Why |
|------|-----------|-----|
| `uploads/` | 755 | Web server needs to write uploaded images |
| `uploads/commission_proofs/` | 755 | Commission payment proof uploads |
| `rental_documents/` | 755 | Rental verification document uploads |
| `sale_documents/` | 755 | Sale verification document uploads |
| `rental_payment_documents/` | 755 | Payment receipt uploads |
| `logs/` | 755 | PHP/cron error logs |
| `config/db_config.php` | 644 | Readable by PHP only, not writable from web |
| `config/mail_config.php` | 644 | Readable by PHP only |
| All `.php` files | 644 | Standard — readable, not writable from web |
| All `.htaccess` files | 644 | Standard — readable, not writable from web |

> **Setting permissions in File Manager:** Right-click the folder → **Change Permissions** → set the numeric value (e.g., 755) → **Change Permissions**.

---

## Phase 4: Post-Deployment Testing

### Step 17: Comprehensive testing checklist

Work through this checklist after deployment. Each item should pass before considering the deployment successful.

#### Public Pages (No Login Required)
- [ ] Homepage loads: `https://yourdomain.com/user_pages/index.php` — featured properties, stats, city/type counts display
- [ ] Property search works: `https://yourdomain.com/user_pages/search_results.php` — AJAX filters (city, type, price, beds) respond correctly
- [ ] Property details load: click any property → gallery, details, tour request form visible
- [ ] Tour request form submits successfully (fill and submit → check for success message)
- [ ] Agent listing page shows approved agents: `https://yourdomain.com/user_pages/agents.php`
- [ ] All images load correctly (no broken image icons)
- [ ] CSS/JS loads (page styled correctly, Bootstrap working, no console errors)

#### Authentication
- [ ] Login page loads with HTTPS padlock: `https://yourdomain.com/login.php`
- [ ] Submit login → 2FA email received within 30–60 seconds
- [ ] 2FA code validates → redirects to correct dashboard (admin vs agent)
- [ ] Session timeout: leave page idle 30+ minutes → redirected to login
- [ ] Logout: click logout → session fully destroyed → redirected to login

#### Admin Portal (Login as Admin)
- [ ] Dashboard loads with correct statistics
- [ ] Property management: add new property, view list, approve/reject
- [ ] Agent management: view agents, approve pending, reject with reason
- [ ] Tour request management: accept, reject, cancel, complete
- [ ] Rental workflow: view rental approvals, finalize rental, manage lease
- [ ] Sale workflow: view sale approvals, approve sale, finalize sale
- [ ] Commission management: view, process payment with proof upload
- [ ] Reports page loads with data and charts
- [ ] Notifications: receive and mark as read
- [ ] Settings: add/remove amenities, specializations, property types

#### Agent Portal (Login as Agent)
- [ ] Dashboard loads after login
- [ ] Add new property: fill form + upload images + floor plans → submitted for approval
- [ ] Edit property: modify details, reorder images (drag-drop), delete images
- [ ] Tour requests: view, accept, reject, complete, cancel
- [ ] Record rental payment: fill form + upload receipt
- [ ] Lease management: renew lease, terminate lease
- [ ] View commissions by status
- [ ] Notifications: receive and mark as read

#### File Operations
- [ ] Image upload works (property photos, floor plans, profile pictures)
- [ ] Document upload works (sale/rental verification docs, payment receipts)
- [ ] Document download works for authorized users
- [ ] Direct access blocked: `https://yourdomain.com/rental_documents/` → 403 Forbidden
- [ ] Direct access blocked: `https://yourdomain.com/config/db_config.php` → 403 Forbidden

#### Email System
- [ ] 2FA emails send and arrive (check spam folder too)
- [ ] Tour request notifications reach agents
- [ ] Approval/rejection emails work (agent approval, sale approval, rental approval)
- [ ] Lease expiry warning emails (trigger cron or test manually)

---

## Phase 5: Security Hardening

### Step 18: Create root .htaccess for security

Create a **`.htaccess`** file in your project root (inside `public_html/` or `public_html/capstoneSystem/`):

```apache
# ============================================================
# HomeEstate Realty — Root .htaccess (Security + Performance)
# ============================================================

# ── Force HTTPS ──
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# ── Block access to sensitive directories ──
# Prevent direct access to config files
<IfModule mod_rewrite.c>
    RewriteRule ^config/ - [F,L]
    RewriteRule ^logs/ - [F,L]
    RewriteRule ^docs/ - [F,L]
    RewriteRule ^PHPMailer/ - [F,L]
</IfModule>

# ── Block access to hidden files (.env, .git, .htaccess backups) ──
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# ── Block access to sensitive file types ──
<FilesMatch "\.(sql|log|md|txt|yml|yaml|json|lock|bak|old|orig|save)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# ── Security Headers ──
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set Permissions-Policy "geolocation=(), camera=(), microphone=()"

    # Remove server signature
    Header unset X-Powered-By
    Header unset Server
</IfModule>

# ── Prevent directory listing ──
Options -Indexes

# ── Browser caching for static assets ──
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType image/svg+xml "access plus 30 days"
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType application/x-font-woff "access plus 30 days"
    ExpiresByType font/woff2 "access plus 30 days"
</IfModule>

# ── Gzip compression ──
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml
    AddOutputFilterByType DEFLATE text/css application/javascript
    AddOutputFilterByType DEFLATE application/json
    AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>
```

> **Test after adding:** Visit `https://yourdomain.com/config/db_config.php` — should show 403 Forbidden. Visit `https://yourdomain.com/logs/` — should show 403 Forbidden.

### Step 19: Verify upload directory protection

Your existing `.htaccess` files in upload directories should already work on GoDaddy. Verify each:

```
https://yourdomain.com/rental_documents/         → 403 Forbidden ✅
https://yourdomain.com/rental_payment_documents/  → 403 Forbidden ✅
https://yourdomain.com/uploads/commission_proofs/ → No directory listing ✅
https://yourdomain.com/sale_documents/            → 403 Forbidden ✅
```

> **If sale_documents/ doesn't have an .htaccess,** create one via cPanel File Manager with the same content as `rental_documents/.htaccess`.

### Step 20: Database security on GoDaddy

GoDaddy handles most database security for you, but verify:

- ✅ Your DB user has a strong password (from Step 10)
- ✅ MySQL is only accessible from localhost (GoDaddy enforces this on shared hosting — no remote MySQL access by default)
- ✅ You're not using the root user (you created a dedicated user)

**Set up database backups:**
1. In cPanel → **Files** → **Backup Wizard** → **Back Up** → **MySQL Databases**
2. Download a backup of your `yourprefix_realestatesystem` database
3. **Schedule regular backups:** Do this weekly (GoDaddy doesn't auto-backup databases on shared plans)

> **Alternatively,** create a simple backup script:
> ```
> # Add as a weekly cron job in cPanel:
> /usr/local/bin/mysqldump -u yourprefix_homestate -p'YourPassword' yourprefix_realestatesystem > ~/backups/db_backup_$(date +\%Y\%m\%d).sql
> ```
> Create the `~/backups/` directory first (outside public_html so it's not web-accessible).

---

## Phase 6: Performance Optimization

### Step 21: Quick performance wins

**Already optimized in your project:**
- ✅ CSS/JS are minified (bootstrap.min.css, bootstrap.bundle.min.js, chart.umd.min.js)
- ✅ Gzip compression enabled via root .htaccess (Step 18)
- ✅ Browser caching headers set via root .htaccess (Step 18)

**Additional optimizations:**

**1. Add database indexes** (run these in phpMyAdmin → SQL tab):

```sql
-- Speed up property search (AJAX filtering on search_results.php)
ALTER TABLE property ADD INDEX idx_search (approval_status, Status, City, PropertyType, ListingPrice, Bedrooms, Bathrooms, ListingDate);

-- Speed up image loading for property listings
ALTER TABLE property_images ADD INDEX idx_img_sort (property_ID, SortOrder);

-- Speed up property creator lookups
ALTER TABLE property_log ADD INDEX idx_log_created (property_id, action, account_id);

-- Speed up notification badge queries (polled every 10 seconds)
ALTER TABLE agent_notifications ADD INDEX idx_agent_unread (agent_account_id, is_read);

-- Speed up tour request lookups
ALTER TABLE tour_requests ADD INDEX idx_tour_agent (agent_account_id, request_status);
```

**2. Optimize images before upload:**
- Use [TinyPNG](https://tinypng.com/) or [Squoosh](https://squoosh.app/) to compress existing property images
- Recommend agents upload images under 2MB (current limit is 25MB — most photos don't need that)

**3. Test performance:**
- Run [Google PageSpeed Insights](https://pagespeed.web.dev/) on your public pages
- Run [GTmetrix](https://gtmetrix.com/) for detailed waterfall analysis
- Target: **70+ score** on mobile, **80+ on desktop**

---

## Troubleshooting: Common GoDaddy Issues

### Issue: "500 Internal Server Error"
**Cause:** Usually a `.htaccess` syntax error or PHP version mismatch.  
**Fix:**
1. Check `logs/` for error details (or cPanel → **Metrics** → **Errors**)
2. Temporarily rename `.htaccess` to `.htaccess.bak` to see if it resolves
3. Verify PHP version is 8.1+ in cPanel → MultiPHP Manager
4. Check `.user.ini` for syntax errors

### Issue: "Database connection failed"
**Cause:** Wrong credentials in `config/db_config.php`.  
**Fix:**
1. Verify `DB_NAME` includes the cPanel prefix (e.g., `abc123_realestatesystem`, not just `realestatesystem`)
2. Verify `DB_USERNAME` includes the cPanel prefix (e.g., `abc123_homestate`)
3. Verify the user is assigned to the database in cPanel → MySQL Databases → **Current Databases**
4. `DB_HOST` should be `localhost` (not your domain name or IP)

### Issue: "2FA emails not arriving"
**Cause:** Gmail SMTP blocked or credentials expired.  
**Fix:**
1. Create a test script:
   ```php
   <?php
   require_once 'mail_helper.php';
   $result = sendSystemMail('your@email.com', 'Test', 'Test Subject', '<p>Test email</p>');
   echo '<pre>'; print_r($result); echo '</pre>';
   ```
2. Upload to server, run in browser, check the output
3. If it fails, verify:
   - Gmail app password is still valid (regenerate if needed at myaccount.google.com → Security → App Passwords)
   - Port 587 is not blocked (try port 465 with `SMTPSecure = 'ssl'` as fallback)
4. **GoDaddy alternative SMTP:** Use `smtpout.secureserver.net` with a GoDaddy email address (set up in cPanel → Email Accounts)
5. **Delete the test script after testing** — don't leave it on the server

### Issue: "File upload fails" or "exceeds maximum size"
**Cause:** PHP upload limits not applied.  
**Fix:**
1. Verify `.user.ini` exists in your project root with correct settings
2. Wait 5 minutes (GoDaddy caches PHP config)
3. Create a `phpinfo.php` test file:
   ```php
   <?php phpinfo(); ?>
   ```
4. View in browser → search for `upload_max_filesize` → should show 25M
5. **Delete `phpinfo.php` after checking** — it exposes server information

### Issue: "Images not displaying / broken images"
**Cause:** File paths or permissions incorrect.  
**Fix:**
1. Check that `uploads/` directory contains the image files (cPanel → File Manager)
2. Verify permissions are 755 on upload directories
3. Check the image URLs in page source — they should match the actual file paths
4. If you uploaded a `.zip`, ensure files extracted to the correct location

### Issue: "CSS/JS not loading / page looks broken"
**Cause:** Asset paths incorrect.  
**Fix:**
1. View page source → check CSS/JS URLs
2. Verify `config/paths.php` detects the correct BASE_URL
3. Check that `assets/css/` and `assets/js/` directories uploaded correctly
4. Clear browser cache (Ctrl+Shift+R)

### Issue: "Cron job not running"
**Cause:** Wrong PHP path or file path.  
**Fix:**
1. In cPanel → Cron Jobs, verify the command uses `/usr/local/bin/php` (GoDaddy's PHP path)
2. Use full absolute path: `~/public_html/cron_lease_expiry_check.php`
3. Check `logs/cron.log` for output
4. Test manually via SSH (Deluxe plan): `/usr/local/bin/php ~/public_html/cron_lease_expiry_check.php`

### Issue: ".htaccess rules not working"
**Cause:** mod_rewrite not enabled or conflicting rules.  
**Fix:**
1. GoDaddy has mod_rewrite enabled by default — this is rarely the issue
2. Check for syntax errors in `.htaccess` (even a single typo causes 500 error)
3. Make sure `RewriteEngine On` appears before any RewriteRule
4. Use cPanel → **Metrics** → **Errors** to see Apache error logs

---

## Files to Modify Summary

| File | Action | Details |
|------|--------|---------|
| `connection.php` | **Edit** | Replace hardcoded credentials with `require_once 'config/db_config.php'` + constants |
| `config/db_config.php` | **Create new** | Production DB credentials (host, user, password, database with GoDaddy prefix) |
| `config/mail_config.php` | **Verify** | Confirm Gmail SMTP credentials; no changes unless emails fail |
| `config/paths.php` | **No change** | Already handles dynamic URL detection |
| `config/session_timeout.php` | **No change** | 30-min timeout already configured |
| `login.php` | **Edit line 7** | Uncomment `ini_set('session.cookie_secure', 1);` |
| `.user.ini` (root) | **Create new** | PHP settings: upload limits, error handling, memory |
| `.htaccess` (root) | **Create new** | HTTPS redirect, security headers, block config/logs access, caching, gzip |
| `cron_lease_expiry_check.php` | **No change** | Configure as cron job in cPanel (Step 15) |
| `PHPMailer/` | **Upload entire dir** | Required for email functionality |

---

## Final Verification Checklist

After completing all steps, verify everything works:

| # | Test | Expected Result | Status |
|---|------|----------------|--------|
| 1 | Visit `https://yourdomain.com` | Padlock icon, no mixed content warnings | ☐ |
| 2 | Visit `http://yourdomain.com` | Auto-redirects to HTTPS | ☐ |
| 3 | Login as admin | Dashboard loads with correct stats | ☐ |
| 4 | Trigger 2FA during login | Email arrives within 30–60 seconds | ☐ |
| 5 | Add a property with images | Images upload and display correctly | ☐ |
| 6 | Visit `https://yourdomain.com/config/db_config.php` | 403 Forbidden | ☐ |
| 7 | Visit `https://yourdomain.com/rental_documents/` | 403 Forbidden | ☐ |
| 8 | Visit `https://yourdomain.com/logs/` | 403 Forbidden | ☐ |
| 9 | Submit a tour request (public page) | Success message + email to agent | ☐ |
| 10 | Open public pages on mobile phone | Responsive layout, no broken elements | ☐ |
| 11 | Leave page idle 30+ minutes | Session expires, redirected to login | ☐ |
| 12 | Check cPanel → Cron Jobs | Lease expiry cron is scheduled | ☐ |
| 13 | Run [PageSpeed Insights](https://pagespeed.web.dev/) | Score 70+ mobile, 80+ desktop | ☐ |

---

## Quick Reference: GoDaddy cPanel Locations

| Task | cPanel Location |
|------|----------------|
| Set PHP version | MultiPHP Manager |
| Create database | Databases → MySQL Databases |
| Import SQL | Databases → phpMyAdmin → Import tab |
| Upload files | Files → File Manager |
| Set permissions | File Manager → right-click → Change Permissions |
| Enable SSL | Security → SSL/TLS Status → Run AutoSSL |
| Set up cron job | Advanced → Cron Jobs |
| View error logs | Metrics → Errors |
| FTP credentials | Files → FTP Accounts |
| Email accounts | Email → Email Accounts |
| Backups | Files → Backup Wizard |
| SSH access (Deluxe+) | Security → SSH Access |

---

## Cost Summary

| Item | Cost | Notes |
|------|------|-------|
| GoDaddy Deluxe Hosting | ~$8.99/mo (or ~$5/mo on annual promo) | Includes 1 free domain for first year |
| Domain (if not included) | ~$12/year | .com domain |
| SSL Certificate | Free | Included with hosting (AutoSSL) |
| Gmail SMTP | Free | Using existing app password (500 emails/day limit) |
| **Total** | **~$6–9/month** | First year may be cheaper with promos |

---

## Post-Capstone Considerations

1. **Gmail SMTP limits:** 500 emails/day is fine for capstone. For real production, switch to Mailgun ($0.80/1000 emails) or SendGrid (100 emails/day free tier).
2. **Backups:** GoDaddy doesn't auto-backup databases on shared plans. Set up weekly manual backups or cron-based mysqldump.
3. **Scaling:** If the system grows beyond capstone (many agents, high traffic), consider upgrading to GoDaddy VPS or migrating to DigitalOcean for more control.
4. **Code improvements for production:**
   - Add CSRF tokens to all POST forms (currently only on 2FA)
   - Add login rate limiting (brute-force protection)
   - Add maximum session lifetime (currently only inactivity timeout)
   - Standardize API responses (JSON everywhere instead of mixed redirect/JSON)
