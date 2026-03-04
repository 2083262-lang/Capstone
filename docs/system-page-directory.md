# System Page Directory

Complete mapping of every page in the HomeEstate Realty system, organised by portal.  
Use this as the **single source of truth** when referencing pages during development or prompting.

**Last updated:** March 2026

---

## Table of Contents

1. [Authentication (Shared)](#1-authentication-shared)
2. [Admin Portal](#2-admin-portal)
3. [Agent Portal](#3-agent-portal)
4. [User Portal (Public)](#4-user-portal-public)
5. [Shared Utilities & Config](#5-shared-utilities--config)
6. [Quick Reference Table](#6-quick-reference-table)

---

## 1. Authentication (Shared)

These pages handle login, registration, and 2FA for **all roles**. They live in the project root.

| File | Purpose |
|------|---------|
| `login.php` | Login form (credentials → 2FA flow) |
| `register.php` | Agent registration form |
| `two_factor.php` | 2FA code entry screen |
| `verify_2fa.php` | 2FA code verification endpoint (POST) |
| `send_2fa.php` | Sends/resends 2FA code via email (AJAX) |
| `logout.php` | Destroys session (admin logout) |
| `logout_modal.php` | Logout confirmation modal (admin) |

---

## 2. Admin Portal

> **Role required:** `admin` (`role_id = 1`)  
> **Base path:** `/` (project root)

### 2.1 Main Pages (Full UI)

These are the navigable pages an admin visits directly.

| File | Page Title / Purpose |
|------|---------------------|
| `admin_dashboard.php` | Admin dashboard — statistics, charts, overview |
| `property.php` | Property listings management (table, filters) |
| `add_property.php` | Add new property form |
| `view_property.php` | Single property detail view / edit |
| `agent.php` | Agent management (list, approve, deactivate) |
| `add_agent.php` | Add new agent form |
| `review_agent_details.php` | Review a specific agent's submitted profile |
| `tour_requests.php` | All tour requests list |
| `admin_tour_request_details.php` | Single tour request detail view |
| `property_tour_requests.php` | Tour requests filtered by a specific property |
| `admin_property_sale_approvals.php` | Property sale verification / approval queue |
| `admin_finalize_sale.php` | Finalize an approved sale |
| `reports.php` | Reports & analytics page |
| `admin_notifications.php` | Notification center (list) |
| `admin_notification_view.php` | Single notification detail |
| `admin_profile.php` | Admin profile page |
| `admin_settings.php` | System settings page |

### 2.2 Backend Processors (POST / AJAX)

These handle form submissions and return JSON or redirect. Never visited directly by the user.

| File | Triggered By | Purpose |
|------|-------------|---------|
| `save_property.php` | `add_property.php` | Save new property (shared with agent) |
| `update_property.php` | `view_property.php` | Update existing property details (JSON) |
| `save_agent.php` | `add_agent.php` | Create new agent account |
| `save_admin_info.php` | `admin_profile.php` | Save/update admin profile (JSON) |
| `review_agent_details_process.php` | `review_agent_details.php` | Approve / reject agent profile |
| `admin_mark_as_sold_process.php` | `view_property.php` | Mark property as sold (JSON) |
| `admin_check_tour_conflict.php` | Tour detail modals | Check for tour schedule conflicts (JSON) |
| `admin_tour_request_accept.php` | `admin_tour_request_details.php` | Accept a tour request |
| `admin_tour_request_reject.php` | `admin_tour_request_details.php` | Reject a tour request |
| `admin_tour_request_cancel.php` | `admin_tour_request_details.php` | Cancel a tour request |
| `admin_tour_request_complete.php` | `admin_tour_request_details.php` | Mark a tour as completed |
| `admin_settings_api.php` | `admin_settings.php` | Settings CRUD endpoint (JSON) |
| `download_document.php` | Sale approvals page | Serve sale document file download |

### 2.3 Property Photo Management (AJAX / JSON)

| File | Purpose |
|------|---------|
| `add_featured_photos.php` | Upload featured (main) property photos |
| `update_featured_photo.php` | Replace a featured photo |
| `delete_featured_photo.php` | Delete a featured photo |
| `add_floor_photos.php` | Upload floor plan photos |
| `update_floor_photo.php` | Replace a floor plan photo |
| `delete_floor_photo.php` | Delete a floor plan photo |
| `get_property_data.php` | Fetch property details for editing (JSON) |
| `get_property_photos.php` | Fetch all photos for a property (JSON) |

### 2.4 UI Components (Included Partials)

These are `include`d inside other pages — never accessed directly via URL.

| File | Included By | Purpose |
|------|------------|---------|
| `admin_navbar.php` | All admin pages | Top navigation bar |
| `admin_sidebar.php` | All admin pages | Side navigation menu |
| `admin_profile_modal.php` | Admin pages | Profile completion modal |
| `admin_profile_check.php` | Admin pages | Helper: checks if admin profile is complete |
| `admin_agent_card_template.php` | `agent.php` | Agent card HTML template |
| `admin_property_card_template.php` | `property.php` | Property card HTML template |
| `agent_display.php` | `agent.php` | Fetches & renders agent cards (partial) |
| `agent_info_form.php` | Post-login flow | Agent profile completion form (for new agents) |

---

## 3. Agent Portal

> **Role required:** `agent` (`role_id = 2`)  
> **Base path:** `/agent_pages/`

### 3.1 Main Pages (Full UI)

| File | Page Title / Purpose |
|------|---------------------|
| `agent_dashboard.php` | Agent dashboard — stats, tours, commissions overview |
| `agent_property.php` | Agent's property listings management |
| `view_agent_property.php` | Single property detail view (agent perspective) |
| `agent_tour_requests.php` | Agent's tour requests list |
| `tour_request_details.php` | Single tour request detail view |
| `agent_commissions.php` | Commissions & earnings page |
| `agent_notifications.php` | Agent notification center |
| `agent_profile.php` | Agent profile / settings page |

### 3.2 Backend Processors (POST / AJAX)

| File | Triggered By | Purpose |
|------|-------------|---------|
| `add_property_process.php` | `agent_property.php` | Submit new property for approval |
| `update_property_process.php` | `view_agent_property.php` | Update property details |
| `update_price_process.php` | `view_agent_property.php` | Update property listing price |
| `save_agent_profile.php` | `agent_profile.php` | Save agent profile changes |
| `mark_as_sold_process.php` | `view_agent_property.php` | Submit sale verification to admin |
| `tour_request_accept.php` | `tour_request_details.php` | Accept a tour request |
| `tour_request_reject.php` | `tour_request_details.php` | Reject a tour request |
| `tour_request_cancel.php` | `tour_request_details.php` | Cancel a tour request |
| `tour_request_complete.php` | `tour_request_details.php` | Mark tour as completed |
| `check_tour_conflict.php` | Tour action modals | Check for tour schedule conflicts (JSON) |
| `get_property_tour_requests.php` | `view_agent_property.php` | Fetch tour requests for a property (JSON) |
| `agent_notifications_api.php` | `agent_notifications.php` | Notifications CRUD / mark-read (JSON) |

### 3.3 Property Photo Management (AJAX / JSON)

| File | Purpose |
|------|---------|
| `upload_property_image.php` | Upload property featured images |
| `delete_property_image.php` | Delete a property image |
| `reorder_property_images.php` | Update image sort order |
| `upload_floor_image.php` | Upload floor plan images |
| `delete_floor_image.php` | Delete a floor plan image |
| `remove_floor.php` | Remove an entire floor and its images |

### 3.4 UI Components (Included Partials)

| File | Included By | Purpose |
|------|------------|---------|
| `agent_navbar.php` | All agent pages | Top navigation bar (dark theme) |
| `property_card_template.php` | `agent_property.php` | Property card HTML template |
| `logout_agent_modal.php` | Agent pages | Logout confirmation modal |
| `agent_notification_helper.php` | Various agent processors | Creates agent notification records |
| `logout.php` | `logout_agent_modal.php` | Destroys agent session |

---

## 4. User Portal (Public)

> **Role required:** None (public-facing)  
> **Base path:** `/user_pages/`

### 4.1 Main Pages (Full UI)

| File | Page Title / Purpose |
|------|---------------------|
| `index.php` | Homepage — featured listings, hero section |
| `search_results.php` | Property search results with filters |
| `property_details.php` | Full property detail page (gallery, map, info) |
| `agents.php` | Browse all approved agents |
| `agent_profile.php` | Individual agent profile page |
| `about.php` | About us / company information page |

### 4.2 Backend Processors (AJAX / JSON)

| File | Triggered By | Purpose |
|------|-------------|---------|
| `request_tour_process.php` | `property_details.php` | Submit a tour request |
| `like_property.php` | Property cards / detail page | Toggle property like (JSON) |
| `get_likes.php` | Property cards | Get like count / status (JSON) |
| `increment_property_view.php` | `property_details.php` | Increment view counter (JSON) |

### 4.3 UI Components (Included Partials)

| File | Included By | Purpose |
|------|------------|---------|
| `navbar.php` | All user pages | Public navigation bar |
| `property_details_backup.php` | — | Backup / previous version of property details |

---

## 5. Shared Utilities & Config

These files are **not tied to any single portal** — they are utilities, configs, or libraries used across the system.

### 5.1 Configuration (`/config/`)

| File | Purpose |
|------|---------|
| `config/paths.php` | Asset path constants (CSS, JS, images) |
| `config/mail_config.php` | SMTP / email credentials |
| `config/session_timeout.php` | Session timeout handler (30-min inactivity logout) |

### 5.2 Database & Email

| File | Purpose |
|------|---------|
| `connection.php` | MySQLi database connection |
| `mail_helper.php` | PHPMailer wrapper for sending emails |
| `email_template.php` | Reusable HTML email layout builder |
| `PHPMailer/get_oauth_token.php` | OAuth token helper for email sending |

### 5.3 Modals (`/modals/`)

| File | Purpose |
|------|---------|
| `modals/edit_property_modal.php` | Property edit modal (used in admin views) |

### 5.4 Dev / Migration (Can be removed in production)

| File | Purpose |
|------|---------|
| `add_buyer_email_column.php` | One-time DB migration script (empty) |
| `test_admin_modal.php` | Dev test page for admin profile modal |

---

## 6. Quick Reference Table

Total page counts by portal:

| Portal | Full Pages | Processors / API | Components | Total |
|--------|-----------|-------------------|------------|-------|
| Auth (Shared) | 4 | 3 | 0 | **7** |
| Admin | 17 | 13 | 8 | **38** |
| — Admin Photos | — | 8 | — | *(included above)* |
| Agent | 8 | 12 | 5 | **25** |
| — Agent Photos | — | 6 | — | *(included above)* |
| User (Public) | 6 | 4 | 2 | **12** |
| Shared Utils | — | — | 7 | **7** |
| **Total** | **35** | **32** | **22** | **89** |

### File Location Cheat Sheet

When referencing files in prompts, use these prefixes:

| Portal | Prefix | Example |
|--------|--------|---------|
| Auth & Admin | `/` (root) | `admin_dashboard.php` |
| Agent | `/agent_pages/` | `agent_pages/agent_dashboard.php` |
| User | `/user_pages/` | `user_pages/index.php` |
| Config | `/config/` | `config/session_timeout.php` |
| Modals | `/modals/` | `modals/edit_property_modal.php` |
| Docs | `/docs/` | `docs/system-page-directory.md` |

---

*This file is part of the project documentation. Keep it updated when adding or removing pages.*
