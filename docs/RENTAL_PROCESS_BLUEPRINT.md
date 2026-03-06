# Rental Process Blueprint

> **Version:** 1.0  
> **Date:** 2026-03-05  
> **Purpose:** Complete implementation reference for the rental verification → approval → lease finalization → commission workflow, mirroring the existing sale process.

---

## Table of Contents

1. [Overview & Goals](#1-overview--goals)
2. [Sale vs Rental — Workflow Comparison](#2-sale-vs-rental--workflow-comparison)
3. [Existing Rental Infrastructure Audit](#3-existing-rental-infrastructure-audit)
4. [Database Schema — New Tables](#4-database-schema--new-tables)
5. [Database Schema — Modified Tables](#5-database-schema--modified-tables)
6. [SQL Migration Script](#6-sql-migration-script)
7. [File Inventory — New Files](#7-file-inventory--new-files)
8. [File Inventory — Modified Files](#8-file-inventory--modified-files)
9. [Workflow Detail — Step by Step](#9-workflow-detail--step-by-step)
10. [Admin Rental Approvals Page](#10-admin-rental-approvals-page)
11. [Agent Rental Dashboard](#11-agent-rental-dashboard)
12. [Commission Handling for Rentals](#12-commission-handling-for-rentals)
13. [Notification Matrix](#13-notification-matrix)
14. [Front-End Components](#14-front-end-components)
15. [Security & Validation](#15-security--validation)
16. [Phase-Based Implementation Plan](#16-phase-based-implementation-plan)
17. [Testing Checklist](#17-testing-checklist)
18. [Open Questions & Future Considerations](#18-open-questions--future-considerations)

---

## 1. Overview & Goals

### What This Document Covers

A complete rental lifecycle that mirrors the existing **sale verification → admin approval → finalized sale → agent commission → payment** pipeline, adapted for the recurring nature of rentals.

### Core Principles

| Principle | Detail |
|-----------|--------|
| **Mirror the sale workflow** | Same patterns, naming conventions, table structures — just adapted for rentals |
| **Keep it simple** | One-time commission per lease signing (no monthly payment tracking for now) |
| **Reuse existing infrastructure** | Extend `agent_commissions` with a `commission_type` column rather than creating a separate table |
| **Minimal disruption** | Existing sale code remains untouched; rental code lives in parallel paths |

### Rental Lifecycle Summary

```
Agent/Admin submits rental verification (tenant found)
        ↓
  Property Status → "Pending Rented"
        ↓
  Admin reviews on Rental Approvals page
        ↓
    ┌─── Approve ───┐        ┌─── Reject ───┐
    │               │        │              │
    ▼               │        ▼              │
finalized_rentals   │   Status → "For Rent" │
record created      │   (back to available)  │
    │               │                        │
    ▼               │                        │
Property Status     │                        │
→ "Rented"          │                        │
is_locked = 1       │                        │
    │               │                        │
    ▼               │                        │
agent_commissions   │                        │
record created      │                        │
(type = 'rental')   │                        │
    │               │                        │
    ▼               │                        │
Admin pays          │                        │
commission          │                        │
(same payment flow) │                        │
```

---

## 2. Sale vs Rental — Workflow Comparison

| Step | Sale Process | Rental Process (New) |
|------|-------------|---------------------|
| **Submission** | Agent/Admin submits to `sale_verifications` | Agent/Admin submits to `rental_verifications` |
| **Documents** | Stored in `sale_verification_documents` | Stored in `rental_verification_documents` |
| **Property Status** | → `Pending Sold` | → `Pending Rented` |
| **Admin Review Page** | `admin_property_sale_approvals.php` | `admin_property_rental_approvals.php` |
| **Approval Result** | Creates `finalized_sales` record | Creates `finalized_rentals` record |
| **Final Property Status** | → `Sold`, `is_locked = 1` | → `Rented`, `is_locked = 1` |
| **Commission** | `agent_commissions` (sale_id FK) | `agent_commissions` (lease_id FK, type = 'rental') |
| **Commission Basis** | % of final sale price | % of total lease value OR flat fee (configurable) |
| **Payment** | `process_commission_payment.php` (shared) | Same file — already handles by `commission_id` |
| **Agent Notification Types** | `sale_approved`, `sale_rejected`, `commission_paid` | `rental_approved`, `rental_rejected`, `commission_paid` |
| **Property Log Action** | `SOLD` | `RENTED` |

---

## 3. Existing Rental Infrastructure Audit

The codebase already has substantial rental support for **listing** rental properties. Here is what already exists vs. what needs to be built.

### ✅ Already Exists (No Changes Needed for These)

| Feature | Files | Notes |
|---------|-------|-------|
| `rental_details` table | SQL schema | `rental_id`, `property_id`, `monthly_rent`, `security_deposit`, `lease_term_months`, `furnishing`, `available_from` |
| Rental data on property creation | `save_property.php` (L139-181, L372-377) | Validates & inserts rental fields when Status = "For Rent" |
| Rental data on property update | `update_property.php` (L167-216) | Updates or inserts rental_details |
| Rental data fetch | `get_property_data.php` (L25-31) | LEFT JOIN to rental_details |
| Property listing with rental data | `property.php` (L30-40) | LEFT JOIN rental_details in main query |
| Rental display on view page | `view_property.php` (L511-517, L2544-2566) | Fetches & displays rental info section |
| Edit property modal (admin) | `modals/edit_property_modal.php` (L107-122) | Rental fields in edit form |
| Agent: rental validation on add | `agent_pages/add_property_process.php` (L139-178) | Same validation as admin side |
| Agent: rental update | `agent_pages/update_property_process.php` (L166-203) | Same pattern |
| Agent: property listing | `agent_pages/agent_property.php` (L30-40) | LEFT JOIN rental_details |
| Agent: rental display | `agent_pages/view_agent_property.php` (L83-91) | Rental info section |
| Agent: edit modal | `agent_pages/modals/edit_property_modal.php` (L181-224) | Rental fields |
| Card templates | `admin_property_card_template.php` (L14-18), `agent_pages/property_card_template.php` (L34-38) | Shows monthly rent for rental properties |
| User: rental details | `user_pages/property_details.php` (L42-50) | Public-facing rental info |
| User: "For Rent" filter | `user_pages/search_results.php` (L131-133) | Status filter on search |
| Rental data rows | 5 properties (IDs 9, 12, 13, 16, 18) currently "For Rent" | Live data ready for testing |

### ❌ Does Not Exist Yet (Must Be Built)

| Feature | Parallel Sale Equivalent |
|---------|------------------------|
| Rental verification submission (agent) | `agent_pages/mark_as_sold_process.php` |
| Rental verification submission (admin) | `admin_mark_as_sold_process.php` |
| Admin rental approvals page | `admin_property_sale_approvals.php` |
| Admin finalize rental (approve handler) | `admin_finalize_sale.php` |
| Admin reject rental handler | (inline in sale approvals JS) |
| Rental document upload/download | `sale_verification_documents` table + `download_document.php` |
| "Mark as Rented" modal (agent) | "Mark as Sold" modal |
| "Mark as Rented" modal (admin) | "Mark as Sold" modal |
| `rental_verifications` table | `sale_verifications` |
| `rental_verification_documents` table | `sale_verification_documents` |
| `finalized_rentals` table | `finalized_sales` |
| Commission for rentals | Extend `agent_commissions` |
| Property Status = "Pending Rented" | "Pending Sold" |
| Property Status = "Rented" | "Sold" |
| `property_log.action` = 'RENTED' | 'SOLD' |
| `agent_notifications.notif_type` for rentals | sale_approved, sale_rejected |
| `notifications.item_type` for rentals | property_sale |

---

## 4. Database Schema — New Tables

### 4.1 `rental_verifications`

> Mirrors `sale_verifications`. Submitted by agent or admin when a tenant has been found.

```sql
CREATE TABLE `rental_verifications` (
  `verification_id`   INT(11)        NOT NULL AUTO_INCREMENT,
  `property_id`       INT(11)        NOT NULL,
  `agent_id`          INT(11)        NOT NULL,
  `tenant_name`       VARCHAR(255)   NOT NULL,
  `tenant_email`      VARCHAR(255)   DEFAULT NULL,
  `tenant_phone`      VARCHAR(20)    DEFAULT NULL,
  `monthly_rent`      DECIMAL(12,2)  NOT NULL          COMMENT 'Agreed monthly rent',
  `security_deposit`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `lease_start_date`  DATE           NOT NULL,
  `lease_end_date`    DATE           NOT NULL,
  `lease_term_months` INT(11)        NOT NULL,
  `additional_notes`  TEXT           DEFAULT NULL,
  `status`            ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
  `admin_notes`       TEXT           DEFAULT NULL       COMMENT 'Admin reason for rejection',
  `reviewed_by`       INT(11)        DEFAULT NULL,
  `reviewed_at`       TIMESTAMP      NULL DEFAULT NULL,
  `submitted_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`verification_id`),
  KEY `idx_rv_property_id` (`property_id`),
  KEY `idx_rv_agent_id` (`agent_id`),
  KEY `idx_rv_status` (`status`),

  CONSTRAINT `fk_rv_property` FOREIGN KEY (`property_id`)
    REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Key Differences from `sale_verifications`:**
- `buyer_name` → `tenant_name`, `buyer_email` → `tenant_email`, plus `tenant_phone`
- `sale_price` & `sale_date` → `monthly_rent`, `security_deposit`, `lease_start_date`, `lease_end_date`, `lease_term_months`
- No `sale_date` equivalent — replaced by `lease_start_date` + `lease_end_date`

---

### 4.2 `rental_verification_documents`

> Mirrors `sale_verification_documents`. Lease agreements, tenant IDs, etc.

```sql
CREATE TABLE `rental_verification_documents` (
  `document_id`       INT(11)       NOT NULL AUTO_INCREMENT,
  `verification_id`   INT(11)       NOT NULL,
  `original_filename` VARCHAR(255)  NOT NULL,
  `stored_filename`   VARCHAR(255)  NOT NULL,
  `file_path`         VARCHAR(500)  NOT NULL,
  `file_size`         INT(11)       DEFAULT NULL,
  `mime_type`         VARCHAR(100)  DEFAULT NULL,
  `uploaded_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`document_id`),
  KEY `idx_rvd_verification_id` (`verification_id`),

  CONSTRAINT `fk_rvd_verification` FOREIGN KEY (`verification_id`)
    REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Upload directory:** `rental_documents/{property_id}/`  
(Mirrors `sale_documents/{property_id}/`)

---

### 4.3 `finalized_rentals`

> Mirrors `finalized_sales`. Created when admin approves a rental verification.

```sql
CREATE TABLE `finalized_rentals` (
  `lease_id`          INT(11)        NOT NULL AUTO_INCREMENT,
  `verification_id`   INT(11)        NOT NULL,
  `property_id`       INT(11)        NOT NULL,
  `agent_id`          INT(11)        NOT NULL,
  `tenant_name`       VARCHAR(255)   NOT NULL,
  `tenant_email`      VARCHAR(255)   DEFAULT NULL,
  `tenant_phone`      VARCHAR(20)    DEFAULT NULL,
  `monthly_rent`      DECIMAL(12,2)  NOT NULL,
  `security_deposit`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `lease_start_date`  DATE           NOT NULL,
  `lease_end_date`    DATE           NOT NULL,
  `lease_term_months` INT(11)        NOT NULL,
  `total_lease_value` DECIMAL(15,2)  NOT NULL COMMENT 'monthly_rent × lease_term_months',
  `additional_notes`  TEXT           DEFAULT NULL,
  `finalized_by`      INT(11)        NOT NULL,
  `finalized_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_locked`         TINYINT(1)     NOT NULL DEFAULT 1,
  `lease_status`      ENUM('active','expired','terminated','renewed') NOT NULL DEFAULT 'active',

  PRIMARY KEY (`lease_id`),
  UNIQUE KEY `uq_fr_verification` (`verification_id`),
  KEY `idx_fr_property_id` (`property_id`),
  KEY `idx_fr_agent_id` (`agent_id`),
  KEY `idx_fr_finalized_by` (`finalized_by`),
  KEY `idx_fr_lease_status` (`lease_status`),

  CONSTRAINT `fk_fr_verification` FOREIGN KEY (`verification_id`)
    REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_property` FOREIGN KEY (`property_id`)
    REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `accounts` (`account_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_finalized_by` FOREIGN KEY (`finalized_by`)
    REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Key Differences from `finalized_sales`:**
- `sale_id` → `lease_id`
- `buyer_*` → `tenant_*` (plus `tenant_phone`)
- `final_sale_price`, `sale_date` → `monthly_rent`, `security_deposit`, `lease_start_date`, `lease_end_date`, `lease_term_months`, `total_lease_value`
- Added `lease_status` for future lease lifecycle management (active, expired, terminated, renewed)

---

## 5. Database Schema — Modified Tables

### 5.1 `agent_commissions` — Add Rental Support

> **Strategy:** Extend the existing `agent_commissions` table with a `commission_type` discriminator and optional `lease_id` FK, rather than creating a separate `rental_commissions` table. This lets us reuse the entire payment processing, proof upload, and commission management UI.

```sql
-- New columns
ALTER TABLE `agent_commissions`
  ADD COLUMN `commission_type` ENUM('sale','rental') NOT NULL DEFAULT 'sale'
    AFTER `commission_id`,
  ADD COLUMN `lease_id` INT(11) DEFAULT NULL
    AFTER `sale_id`,
  ADD KEY `idx_commission_type` (`commission_type`),
  ADD KEY `idx_commission_lease` (`lease_id`),
  ADD CONSTRAINT `fk_commission_lease` FOREIGN KEY (`lease_id`)
    REFERENCES `finalized_rentals` (`lease_id`) ON DELETE CASCADE;

-- Make sale_id nullable (was NOT NULL, because rental commissions won't have a sale_id)
ALTER TABLE `agent_commissions`
  MODIFY COLUMN `sale_id` INT(11) DEFAULT NULL;

-- Drop the old unique key on sale_id (since it will be NULL for rentals)
ALTER TABLE `agent_commissions`
  DROP INDEX `uq_sale_commission`;

-- Re-create as conditional unique indexes (MariaDB handles NULL in UNIQUE gracefully)
ALTER TABLE `agent_commissions`
  ADD UNIQUE KEY `uq_sale_commission` (`sale_id`),
  ADD UNIQUE KEY `uq_lease_commission` (`lease_id`);
```

**Constraint logic:**
- For sale commissions: `sale_id` is set, `lease_id` is NULL
- For rental commissions: `lease_id` is set, `sale_id` is NULL
- The `commission_type` column makes filtering trivial

---

### 5.2 `property` — New Status Values

The `Status` column is `VARCHAR(50)` (not ENUM), so no ALTER needed — just use the new values in code.

| New Status Value | When Set | Mirror Of |
|-----------------|----------|-----------|
| `Pending Rented` | Agent/admin submits rental verification | `Pending Sold` |
| `Rented` | Admin approves rental verification | `Sold` |

---

### 5.3 `property_log` — New Action

The `action` column is currently `ENUM('CREATED','UPDATED','DELETED','SOLD','REJECTED')`.

```sql
ALTER TABLE `property_log`
  MODIFY COLUMN `action` ENUM('CREATED','UPDATED','DELETED','SOLD','REJECTED','RENTED')
    NOT NULL DEFAULT 'CREATED';
```

---

### 5.4 `notifications` — New Item Type

The `item_type` column is currently `ENUM('agent','property','property_sale','tour')`.

```sql
ALTER TABLE `notifications`
  MODIFY COLUMN `item_type` ENUM('agent','property','property_sale','property_rental','tour')
    NOT NULL DEFAULT 'agent';
```

---

### 5.5 `agent_notifications` — New Notification Types

The `notif_type` column is currently:  
`ENUM('tour_new','tour_cancelled','tour_completed','property_approved','property_rejected','sale_approved','sale_rejected','commission_paid','general')`

```sql
ALTER TABLE `agent_notifications`
  MODIFY COLUMN `notif_type` ENUM(
    'tour_new','tour_cancelled','tour_completed',
    'property_approved','property_rejected',
    'sale_approved','sale_rejected',
    'rental_approved','rental_rejected',
    'commission_paid','general'
  ) NOT NULL DEFAULT 'general';
```

---

### 5.6 `price_history` — New Event Type

The `event_type` column is currently `ENUM('Listed','Price Change','Sold','Off Market')`.

```sql
ALTER TABLE `price_history`
  MODIFY COLUMN `event_type` ENUM('Listed','Price Change','Sold','Rented','Off Market')
    NOT NULL;
```

---

## 6. SQL Migration Script

> Single file: `migrations/add_rental_workflow.sql`

```sql
-- ============================================================
-- RENTAL WORKFLOW MIGRATION
-- Run this on the realestatesystem database
-- ============================================================

-- 1. Create rental_verifications
CREATE TABLE IF NOT EXISTS `rental_verifications` (
  `verification_id`   INT(11)        NOT NULL AUTO_INCREMENT,
  `property_id`       INT(11)        NOT NULL,
  `agent_id`          INT(11)        NOT NULL,
  `tenant_name`       VARCHAR(255)   NOT NULL,
  `tenant_email`      VARCHAR(255)   DEFAULT NULL,
  `tenant_phone`      VARCHAR(20)    DEFAULT NULL,
  `monthly_rent`      DECIMAL(12,2)  NOT NULL,
  `security_deposit`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `lease_start_date`  DATE           NOT NULL,
  `lease_end_date`    DATE           NOT NULL,
  `lease_term_months` INT(11)        NOT NULL,
  `additional_notes`  TEXT           DEFAULT NULL,
  `status`            ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
  `admin_notes`       TEXT           DEFAULT NULL,
  `reviewed_by`       INT(11)        DEFAULT NULL,
  `reviewed_at`       TIMESTAMP      NULL DEFAULT NULL,
  `submitted_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`verification_id`),
  KEY `idx_rv_property_id` (`property_id`),
  KEY `idx_rv_agent_id` (`agent_id`),
  KEY `idx_rv_status` (`status`),
  CONSTRAINT `fk_rv_property` FOREIGN KEY (`property_id`)
    REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Create rental_verification_documents
CREATE TABLE IF NOT EXISTS `rental_verification_documents` (
  `document_id`       INT(11)       NOT NULL AUTO_INCREMENT,
  `verification_id`   INT(11)       NOT NULL,
  `original_filename` VARCHAR(255)  NOT NULL,
  `stored_filename`   VARCHAR(255)  NOT NULL,
  `file_path`         VARCHAR(500)  NOT NULL,
  `file_size`         INT(11)       DEFAULT NULL,
  `mime_type`         VARCHAR(100)  DEFAULT NULL,
  `uploaded_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_rvd_verification_id` (`verification_id`),
  CONSTRAINT `fk_rvd_verification` FOREIGN KEY (`verification_id`)
    REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create finalized_rentals
CREATE TABLE IF NOT EXISTS `finalized_rentals` (
  `lease_id`          INT(11)        NOT NULL AUTO_INCREMENT,
  `verification_id`   INT(11)        NOT NULL,
  `property_id`       INT(11)        NOT NULL,
  `agent_id`          INT(11)        NOT NULL,
  `tenant_name`       VARCHAR(255)   NOT NULL,
  `tenant_email`      VARCHAR(255)   DEFAULT NULL,
  `tenant_phone`      VARCHAR(20)    DEFAULT NULL,
  `monthly_rent`      DECIMAL(12,2)  NOT NULL,
  `security_deposit`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `lease_start_date`  DATE           NOT NULL,
  `lease_end_date`    DATE           NOT NULL,
  `lease_term_months` INT(11)        NOT NULL,
  `total_lease_value` DECIMAL(15,2)  NOT NULL,
  `additional_notes`  TEXT           DEFAULT NULL,
  `finalized_by`      INT(11)        NOT NULL,
  `finalized_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_locked`         TINYINT(1)     NOT NULL DEFAULT 1,
  `lease_status`      ENUM('active','expired','terminated','renewed') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`lease_id`),
  UNIQUE KEY `uq_fr_verification` (`verification_id`),
  KEY `idx_fr_property_id` (`property_id`),
  KEY `idx_fr_agent_id` (`agent_id`),
  KEY `idx_fr_finalized_by` (`finalized_by`),
  KEY `idx_fr_lease_status` (`lease_status`),
  CONSTRAINT `fk_fr_verification` FOREIGN KEY (`verification_id`)
    REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_property` FOREIGN KEY (`property_id`)
    REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `accounts` (`account_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_finalized_by` FOREIGN KEY (`finalized_by`)
    REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Extend agent_commissions for rental support
ALTER TABLE `agent_commissions`
  ADD COLUMN `commission_type` ENUM('sale','rental') NOT NULL DEFAULT 'sale'
    AFTER `commission_id`;

ALTER TABLE `agent_commissions`
  ADD COLUMN `lease_id` INT(11) DEFAULT NULL
    AFTER `sale_id`;

ALTER TABLE `agent_commissions`
  MODIFY COLUMN `sale_id` INT(11) DEFAULT NULL;

-- Drop old unique constraint and re-create (handles NULL for rental rows)
ALTER TABLE `agent_commissions`
  DROP INDEX `uq_sale_commission`;

ALTER TABLE `agent_commissions`
  ADD UNIQUE KEY `uq_sale_commission` (`sale_id`),
  ADD UNIQUE KEY `uq_lease_commission` (`lease_id`),
  ADD KEY `idx_commission_type` (`commission_type`),
  ADD KEY `idx_commission_lease` (`lease_id`);

ALTER TABLE `agent_commissions`
  ADD CONSTRAINT `fk_commission_lease` FOREIGN KEY (`lease_id`)
    REFERENCES `finalized_rentals` (`lease_id`) ON DELETE CASCADE;

-- 5. Extend property_log action enum
ALTER TABLE `property_log`
  MODIFY COLUMN `action` ENUM('CREATED','UPDATED','DELETED','SOLD','REJECTED','RENTED')
    NOT NULL DEFAULT 'CREATED';

-- 6. Extend notifications item_type enum
ALTER TABLE `notifications`
  MODIFY COLUMN `item_type` ENUM('agent','property','property_sale','property_rental','tour')
    NOT NULL DEFAULT 'agent';

-- 7. Extend agent_notifications notif_type enum
ALTER TABLE `agent_notifications`
  MODIFY COLUMN `notif_type` ENUM(
    'tour_new','tour_cancelled','tour_completed',
    'property_approved','property_rejected',
    'sale_approved','sale_rejected',
    'rental_approved','rental_rejected',
    'commission_paid','general'
  ) NOT NULL DEFAULT 'general';

-- 8. Extend price_history event_type enum
ALTER TABLE `price_history`
  MODIFY COLUMN `event_type` ENUM('Listed','Price Change','Sold','Rented','Off Market')
    NOT NULL;

-- 9. Create upload directory (manual step)
-- mkdir rental_documents/
-- Create .htaccess to deny direct access (same as sale_documents/)
```

---

## 7. File Inventory — New Files

### 7.1 Backend (PHP)

| # | File | Mirrors | Purpose |
|---|------|---------|---------|
| 1 | `agent_pages/mark_as_rented_process.php` | `agent_pages/mark_as_sold_process.php` | Agent submits rental verification |
| 2 | `admin_mark_as_rented_process.php` | `admin_mark_as_sold_process.php` | Admin submits rental verification |
| 3 | `admin_property_rental_approvals.php` | `admin_property_sale_approvals.php` | Admin rental approvals page (full page) |
| 4 | `admin_finalize_rental.php` | `admin_finalize_sale.php` | Backend handler — approve & create `finalized_rentals` + commission |
| 5 | `admin_reject_rental.php` | (inline in sale approvals) | Backend handler — reject rental verification |
| 6 | `download_rental_document.php` | `download_document.php` | Secure download for rental verification documents |
| 7 | `process_rental_commission_payment.php` | — | **OPTIONAL** — can reuse `process_commission_payment.php` since it works by `commission_id` |

### 7.2 Modals

| # | File | Mirrors | Purpose |
|---|------|---------|---------|
| 8 | `modals/mark_as_rented_modal.php` | (mark as sold modal inside view_property) | Admin-side "Mark as Rented" form modal |
| 9 | `agent_pages/modals/mark_as_rented_modal.php` | (mark as sold modal inside view_agent_property) | Agent-side "Mark as Rented" form modal |

### 7.3 Upload Directory

| # | Path | Mirrors | Purpose |
|---|------|---------|---------|
| 10 | `rental_documents/` | `sale_documents/` | Root directory for rental verification document uploads |
| 11 | `rental_documents/.htaccess` | `sale_documents/.htaccess` | Deny direct access; force downloads through PHP |

### 7.4 Migration

| # | File | Purpose |
|---|------|---------|
| 12 | `migrations/add_rental_workflow.sql` | Full SQL migration (Section 6 contents) |

---

## 8. File Inventory — Modified Files

### 8.1 Admin Side

| # | File | Changes Needed |
|---|------|---------------|
| 1 | `admin_sidebar.php` | Add "Rental Approvals" nav link (with pending count badge) |
| 2 | `admin_dashboard.php` | Add rental stats cards: pending rentals, active leases, rental commissions |
| 3 | `admin_navbar.php` | Add rental notification support in notification dropdown |
| 4 | `admin_notifications.php` | Handle `property_rental` item_type |
| 5 | `view_property.php` | Add "Mark as Rented" button for properties with Status = "For Rent" |
| 6 | `admin_property_card_template.php` | Show "Pending Rented" status badge, update lock icon logic |
| 7 | `admin_property_sale_approvals.php` | **Commission Management section**: Add `commission_type` filter chip to distinguish sale vs rental commissions |
| 8 | `reports.php` | Add rental-related report data (active leases, rental income, rental commissions) |

### 8.2 Agent Side

| # | File | Changes Needed |
|---|------|---------------|
| 9 | `agent_pages/agent_dashboard.php` | Add rental stats (active leases, pending rental verifications) |
| 10 | `agent_pages/agent_property.php` | Handle "Pending Rented" and "Rented" status badges |
| 11 | `agent_pages/view_agent_property.php` | Add "Mark as Rented" button for agent's "For Rent" properties |
| 12 | `agent_pages/property_card_template.php` | Show "Pending Rented" / "Rented" badges |
| 13 | `agent_pages/agent_commissions.php` | Show rental commissions alongside sale commissions (use `commission_type`) |
| 14 | `agent_pages/agent_notifications.php` | Handle `rental_approved`, `rental_rejected` notif_types |
| 15 | `agent_pages/agent_notification_helper.php` | Generate notification messages for rental events |

### 8.3 User Side

| # | File | Changes Needed |
|---|------|---------------|
| 16 | `user_pages/property_details.php` | Show "Rented" / "Pending Rented" status (no public action, display only) |
| 17 | `user_pages/search_results.php` | Add "Rented" status filter option (or exclude from results) |

### 8.4 Shared / Config

| # | File | Changes Needed |
|---|------|---------------|
| 18 | `connection.php` | No changes (already shared) |
| 19 | `mail_helper.php` | No changes (already generic) |
| 20 | `email_template.php` | Add rental-specific email templates (lease confirmation, rental approved/rejected) |
| 21 | `process_commission_payment.php` | No changes needed if `commission_type` is transparent (works by `commission_id`) |

---

## 9. Workflow Detail — Step by Step

### Step 1: Agent Submits Rental Verification

**Trigger:** Agent clicks "Mark as Rented" on a property with `Status = 'For Rent'`

**File:** `agent_pages/mark_as_rented_process.php`

**Process:**
1. Validate session (must be logged-in agent)
2. Validate POST fields:
   - `property_id` (required, int)
   - `tenant_name` (required, string)
   - `tenant_email` (optional, must be valid email if provided)
   - `tenant_phone` (optional)
   - `monthly_rent` (required, positive decimal)
   - `security_deposit` (required, non-negative decimal)
   - `lease_start_date` (required, date, not in the past beyond 30 days)
   - `lease_end_date` (required, date, must be after start date)
   - `additional_notes` (optional)
3. Validate file uploads (at least 1 document required):
   - Allowed types: PDF, JPG, PNG, DOC, DOCX
   - Max size: 120MB per file
4. Verify property belongs to agent (`property_log.action = 'CREATED'`, `account_id = agent_id`)
5. Verify property `Status = 'For Rent'` and `approval_status = 'approved'`
6. Check no existing pending/approved rental verification: `SELECT verification_id FROM rental_verifications WHERE property_id = ? AND status IN ('Pending','Approved')`
7. Calculate `lease_term_months` from dates (or accept from form)
8. **BEGIN TRANSACTION:**
   - INSERT into `rental_verifications` (status = 'Pending')
   - Upload files to `rental_documents/{property_id}/`
   - INSERT into `rental_verification_documents`
   - UPDATE `property` SET `Status = 'Pending Rented'` WHERE `property_ID = ?`
   - INSERT into `property_log` (action = 'UPDATED', reason = 'Rental verification submitted by agent')
   - INSERT into `notifications` (item_type = 'property_rental', for admin)
9. **COMMIT**
10. Return JSON success with `verification_id`

---

### Step 2: Admin Submits Rental Verification (Alternative Path)

**File:** `admin_mark_as_rented_process.php`

Same as Step 1 but:
- Admin role check instead of agent
- Determines agent from `property_log` (`action = 'CREATED'` → `account_id`)
- File path uses `__DIR__` for proper pathing

---

### Step 3: Admin Reviews on Rental Approvals Page

**File:** `admin_property_rental_approvals.php`

**Data queries:**
```php
// Pending rental verifications
$pending_rentals_sql = "
    SELECT rv.*, p.StreetAddress, p.City, p.Province, p.PropertyType,
           p.property_ID, p.ListingPrice,
           rd.monthly_rent AS listing_monthly_rent, rd.furnishing,
           CONCAT(a.first_name, ' ', a.last_name) AS agent_name,
           a.email AS agent_email
    FROM rental_verifications rv
    JOIN property p ON rv.property_id = p.property_ID
    LEFT JOIN rental_details rd ON p.property_ID = rd.property_id
    JOIN accounts a ON rv.agent_id = a.account_id
    WHERE rv.status = 'Pending'
    ORDER BY rv.submitted_at DESC
";

// All rental verifications (for history)
$all_rentals_sql = "
    SELECT rv.*, p.StreetAddress, p.City, p.PropertyType,
           CONCAT(a.first_name, ' ', a.last_name) AS agent_name,
           reviewer.first_name AS reviewer_name
    FROM rental_verifications rv
    JOIN property p ON rv.property_id = p.property_ID
    JOIN accounts a ON rv.agent_id = a.account_id
    LEFT JOIN accounts reviewer ON rv.reviewed_by = reviewer.account_id
    ORDER BY rv.submitted_at DESC
";

// Finalized rentals with commission info
$finalized_rentals_sql = "
    SELECT fr.*, p.StreetAddress, p.City,
           CONCAT(a.first_name, ' ', a.last_name) AS agent_name,
           ac.commission_amount, ac.commission_percentage, ac.status AS commission_status
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON fr.agent_id = a.account_id
    LEFT JOIN agent_commissions ac ON fr.lease_id = ac.lease_id
    ORDER BY fr.finalized_at DESC
";
```

**Page sections (mirror sale approvals):**
1. KPI stat cards (Pending Rentals, Active Leases, Total Rental Value, Rental Commissions)
2. Pending rental verification cards (with details, documents, approve/reject buttons)
3. Approved/Rejected history table
4. Commission Management for rental commissions (reusing the same UI pattern)

---

### Step 4A: Admin Approves Rental → Finalize

**File:** `admin_finalize_rental.php`

**POST parameters:**
- `property_id` (int)
- `agent_id` (int)
- `monthly_rent` (decimal — agreed amount, may differ from listing)
- `security_deposit` (decimal)
- `lease_start_date` (date)
- `lease_end_date` (date)
- `tenant_name` (string)
- `tenant_email` (string, optional)
- `tenant_phone` (string, optional)
- `commission_percentage` (decimal, 0-100)
- `notes` (text, optional)

**Process:**
1. Validate admin session
2. Validate all inputs
3. Calculate `lease_term_months` from dates
4. Calculate `total_lease_value = monthly_rent × lease_term_months`
5. Calculate `commission_amount = total_lease_value × (commission_percentage / 100)`
6. Verify property exists and Status = 'Pending Rented'
7. **BEGIN TRANSACTION:**
   - a) UPDATE `rental_verifications` SET `status = 'Approved'`, `reviewed_by`, `reviewed_at`
   - b) INSERT / UPDATE `finalized_rentals` record
   - c) UPDATE `property` SET `Status = 'Rented'`, `is_locked = 1`
   - d) INSERT into `property_log` (action = 'RENTED')
   - e) INSERT into `agent_commissions` (commission_type = 'rental', lease_id = ?, sale_id = NULL, status = 'calculated')
   - f) INSERT into `commission_payment_logs` (action = 'calculated')
   - g) INSERT into `price_history` (event_type = 'Rented', price = monthly_rent)
   - h) INSERT into `agent_notifications` (notif_type = 'rental_approved')
   - i) INSERT into `notifications` (item_type = 'property_rental', for admin log)
   - j) Send email to agent (lease approved, commission details)
   - k) Send email to tenant if email provided (lease confirmation)
8. **COMMIT**
9. Return JSON `{ ok: true, lease_id: X, commission_id: Y }`

---

### Step 4B: Admin Rejects Rental

**File:** `admin_reject_rental.php` (or inline AJAX handler)

**Process:**
1. Validate admin session
2. UPDATE `rental_verifications` SET `status = 'Rejected'`, `admin_notes`, `reviewed_by`, `reviewed_at`
3. UPDATE `property` SET `Status = 'For Rent'` (back to available)
4. INSERT into `property_log` (action = 'REJECTED')
5. INSERT into `agent_notifications` (notif_type = 'rental_rejected')
6. Send rejection email to agent
7. Return JSON success

---

### Step 5: Commission Payment

**No new file needed.** The existing `process_commission_payment.php` works by `commission_id` and is agnostic to whether the commission is sale or rental. The only prerequisite is that the `agent_commissions` row exists.

The admin will see rental commissions in the **Commission Management** section of either the sale approvals page or the new rental approvals page (or both — see design decision below).

---

## 10. Admin Rental Approvals Page

### UI Structure

```
┌──────────────────────────────────────────────────────────┐
│  HEADER: "Rental Verification Approvals"                 │
│  Breadcrumb: Dashboard > Rental Approvals                │
├──────────────────────────────────────────────────────────┤
│  KPI CARDS ROW                                           │
│  ┌─────────┐ ┌─────────┐ ┌──────────┐ ┌──────────────┐  │
│  │Pending  │ │Active   │ │Total     │ │Rental        │  │
│  │Rentals  │ │Leases   │ │Lease     │ │Commissions   │  │
│  │   3     │ │   12    │ │Value     │ │Awaiting      │  │
│  │         │ │         │ │₱4.2M    │ │   2          │  │
│  └─────────┘ └─────────┘ └──────────┘ └──────────────┘  │
├──────────────────────────────────────────────────────────┤
│  FILTER SIDEBAR (same pattern as sale approvals)         │
│  - Status: All / Pending / Approved / Rejected           │
│  - Sort: Newest / Oldest / Highest Rent / Agent          │
│  - Agent filter                                          │
│  - Commission: Awaiting Payment / Paid                   │
├──────────────────────────────────────────────────────────┤
│  RENTAL VERIFICATION CARDS                               │
│  ┌────────────────────────────────────────────────────┐  │
│  │ Property: Unit 18C, The Rise Makati                │  │
│  │ Agent: Kent Giniseran                              │  │
│  │ Tenant: John Doe (johndoe@mail.com)               │  │
│  │ Monthly Rent: ₱65,000  |  Deposit: ₱70,000       │  │
│  │ Lease: Apr 1, 2026 → Mar 31, 2027 (12 months)    │  │
│  │ Total Lease Value: ₱780,000                       │  │
│  │ Documents: 2 files  [View]                         │  │
│  │ Submitted: Mar 5, 2026 2:30 PM                    │  │
│  │                                                    │  │
│  │ [Approve & Finalize]    [Reject]     [View Details]│  │
│  └────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────┤
│  RENTAL COMMISSION MANAGEMENT (same pattern)             │
│  [Awaiting Payment] [Paid]  [Search: ___________]       │
│  ┌────────────────────────────────────────────────────┐  │
│  │ # │ Property │ Agent │ Lease Val │ Commission │ .. │  │
│  └────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────┘
```

### Modals

| Modal | Purpose | Mirror Of |
|-------|---------|-----------|
| **Details Modal** | View full rental verification details + documents | Sale details modal |
| **Document Preview Modal** | Preview PDF/images | Sale document preview |
| **Finalize Rental Modal** | Confirm approval, set commission %, final amounts | Finalize sale modal |
| **Reject Modal** | Enter rejection reason | Sale reject modal |
| **Payment Modal** | Record commission payment (proof upload) | Commission payment modal |
| **Processing Overlay** | Loading state during form submission | Same |

---

## 11. Agent Rental Dashboard

### Agent's "Mark as Rented" Flow

1. Agent navigates to their property (`view_agent_property.php`)
2. For properties with `Status = 'For Rent'`, a "Mark as Rented" button appears
3. Clicking opens the **Mark as Rented Modal** with fields:
   - Tenant Name (required)
   - Tenant Email (optional)
   - Tenant Phone (optional)
   - Monthly Rent (pre-filled from `rental_details.monthly_rent`)
   - Security Deposit (pre-filled from `rental_details.security_deposit`)
   - Lease Start Date (required, date picker)
   - Lease End Date (required, date picker, auto-calculated from term)
   - Lease Documents upload (required, multi-file)
   - Additional Notes (optional)
4. Form submits to `agent_pages/mark_as_rented_process.php`
5. On success, property status updates to "Pending Rented"

### Agent Commissions Page Updates

The existing `agent_pages/agent_commissions.php` should show rental commissions alongside sale ones:

```php
// Modified query — add commission_type handling
$commissions_sql = "
    SELECT ac.*,
           ac.commission_type,
           -- Sale details (when type = 'sale')
           fs.property_id AS sale_property_id,
           fs.final_sale_price,
           -- Rental details (when type = 'rental')
           fr.property_id AS rental_property_id,
           fr.monthly_rent, fr.total_lease_value,
           fr.tenant_name, fr.lease_start_date, fr.lease_end_date,
           -- Common
           COALESCE(fs.property_id, fr.property_id) AS prop_id,
           p.StreetAddress, p.City
    FROM agent_commissions ac
    LEFT JOIN finalized_sales fs ON ac.sale_id = fs.sale_id
    LEFT JOIN finalized_rentals fr ON ac.lease_id = fr.lease_id
    LEFT JOIN property p ON COALESCE(fs.property_id, fr.property_id) = p.property_ID
    WHERE ac.agent_id = ?
    ORDER BY ac.created_at DESC
";
```

Display a "Sale" or "Rental" badge on each commission row.

---

## 12. Commission Handling for Rentals

### Commission Basis Options

| Method | Formula | When to Use |
|--------|---------|-------------|
| **% of Total Lease Value** | `monthly_rent × lease_term_months × (commission_% / 100)` | Standard approach — ties commission to full contract value |
| **% of Monthly Rent** | `monthly_rent × (commission_% / 100)` | Simpler — one month's commission |
| **Flat Fee** | Fixed amount entered by admin | Special arrangements |

> **Recommended default:** % of Total Lease Value (same pattern as sale % of sale price)

### Commission Amount Calculation (in `admin_finalize_rental.php`)

```php
$total_lease_value = $monthly_rent * $lease_term_months;
$commission_amount = round($total_lease_value * ($commission_percentage / 100), 2);
```

### Commission Record Insert

```php
$insert_commission = $conn->prepare("
    INSERT INTO agent_commissions
        (commission_type, sale_id, lease_id, agent_id,
         commission_amount, commission_percentage,
         status, calculated_at, processed_by, payment_notes, created_at)
    VALUES ('rental', NULL, ?, ?, ?, ?, 'calculated', NOW(), ?, ?, NOW())
");
$insert_commission->bind_param(
    'iiddis',
    $lease_id,
    $agent_id,
    $commission_amount,
    $commission_percentage,
    $_SESSION['account_id'],
    $notes
);
```

### Payment Processing

The existing `process_commission_payment.php` already works by `commission_id` — no changes needed. It updates:
- `agent_commissions.status` → 'paid'
- `agent_commissions.paid_at`, `payment_method`, `payment_reference`, proof file columns
- Creates `commission_payment_logs` entry
- Sends agent notification

---

## 13. Notification Matrix

### Admin Notifications (`notifications` table)

| Event | `item_type` | `category` | `priority` | `title` | `icon` |
|-------|------------|-----------|-----------|---------|--------|
| Rental verification submitted | `property_rental` | `request` | `high` | "Rental Verification Submitted" | `bi-house-check` |
| Rental approved (log) | `property_rental` | `update` | `normal` | "Rental Approved" | `bi-check-circle` |
| Rental commission paid (log) | `property_rental` | `update` | `normal` | "Rental Commission Paid" | `bi-cash-coin` |

### Agent Notifications (`agent_notifications` table)

| Event | `notif_type` | `title` | `message` template |
|-------|-------------|---------|-------------------|
| Rental approved | `rental_approved` | "Rental Approved" | "Your rental for Property #{id} has been approved! Tenant: {name}. Monthly rent: ₱{amount}. Commission will be processed shortly." |
| Rental rejected | `rental_rejected` | "Rental Rejected" | "Your rental verification for Property #{id} has been rejected. Reason: {admin_notes}" |
| Commission paid (rental) | `commission_paid` | "Commission Paid" | "Your commission of ₱{amount} for rental of Property #{id} has been paid. Reference: {ref}." |

### Email Notifications

| Recipient | Event | Template Name |
|-----------|-------|--------------|
| Agent | Rental approved | `rental_approved_agent` |
| Agent | Rental rejected | `rental_rejected_agent` |
| Agent | Commission paid | `commission_paid_agent` (existing, add rental context) |
| Tenant | Lease confirmed | `lease_confirmation_tenant` |

---

## 14. Front-End Components

### 14.1 Status Badges

```php
// Add to status badge rendering logic (in card templates)
case 'Pending Rented':
    $badge_class = 'bg-warning text-dark';
    $badge_icon = 'bi-hourglass-split';
    $badge_text = 'Pending Rented';
    break;
case 'Rented':
    $badge_class = 'bg-info text-white';
    $badge_icon = 'bi-key-fill';
    $badge_text = 'Rented';
    break;
```

### 14.2 "Mark as Rented" Button

Show on property view pages when:
- Property `Status = 'For Rent'`
- Property `approval_status = 'approved'`
- Property `is_locked = 0`
- No pending/approved rental verification exists

```html
<button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#markAsRentedModal">
    <i class="bi bi-key-fill"></i> Mark as Rented
</button>
```

### 14.3 Mark as Rented Modal Fields

```
┌──────────────────────────────────────────┐
│  Mark as Rented — Unit 18C, The Rise     │
├──────────────────────────────────────────┤
│                                          │
│  Tenant Information                      │
│  ┌─────────────────────────────────────┐ │
│  │ Tenant Name *         [___________] │ │
│  │ Tenant Email          [___________] │ │
│  │ Tenant Phone          [___________] │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  Lease Details                           │
│  ┌─────────────────────────────────────┐ │
│  │ Monthly Rent *        [₱ 65,000  ] │ │
│  │ Security Deposit *    [₱ 70,000  ] │ │
│  │ Lease Start Date *    [2026-04-01 ] │ │
│  │ Lease End Date *      [2026-03-31 ] │ │
│  │ Lease Term            12 months     │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  Documents                               │
│  ┌─────────────────────────────────────┐ │
│  │ Upload lease documents *            │ │
│  │ [Choose Files] (PDF, JPG, PNG, DOC) │ │
│  │ Max 120MB per file                  │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  Additional Notes                        │
│  ┌─────────────────────────────────────┐ │
│  │ [________________________]          │ │
│  └─────────────────────────────────────┘ │
│                                          │
│          [Cancel]  [Submit for Review]   │
└──────────────────────────────────────────┘
```

### 14.4 Finalize Rental Modal (Admin)

Same structure as the Finalize Sale modal, but with rental fields:
- Monthly Rent (pre-filled from verification)
- Security Deposit (pre-filled)
- Lease Start/End Dates (pre-filled)
- Tenant info (pre-filled, editable)
- Commission % (admin enters)
- Calculated commission amount (auto-computed: total_lease_value × %)
- Admin notes

### 14.5 Toast Notifications

Reuse existing toast system with the `toast-money` type for rental commission events:

```javascript
// In admin_property_rental_approvals.php
case 'rental_approved':
    toastType = 'success';
    toastTitle = 'Rental Approved';
    break;
case 'rental_rejected':
    toastType = 'error';
    toastTitle = 'Rental Rejected';
    break;
case 'rental_payment_processed':
    toastType = 'money';
    toastTitle = 'Commission Payment Processed';
    break;
```

---

## 15. Security & Validation

### Server-Side Validation

| Check | Where | Implementation |
|-------|-------|---------------|
| Admin session | All `admin_*.php` handlers | `$_SESSION['user_role'] === 'admin'` |
| Agent session | All `agent_pages/*.php` handlers | `$_SESSION['user_role'] === 'agent'` |
| Property ownership | Agent submission | JOIN `property_log` where `action = 'CREATED'` and `account_id = agent_id` |
| Property status | Submission handlers | Must be `Status = 'For Rent'` and `approval_status = 'approved'` |
| No duplicate verification | Submission handlers | Check `rental_verifications WHERE property_id = ? AND status IN ('Pending','Approved')` |
| Date validation | Submission & finalization | `lease_end_date > lease_start_date`, dates not unreasonably far in past/future |
| Monetary validation | All financial inputs | Must be positive, `DECIMAL(12,2)` range, sanitized with `floatval()` |
| File validation | Upload handlers | MIME type whitelist, size limit (120MB), extension check |
| CSRF protection | All POST handlers | (if implemented system-wide) |
| SQL injection | All queries | Prepared statements with `bind_param()` |
| XSS prevention | All display | `htmlspecialchars()` on all user-supplied output |
| Transaction safety | Multi-table writes | `$conn->begin_transaction()` / `commit()` / `rollback()` |

### File Upload Security

```php
// rental_documents/.htaccess
Order Allow,Deny
Deny from all
```

Download must go through `download_rental_document.php` with:
- Admin session check (or agent who owns the property)
- File existence verification
- Proper `Content-Type` and `Content-Disposition` headers

---

## 16. Phase-Based Implementation Plan

### Phase 1: Database & Foundation (Day 1)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 1.1 | Run SQL migration script | 15 min | None |
| 1.2 | Create `rental_documents/` directory + `.htaccess` | 5 min | None |
| 1.3 | Create `download_rental_document.php` | 30 min | 1.1 |
| 1.4 | Verify migration: test `agent_commissions` with `commission_type` column, ensure existing sale data is intact | 15 min | 1.1 |

### Phase 2: Agent Submission Flow (Day 1-2)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 2.1 | Create `agent_pages/modals/mark_as_rented_modal.php` | 45 min | Phase 1 |
| 2.2 | Create `agent_pages/mark_as_rented_process.php` | 1.5 hr | Phase 1 |
| 2.3 | Modify `agent_pages/view_agent_property.php` — add "Mark as Rented" button + include modal | 30 min | 2.1 |
| 2.4 | Update `agent_pages/property_card_template.php` — "Pending Rented" / "Rented" badges | 15 min | Phase 1 |
| 2.5 | Test agent submission flow end-to-end | 30 min | 2.1-2.4 |

### Phase 3: Admin Submission Flow (Day 2)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 3.1 | Create `modals/mark_as_rented_modal.php` (admin version) | 30 min | Phase 1 |
| 3.2 | Create `admin_mark_as_rented_process.php` | 1.5 hr | Phase 1 |
| 3.3 | Modify `view_property.php` — add "Mark as Rented" button + include modal | 30 min | 3.1 |
| 3.4 | Update `admin_property_card_template.php` — "Pending Rented" / "Rented" badges | 15 min | Phase 1 |
| 3.5 | Test admin submission flow | 30 min | 3.1-3.4 |

### Phase 4: Admin Rental Approvals Page (Day 2-3)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 4.1 | Create `admin_property_rental_approvals.php` — PHP data layer (queries) | 1 hr | Phase 1 |
| 4.2 | Create page CSS (copy & adapt from sale approvals) | 1 hr | 4.1 |
| 4.3 | Create page HTML — KPIs, verification cards, modals | 2 hr | 4.1-4.2 |
| 4.4 | Create page JS — approve/reject handlers, filters, search | 1.5 hr | 4.3 |
| 4.5 | Add sidebar link in `admin_sidebar.php` | 15 min | 4.1 |
| 4.6 | Test full page rendering | 30 min | 4.1-4.5 |

### Phase 5: Approve & Finalize Backend (Day 3)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 5.1 | Create `admin_finalize_rental.php` (approve + create finalized_rentals + commission) | 2 hr | Phase 1, Phase 4 |
| 5.2 | Create `admin_reject_rental.php` | 45 min | Phase 1, Phase 4 |
| 5.3 | Add Finalize Rental modal JS/HTML in rental approvals page | 1 hr | 4.3, 5.1 |
| 5.4 | Add Reject modal JS/HTML | 30 min | 4.3, 5.2 |
| 5.5 | Test approve flow (verification → finalized_rentals → commission) | 45 min | 5.1-5.4 |
| 5.6 | Test reject flow (verification rejected → status back to "For Rent") | 30 min | 5.2, 5.4 |

### Phase 6: Commission Management for Rentals (Day 3-4)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 6.1 | Add Commission Management section to rental approvals page | 1.5 hr | Phase 4, Phase 5 |
| 6.2 | Update `process_commission_payment.php` if any rental-specific logic needed | 30 min | Phase 5 |
| 6.3 | Test commission payment flow for rental commissions | 30 min | 6.1-6.2 |

### Phase 7: Notifications (Day 4)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 7.1 | Update `agent_pages/agent_notification_helper.php` — rental templates | 30 min | Phase 5 |
| 7.2 | Update `agent_pages/agent_notifications.php` — handle new notif_types | 30 min | 7.1 |
| 7.3 | Update `admin_notifications.php` — handle `property_rental` item_type | 30 min | Phase 5 |
| 7.4 | Add email templates in `email_template.php` | 45 min | Phase 5 |
| 7.5 | Toast notifications on rental approvals page | 30 min | Phase 4 |
| 7.6 | Test all notification channels | 30 min | 7.1-7.5 |

### Phase 8: Agent Commission Updates (Day 4)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 8.1 | Update `agent_pages/agent_commissions.php` — show rental commissions | 1 hr | Phase 5 |
| 8.2 | Add "Sale" / "Rental" badge/filter | 30 min | 8.1 |
| 8.3 | Test agent commissions page with mixed data | 30 min | 8.1-8.2 |

### Phase 9: Dashboard & Reports (Day 4-5)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 9.1 | Update `admin_dashboard.php` — rental stat cards | 45 min | Phase 5 |
| 9.2 | Update `agent_pages/agent_dashboard.php` — rental stats | 45 min | Phase 5 |
| 9.3 | Update `reports.php` — rental report data | 1 hr | Phase 5 |
| 9.4 | Test dashboards and reports | 30 min | 9.1-9.3 |

### Phase 10: Polish & Edge Cases (Day 5)

| # | Task | Est. Time | Dependencies |
|---|------|-----------|-------------|
| 10.1 | Filter system updates on sale approvals page (commission_type chip) | 30 min | Phase 6 |
| 10.2 | User-facing pages — "Rented" status display | 30 min | Phase 1 |
| 10.3 | Skeleton screen / loading states on rental approvals page | 30 min | Phase 4 |
| 10.4 | Cross-browser testing | 30 min | All |
| 10.5 | PHP lint all new/modified files | 15 min | All |
| 10.6 | Final end-to-end test (full lifecycle) | 1 hr | All |

### Total Estimated Time: ~28 hours

---

## 17. Testing Checklist

### Database

- [ ] Migration runs without errors
- [ ] Existing `agent_commissions` data is preserved (all sale rows have `commission_type = 'sale'`)
- [ ] New ENUM values added correctly to `property_log`, `notifications`, `agent_notifications`, `price_history`
- [ ] Foreign key constraints work: `rental_verifications` → `property`, `accounts`
- [ ] Foreign key constraints work: `finalized_rentals` → `rental_verifications`, `property`, `accounts`
- [ ] Foreign key constraints work: `agent_commissions.lease_id` → `finalized_rentals.lease_id`
- [ ] `sale_id` is now nullable in `agent_commissions` and existing data still works
- [ ] Unique constraints: can't create duplicate commissions for same `sale_id` or `lease_id`

### Agent Flow

- [ ] "Mark as Rented" button appears only for `Status = 'For Rent'` properties
- [ ] "Mark as Rented" button hidden when pending/approved rental verification exists
- [ ] Modal pre-fills monthly rent and deposit from `rental_details`
- [ ] Lease end date auto-calculates from start date + term
- [ ] File upload validates types and sizes
- [ ] At least one document required
- [ ] Successful submission → property status changes to "Pending Rented"
- [ ] Successful submission → `rental_verifications` row created with status "Pending"
- [ ] Successful submission → documents uploaded and `rental_verification_documents` rows created
- [ ] Successful submission → `property_log` entry created
- [ ] Successful submission → admin notification created
- [ ] Error handling: duplicate submission, invalid property, missing fields
- [ ] Card template shows "Pending Rented" badge correctly

### Admin Flow

- [ ] Admin can also submit rental verification (for admin-listed properties)
- [ ] Rental approvals page loads with correct data
- [ ] KPI cards show accurate counts
- [ ] Pending verification cards display all details
- [ ] Document download works
- [ ] Approve → finalize rental modal opens with pre-filled data
- [ ] Commission % calculation preview updates live
- [ ] Approve → `finalized_rentals` record created
- [ ] Approve → `agent_commissions` record created with `commission_type = 'rental'`
- [ ] Approve → property Status → "Rented", `is_locked = 1`
- [ ] Approve → `property_log` entry (RENTED)
- [ ] Approve → `price_history` entry (Rented)
- [ ] Approve → agent notification sent
- [ ] Reject → `rental_verifications.status` → 'Rejected'
- [ ] Reject → property Status → "For Rent" (back to available)
- [ ] Reject → agent notification sent
- [ ] Commission payment flow works for rental commissions
- [ ] Filter/search on rental approvals page

### Notifications

- [ ] Agent receives `rental_approved` notification on approval
- [ ] Agent receives `rental_rejected` notification on rejection
- [ ] Agent receives `commission_paid` notification on payment
- [ ] Admin receives submission notification
- [ ] Email notifications sent (if configured)
- [ ] Toast notifications display on rental approvals page

### Commission Management

- [ ] Rental commissions appear in Commission Management section
- [ ] Awaiting/Paid toggle works for rental commissions
- [ ] Payment modal works (method, reference, proof upload)
- [ ] Agent commissions page shows both sale and rental commissions
- [ ] "Sale" / "Rental" badge distinguishes commission types

### Edge Cases

- [ ] Cannot mark a "Sold" property as rented
- [ ] Cannot mark a "Rented" property as rented again
- [ ] Cannot mark a "Pending Sold" property as rented
- [ ] Cannot approve a rental if property status changed since submission
- [ ] Multiple agents — only the assigned agent sees their rental data
- [ ] Commission proof download requires proper authorization
- [ ] Transaction rollback works if any step fails during approval

---

## 18. Open Questions & Future Considerations

### Design Decisions (Decide Before Implementation)

| # | Question | Options | Recommendation |
|---|----------|---------|---------------|
| 1 | **Separate page or tab?** Should rental approvals be a separate page or a tab within the existing sale approvals page? | A) Separate page (`admin_property_rental_approvals.php`) B) Tab within sale approvals | **A) Separate page** — keeps each page focused and manageable |
| 2 | **Commission Management location** Should rental commissions appear in the sale approvals page commission section, or only on the rental approvals page? | A) Both pages B) Only rental page C) Unified commissions page | **A) Both pages** — the Commission Management section already filters by `commission_id`, just add a type filter |
| 3 | **Lease term: form field or calculated?** Should `lease_term_months` be a form field that auto-calculates end date, or should start/end dates be entered and term calculated? | A) Term → end date B) Start + end → term | **B) Start + end → term** — more flexible, term is just display |
| 4 | **Commission basis** % of total lease value, % of monthly rent, or flat fee? | A) % of total B) % of monthly C) Flat D) Configurable | **A) % of total** for now — consistent with sale commission pattern |
| 5 | **`rented_by_agent` column?** Should property table get a `rented_by_agent` FK (mirroring `sold_by_agent`)? | A) Yes B) No, derive from `finalized_rentals` | **A) Yes** — consistency with sale pattern, enables simple queries |

### Future Enhancements (Not in V1)

| Enhancement | Description | Priority |
|-------------|-------------|----------|
| **Lease renewal** | When a lease expires, allow renewal (new `finalized_rentals` row, status = 'renewed') | Medium |
| **Lease termination** | Early termination flow with penalty calculation | Medium |
| **Monthly rent tracking** | Track actual rent payments received | Low |
| **Lease expiry alerts** | Cron job / dashboard alert when leases are nearing end date | High |
| **Tenant portal** | Allow tenants to log in, view lease, submit maintenance requests | Low |
| **Rent increase history** | Track rent changes across lease renewals | Low |
| **Occupancy reports** | Aggregate reporting on rental occupancy rates | Medium |
| **Auto-status update** | Cron to auto-set `lease_status = 'expired'` when `lease_end_date` passes | High |

---

## Appendix A: Entity Relationship Summary

```
property (1) ──── (0..1) rental_details           [listing info]
    │
    ├──── (0..*) rental_verifications              [submission]
    │                  │
    │                  └──── (0..*) rental_verification_documents
    │
    └──── (0..*) finalized_rentals                 [approved lease]
                       │
                       └──── (0..1) agent_commissions (type='rental')
                                         │
                                         └──── (0..*) commission_payment_logs
```

## Appendix B: Property Status State Machine

```
                  ┌─────────────────────────────┐
                  │                             │
                  ▼                             │
    ┌──────────────────┐                        │
    │    For Rent       │ ◄──── [Rejected]      │
    └──────────────────┘                        │
           │                                    │
    [Agent/Admin submits                        │
     rental verification]                       │
           │                                    │
           ▼                                    │
    ┌──────────────────┐                        │
    │  Pending Rented   │ ──── [Admin Rejects] ─┘
    └──────────────────┘
           │
    [Admin Approves]
           │
           ▼
    ┌──────────────────┐
    │     Rented        │  (is_locked = 1)
    └──────────────────┘
           │
    [Future: Lease expires / terminates]
           │
           ▼
    ┌──────────────────┐
    │    For Rent       │  (is_locked = 0, back to available)
    └──────────────────┘
```

## Appendix C: Quick Reference — Sale vs Rental Table Mapping

| Sale Table | Rental Table | PK |
|------------|-------------|-----|
| `sale_verifications` | `rental_verifications` | `verification_id` |
| `sale_verification_documents` | `rental_verification_documents` | `document_id` |
| `finalized_sales` | `finalized_rentals` | `sale_id` / `lease_id` |
| `agent_commissions` (sale_id FK) | `agent_commissions` (lease_id FK, type='rental') | `commission_id` |
| `commission_payment_logs` | Same table (shared) | `log_id` |

## Appendix D: Quick Reference — Sale vs Rental File Mapping

| Sale File | Rental File |
|-----------|------------|
| `agent_pages/mark_as_sold_process.php` | `agent_pages/mark_as_rented_process.php` |
| `admin_mark_as_sold_process.php` | `admin_mark_as_rented_process.php` |
| `admin_property_sale_approvals.php` | `admin_property_rental_approvals.php` |
| `admin_finalize_sale.php` | `admin_finalize_rental.php` |
| (inline reject in sale approvals) | `admin_reject_rental.php` |
| `download_document.php` | `download_rental_document.php` |
| `process_commission_payment.php` | Same file (shared by commission_id) |
| `sale_documents/` | `rental_documents/` |

---

*End of Blueprint*
