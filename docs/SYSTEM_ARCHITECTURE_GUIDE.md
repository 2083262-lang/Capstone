# HomeEstate Realty — System Architecture Guide

> **Version:** 1.0  
> **Date:** March 4, 2026  
> **Purpose:** System blueprint, refactoring guide, structural documentation, and capstone-ready reference  
> **Stack:** PHP 8.2 · MariaDB 10.4 · Bootstrap 5 · Chart.js · PHPMailer · XAMPP

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Current Folder Tree](#2-current-folder-tree)
3. [Folder Purpose Reference](#3-folder-purpose-reference)
4. [File-by-File Reference](#4-file-by-file-reference)
   - 4.1 [Shared / Core Files](#41-shared--core-files)
   - 4.2 [Admin Portal Files](#42-admin-portal-files)
   - 4.3 [Agent Portal Files](#43-agent-portal-files)
   - 4.4 [User/Consumer Portal Files](#44-userconsumer-portal-files)
   - 4.5 [Config Files](#45-config-files)
   - 4.6 [CSS Files](#46-css-files)
   - 4.7 [JavaScript Files](#47-javascript-files)
   - 4.8 [Modal Components](#48-modal-components)
   - 4.9 [Documentation Files](#49-documentation-files)
5. [Database Schema Reference](#5-database-schema-reference)
6. [Dependency & Cross-Reference Map](#6-dependency--cross-reference-map)
7. [AJAX Endpoint Inventory](#7-ajax-endpoint-inventory)
8. [Identified Architectural Issues](#8-identified-architectural-issues)
9. [Proposed Clean Folder Structure](#9-proposed-clean-folder-structure)
10. [Naming Conventions](#10-naming-conventions)
11. [Phase-Based Restructuring Plan](#11-phase-based-restructuring-plan)
    - Phase 0: Preparation & Backup
    - Phase 1: Config & Shared Infrastructure
    - Phase 2: Admin Portal Consolidation
    - Phase 3: Agent Portal Alignment
    - Phase 4: User/Consumer Portal Cleanup
    - Phase 5: Assets & Static Resources
    - Phase 6: Cleanup, Legacy Removal & QA
12. [Reference Update Checklist](#12-reference-update-checklist)
13. [Quality Assurance Protocol](#13-quality-assurance-protocol)
14. [Scalability & Future Expansion Notes](#14-scalability--future-expansion-notes)

---

## 1. System Overview

HomeEstate Realty is a multi-portal real estate management system with three distinct user interfaces:

| Portal | Audience | Access | Key Features |
|--------|----------|--------|--------------|
| **Admin** | System Administrator | `/admin_dashboard.php` | Dashboard, property CRUD, agent management, tour approval, sale verification, reports, notifications, settings |
| **Agent** | Licensed Real Estate Agents | `/agent_pages/agent_dashboard.php` | Dashboard, property listings, tour management, commission tracking, notifications, profile |
| **User/Consumer** | Public Visitors | `/user_pages/index.php` | Browse properties, search/filter, view agent profiles, request tours, like properties |

### Authentication Flow

```
login.php → (credentials OK) → two_factor.php → send_2fa.php → verify_2fa.php
                                                                    ├─ Admin  → admin_dashboard.php
                                                                    ├─ Agent (approved) → agent_pages/agent_dashboard.php
                                                                    └─ Agent (new) → agent_info_form.php
```

### Technology Stack

| Layer | Technology |
|-------|-----------|
| Server | Apache (XAMPP) |
| Backend | PHP 8.2 (procedural, MySQLi) |
| Database | MariaDB 10.4 (`realestatesystem`) |
| Frontend | Bootstrap 5.3, Bootstrap Icons, Font Awesome 6, Inter Font |
| Charts | Chart.js + chartjs-adapter-date-fns |
| Email | PHPMailer 6.x (Gmail SMTP/TLS) |
| Export | jsPDF + AutoTable, SheetJS (xlsx) |

---

## 2. Current Folder Tree

```
capstoneSystem/                         ← Project root (57 PHP files + misc)
│
├── agent_pages/                        ← Agent portal (32 files)
│   ├── modals/
│   │   └── edit_property_modal.php
│   ├── agent_dashboard.php
│   ├── agent_navbar.php
│   ├── agent_profile.php
│   ├── agent_property.php
│   ├── agent_tour_requests.php
│   ├── agent_commissions.php
│   ├── agent_notifications.php
│   ├── agent_notifications_api.php
│   ├── agent_notification_helper.php
│   ├── add_property_process.php
│   ├── update_property_process.php
│   ├── update_price_process.php
│   ├── upload_property_image.php
│   ├── delete_property_image.php
│   ├── reorder_property_images.php
│   ├── upload_floor_image.php
│   ├── delete_floor_image.php
│   ├── remove_floor.php
│   ├── mark_as_sold_process.php
│   ├── save_agent_profile.php
│   ├── view_agent_property.php
│   ├── property_card_template.php
│   ├── get_property_tour_requests.php
│   ├── tour_request_details.php
│   ├── tour_request_accept.php
│   ├── tour_request_reject.php
│   ├── tour_request_cancel.php
│   ├── tour_request_complete.php
│   ├── check_tour_conflict.php
│   ├── logout.php
│   └── logout_agent_modal.php
│
├── user_pages/                         ← User/Consumer portal (12 files)
│   ├── index.php
│   ├── navbar.php
│   ├── about.php
│   ├── agents.php
│   ├── agent_profile.php
│   ├── property_details.php
│   ├── property_details_backup.php     ← Legacy backup
│   ├── search_results.php
│   ├── request_tour_process.php
│   ├── increment_property_view.php
│   ├── like_property.php
│   └── get_likes.php
│
├── assets/                             ← Third-party vendor assets
│   ├── css/
│   │   ├── bootstrap.min.css
│   │   ├── bootstrap-icons.min.css
│   │   ├── fontawesome-all.min.css
│   │   ├── inter-font.css
│   │   └── uicons-regular-straight.css
│   ├── js/
│   │   ├── bootstrap.bundle.min.js
│   │   ├── chart.umd.min.js
│   │   ├── chartjs-adapter-date-fns.bundle.min.js
│   │   ├── jspdf.umd.min.js
│   │   ├── jspdf.plugin.autotable.min.js
│   │   └── xlsx.full.min.js
│   ├── fonts/
│   │   ├── bootstrap-icons/
│   │   ├── inter/
│   │   └── uicons/
│   └── webfonts/                       ← Font Awesome woff2/ttf
│
├── config/                             ← Configuration files (3 files)
│   ├── mail_config.php
│   ├── paths.php
│   └── session_timeout.php
│
├── css/                                ← Custom stylesheets (4 files)
│   ├── admin_layout.css
│   ├── agent_property_modals.css
│   ├── login_style.css
│   └── property_style.css
│
├── script/                             ← Custom JavaScript (4 files)
│   ├── add_property_script.js
│   ├── agent_property_modals.js
│   ├── property_filter.js
│   └── tour_requests.js
│
├── modals/                             ← Shared modal components (2 files)
│   ├── edit_property_modal.php
│   └── tour_modals.html
│
├── images/                             ← Static images (15 files)
│   ├── Logo.png
│   ├── LogoName.png
│   ├── hero-bg.jpg / hero-bg2.jpg
│   ├── login-bg.jpg / register-bg.jpg
│   ├── background.svg
│   ├── bannerListing.jpg / agentBanner.jpg
│   ├── agent-info-bg.jpg
│   ├── HomePagePic1.jpg / HomePagePic2.jpg
│   ├── placeholder.svg / placeholder-avatar.svg
│   └── profile.png
│
├── uploads/                            ← User-generated uploads
│   ├── admins/                         ← Admin profile pictures
│   ├── agents/                         ← Agent profile pictures
│   ├── floors/                         ← Floor plan images (by property)
│   └── prop_*.jpg                      ← Property featured images
│
├── sale_documents/                     ← Sale verification documents (by property)
│   └── {property_id}/
│
├── PHPMailer/                          ← PHPMailer library
│   └── src/
│       ├── PHPMailer.php
│       ├── SMTP.php
│       ├── Exception.php
│       └── ...
│
├── docs/                               ← Documentation
│   ├── INSTALLATION_GUIDE.md
│   ├── SECURE_FILE_STORAGE_GUIDE.md
│   ├── SESSION_TIMEOUT_IMPLEMENTATION.md
│   ├── SKELETON_SCREEN_GUIDE.md
│   ├── system-page-directory.md
│   └── toast-notification-system.md
│
├── connection.php                      ← Database connection
├── login.php                           ← Login page
├── register.php                        ← Agent registration
├── logout.php                          ← Session destroy + redirect
├── logout_modal.php                    ← Logout confirmation modal
├── two_factor.php                      ← 2FA code entry page
├── verify_2fa.php                      ← 2FA verification endpoint
├── send_2fa.php                        ← 2FA code generation/email endpoint
├── mail_helper.php                     ← PHPMailer wrapper functions
├── email_template.php                  ← HTML email template builder
│
├── admin_dashboard.php                 ← Admin main dashboard
├── admin_navbar.php                    ← Admin top navigation component
├── admin_sidebar.php                   ← Admin left sidebar component
├── admin_profile.php                   ← Admin profile page
├── admin_profile_check.php             ← Admin profile completion helper
├── admin_profile_modal.php             ← Admin profile edit modal
├── admin_settings.php                  ← System settings page
├── admin_settings_api.php              ← Settings CRUD API endpoint
├── admin_notifications.php             ← Admin notification center
├── admin_notification_view.php         ← Single notification detail
├── admin_agent_card_template.php       ← Agent card component
├── admin_property_card_template.php    ← Property card component
├── admin_tour_request_details.php      ← Tour detail API endpoint
├── admin_tour_request_accept.php       ← Tour accept API endpoint
├── admin_tour_request_reject.php       ← Tour reject API endpoint
├── admin_tour_request_cancel.php       ← Tour cancel API endpoint
├── admin_tour_request_complete.php     ← Tour complete API endpoint
├── admin_check_tour_conflict.php       ← Tour conflict check API
├── admin_property_sale_approvals.php   ← Sale approval management page
├── admin_finalize_sale.php             ← Sale finalization API endpoint
├── admin_mark_as_sold_process.php      ← Admin mark-as-sold API endpoint
│
├── agent.php                           ← Agent listing page (admin)
├── agent_display.php                   ← Agent display helper component
├── agent_info_form.php                 ← Agent profile completion form
├── review_agent_details.php            ← Agent review page (admin)
├── review_agent_details_process.php    ← Agent approve/reject processor
├── save_admin_info.php                 ← Admin profile save API endpoint
│
├── add_agent.php                       ← ⚠ Legacy agent creation
├── save_agent.php                      ← ⚠ Legacy agent save
│
├── property.php                        ← Property listing page (admin)
├── add_property.php                    ← Add property form page
├── save_property.php                   ← Property save processor
├── update_property.php                 ← Property update API endpoint
├── view_property.php                   ← Property detail page (admin)
├── get_property_data.php               ← Property data API endpoint
├── get_property_photos.php             ← Property photos API endpoint
├── add_featured_photos.php             ← Featured photo upload API
├── update_featured_photo.php           ← Featured photo replace API
├── delete_featured_photo.php           ← Featured photo delete API
├── add_floor_photos.php                ← Floor photo upload API
├── update_floor_photo.php              ← Floor photo replace API
├── delete_floor_photo.php              ← Floor photo delete API
│
├── property_tour_requests.php          ← Property-specific tour list (admin)
├── tour_requests.php                   ← Tour request management (admin)
├── reports.php                         ← Reports & analytics (admin)
├── download_document.php               ← Document download handler
│
├── test_admin_modal.php                ← ⚠ Dev/test file
├── add_buyer_email_column.php          ← ⚠ Empty migration script
├── README.md
└── .gitignore
```

---

## 3. Folder Purpose Reference

| Folder | Purpose | Portal Scope |
|--------|---------|-------------|
| `/` (root) | Auth pages, admin pages, shared utilities, API endpoints | Shared + Admin |
| `/agent_pages/` | Agent dashboard, properties, tours, commissions, notifications | Agent |
| `/agent_pages/modals/` | Agent-specific modal components | Agent |
| `/user_pages/` | Public-facing pages (homepage, search, property details, agent profiles) | User/Consumer |
| `/config/` | Configuration files (paths, mail, session timeout) | Shared |
| `/assets/` | Third-party vendor CSS, JS, and fonts | Shared |
| `/assets/css/` | Bootstrap, Bootstrap Icons, Font Awesome, Inter font CSS | Shared |
| `/assets/js/` | Bootstrap JS, Chart.js, jsPDF, SheetJS | Shared |
| `/assets/fonts/` | Font files (Bootstrap Icons, Inter, UIcons) | Shared |
| `/assets/webfonts/` | Font Awesome woff2/ttf files | Shared |
| `/css/` | Custom application CSS files | Mixed |
| `/script/` | Custom application JavaScript files | Mixed |
| `/modals/` | Shared modal HTML/PHP components | Admin |
| `/images/` | Static images (logos, backgrounds, placeholders) | Shared |
| `/uploads/` | Dynamic user-generated uploads (property photos, profile pictures) | Shared |
| `/uploads/admins/` | Admin profile pictures | Admin |
| `/uploads/agents/` | Agent profile pictures | Agent |
| `/uploads/floors/` | Floor plan images organized by property ID | Shared |
| `/sale_documents/` | Sale verification documents organized by property ID | Admin/Agent |
| `/PHPMailer/` | PHPMailer library (vendor) | Shared |
| `/docs/` | Project documentation files | N/A |

---

## 4. File-by-File Reference

### 4.1 Shared / Core Files

| File | Type | Purpose |
|------|------|---------|
| `connection.php` | Utility | MySQLi connection to `realestatesystem` (localhost, root, no password) |
| `login.php` | Page | Login form with session hardening, credential validation, redirect to 2FA |
| `register.php` | Page | Agent self-registration (inserts into `accounts` with role_id=2) |
| `logout.php` | Handler | Destroys session; logs admin logout to `admin_logs`; redirects to login |
| `logout_modal.php` | Component | Bootstrap logout confirmation modal (included by admin layouts) |
| `two_factor.php` | Page | 2FA OTP entry page, guards with `pending_login` session + 10-min expiry |
| `verify_2fa.php` | API (POST) | Verifies 6-digit OTP; CSRF check, rate limiting, role-based redirect |
| `send_2fa.php` | API (POST) | Generates OTP, stores hashed, emails via PHPMailer; 60s cooldown |
| `mail_helper.php` | Utility | PHPMailer wrapper — `sendSystemMail()` and legacy `sendEmail()` |
| `email_template.php` | Utility | HTML email template builder with helpers (greeting, paragraph, cards, etc.) |

### 4.2 Admin Portal Files

#### Dashboard & Layout

| File | Type | Purpose |
|------|------|---------|
| `admin_dashboard.php` | Page | Main dashboard — KPIs, charts, agent stats, upcoming tours |
| `admin_navbar.php` | Component | Top nav — user info, notification count, pending action badges |
| `admin_sidebar.php` | Component | Left sidebar — menu items, active page highlighting |
| `admin_profile.php` | Page | Admin profile — hero section, stats, specializations, commissions chart |
| `admin_profile_check.php` | Utility | `checkAdminProfileCompletion()` — queries profile_completed flag |
| `admin_profile_modal.php` | Component | Profile edit modal form → POST to `save_admin_info.php` |
| `save_admin_info.php` | API (POST) | Validates & upserts admin profile data + profile picture upload |

#### Settings

| File | Type | Purpose |
|------|------|---------|
| `admin_settings.php` | Page | CRUD UI for amenities, specializations, property types (tabbed) |
| `admin_settings_api.php` | API (POST) | JSON endpoint for settings add/delete operations |

#### Notifications

| File | Type | Purpose |
|------|------|---------|
| `admin_notifications.php` | Page + API | Notification list with AJAX mark/delete + client-side filtering |
| `admin_notification_view.php` | Page | Single notification detail with contextual data |

#### Agent Management

| File | Type | Purpose |
|------|------|---------|
| `agent.php` | Page | Agent listing — pending, approved, needs profile, rejected categories |
| `agent_display.php` | Component | Agent display helper (3-category query with limits) |
| `admin_agent_card_template.php` | Component | Reusable agent card with status badge, avatar, action link |
| `review_agent_details.php` | Page | Agent profile review with approve/reject/disable actions |
| `review_agent_details_process.php` | Handler | Processes approve/reject/disable with email notifications |
| `agent_info_form.php` | Page | Agent profile completion form (for new agents post-registration) |

#### Property Management

| File | Type | Purpose |
|------|------|---------|
| `property.php` | Page | Property listing — tabbed by approval/sold status, type filters |
| `add_property.php` | Page | Multi-step add property form with progress bar |
| `save_property.php` | Handler | Validates & inserts property + images + amenities + rental details |
| `update_property.php` | API (POST) | Updates property fields + rental details (JSON response) |
| `view_property.php` | Page | Full property review — approve/reject/update price + tour history |
| `get_property_data.php` | API (GET) | Property data + amenities for edit form population |
| `get_property_photos.php` | API (GET) | Featured + floor photos grouped by floor |
| `admin_property_card_template.php` | Component | Reusable property card with badges, price, stats |

#### Photo Management

| File | Type | Purpose |
|------|------|---------|
| `add_featured_photos.php` | API (POST) | Upload featured photos (max 20, 25MB, JPG/PNG/GIF) |
| `update_featured_photo.php` | API (POST) | Replace single featured photo |
| `delete_featured_photo.php` | API (POST) | Delete featured photo (prevents last-photo deletion) |
| `add_floor_photos.php` | API (POST) | Upload floor plan photos by floor number |
| `update_floor_photo.php` | API (POST) | Replace single floor plan photo |
| `delete_floor_photo.php` | API (POST) | Delete floor plan photo |

#### Tour Management

| File | Type | Purpose |
|------|------|---------|
| `tour_requests.php` | Page | Tour request management — auto-expiry, status tabs, property filter |
| `property_tour_requests.php` | Page | Property-specific tour list with status counts |
| `admin_tour_request_details.php` | API (GET/POST) | Tour detail HTML for modal |
| `admin_tour_request_accept.php` | API (POST) | Accept tour with conflict detection, email |
| `admin_tour_request_reject.php` | API (POST) | Reject tour with reason, email |
| `admin_tour_request_cancel.php` | API (POST) | Cancel confirmed tour with reason, email |
| `admin_tour_request_complete.php` | API (POST) | Mark tour completed, email |
| `admin_check_tour_conflict.php` | API (POST) | Scheduling conflict check (30-min buffer, public/private rules) |

#### Sales & Reports

| File | Type | Purpose |
|------|------|---------|
| `admin_property_sale_approvals.php` | Page | Sale verification approve/reject with email notifications |
| `admin_finalize_sale.php` | API (POST) | Finalize sale + commission calculation |
| `admin_mark_as_sold_process.php` | API (POST) | Admin-initiated mark-as-sold with document upload |
| `reports.php` | Page | 6-section analytics dashboard with Chart.js + PDF/Excel export |
| `download_document.php` | Handler | Streams sale verification documents |

#### Legacy / Dev Files

| File | Type | Purpose | Status |
|------|------|---------|--------|
| `add_agent.php` | Page | Legacy agent creation form (old session keys, old schema) | ⚠ **Deprecated** |
| `save_agent.php` | Handler | Legacy agent save (references non-existent `agents` table) | ⚠ **Deprecated** |
| `test_admin_modal.php` | Page | Dev test page for admin profile modal | ⚠ **Dev only** |
| `add_buyer_email_column.php` | Script | Empty migration script | ⚠ **Empty/unused** |

### 4.3 Agent Portal Files

#### Dashboard & Layout

| File | Type | Purpose |
|------|------|---------|
| `agent_pages/agent_dashboard.php` | Page | Agent dashboard — KPIs, upcoming tours, recent properties, activity |
| `agent_pages/agent_navbar.php` | Component | Top nav — profile pic, nav links, notification dropdown, user menu |
| `agent_pages/agent_profile.php` | Page | Profile view/edit — personal info, professional info, performance stats |
| `agent_pages/save_agent_profile.php` | API (POST) | Validates & saves agent profile updates + picture upload |
| `agent_pages/logout.php` | Handler | Destroys session → redirects to `../login.php` |
| `agent_pages/logout_agent_modal.php` | Component | Logout confirmation modal with transition animation |

#### Property Management

| File | Type | Purpose |
|------|------|---------|
| `agent_pages/agent_property.php` | Page | Property listing with status tabs, add/mark-sold modals |
| `agent_pages/view_agent_property.php` | Page | Property detail — gallery, specs, amenities, price history, actions |
| `agent_pages/property_card_template.php` | Component | Reusable property card for agent listings |
| `agent_pages/modals/edit_property_modal.php` | Component | Comprehensive property edit modal with deferred photo handling |
| `agent_pages/add_property_process.php` | Handler | New property creation with full validation |
| `agent_pages/update_property_process.php` | API (POST) | Property update with ownership check, sold-lock |
| `agent_pages/update_price_process.php` | API (POST) | Price update with history logging |
| `agent_pages/upload_property_image.php` | API (POST) | Upload featured images (max 20) |
| `agent_pages/delete_property_image.php` | API (POST) | Delete featured image with sort normalization |
| `agent_pages/reorder_property_images.php` | API (POST) | Reorder featured images (drag-and-drop) |
| `agent_pages/upload_floor_image.php` | API (POST) | Upload floor plan images |
| `agent_pages/delete_floor_image.php` | API (POST) | Delete floor plan image |
| `agent_pages/remove_floor.php` | API (POST) | Remove entire floor + renumber higher floors |
| `agent_pages/mark_as_sold_process.php` | API (POST) | Mark-as-sold request with document upload |

#### Tour Management

| File | Type | Purpose |
|------|------|---------|
| `agent_pages/agent_tour_requests.php` | Page | Tour list with auto-expiry, status tabs, search/filter |
| `agent_pages/get_property_tour_requests.php` | API (GET) | Tours for specific property |
| `agent_pages/tour_request_details.php` | API (POST) | Tour detail HTML + mark as read |
| `agent_pages/tour_request_accept.php` | API (POST) | Accept with row locking + conflict detection + email |
| `agent_pages/tour_request_reject.php` | API (POST) | Reject with reason + email |
| `agent_pages/tour_request_cancel.php` | API (POST) | Cancel confirmed tour + email |
| `agent_pages/tour_request_complete.php` | API (POST) | Complete tour + email |
| `agent_pages/check_tour_conflict.php` | API (POST) | Scheduling conflict check |

#### Commissions & Notifications

| File | Type | Purpose |
|------|------|---------|
| `agent_pages/agent_commissions.php` | Page | Commission dashboard — KPIs, monthly chart, detail table |
| `agent_pages/agent_notifications.php` | Page + API | Full notification center with AJAX actions |
| `agent_pages/agent_notifications_api.php` | API (GET/POST) | Navbar notification dropdown API (fetch/mark/delete) |
| `agent_pages/agent_notification_helper.php` | Utility | Notification functions (`create`, `getUnread`, `getLatest`, `formatTime`, `getIcon`) |

### 4.4 User/Consumer Portal Files

| File | Type | Purpose |
|------|------|---------|
| `user_pages/index.php` | Page | Homepage — hero, search bar, featured properties, stats counters |
| `user_pages/navbar.php` | Component | Public navigation bar (Home, Properties, Agents, About) |
| `user_pages/about.php` | Page | About Us — company info, mission, aggregate stats |
| `user_pages/agents.php` | Page | Agent directory — search, filter by city/specialization, pagination |
| `user_pages/agent_profile.php` | Page | Single agent profile — bio, stats, listings, similar agents |
| `user_pages/property_details.php` | Page | Property detail — gallery, specs, amenities, agent card, tour form, price history |
| `user_pages/search_results.php` | Page + API | Property search — AJAX grid loading, filters, sort, pagination |
| `user_pages/request_tour_process.php` | API (POST) | Tour request submission + admin/agent notifications + emails |
| `user_pages/increment_property_view.php` | API (POST) | Increment property view count |
| `user_pages/like_property.php` | API (POST) | Like/unlike property |
| `user_pages/get_likes.php` | API (GET) | Get current like count |
| `user_pages/property_details_backup.php` | Page | ⚠ Legacy backup of property details (light theme) |

### 4.5 Config Files

| File | Purpose | Key Constants/Functions |
|------|---------|----------------------|
| `config/paths.php` | Auto-detects base URL, defines asset paths | `BASE_URL`, `ASSETS_CSS`, `ASSETS_JS`, `ASSETS_FONTS` |
| `config/mail_config.php` | SMTP configuration for PHPMailer | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_*` |
| `config/session_timeout.php` | 30-min session timeout handler | `getSessionTimeRemaining()`, `isSessionExpiringSoon()`, `refreshSessionActivity()` |

### 4.6 CSS Files

| File | Scope | Purpose |
|------|-------|---------|
| `css/admin_layout.css` | Admin | Sidebar (290px), navbar (70px), content area, responsive breakpoints |
| `css/agent_property_modals.css` | Agent | Dark-themed modal styles for agent property modals |
| `css/login_style.css` | Shared | Login page styling (centered card, gold accent) |
| `css/property_style.css` | Admin | Legacy light-theme property page styles |
| `assets/css/bootstrap.min.css` | Shared | Bootstrap 5.3 styles |
| `assets/css/bootstrap-icons.min.css` | Shared | Bootstrap Icons |
| `assets/css/fontawesome-all.min.css` | Shared | Font Awesome 6 |
| `assets/css/inter-font.css` | Shared | Inter font family |

### 4.7 JavaScript Files

| File | Scope | Purpose |
|------|-------|---------|
| `script/add_property_script.js` | Admin/Agent | Multi-step form, image upload, drag-drop, validation |
| `script/agent_property_modals.js` | Agent | Edit property modal JS, photo reorder, AJAX submission |
| `script/property_filter.js` | Admin | Property filter range slider display formatting |
| `script/tour_requests.js` | Admin | Tour request modal loading, status filtering, action handlers |
| `assets/js/bootstrap.bundle.min.js` | Shared | Bootstrap JS (popovers, modals, dropdowns) |
| `assets/js/chart.umd.min.js` | Admin/Agent | Chart.js charting library |
| `assets/js/jspdf.umd.min.js` | Admin | jsPDF for PDF export |
| `assets/js/jspdf.plugin.autotable.min.js` | Admin | jsPDF AutoTable plugin |
| `assets/js/xlsx.full.min.js` | Admin | SheetJS for Excel export |

### 4.8 Modal Components

| File | Used By | Purpose |
|------|---------|---------|
| `modals/edit_property_modal.php` | Admin (`view_property.php`) | Full property edit with deferred photo changes |
| `modals/tour_modals.html` | Admin (`tour_requests.js`) | Tour detail + confirmation modals (lazy-loaded) |
| `agent_pages/modals/edit_property_modal.php` | Agent (`view_agent_property.php`) | Agent property edit with photo management |
| `logout_modal.php` | Admin (included by navbar/sidebar) | Admin logout confirmation |
| `agent_pages/logout_agent_modal.php` | Agent (included by navbar) | Agent logout confirmation with animation |
| `admin_profile_modal.php` | Admin (`admin_profile.php`) | Admin profile edit form |

### 4.9 Documentation Files

| File | Content |
|------|---------|
| `docs/INSTALLATION_GUIDE.md` | Server setup, database import, configuration steps |
| `docs/SECURE_FILE_STORAGE_GUIDE.md` | File upload security best practices |
| `docs/SESSION_TIMEOUT_IMPLEMENTATION.md` | Session timeout mechanism documentation |
| `docs/SKELETON_SCREEN_GUIDE.md` | Loading skeleton screen implementation guide |
| `docs/system-page-directory.md` | Page routing directory |
| `docs/toast-notification-system.md` | Toast notification system documentation |

---

## 5. Database Schema Reference

### Entity-Relationship Summary

```
user_roles (1) ──< accounts (1) ──< admin_information
                           │──< agent_information (1) ──< agent_specializations >── specializations
                           │──< admin_logs
                           │──< property_log
                           │──< tour_requests
                           │──< sale_verifications ──< sale_verification_documents
                           │──< finalized_sales ──< agent_commissions
                           │──< agent_notifications
                           └──< two_factor_codes

property (1) ──< property_images
           │──< property_floor_images
           │──< property_amenities >── amenities
           │──< property_log
           │──< tour_requests
           │──< price_history
           │──< rental_details
           │──< sale_verifications
           └──< finalized_sales

notifications (standalone — admin inbox)
status_log (standalone — approval/rejection history)
property_types (lookup table)
```

### Table Inventory (20 tables)

| Table | Records | Purpose |
|-------|---------|---------|
| `user_roles` | 2 | Role definitions (admin, agent) |
| `accounts` | 3 | User accounts (admin + agents) |
| `admin_information` | 1 | Admin profile details |
| `admin_logs` | 14 | Admin login/logout audit trail |
| `agent_information` | 2 | Agent profile details |
| `agent_specializations` | 7 | Agent-specialization pivot table |
| `specializations` | 12 | Specialization lookup |
| `agent_notifications` | 4 | Agent notification inbox |
| `agent_commissions` | 0 | Commission records per sale |
| `property` | 7 | Property listings |
| `property_images` | 34 | Property featured photos |
| `property_floor_images` | 10 | Floor plan images |
| `property_amenities` | 47 | Property-amenity pivot table |
| `amenities` | 26 | Amenity lookup |
| `property_types` | 6 | Property type lookup |
| `property_log` | 22 | Property action audit trail |
| `price_history` | 5 | Property price change history |
| `rental_details` | 0 | Rental-specific property details |
| `tour_requests` | 2 | Tour scheduling requests |
| `sale_verifications` | 1 | Sale verification submissions |
| `sale_verification_documents` | 1 | Supporting documents for sales |
| `finalized_sales` | 1 | Completed/approved sales |
| `notifications` | 6 | Admin notification inbox |
| `status_log` | 4 | Agent/property approval history |
| `two_factor_codes` | 6 | 2FA OTP codes |

---

## 6. Dependency & Cross-Reference Map

### Include Chain — Admin Pages

```
admin_dashboard.php
 ├── connection.php
 ├── admin_profile_check.php
 ├── config/session_timeout.php
 ├── config/paths.php
 ├── admin_sidebar.php
 │   └── logout_modal.php
 └── admin_navbar.php
     └── logout_modal.php
```

> All admin page-type files follow this same inclusion pattern.

### Include Chain — Agent Pages

```
agent_pages/agent_dashboard.php
 ├── ../connection.php
 ├── ../config/session_timeout.php
 ├── ../config/paths.php
 └── agent_navbar.php
     └── ../connection.php (include_once)
```

> Agent page-type files follow this pattern. Agent API endpoints only include `../connection.php` and `../config/session_timeout.php`.

### Include Chain — User Pages

```
user_pages/index.php
 ├── ../connection.php
 ├── ../config/paths.php
 └── navbar.php
```

> User pages do NOT use session_timeout (no authentication required).

### Cross-Portal Dependencies

| File | Depends On From Other Portal |
|------|------------------------------|
| `view_property.php` (admin) | `agent_pages/agent_notification_helper.php` |
| `user_pages/request_tour_process.php` | `agent_pages/agent_notification_helper.php` |
| Agent API endpoints | `../mail_helper.php`, `../email_template.php` (root) |
| Agent pages | `../connection.php`, `../config/*` (root) |

---

## 7. AJAX Endpoint Inventory

### Admin AJAX Endpoints

| Endpoint | Method | Called By | Purpose |
|----------|--------|-----------|---------|
| `admin_settings_api.php` | POST | `admin_settings.php` | Add/delete amenities, specializations, types |
| `admin_notifications.php` | POST | Self | Mark read, mark all, delete, delete all read |
| `admin_tour_request_details.php` | GET/POST | `script/tour_requests.js` | Fetch tour detail HTML |
| `admin_tour_request_accept.php` | POST | `script/tour_requests.js` | Accept tour |
| `admin_tour_request_reject.php` | POST | `script/tour_requests.js` | Reject tour |
| `admin_tour_request_cancel.php` | POST | `script/tour_requests.js` | Cancel tour |
| `admin_tour_request_complete.php` | POST | `script/tour_requests.js` | Complete tour |
| `admin_check_tour_conflict.php` | POST | `script/tour_requests.js` | Check conflicts |
| `admin_finalize_sale.php` | POST | `admin_property_sale_approvals.php` | Finalize sale |
| `admin_mark_as_sold_process.php` | POST | `view_property.php` | Admin mark sold |
| `save_admin_info.php` | POST | `admin_profile_modal.php` | Save admin profile |
| `get_property_data.php` | GET | `modals/edit_property_modal.php` | Property data for edit |
| `get_property_photos.php` | GET | `modals/edit_property_modal.php` | Property photos for edit |
| `update_property.php` | POST | `modals/edit_property_modal.php` | Update property |
| `add_featured_photos.php` | POST | `modals/edit_property_modal.php` | Upload featured photos |
| `update_featured_photo.php` | POST | `modals/edit_property_modal.php` | Replace featured photo |
| `delete_featured_photo.php` | POST | `modals/edit_property_modal.php` | Delete featured photo |
| `add_floor_photos.php` | POST | `modals/edit_property_modal.php` | Upload floor photos |
| `update_floor_photo.php` | POST | `modals/edit_property_modal.php` | Replace floor photo |
| `delete_floor_photo.php` | POST | `modals/edit_property_modal.php` | Delete floor photo |
| `send_2fa.php` | POST | `two_factor.php` | Send OTP |
| `verify_2fa.php` | POST | `two_factor.php` | Verify OTP |

### Agent AJAX Endpoints

| Endpoint | Method | Called By | Purpose |
|----------|--------|-----------|---------|
| `agent_pages/save_agent_profile.php` | POST | `agent_profile.php` | Save agent profile |
| `agent_pages/update_property_process.php` | POST | `edit_property_modal.php` | Update property |
| `agent_pages/update_price_process.php` | POST | `view_agent_property.php` | Update price |
| `agent_pages/upload_property_image.php` | POST | `edit_property_modal.php` | Upload images |
| `agent_pages/delete_property_image.php` | POST | `edit_property_modal.php` | Delete image |
| `agent_pages/reorder_property_images.php` | POST | `edit_property_modal.php` | Reorder images |
| `agent_pages/upload_floor_image.php` | POST | `edit_property_modal.php` | Upload floor images |
| `agent_pages/delete_floor_image.php` | POST | `edit_property_modal.php` | Delete floor image |
| `agent_pages/remove_floor.php` | POST | `edit_property_modal.php` | Remove entire floor |
| `agent_pages/mark_as_sold_process.php` | POST | `agent_property.php` | Mark as sold |
| `agent_pages/tour_request_details.php` | POST | `agent_tour_requests.php` | Tour detail |
| `agent_pages/tour_request_accept.php` | POST | `agent_tour_requests.php` | Accept tour |
| `agent_pages/tour_request_reject.php` | POST | `agent_tour_requests.php` | Reject tour |
| `agent_pages/tour_request_cancel.php` | POST | `agent_tour_requests.php` | Cancel tour |
| `agent_pages/tour_request_complete.php` | POST | `agent_tour_requests.php` | Complete tour |
| `agent_pages/check_tour_conflict.php` | POST | `agent_tour_requests.php` | Check conflicts |
| `agent_pages/get_property_tour_requests.php` | GET | `view_agent_property.php` | Property tours |
| `agent_pages/agent_notifications.php` | POST | Self | Mark/delete notifications |
| `agent_pages/agent_notifications_api.php` | GET/POST | `agent_navbar.php` | Dropdown notifications |

### User AJAX Endpoints

| Endpoint | Method | Called By | Purpose |
|----------|--------|-----------|---------|
| `user_pages/request_tour_process.php` | POST | `property_details.php` | Submit tour request |
| `user_pages/increment_property_view.php` | POST | `property_details.php` | Track view |
| `user_pages/like_property.php` | POST | `property_details.php` | Like/unlike |
| `user_pages/get_likes.php` | GET | `property_details.php` | Get like count |
| `user_pages/search_results.php?partial=grid` | GET | `search_results.php` | Load property grid |

---

## 8. Identified Architectural Issues

### Critical Issues

| # | Issue | Impact | Files Affected |
|---|-------|--------|----------------|
| 1 | **57 PHP files in root directory** — Admin pages, auth pages, API endpoints, utilities, and legacy files all mixed in root | Navigation confusion, hard to maintain | All root PHP files |
| 2 | **Duplicate functionality (Admin vs Agent)** — Photo CRUD, tour management, property update logic duplicated | Bug fixes must be applied twice, inconsistency risk | Root photo endpoints vs `agent_pages/` photo endpoints |
| 3 | **No separation of pages vs API endpoints** — HTML pages and JSON API endpoints live side-by-side | Unclear which files are entry points vs services | All API-type files |
| 4 | **Custom CSS/JS scattered between `css/`, `script/`, and inline** — Two separate locations for custom styles/scripts | Inconsistent asset organization | `css/`, `script/` folders |

### Moderate Issues

| # | Issue | Impact |
|---|-------|--------|
| 5 | **Legacy files still present** — `add_agent.php`, `save_agent.php` reference old schema; `test_admin_modal.php` is dev-only; `add_buyer_email_column.php` is empty | Dead code, confusion |
| 6 | **Backup file in production** — `property_details_backup.php` should not be in the codebase | Confusion, potential security |
| 7 | **Inconsistent naming** — Mix of `snake_case` and no prefix (e.g., `property.php` vs `admin_property_sale_approvals.php`) | Hard to identify file portal ownership |
| 8 | **`connection.php` in root with hardcoded credentials** — No `.env` or config-based credential management | Security concern (mitigated by local dev context) |
| 9 | **`modals/` in root only has admin-specific content** — Misleading folder name suggests shared components | Files are only referenced by admin pages |
| 10 | **`css/property_style.css` is legacy** — Uses older "Prestige Properties" branding | Potential styling conflict |

### Structural Observations

- The `agent_pages/` folder is well-organized — follows a clear pattern
- The `user_pages/` folder is clean and compact
- Admin portal files are the ones needing the most organizational attention
- The `config/` folder is proper but could contain `connection.php` too
- PHPMailer could benefit from Composer autoloading instead of manual includes

---

## 9. Proposed Clean Folder Structure

```
capstoneSystem/
│
├── config/                                 ← All configuration
│   ├── connection.php                      ← Database connection (moved from root)
│   ├── paths.php                           ← URL/path constants
│   ├── mail_config.php                     ← SMTP settings
│   └── session_timeout.php                 ← Session timeout handler
│
├── shared/                                 ← Shared utilities & components
│   ├── helpers/
│   │   ├── mail_helper.php                 ← PHPMailer wrapper (moved from root)
│   │   ├── email_template.php              ← Email template builder (moved from root)
│   │   └── notification_helper.php         ← Agent notification functions (promoted)
│   ├── components/
│   │   └── logout_modal.php                ← Logout confirmation modal
│   └── middleware/
│       └── auth_check.php                  ← Future: centralized auth middleware
│
├── auth/                                   ← Authentication flow (moved from root)
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── two_factor.php
│   ├── verify_2fa.php
│   └── send_2fa.php
│
├── admin/                                  ← Admin portal (moved from root)
│   ├── pages/
│   │   ├── dashboard.php                   ← admin_dashboard.php
│   │   ├── properties.php                  ← property.php
│   │   ├── property_detail.php             ← view_property.php
│   │   ├── add_property.php                ← add_property.php
│   │   ├── agents.php                      ← agent.php
│   │   ├── agent_detail.php                ← review_agent_details.php
│   │   ├── tour_requests.php               ← tour_requests.php
│   │   ├── property_tours.php              ← property_tour_requests.php
│   │   ├── sale_approvals.php              ← admin_property_sale_approvals.php
│   │   ├── notifications.php               ← admin_notifications.php
│   │   ├── notification_detail.php         ← admin_notification_view.php
│   │   ├── reports.php                     ← reports.php
│   │   ├── settings.php                    ← admin_settings.php
│   │   └── profile.php                     ← admin_profile.php
│   ├── api/
│   │   ├── settings_api.php
│   │   ├── save_property.php
│   │   ├── update_property.php
│   │   ├── get_property_data.php
│   │   ├── get_property_photos.php
│   │   ├── add_featured_photos.php
│   │   ├── update_featured_photo.php
│   │   ├── delete_featured_photo.php
│   │   ├── add_floor_photos.php
│   │   ├── update_floor_photo.php
│   │   ├── delete_floor_photo.php
│   │   ├── tour_request_details.php
│   │   ├── tour_request_accept.php
│   │   ├── tour_request_reject.php
│   │   ├── tour_request_cancel.php
│   │   ├── tour_request_complete.php
│   │   ├── check_tour_conflict.php
│   │   ├── finalize_sale.php
│   │   ├── mark_as_sold.php
│   │   ├── save_admin_info.php
│   │   ├── review_agent_process.php
│   │   └── download_document.php
│   ├── components/
│   │   ├── navbar.php
│   │   ├── sidebar.php
│   │   ├── profile_check.php
│   │   ├── profile_modal.php
│   │   ├── agent_card_template.php
│   │   └── property_card_template.php
│   └── modals/
│       ├── edit_property_modal.php
│       └── tour_modals.html
│
├── agent_pages/                            ← Agent portal (already organized)
│   ├── (current structure maintained)
│   └── ...
│
├── user_pages/                             ← User/Consumer portal (already organized)
│   ├── (current structure maintained)
│   └── ...
│
├── assets/                                 ← All static assets
│   ├── css/
│   │   ├── vendor/                         ← Third-party CSS (moved from assets/css/)
│   │   │   ├── bootstrap.min.css
│   │   │   ├── bootstrap-icons.min.css
│   │   │   ├── fontawesome-all.min.css
│   │   │   ├── inter-font.css
│   │   │   └── uicons-regular-straight.css
│   │   ├── admin_layout.css                ← Moved from css/
│   │   ├── agent_property_modals.css       ← Moved from css/
│   │   ├── login_style.css                 ← Moved from css/
│   │   └── property_style.css              ← Moved from css/ (or removed if legacy)
│   ├── js/
│   │   ├── vendor/                         ← Third-party JS (moved from assets/js/)
│   │   │   ├── bootstrap.bundle.min.js
│   │   │   ├── chart.umd.min.js
│   │   │   ├── chartjs-adapter-date-fns.bundle.min.js
│   │   │   ├── jspdf.umd.min.js
│   │   │   ├── jspdf.plugin.autotable.min.js
│   │   │   └── xlsx.full.min.js
│   │   ├── add_property_script.js          ← Moved from script/
│   │   ├── agent_property_modals.js        ← Moved from script/
│   │   ├── property_filter.js              ← Moved from script/
│   │   └── tour_requests.js                ← Moved from script/
│   ├── fonts/
│   │   ├── bootstrap-icons/
│   │   ├── inter/
│   │   └── uicons/
│   ├── webfonts/
│   └── images/                             ← Moved from /images/
│       ├── Logo.png
│       ├── LogoName.png
│       └── ...
│
├── uploads/                                ← User-generated content (unchanged)
│   ├── admins/
│   ├── agents/
│   ├── floors/
│   └── *.jpg
│
├── sale_documents/                         ← Sale documents (unchanged)
│
├── PHPMailer/                              ← Vendor library (unchanged)
│   └── src/
│
├── docs/                                   ← Documentation (unchanged)
│   ├── SYSTEM_ARCHITECTURE_GUIDE.md        ← This file
│   └── ...
│
├── .gitignore
└── README.md
```

---

## 10. Naming Conventions

### Files

| Convention | Rule | Example |
|-----------|------|---------|
| **Pages** | `snake_case.php` — descriptive, no portal prefix when inside portal folder | `dashboard.php`, `agent_property.php` |
| **API Endpoints** | `snake_case.php` — action-oriented | `save_property.php`, `tour_request_accept.php` |
| **Components** | `snake_case.php` — component type suffix recommended | `navbar.php`, `property_card_template.php` |
| **Utilities/Helpers** | `snake_case.php` — `_helper` suffix | `mail_helper.php`, `notification_helper.php` |
| **Config** | `snake_case.php` — descriptive | `mail_config.php`, `session_timeout.php` |
| **CSS** | `snake_case.css` — scope prefix | `admin_layout.css`, `login_style.css` |
| **JavaScript** | `snake_case.js` — scope prefix | `tour_requests.js`, `add_property_script.js` |
| **Images** | `camelCase` or `kebab-case` — descriptive | `hero-bg.jpg`, `LogoName.png` |

### Database

| Convention | Rule | Example |
|-----------|------|---------|
| **Tables** | `snake_case` (lowercase) | `tour_requests`, `property_images` |
| **Columns** | `snake_case` preferred (existing uses PascalCase for some) | `property_ID`, `ListingPrice` |
| **Foreign Keys** | `fk_{table}_{column}` | `fk_rental_property` |
| **Indexes** | `idx_{table}_{columns}` | `idx_property_filter` |

> **Note:** The database has inconsistent column naming (mix of PascalCase: `StreetAddress`, `ListingPrice` and snake_case: `property_id`, `tour_date`). This is due to incremental development. Renaming DB columns is a high-risk change and should only be considered in a future major version.

### PHP Variables & Functions

| Convention | Rule | Example |
|-----------|------|---------|
| **Variables** | `$snake_case` | `$property_data`, `$agent_info` |
| **Functions** | `camelCase()` | `sendSystemMail()`, `checkAdminProfileCompletion()` |
| **Constants** | `UPPER_SNAKE_CASE` | `BASE_URL`, `MAIL_HOST` |
| **Session Keys** | `snake_case` strings | `$_SESSION['account_id']`, `$_SESSION['role_id']` |

---

## 11. Phase-Based Restructuring Plan

> **⚠ CRITICAL RULE:** Only ONE phase is implemented at a time. Verify the entire system works before proceeding to the next phase. Each phase is designed to be independently deployable.

---

### Phase 0: Preparation & Backup

**Objective:** Create a safe environment for restructuring. Ensure a full backup exists.

**Files Affected:** None (no code changes)

**Changes:**
1. Create a full project backup (ZIP the entire `capstoneSystem/` folder)
2. Export a fresh database dump (`realestatesystem.sql`)
3. Create a Git branch: `git checkout -b restructure/phase-0-backup`
4. Tag the current state: `git tag v1.0-pre-restructure`
5. Document the current working state (all features confirmed functional)

**Expected Outcome:**
- Complete backup exists at a safe location
- Git branch created
- All current functionality verified as baseline

**Verification Checklist:**
- [ ] Full ZIP backup created
- [ ] Fresh SQL dump exported
- [ ] Git branch `restructure/phase-0-backup` created
- [ ] Admin login → dashboard loads correctly
- [ ] Agent login → dashboard loads correctly
- [ ] User homepage loads correctly
- [ ] Tour request submission works
- [ ] Property add/edit works
- [ ] Email sending works (2FA, notifications)
- [ ] Reports page loads with data

---

### Phase 1: Config & Shared Infrastructure

**Objective:** Move `connection.php` into `config/` and create the `shared/helpers/` directory to centralize utilities. Update all `include`/`require` paths.

**Files Affected:** (All files that include `connection.php`, `mail_helper.php`, or `email_template.php`)

**Changes:**

| Step | Action | Details |
|------|--------|---------|
| 1.1 | Move `connection.php` → `config/connection.php` | Physical file move |
| 1.2 | Create `shared/` and `shared/helpers/` directories | New folders |
| 1.3 | Move `mail_helper.php` → `shared/helpers/mail_helper.php` | Physical file move |
| 1.4 | Move `email_template.php` → `shared/helpers/email_template.php` | Physical file move |
| 1.5 | Copy `agent_pages/agent_notification_helper.php` → `shared/helpers/notification_helper.php` | Copy (keep original temporarily) |
| 1.6 | Move `logout_modal.php` → `shared/components/logout_modal.php` | Physical file move |
| 1.7 | Update ALL `include`/`require` paths for `connection.php` | ~70+ references |
| 1.8 | Update ALL `include`/`require` paths for `mail_helper.php` | ~15 references |
| 1.9 | Update ALL `include`/`require` paths for `email_template.php` | ~10 references |
| 1.10 | Update `mail_helper.php` internal include of `config/mail_config.php` | Path adjustment |
| 1.11 | Update `logout_modal.php` include paths | In `admin_navbar.php`, `admin_sidebar.php` |

**Path Update Details:**

```php
// BEFORE (from root files):
require_once 'connection.php';
// AFTER:
require_once __DIR__ . '/config/connection.php';

// BEFORE (from agent_pages/):
require_once '../connection.php';
// AFTER:
require_once __DIR__ . '/../config/connection.php';

// BEFORE (from user_pages/):
require_once '../connection.php';
// AFTER:
require_once __DIR__ . '/../config/connection.php';

// BEFORE (mail_helper from root):
require_once 'mail_helper.php';
// AFTER:
require_once __DIR__ . '/shared/helpers/mail_helper.php';

// BEFORE (mail_helper from agent_pages/):
require_once '../mail_helper.php';
// AFTER:
require_once __DIR__ . '/../shared/helpers/mail_helper.php';

// BEFORE (email_template from root):
require_once 'email_template.php';
// AFTER:
require_once __DIR__ . '/shared/helpers/email_template.php';

// BEFORE (internal mail_helper.php include):
require_once 'config/mail_config.php';
// AFTER:
require_once __DIR__ . '/../config/mail_config.php';
```

**Expected Outcome:**
- All configuration in `config/`
- All shared utilities in `shared/helpers/`
- Root directory ~4 files leaner
- All pages continue to work identically

**Verification Checklist:**
- [ ] Admin dashboard loads (connection works)
- [ ] Agent dashboard loads (connection works)
- [ ] User homepage loads (connection works)
- [ ] 2FA email sends (mail_helper works)
- [ ] Tour accept sends email (email_template works)
- [ ] Agent notification creation works (notification_helper works)
- [ ] Admin logout modal appears (logout_modal works)
- [ ] No PHP warnings/errors in browser
- [ ] Git commit with message: `Phase 1: Centralize config and shared helpers`

---

### Phase 2: Admin Portal Consolidation

**Objective:** Move all admin-specific files from root into an `admin/` directory structure with `pages/`, `api/`, `components/`, and `modals/` subdirectories.

**Files Affected:** ~43 root-level admin files

**Changes:**

| Step | Action | Source → Destination |
|------|--------|---------------------|
| 2.1 | Create `admin/pages/`, `admin/api/`, `admin/components/`, `admin/modals/` | New directories |
| **Pages** |||
| 2.2 | Move admin page files | `admin_dashboard.php` → `admin/pages/dashboard.php` |
| 2.3 | | `property.php` → `admin/pages/properties.php` |
| 2.4 | | `view_property.php` → `admin/pages/property_detail.php` |
| 2.5 | | `add_property.php` → `admin/pages/add_property.php` |
| 2.6 | | `agent.php` → `admin/pages/agents.php` |
| 2.7 | | `review_agent_details.php` → `admin/pages/agent_detail.php` |
| 2.8 | | `agent_info_form.php` → `admin/pages/agent_info_form.php` |
| 2.9 | | `tour_requests.php` → `admin/pages/tour_requests.php` |
| 2.10 | | `property_tour_requests.php` → `admin/pages/property_tours.php` |
| 2.11 | | `admin_property_sale_approvals.php` → `admin/pages/sale_approvals.php` |
| 2.12 | | `admin_notifications.php` → `admin/pages/notifications.php` |
| 2.13 | | `admin_notification_view.php` → `admin/pages/notification_detail.php` |
| 2.14 | | `reports.php` → `admin/pages/reports.php` |
| 2.15 | | `admin_settings.php` → `admin/pages/settings.php` |
| 2.16 | | `admin_profile.php` → `admin/pages/profile.php` |
| **API Endpoints** |||
| 2.17 | Move admin API files | `admin_settings_api.php` → `admin/api/settings_api.php` |
| 2.18 | | `save_property.php` → `admin/api/save_property.php` |
| 2.19 | | `update_property.php` → `admin/api/update_property.php` |
| 2.20 | | `get_property_data.php` → `admin/api/get_property_data.php` |
| 2.21 | | `get_property_photos.php` → `admin/api/get_property_photos.php` |
| 2.22 | | `add_featured_photos.php` → `admin/api/add_featured_photos.php` |
| 2.23 | | `update_featured_photo.php` → `admin/api/update_featured_photo.php` |
| 2.24 | | `delete_featured_photo.php` → `admin/api/delete_featured_photo.php` |
| 2.25 | | `add_floor_photos.php` → `admin/api/add_floor_photos.php` |
| 2.26 | | `update_floor_photo.php` → `admin/api/update_floor_photo.php` |
| 2.27 | | `delete_floor_photo.php` → `admin/api/delete_floor_photo.php` |
| 2.28 | | `admin_tour_request_details.php` → `admin/api/tour_request_details.php` |
| 2.29 | | `admin_tour_request_accept.php` → `admin/api/tour_request_accept.php` |
| 2.30 | | `admin_tour_request_reject.php` → `admin/api/tour_request_reject.php` |
| 2.31 | | `admin_tour_request_cancel.php` → `admin/api/tour_request_cancel.php` |
| 2.32 | | `admin_tour_request_complete.php` → `admin/api/tour_request_complete.php` |
| 2.33 | | `admin_check_tour_conflict.php` → `admin/api/check_tour_conflict.php` |
| 2.34 | | `admin_finalize_sale.php` → `admin/api/finalize_sale.php` |
| 2.35 | | `admin_mark_as_sold_process.php` → `admin/api/mark_as_sold.php` |
| 2.36 | | `save_admin_info.php` → `admin/api/save_admin_info.php` |
| 2.37 | | `review_agent_details_process.php` → `admin/api/review_agent_process.php` |
| 2.38 | | `download_document.php` → `admin/api/download_document.php` |
| **Components** |||
| 2.39 | Move admin components | `admin_navbar.php` → `admin/components/navbar.php` |
| 2.40 | | `admin_sidebar.php` → `admin/components/sidebar.php` |
| 2.41 | | `admin_profile_check.php` → `admin/components/profile_check.php` |
| 2.42 | | `admin_profile_modal.php` → `admin/components/profile_modal.php` |
| 2.43 | | `admin_agent_card_template.php` → `admin/components/agent_card_template.php` |
| 2.44 | | `admin_property_card_template.php` → `admin/components/property_card_template.php` |
| 2.45 | | `agent_display.php` → `admin/components/agent_display.php` |
| **Modals** |||
| 2.46 | Move admin modals | `modals/edit_property_modal.php` → `admin/modals/edit_property_modal.php` |
| 2.47 | | `modals/tour_modals.html` → `admin/modals/tour_modals.html` |

**Reference Updates Required:**

```php
// ALL admin pages — include path updates:
// BEFORE:
require_once 'config/connection.php';
require_once 'config/session_timeout.php';
require_once 'config/paths.php';
require_once 'admin_sidebar.php';
// AFTER (from admin/pages/):
require_once __DIR__ . '/../../config/connection.php';
require_once __DIR__ . '/../../config/session_timeout.php';
require_once __DIR__ . '/../../config/paths.php';
require_once __DIR__ . '/../components/sidebar.php';

// Session timeout redirect update (from admin/pages/):
// BEFORE: header("Location: login.php?timeout=1");
// AFTER:  header("Location: ../../auth/login.php?timeout=1");
// (or update after Phase auth move)

// JavaScript AJAX endpoint path updates:
// BEFORE: fetch('admin_tour_request_details.php')
// AFTER:  fetch('../api/tour_request_details.php')
// OR use BASE_URL: fetch(BASE_URL + 'admin/api/tour_request_details.php')

// Tour modals HTML fetch:
// BEFORE: fetch('modals/tour_modals.html')
// AFTER:  fetch('../modals/tour_modals.html')
// OR use BASE_URL: fetch(BASE_URL + 'admin/modals/tour_modals.html')

// Admin login redirects in verify_2fa.php:
// BEFORE: 'redirect' => 'admin_dashboard.php'
// AFTER:  'redirect' => 'admin/pages/dashboard.php'

// Form actions in add_property.php:
// BEFORE: action="save_property.php"
// AFTER:  action="../api/save_property.php"
```

**Expected Outcome:**
- Root directory reduced from ~57 PHP files to ~0 admin files
- Clean `admin/` folder with clear sub-organization
- All admin functionality preserved

**Verification Checklist:**
- [ ] Admin login redirects to `admin/pages/dashboard.php` correctly
- [ ] Dashboard loads with all KPIs and charts
- [ ] Sidebar navigation links work (all pages accessible)
- [ ] Property listing page loads with cards
- [ ] Add property form submits successfully
- [ ] Edit property modal opens, loads data, saves changes
- [ ] Photo upload/delete/replace works
- [ ] Floor photo management works
- [ ] Tour request page loads, filter tabs work
- [ ] Tour detail modal loads via AJAX
- [ ] Tour accept/reject/cancel/complete work
- [ ] Agent listing page loads
- [ ] Agent review page loads with approve/reject actions
- [ ] Sale approval page loads and approve/reject work
- [ ] Notifications page loads with AJAX actions
- [ ] Reports page loads with all charts
- [ ] Settings page CRUD works
- [ ] Admin profile page loads, edit modal saves
- [ ] No 404 errors in browser console
- [ ] No broken AJAX calls in Network tab
- [ ] Git commit: `Phase 2: Consolidate admin portal into admin/ directory`

---

### Phase 3: Agent Portal Alignment

**Objective:** Align agent_pages to mirror the admin structure. Create `api/` and `components/` subdirectories. This phase has smaller scope since agent_pages is already organized.

**Files Affected:** ~32 files in `agent_pages/`

**Changes:**

| Step | Action | Source → Destination |
|------|--------|---------------------|
| 3.1 | Create `agent_pages/api/` and `agent_pages/components/` | New directories |
| **API Endpoints → api/** |||
| 3.2 | Move API files | `add_property_process.php` → `api/add_property_process.php` |
| 3.3 | | `update_property_process.php` → `api/update_property_process.php` |
| 3.4 | | `update_price_process.php` → `api/update_price_process.php` |
| 3.5 | | `upload_property_image.php` → `api/upload_property_image.php` |
| 3.6 | | `delete_property_image.php` → `api/delete_property_image.php` |
| 3.7 | | `reorder_property_images.php` → `api/reorder_property_images.php` |
| 3.8 | | `upload_floor_image.php` → `api/upload_floor_image.php` |
| 3.9 | | `delete_floor_image.php` → `api/delete_floor_image.php` |
| 3.10 | | `remove_floor.php` → `api/remove_floor.php` |
| 3.11 | | `mark_as_sold_process.php` → `api/mark_as_sold_process.php` |
| 3.12 | | `save_agent_profile.php` → `api/save_agent_profile.php` |
| 3.13 | | `tour_request_details.php` → `api/tour_request_details.php` |
| 3.14 | | `tour_request_accept.php` → `api/tour_request_accept.php` |
| 3.15 | | `tour_request_reject.php` → `api/tour_request_reject.php` |
| 3.16 | | `tour_request_cancel.php` → `api/tour_request_cancel.php` |
| 3.17 | | `tour_request_complete.php` → `api/tour_request_complete.php` |
| 3.18 | | `check_tour_conflict.php` → `api/check_tour_conflict.php` |
| 3.19 | | `get_property_tour_requests.php` → `api/get_property_tour_requests.php` |
| 3.20 | | `agent_notifications_api.php` → `api/notifications_api.php` |
| **Components → components/** |||
| 3.21 | Move components | `agent_navbar.php` → `components/navbar.php` |
| 3.22 | | `property_card_template.php` → `components/property_card_template.php` |
| 3.23 | | `logout_agent_modal.php` → `components/logout_modal.php` |

**Reference Update Details:**

```php
// From agent page files (e.g., agent_dashboard.php):
// BEFORE: include 'agent_navbar.php';
// AFTER:  include __DIR__ . '/components/navbar.php';

// AJAX endpoints in JS within agent pages:
// BEFORE: fetch('tour_request_accept.php', ...)
// AFTER:  fetch('api/tour_request_accept.php', ...)

// Notification API in navbar JS:
// BEFORE: fetch('agent_notifications_api.php?action=fetch')
// AFTER:  fetch('api/notifications_api.php?action=fetch')

// From agent API files — connection path adjustment:
// BEFORE (from agent_pages/): require_once '../config/connection.php';
// AFTER  (from agent_pages/api/): require_once '../../config/connection.php';
```

**Expected Outcome:**
- Clean separation within `agent_pages/` between pages, API endpoints, and components
- Mirrors the admin portal structure
- All agent functionality preserved

**Verification Checklist:**
- [ ] Agent login redirects to agent_pages dashboard correctly
- [ ] Agent dashboard loads with KPIs
- [ ] Agent navbar loads with notification dropdown
- [ ] Property listing page loads with status tabs
- [ ] Add property form works
- [ ] View property detail page loads
- [ ] Edit property modal works (all AJAX calls)
- [ ] Photo upload/delete/reorder works
- [ ] Floor image management works
- [ ] Price update works
- [ ] Mark as sold works
- [ ] Tour requests page loads
- [ ] Tour accept/reject/cancel/complete work
- [ ] Commissions page loads
- [ ] Notifications page and dropdown work
- [ ] Agent profile view and edit work
- [ ] Logout works
- [ ] Git commit: `Phase 3: Align agent portal structure (api/, components/)`

---

### Phase 4: User/Consumer Portal Cleanup

**Objective:** Create `api/` subdirectory in `user_pages/`, remove backup file, and ensure clean separation.

**Files Affected:** ~12 files in `user_pages/`

**Changes:**

| Step | Action | Source → Destination |
|------|--------|---------------------|
| 4.1 | Create `user_pages/api/` | New directory |
| 4.2 | Move `request_tour_process.php` → `api/request_tour_process.php` | |
| 4.3 | Move `increment_property_view.php` → `api/increment_property_view.php` | |
| 4.4 | Move `like_property.php` → `api/like_property.php` | |
| 4.5 | Move `get_likes.php` → `api/get_likes.php` | |
| 4.6 | Delete `property_details_backup.php` | Remove legacy backup |
| 4.7 | Move `navbar.php` → `components/navbar.php` (or keep as-is) | Optional |
| 4.8 | Update AJAX paths in `property_details.php` | `request_tour_process.php` → `api/request_tour_process.php`, etc. |
| 4.9 | Update AJAX paths in `search_results.php` | Self-reference stays same |

**Reference Update Details:**

```javascript
// In property_details.php JavaScript:
// BEFORE: fetch('request_tour_process.php', ...)
// AFTER:  fetch('api/request_tour_process.php', ...)

// BEFORE: fetch('increment_property_view.php', ...)
// AFTER:  fetch('api/increment_property_view.php', ...)

// BEFORE: fetch('like_property.php', ...)
// AFTER:  fetch('api/like_property.php', ...)

// BEFORE: fetch('get_likes.php?property_id=')
// AFTER:  fetch('api/get_likes.php?property_id=')
```

**Expected Outcome:**
- User pages have clean page/API separation
- Legacy backup removed
- All public functionality preserved

**Verification Checklist:**
- [ ] Homepage loads with featured properties
- [ ] Search results page loads via AJAX grid
- [ ] Property detail page loads with gallery
- [ ] View count increments on property detail visit
- [ ] Like/unlike buttons work
- [ ] Tour request form submits successfully
- [ ] Tour request generates notifications and emails
- [ ] Agent listing page loads
- [ ] Agent profile page loads
- [ ] About page loads with stats
- [ ] No 404 errors in console
- [ ] Git commit: `Phase 4: Clean up user portal (api/, remove backup)`

---

### Phase 5: Assets & Static Resources

**Objective:** Consolidate all static assets. Move custom CSS/JS into `assets/`, move `images/` into `assets/images/`, and remove the now-empty `css/`, `script/`, `modals/`, and `images/` root folders.

**Files Affected:** All files referencing CSS/JS/image paths

**Changes:**

| Step | Action | Details |
|------|--------|---------|
| 5.1 | Move `css/*.css` → `assets/css/` | 4 custom CSS files |
| 5.2 | Move current `assets/css/*.css` → `assets/css/vendor/` | 5 vendor CSS files |
| 5.3 | Move `script/*.js` → `assets/js/` | 4 custom JS files |
| 5.4 | Move current `assets/js/*.js` → `assets/js/vendor/` | 6 vendor JS files |
| 5.5 | Move `images/` → `assets/images/` | 15 static image files |
| 5.6 | Delete empty `css/` folder | Cleanup |
| 5.7 | Delete empty `script/` folder | Cleanup |
| 5.8 | Delete empty `modals/` folder (if moved in Phase 2) | Cleanup |
| 5.9 | Delete empty `images/` folder | Cleanup |
| 5.10 | Update `config/paths.php` | Add `ASSETS_IMAGES`, update if needed |
| 5.11 | Update ALL CSS `<link>` references | Throughout all pages |
| 5.12 | Update ALL JS `<script>` references | Throughout all pages |
| 5.13 | Update ALL image `src` references | Throughout all pages |

**Path Update Details:**

```php
// Update config/paths.php — add images path:
define('ASSETS_IMAGES', BASE_URL . 'assets/images/');

// CSS references:
// BEFORE: <link href="css/admin_layout.css" ...>
// AFTER:  <link href="<?= ASSETS_CSS ?>admin_layout.css" ...>
//   or:   <link href="<?= BASE_URL ?>assets/css/admin_layout.css" ...>

// BEFORE: <link href="<?= ASSETS_CSS ?>bootstrap.min.css" ...>
// AFTER:  <link href="<?= ASSETS_CSS ?>vendor/bootstrap.min.css" ...>

// JS references:
// BEFORE: <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
// AFTER:  <script src="<?= ASSETS_JS ?>vendor/bootstrap.bundle.min.js"></script>

// BEFORE: <script src="script/tour_requests.js"></script>
// AFTER:  <script src="<?= ASSETS_JS ?>tour_requests.js"></script>

// Image references:
// BEFORE: <img src="images/Logo.png" ...>
// AFTER:  <img src="<?= ASSETS_IMAGES ?>Logo.png" ...>
// or:     <img src="<?= BASE_URL ?>assets/images/Logo.png" ...>
```

**Expected Outcome:**
- Single `assets/` directory contains ALL static resources
- Vendor vs. custom assets clearly separated
- No loose `css/`, `script/`, `images/`, or `modals/` in root
- `config/paths.php` provides constants for all asset directories

**Verification Checklist:**
- [ ] All admin pages render with correct styling (no broken CSS)
- [ ] All agent pages render with correct styling
- [ ] All user pages render with correct styling
- [ ] Bootstrap components work (dropdowns, modals, tooltips)
- [ ] Bootstrap Icons display correctly
- [ ] Font Awesome icons display correctly
- [ ] Inter font loads correctly
- [ ] Chart.js renders charts on dashboard/reports/commissions
- [ ] jsPDF export works on reports page
- [ ] SheetJS Excel export works on reports page
- [ ] All images load (logos, backgrounds, placeholders)
- [ ] No 404 errors for CSS/JS/fonts/images in Network tab
- [ ] Git commit: `Phase 5: Consolidate all static assets`

---

### Phase 6: Cleanup, Legacy Removal & Final QA

**Objective:** Remove all legacy/deprecated files, update the auth flow (move auth files to `auth/`), perform comprehensive QA, and finalize documentation.

**Files Affected:** Legacy files + auth files

**Changes:**

| Step | Action | Details |
|------|--------|---------|
| **Legacy Removal** |||
| 6.1 | Delete `add_agent.php` | Legacy — references non-existent `agents` table |
| 6.2 | Delete `save_agent.php` | Legacy — references non-existent `agents` table |
| 6.3 | Delete `test_admin_modal.php` | Dev/test file |
| 6.4 | Delete `add_buyer_email_column.php` | Empty migration file |
| 6.5 | Delete `css/property_style.css` if unused | Legacy "Prestige Properties" theme |
| **Auth Consolidation** |||
| 6.6 | Create `auth/` directory | New folder |
| 6.7 | Move `login.php` → `auth/login.php` | |
| 6.8 | Move `register.php` → `auth/register.php` | |
| 6.9 | Move `logout.php` → `auth/logout.php` | |
| 6.10 | Move `two_factor.php` → `auth/two_factor.php` | |
| 6.11 | Move `verify_2fa.php` → `auth/verify_2fa.php` | |
| 6.12 | Move `send_2fa.php` → `auth/send_2fa.php` | |
| **Update ALL auth references** |||
| 6.13 | Update login redirects everywhere | `login.php` → `auth/login.php` |
| 6.14 | Update register links | `register.php` → `auth/register.php` |
| 6.15 | Update logout links/actions | `logout.php` → `auth/logout.php` |
| 6.16 | Update 2FA internal references | Cross-references between auth files |
| 6.17 | Update session timeout redirect | `config/session_timeout.php` redirect paths |
| 6.18 | Update `verify_2fa.php` role redirects | Dashboard redirect paths |
| **Final Cleanup** |||
| 6.19 | Verify root has NO PHP files except `README.md` and `.gitignore` | The root should now be clean |
| 6.20 | Update `agent_pages/logout.php` redirect | `../login.php` → `../../auth/login.php` |
| 6.21 | Update `README.md` with new structure | Documentation |

**Auth Reference Update Map:**

```php
// config/session_timeout.php:
// Detect calling path depth and redirect appropriately
// Use BASE_URL for consistent redirects:
header("Location: " . BASE_URL . "auth/login.php?timeout=1");

// verify_2fa.php role redirects:
// Admin:  'redirect' => BASE_URL . 'admin/pages/dashboard.php'
// Agent:  'redirect' => BASE_URL . 'agent_pages/agent_dashboard.php'
// New Agent: 'redirect' => BASE_URL . 'admin/pages/agent_info_form.php'

// All session checks:
// BEFORE: header("Location: login.php");
// AFTER:  header("Location: " . BASE_URL . "auth/login.php");
// OR with relative paths calculated from current file depth

// agent_pages/logout.php:
// BEFORE: header("Location: ../login.php");
// AFTER:  header("Location: ../auth/login.php"); // if auth/ is at root level
```

**Expected Outcome:**
- Zero PHP files in root (only `README.md`, `.gitignore`)
- Clean auth flow in `auth/`
- No legacy/dead code in codebase
- Professional project structure

**Verification Checklist:**
- [ ] Login page accessible at `auth/login.php`
- [ ] Registration works at `auth/register.php`
- [ ] 2FA flow works end-to-end
- [ ] Admin login → admin dashboard
- [ ] Agent login → agent dashboard (or agent info form for new agents)
- [ ] Session timeout redirects to `auth/login.php`
- [ ] Admin logout works
- [ ] Agent logout works
- [ ] No reference to deleted legacy files
- [ ] No PHP files in root directory
- [ ] Complete run-through of ALL features passes
- [ ] Git commit: `Phase 6: Auth consolidation, legacy removal, final QA`
- [ ] Git tag: `v2.0-restructured`

---

## 12. Reference Update Checklist

When moving any file, use this master checklist to ensure nothing breaks:

### PHP Includes/Requires

- [ ] `require_once` / `include_once` for `connection.php`
- [ ] `require_once` / `include_once` for `config/paths.php`
- [ ] `require_once` / `include_once` for `config/session_timeout.php`
- [ ] `require_once` / `include_once` for `mail_helper.php`
- [ ] `require_once` / `include_once` for `email_template.php`
- [ ] `require_once` / `include_once` for layout components (navbar, sidebar)
- [ ] `require_once` / `include_once` for modal components
- [ ] `require_once` / `include_once` for card templates
- [ ] `require_once` / `include_once` for `notification_helper.php`
- [ ] `require_once` / `include_once` for `admin_profile_check.php`
- [ ] `require_once` / `include_once` for PHPMailer files

### Header Redirects

- [ ] `header("Location: login.php")` — all auth guards
- [ ] `header("Location: admin_dashboard.php")` — post-login admin
- [ ] `header("Location: agent_pages/agent_dashboard.php")` — post-login agent
- [ ] `header("Location: agent_info_form.php")` — agent profile setup
- [ ] `header("Location: property.php")` — after property actions
- [ ] `header("Location: agent.php")` — after agent actions
- [ ] `header("Location: admin_notifications.php")` — notification fallbacks
- [ ] `header("Location: ../login.php")` — agent pages auth guard
- [ ] Session timeout redirects in `config/session_timeout.php`

### JavaScript Redirects

- [ ] `window.location.href = '...'` in inline scripts
- [ ] `window.location.replace('...')` in auth flows

### AJAX Endpoints (fetch / XMLHttpRequest)

- [ ] All `fetch()` calls to admin API endpoints
- [ ] All `fetch()` calls to agent API endpoints
- [ ] All `fetch()` calls to user API endpoints
- [ ] Tour modal HTML lazy-load path (`modals/tour_modals.html`)
- [ ] Notification API calls in navbar dropdowns

### Form Actions

- [ ] `<form action="save_property.php">`
- [ ] `<form action="save_agent.php">`
- [ ] `<form action="save_admin_info.php">`
- [ ] All POST-to-self forms (ensure self-targeting works at new path)

### Asset Paths

- [ ] CSS `<link>` hrefs — vendor and custom
- [ ] JS `<script>` srcs — vendor and custom
- [ ] Image `<img>` srcs — static images
- [ ] Font CSS `url()` references (relative paths in font CSS files)
- [ ] Background images in CSS (`background-image: url(...)`)
- [ ] Upload directory references for property images
- [ ] Upload directory references for profile pictures
- [ ] Upload directory references for floor images
- [ ] Sale document directory references

### Hardcoded URLs

- [ ] Search for `href="login.php"` in all files
- [ ] Search for `href="admin_dashboard.php"` in all files
- [ ] Search for any absolute paths with `capstoneSystem/` in them
- [ ] Search for `window.location` assignments

---

## 13. Quality Assurance Protocol

### Per-Phase QA Process

After each phase, execute the following:

#### 1. Automated Checks
```bash
# Check for PHP syntax errors in all files
find . -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# Search for broken include references
grep -rn "require_once\|include_once\|require\|include" --include="*.php" . | grep -v "vendor\|PHPMailer"

# Search for old file references that no longer exist
# (Run after each phase based on moved files)
grep -rn "admin_dashboard\.php\|admin_navbar\.php" --include="*.php" .
```

#### 2. Browser Testing
- [ ] Open every page in the browser
- [ ] Check browser console for JavaScript errors
- [ ] Check Network tab for 404 responses
- [ ] Check for broken CSS (visual inspection)
- [ ] Test all form submissions
- [ ] Test all AJAX operations

#### 3. Feature Testing Matrix

| Feature | Admin | Agent | User |
|---------|-------|-------|------|
| Login | ✓ | ✓ | N/A |
| 2FA | ✓ | ✓ | N/A |
| Dashboard | ✓ | ✓ | N/A |
| View Properties | ✓ | ✓ | ✓ |
| Add Property | ✓ | ✓ | N/A |
| Edit Property | ✓ | ✓ | N/A |
| Photo Management | ✓ | ✓ | N/A |
| Tour Requests | ✓ (manage) | ✓ (manage) | ✓ (submit) |
| Tour Actions | ✓ | ✓ | N/A |
| Agent Management | ✓ | N/A | N/A |
| Sale Verification | ✓ | ✓ | N/A |
| Notifications | ✓ | ✓ | N/A |
| Reports | ✓ | N/A | N/A |
| Settings | ✓ | N/A | N/A |
| Profile | ✓ | ✓ | N/A |
| Search/Filter | N/A | N/A | ✓ |
| Like/View Count | N/A | N/A | ✓ |
| Email Sending | ✓ | ✓ | ✓ |
| Session Timeout | ✓ | ✓ | N/A |
| Logout | ✓ | ✓ | N/A |

#### 4. Database Integrity
- [ ] No orphaned records
- [ ] All foreign keys intact
- [ ] File paths in DB match actual file locations (uploads)

---

## 14. Scalability & Future Expansion Notes

### Short-Term Improvements (Post-Restructure)

| Improvement | Priority | Description |
|------------|----------|-------------|
| **Centralized Auth Middleware** | High | Create a single `auth_check.php` in `shared/middleware/` that all protected pages include, replacing duplicate session checks |
| **Error Handler** | High | Create `shared/helpers/error_handler.php` for consistent error responses (JSON for API, redirect for pages) |
| **CSRF Token Helper** | High | Create `shared/helpers/csrf.php` — centralized token generation and validation |
| **Environment Config** | Medium | Create `.env` file for database credentials, mail config, debug mode instead of hardcoded values |
| **Autoloader** | Medium | Implement a simple PSR-4-style autoloader or use Composer |
| **Upload Path Constant** | Medium | Define `UPLOAD_DIR` in `config/paths.php` for consistent upload path references |

### Medium-Term Improvements

| Improvement | Priority | Description |
|------------|----------|-------------|
| **Database Abstraction** | Medium | Create a lightweight DB wrapper class in `shared/helpers/database.php` (prepared statements, error handling) |
| **API Response Helper** | Medium | Standardize all JSON responses through a helper: `jsonResponse($success, $message, $data)` |
| **Pagination Helper** | Low | Centralized pagination component (used by user search, admin lists, agent lists) |
| **User Registration** | High | Add buyer/user role with account creation, wishlists, saved searches |
| **Admin Activity Logging** | Medium | Extend `admin_logs` to track all admin actions (not just login/logout) |

### Long-Term Architecture Considerations

| Consideration | Description |
|--------------|-------------|
| **MVC Pattern** | Consider refactoring to a lightweight MVC structure (`controllers/`, `models/`, `views/`) for the next major version |
| **Routing** | Implement a front controller (`index.php`) with URL routing instead of direct file access |
| **Templating** | Consider a templating engine (Twig, Blade) to separate PHP logic from HTML |
| **ORM** | Consider Eloquent (standalone) or Doctrine for database operations |
| **Composer** | Use Composer for dependency management (PHPMailer, Chart.js CDN, etc.) |
| **REST API** | Build a proper REST API layer if mobile app or SPA frontend is planned |
| **Role-Based Access Control** | Expand the role system beyond admin/agent (buyer, manager, super-admin) |
| **Multi-Tenancy** | If scaling to multiple agencies, consider tenant-based data isolation |

### File Naming for Future Features

When adding new features, follow these conventions:

```
admin/pages/{feature_name}.php           ← Admin feature page
admin/api/{action_name}.php              ← Admin API endpoint
admin/components/{component_name}.php    ← Admin reusable component
agent_pages/{feature_name}.php           ← Agent feature page
agent_pages/api/{action_name}.php        ← Agent API endpoint
agent_pages/components/{component_name}.php ← Agent reusable component
user_pages/{feature_name}.php            ← User feature page
user_pages/api/{action_name}.php         ← User API endpoint
user_pages/components/{component_name}.php ← User reusable component
shared/helpers/{helper_name}.php         ← Shared utility file
config/{config_name}.php                 ← Configuration file
assets/css/{scope}_{name}.css            ← Custom stylesheet
assets/js/{scope}_{name}.js              ← Custom script
docs/{FEATURE_NAME}.md                   ← Documentation
```

---

## Final Clean Structure (After All Phases)

```
capstoneSystem/
│
├── auth/                           ← Authentication (Phase 6)
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── two_factor.php
│   ├── verify_2fa.php
│   └── send_2fa.php
│
├── config/                         ← Configuration (Phase 1)
│   ├── connection.php
│   ├── paths.php
│   ├── mail_config.php
│   └── session_timeout.php
│
├── shared/                         ← Shared resources (Phase 1)
│   ├── helpers/
│   │   ├── mail_helper.php
│   │   ├── email_template.php
│   │   └── notification_helper.php
│   └── components/
│       └── logout_modal.php
│
├── admin/                          ← Admin portal (Phase 2)
│   ├── pages/                      (15 page files)
│   ├── api/                        (22 API endpoint files)
│   ├── components/                 (7 component files)
│   └── modals/                     (2 modal files)
│
├── agent_pages/                    ← Agent portal (Phase 3)
│   ├── api/                        (19 API endpoint files)
│   ├── components/                 (3 component files)
│   ├── modals/                     (1 modal file)
│   └── (7 page files)
│
├── user_pages/                     ← User portal (Phase 4)
│   ├── api/                        (4 API endpoint files)
│   ├── components/                 (1 component: navbar.php)
│   └── (6 page files)
│
├── assets/                         ← Static assets (Phase 5)
│   ├── css/
│   │   ├── vendor/                 (5 vendor CSS files)
│   │   └── (4 custom CSS files)
│   ├── js/
│   │   ├── vendor/                 (6 vendor JS files)
│   │   └── (4 custom JS files)
│   ├── fonts/
│   ├── webfonts/
│   └── images/                     (15 static images)
│
├── uploads/                        ← Dynamic uploads (unchanged)
├── sale_documents/                 ← Sale documents (unchanged)
├── PHPMailer/                      ← PHP library (unchanged)
├── docs/                           ← Documentation
│
├── .gitignore
└── README.md
```

**Total files: ~107 PHP + assets | Portals: 3 | Phases: 7 (incl. Phase 0)**

---

> **Document maintained by:** Development Team  
> **Last updated:** March 4, 2026  
> **Next review:** After completion of each restructuring phase
