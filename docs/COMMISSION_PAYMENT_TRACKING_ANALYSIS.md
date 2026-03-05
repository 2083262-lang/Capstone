# Commission Payment Tracking System — Analysis & Implementation Guide

> **Project:** HomeEstate Realty Capstone System  
> **Date:** March 5, 2026  
> **Scope:** Internal manual commission payment tracking (no third-party payment gateway)

---

## Table of Contents

1. [Current Database Analysis](#1-current-database-analysis)
2. [Gap Analysis — What's Missing](#2-gap-analysis--whats-missing)
3. [Proposed Schema Improvements](#3-proposed-schema-improvements)
4. [Commission Lifecycle](#4-commission-lifecycle)
5. [Implementation Details](#5-implementation-details)
6. [Security & Standards](#6-security--standards)
7. [Role-Based Access Control](#7-role-based-access-control)
8. [Best Practices Summary](#8-best-practices-summary)
9. [SQL Migration Script](#9-sql-migration-script)

---

## 1. Current Database Analysis

### 1.1 Current `agent_commissions` Table Structure

```sql
CREATE TABLE `agent_commissions` (
  `commission_id`       int(11) NOT NULL AUTO_INCREMENT,
  `sale_id`             int(11) NOT NULL,
  `agent_id`            int(11) NOT NULL,
  `commission_amount`   decimal(12,2) NOT NULL,
  `commission_percentage` decimal(5,2) NOT NULL,
  `status`              enum('pending','calculated','paid','cancelled') NOT NULL DEFAULT 'pending',
  `calculated_at`       timestamp NULL DEFAULT NULL,
  `paid_at`             timestamp NULL DEFAULT NULL,
  `payment_reference`   varchar(100) DEFAULT NULL,
  `processed_by`        int(11) DEFAULT NULL,
  `created_at`          timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`commission_id`),
  KEY `sale_id` (`sale_id`),
  KEY `agent_id` (`agent_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `fk_agent_commissions_agent`        FOREIGN KEY (`agent_id`)      REFERENCES `accounts` (`account_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_commissions_processed_by` FOREIGN KEY (`processed_by`)  REFERENCES `accounts` (`account_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agent_commissions_sale`         FOREIGN KEY (`sale_id`)       REFERENCES `finalized_sales` (`sale_id`) ON DELETE CASCADE
);
```

### 1.2 Existing Data (2 Records)

| commission_id | sale_id | agent_id | commission_amount | commission_percentage | status     | calculated_at       | paid_at | payment_reference                  | processed_by |
|---------------|---------|----------|-------------------|-----------------------|------------|---------------------|---------|------------------------------------|--------------|
| 1             | 1       | 2        | 190,000.00        | 2.00                  | calculated | 2026-03-02 00:08:26 | NULL    | Congratulations for a success sale | 2            |
| 2             | 2       | 2        | 193,750.00        | 1.25                  | calculated | 2026-03-02 01:15:07 | NULL    | Sample notes congrats              | 2            |

### 1.3 Related Tables in the Current Flow

| Table                        | Role in Commission Flow                                        |
|------------------------------|----------------------------------------------------------------|
| `sale_verifications`         | Agent submits sale proof → Admin reviews                       |
| `sale_verification_documents`| Supporting documents for the sale claim                        |
| `finalized_sales`            | Created when Admin approves a sale verification                |
| `agent_commissions`          | Created/updated when Admin clicks "Finalize Commission"        |
| `agent_notifications`        | Notifies agent about sale approval & commission                |
| `notifications`              | Admin-side notifications                                       |
| `status_log`                 | Audit trail for approve/reject actions                         |
| `property_log`               | Property-level action history                                  |

### 1.4 Current Workflow (as-is)

```
Agent submits sale_verification (with documents)
        ↓
Admin approves → sale_verifications.status = 'Approved'
        ↓
System creates finalized_sales record
Property marked as Sold + locked
        ↓
Admin opens "Finalize Sale & Commission" modal
Enters: final_sale_price, commission_percentage, buyer_name, buyer_email, notes
        ↓
System creates/updates agent_commissions
  → status = 'calculated'
  → paid_at = NULL (NEVER set)
  → payment_reference = notes text (MISUSED)
        ↓
Email sent to agent → "Commission Earned — Calculated, pending payout"
        ↓
*** WORKFLOW ENDS HERE — No mechanism to mark as PAID ***
```

### 1.5 `paid_at` Field — Current State

| Aspect              | Finding                                                        |
|----------------------|----------------------------------------------------------------|
| **Intended purpose** | Record the exact timestamp when commission payment was sent    |
| **Current value**    | Always `NULL` — never populated anywhere in the codebase       |
| **Problem**          | No backend endpoint, UI action, or process ever sets this field|
| **Impact**           | Commission status is stuck at `'calculated'` permanently       |

### 1.6 `payment_reference` Field — Misuse Detected

The `payment_reference` field is currently being used to store **admin notes** (the `$notes` variable from the finalize modal), not an actual payment transaction reference. This conflates two different data points:

- **Notes:** "Congratulations for a success sale"  
- **Payment Reference:** Should be something like "BDO-TXN-20260302-001" or "GCash Ref #7845120"

---

## 2. Gap Analysis — What's Missing

### 2.1 Critical Gaps

| #  | Gap                                    | Severity | Impact                                         |
|----|----------------------------------------|----------|-------------------------------------------------|
| G1 | No payment proof upload mechanism      | **HIGH** | Cannot verify payment was actually made          |
| G2 | `paid_at` is never set                 | **HIGH** | Commission lifecycle is incomplete               |
| G3 | No payment method tracking             | **HIGH** | No record of HOW payment was sent                |
| G4 | `payment_reference` misused as notes   | **HIGH** | Real transaction references cannot be stored     |
| G5 | No `payment_status` intermediate state | **MEDIUM** | Cannot track "processing" states                |
| G6 | No `paid_by` (who marked as paid)      | **MEDIUM** | Cannot audit WHO processed the payment           |
| G7 | No commission payment audit log        | **HIGH** | Changes to payment status are untracked          |
| G8 | No double-payment protection           | **HIGH** | Same commission could theoretically be paid twice |
| G9 | No payment documents table             | **HIGH** | No structured storage for proof of payment       |
| G10 | No dispute/rejection flow for payment | **LOW**  | Agent cannot flag incorrect commission           |

### 2.2 Data Integrity Concerns

- `status` enum has `'pending'` but records skip directly to `'calculated'` — the `'pending'` state is never used
- No `UNIQUE` constraint on `sale_id` in `agent_commissions` — allows duplicate commissions per sale
- `ON DELETE CASCADE` on `sale_id` FK means deleting a finalized sale silently removes commission records without audit

---

## 3. Proposed Schema Improvements

### 3.1 Enhanced `agent_commissions` Table

```sql
ALTER TABLE `agent_commissions`
  -- Add new columns for payment tracking
  ADD COLUMN `payment_method`    VARCHAR(50) DEFAULT NULL 
      COMMENT 'bank_transfer, gcash, maya, cash, check, etc.',
  ADD COLUMN `payment_proof_path` VARCHAR(500) DEFAULT NULL 
      COMMENT 'Secure file path to uploaded payment proof',
  ADD COLUMN `payment_proof_original_name` VARCHAR(255) DEFAULT NULL 
      COMMENT 'Original filename of the uploaded proof',
  ADD COLUMN `payment_proof_mime` VARCHAR(100) DEFAULT NULL 
      COMMENT 'MIME type of the proof file',
  ADD COLUMN `payment_proof_size` INT DEFAULT NULL 
      COMMENT 'File size in bytes',
  ADD COLUMN `paid_by`           INT DEFAULT NULL 
      COMMENT 'Admin account_id who marked commission as paid',
  ADD COLUMN `payment_notes`     TEXT DEFAULT NULL 
      COMMENT 'Admin notes specific to the payment action',
  ADD COLUMN `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP 
      ON UPDATE CURRENT_TIMESTAMP,
  ADD CONSTRAINT `fk_commission_paid_by` 
      FOREIGN KEY (`paid_by`) REFERENCES `accounts` (`account_id`) ON DELETE SET NULL;

-- Modify status enum to include 'processing' state
ALTER TABLE `agent_commissions`
  MODIFY COLUMN `status` ENUM('pending','calculated','processing','paid','cancelled') 
      NOT NULL DEFAULT 'pending';

-- Prevent duplicate commissions per sale
ALTER TABLE `agent_commissions`
  ADD UNIQUE KEY `uq_sale_commission` (`sale_id`);
```

### 3.2 New `commission_payment_logs` Table (Audit Trail)

```sql
CREATE TABLE `commission_payment_logs` (
  `log_id`          INT NOT NULL AUTO_INCREMENT,
  `commission_id`   INT NOT NULL,
  `action`          ENUM('created','calculated','processing','paid','cancelled','updated','proof_uploaded','proof_replaced') NOT NULL,
  `old_status`      VARCHAR(20) DEFAULT NULL,
  `new_status`      VARCHAR(20) DEFAULT NULL,
  `details`         TEXT DEFAULT NULL COMMENT 'JSON or text describing the change',
  `performed_by`    INT NOT NULL COMMENT 'account_id of the admin who performed the action',
  `ip_address`      VARCHAR(45) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_commission_logs_commission` (`commission_id`),
  KEY `idx_commission_logs_action` (`action`),
  KEY `idx_commission_logs_performed_by` (`performed_by`),
  CONSTRAINT `fk_comm_logs_commission` FOREIGN KEY (`commission_id`) REFERENCES `agent_commissions` (`commission_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comm_logs_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3.3 Updated `payment_reference` Purpose

After the schema changes, the data model separates concerns:

| Field               | Purpose                                              | Example                          |
|---------------------|------------------------------------------------------|----------------------------------|
| `payment_reference` | Transaction/reference number from the payment channel | `BDO-TXN-20260302-001`          |
| `payment_notes`     | Admin-written free-text notes about the payment       | `Paid via bank transfer to BDO`  |
| `payment_method`    | Payment channel used                                  | `bank_transfer`                  |
| `payment_proof_path`| File system path to the proof image/PDF              | `uploads/commission_proofs/...`  |
| `additional_notes`  | Notes from the finalize step (in `finalized_sales`)   | `Congratulations for the sale`   |

### 3.4 Complete Enhanced Column Summary

| Column                       | Type            | Nullable | Purpose                                        |
|------------------------------|-----------------|----------|-------------------------------------------------|
| `commission_id`              | INT PK AI       | NO       | Unique identifier                               |
| `sale_id`                    | INT FK UNIQUE   | NO       | Links to `finalized_sales`                      |
| `agent_id`                   | INT FK          | NO       | The agent receiving commission                  |
| `commission_amount`          | DECIMAL(12,2)   | NO       | Calculated dollar amount                        |
| `commission_percentage`      | DECIMAL(5,2)    | NO       | The rate applied to sale price                  |
| `status`                     | ENUM            | NO       | `pending → calculated → processing → paid`      |
| `calculated_at`              | TIMESTAMP       | YES      | When commission was first calculated            |
| `paid_at`                    | TIMESTAMP       | YES      | When payment was completed                      |
| `payment_reference`          | VARCHAR(100)    | YES      | Transaction reference number                    |
| `payment_method`             | VARCHAR(50)     | YES      | bank_transfer, gcash, maya, cash, check         |
| `payment_proof_path`         | VARCHAR(500)    | YES      | Secure path to proof file                       |
| `payment_proof_original_name`| VARCHAR(255)    | YES      | Original filename                               |
| `payment_proof_mime`         | VARCHAR(100)    | YES      | MIME type for validation                        |
| `payment_proof_size`         | INT             | YES      | File size in bytes                              |
| `paid_by`                    | INT FK          | YES      | Admin who processed payment                     |
| `payment_notes`              | TEXT            | YES      | Notes about the payment itself                  |
| `processed_by`               | INT FK          | YES      | Admin who finalized the sale/commission          |
| `created_at`                 | TIMESTAMP       | NO       | Record creation time                            |
| `updated_at`                 | TIMESTAMP       | NO       | Last modification time                          |

---

## 4. Commission Lifecycle

### 4.1 Complete State Machine

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                        COMMISSION LIFECYCLE FLOWCHART                            │
├──────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  ┌──────────────┐     Sale Approved      ┌──────────────┐                       │
│  │   (no record) │ ─────────────────────→ │   PENDING    │                       │
│  └──────────────┘   finalized_sales       │  created_at  │                       │
│                      record created       └──────┬───────┘                       │
│                                                  │                               │
│                                    Admin enters rate                              │
│                                  & clicks "Save &                                │
│                                    Calculate"                                    │
│                                                  │                               │
│                                                  ▼                               │
│                                          ┌──────────────┐                        │
│                                          │  CALCULATED  │                        │
│                                          │ calculated_at│                        │
│                                          └──────┬───────┘                        │
│                                                 │                                │
│                                 Admin uploads proof                              │
│                                 & marks payment as                               │
│                                   "Processing"                                   │
│                                                 │                                │
│                                                 ▼                                │
│                                          ┌──────────────┐                        │
│                                          │  PROCESSING  │  (optional             │
│                                          │  proof_path  │   intermediate)        │
│                                          └──────┬───────┘                        │
│                                                 │                                │
│                                 Admin confirms payment                           │
│                                 sent successfully                                │
│                                                 │                                │
│                                                 ▼                                │
│                                          ┌──────────────┐                        │
│                                          │     PAID     │                        │
│                                          │   paid_at    │  ← timestamp set      │
│                                          │   paid_by    │  ← admin recorded     │
│                                          └──────────────┘                        │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────┐             │
│  │  CANCELLED — can be set from 'pending' or 'calculated' only    │             │
│  │  (e.g., sale dispute, incorrect commission, agent terminated)   │             │
│  └─────────────────────────────────────────────────────────────────┘             │
│                                                                                  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Allowed State Transitions

| From          | To            | Trigger                                    | Who           |
|---------------|---------------|--------------------------------------------|---------------|
| *(none)*      | `pending`     | Finalized sale record created              | System        |
| `pending`     | `calculated`  | Admin enters rate & saves                  | Admin         |
| `calculated`  | `processing`  | Admin uploads proof & initiates payment    | Admin         |
| `calculated`  | `paid`        | Admin uploads proof & marks paid directly  | Admin         |
| `processing`  | `paid`        | Admin confirms payment completed           | Admin         |
| `pending`     | `cancelled`   | Sale disputed or commission voided         | Admin         |
| `calculated`  | `cancelled`   | Commission recalculation or void           | Admin         |

> **Forbidden transitions:** `paid → *` (paid is terminal), `cancelled → *` (unless explicitly re-opened)

### 4.3 Step-by-Step Walkthrough

#### Step 1: Sale Finalized
- Admin approves `sale_verifications` → system creates `finalized_sales` record
- Property status → `Sold`, `is_locked = 1`
- Agent notification sent

#### Step 2: Commission Calculated  
- Admin opens "Finalize Sale & Commission" modal
- Enters: final sale price, commission rate (%)
- System calculates: `commission_amount = final_sale_price × (rate / 100)`
- Record inserted/updated in `agent_commissions` with `status = 'calculated'`
- `calculated_at` timestamp set
- `processed_by` = admin's `account_id`
- Agent receives email notification

#### Step 3: Commission Payment Marked as Pending/Processing
- Admin opens "Process Payment" action (new UI needed)
- Selects payment method (bank transfer, GCash, Maya, cash, check)
- Enters transaction/reference number
- Uploads payment proof (receipt screenshot, bank transfer confirmation)
- System validates file (MIME, size, extension)
- Status updated to `'processing'` (optional) or directly to `'paid'`

#### Step 4: Commission Marked as Paid
- Admin confirms payment was sent successfully
- `status = 'paid'`
- `paid_at = NOW()`
- `paid_by = admin account_id`
- Audit log entry created in `commission_payment_logs`
- Agent notification: "Your commission of ₱X has been paid"
- Agent email sent with payment details

#### Step 5: Agent Views Commission
- Agent dashboard shows commission with status badge
- `paid_at` displayed as "Paid on Mar 5, 2026"
- Payment reference visible
- Payment proof downloadable (if allowed by policy)

---

## 5. Implementation Details

### 5.1 Backend: Mark Commission as Paid (`process_commission_payment.php`)

```php
<?php
/**
 * process_commission_payment.php
 * 
 * Handles the admin action of marking a commission as paid,
 * including payment proof upload and audit logging.
 * 
 * Method: POST (multipart/form-data)
 * Required role: admin
 */

session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/agent_pages/agent_notification_helper.php';

header('Content-Type: application/json');

try {
    // ── 1. Auth check ──
    if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $admin_id       = (int) $_SESSION['account_id'];
    $commission_id  = isset($_POST['commission_id']) ? (int) $_POST['commission_id'] : 0;
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
    $payment_ref    = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
    $payment_notes  = isset($_POST['payment_notes']) ? trim($_POST['payment_notes']) : '';

    // ── 2. Input validation ──
    $errors = [];
    if ($commission_id <= 0) $errors[] = 'Invalid commission ID.';

    $allowed_methods = ['bank_transfer', 'gcash', 'maya', 'cash', 'check', 'other'];
    if (!in_array($payment_method, $allowed_methods)) {
        $errors[] = 'Invalid payment method.';
    }

    if (empty($payment_ref)) {
        $errors[] = 'Payment reference/transaction number is required.';
    } elseif (strlen($payment_ref) > 100) {
        $errors[] = 'Payment reference must be 100 characters or fewer.';
    }

    // ── 3. File upload validation ──
    $proof_path = null;
    $proof_original = null;
    $proof_mime = null;
    $proof_size = null;

    $allowed_mimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf'
    ];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $max_file_size = 5 * 1024 * 1024; // 5 MB

    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['payment_proof'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error (code: ' . $file['error'] . ').';
        } else {
            // Validate MIME via finfo (not trusting client-reported type)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected_mime = $finfo->file($file['tmp_name']);

            if (!in_array($detected_mime, $allowed_mimes)) {
                $errors[] = 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF.';
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions)) {
                $errors[] = 'Invalid file extension.';
            }

            if ($file['size'] > $max_file_size) {
                $errors[] = 'File too large. Maximum: 5 MB.';
            }

            if ($file['size'] === 0) {
                $errors[] = 'Uploaded file is empty.';
            }

            $proof_original = basename($file['name']);
            $proof_mime = $detected_mime;
            $proof_size = $file['size'];
        }
    } else {
        $errors[] = 'Payment proof file is required.';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // ── 4. Database transaction ──
    $conn->begin_transaction();

    // Lock the commission row to prevent race conditions
    $stmt = $conn->prepare("
        SELECT ac.*, fs.property_id, fs.final_sale_price, fs.agent_id,
               a.first_name, a.last_name, a.email AS agent_email,
               p.StreetAddress, p.City, p.PropertyType
        FROM agent_commissions ac
        JOIN finalized_sales fs ON fs.sale_id = ac.sale_id
        JOIN accounts a ON a.account_id = ac.agent_id
        LEFT JOIN property p ON p.property_ID = fs.property_id
        WHERE ac.commission_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $commission_id);
    $stmt->execute();
    $commission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$commission) {
        throw new Exception('Commission record not found.');
    }

    // ── 5. Double-payment protection ──
    if ($commission['status'] === 'paid') {
        throw new Exception('This commission has already been paid on ' 
            . date('M j, Y g:i A', strtotime($commission['paid_at'])) . '.');
    }

    if (!in_array($commission['status'], ['calculated', 'processing'])) {
        throw new Exception('Only calculated or processing commissions can be marked as paid. Current status: ' . $commission['status']);
    }

    // ── 6. Save proof file ──
    $upload_dir = __DIR__ . '/uploads/commission_proofs/' . $commission_id . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate secure filename
    $safe_name = 'proof_' . $commission_id . '_' . uniqid('', true) . '.' . $ext;
    $dest_path = $upload_dir . $safe_name;
    $relative_path = 'uploads/commission_proofs/' . $commission_id . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        throw new Exception('Failed to save payment proof file.');
    }

    // ── 7. Update commission record ──
    $now = date('Y-m-d H:i:s');
    $upd = $conn->prepare("
        UPDATE agent_commissions 
        SET status = 'paid',
            paid_at = ?,
            paid_by = ?,
            payment_method = ?,
            payment_reference = ?,
            payment_proof_path = ?,
            payment_proof_original_name = ?,
            payment_proof_mime = ?,
            payment_proof_size = ?,
            payment_notes = ?
        WHERE commission_id = ?
        AND status IN ('calculated', 'processing')
    ");
    $upd->bind_param('sisssssis' . 'i',
        $now, $admin_id, $payment_method, $payment_ref,
        $relative_path, $proof_original, $proof_mime, $proof_size,
        $payment_notes, $commission_id
    );
    if (!$upd->execute() || $upd->affected_rows === 0) {
        throw new Exception('Failed to update commission. It may have been modified by another process.');
    }
    $upd->close();

    // ── 8. Audit log ──
    $log_details = json_encode([
        'payment_method'    => $payment_method,
        'payment_reference' => $payment_ref,
        'proof_file'        => $relative_path,
        'commission_amount' => $commission['commission_amount'],
        'agent_id'          => $commission['agent_id'],
        'admin_ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    $log = $conn->prepare("
        INSERT INTO commission_payment_logs 
            (commission_id, action, old_status, new_status, details, performed_by, ip_address)
        VALUES (?, 'paid', ?, 'paid', ?, ?, ?)
    ");
    $old_status = $commission['status'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $log->bind_param('isssi' . 's', $commission_id, $old_status, $log_details, $admin_id, $ip);
    $log->execute();
    $log->close();

    // ── 9. Agent notification ──
    $fmtAmount = '₱' . number_format($commission['commission_amount'], 2);
    createAgentNotification(
        $conn,
        (int) $commission['agent_id'],
        'commission_paid',
        'Commission Paid',
        "Your commission of {$fmtAmount} for Property #{$commission['property_id']} has been paid. "
        . "Reference: {$payment_ref}. Check your commissions page for details.",
        $commission_id
    );

    // ── 10. Admin notification ──
    $agentName = trim($commission['first_name'] . ' ' . $commission['last_name']);
    $adminMsg = "Commission #{$commission_id} ({$fmtAmount}) paid to {$agentName} for Property #{$commission['property_id']}. Ref: {$payment_ref}";
    $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, created_at) VALUES (?, 'property_sale', 'Commission Paid', ?, 'update', 'normal', NOW())");
    $n->bind_param('is', $commission_id, $adminMsg);
    $n->execute();
    $n->close();

    $conn->commit();

    // ── 11. Email (best-effort, after commit) ──
    // ... (send email logic similar to existing patterns)

    echo json_encode([
        'ok'      => true,
        'message' => 'Commission marked as paid successfully.',
        'paid_at' => $now
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) $conn->rollback();
    // Clean up uploaded file if it was saved
    if (isset($dest_path) && file_exists($dest_path)) @unlink($dest_path);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
```

### 5.2 Frontend: "Process Payment" UI (Modal Fields)

The finalize modal should be enhanced with a **second step** or a separate "Process Payment" button that appears after commission is calculated:

```
┌─────────────────────────────────────────────────────────┐
│           💰 Process Commission Payment                 │
│  Commission #1 • Agent: Kent Giniseran                  │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  COMMISSION AMOUNT          STATUS                      │
│  ₱190,000.00                [Calculated]                │
│                                                         │
│  ─────────────────────────────────────────────────────  │
│                                                         │
│  PAYMENT METHOD *           TRANSACTION REFERENCE *     │
│  [▼ Bank Transfer     ]     [BDO-TXN-20260302-001  ]   │
│                                                         │
│  PAYMENT PROOF *                                        │
│  ┌──────────────────────────────────────┐               │
│  │  📎 Click to upload or drag & drop   │               │
│  │     JPG, PNG, PDF — Max 5 MB         │               │
│  └──────────────────────────────────────┘               │
│                                                         │
│  PAYMENT NOTES (optional)                               │
│  ┌──────────────────────────────────────┐               │
│  │ Transferred via BDO online banking   │               │
│  └──────────────────────────────────────┘               │
│                                                         │
│  ⚠️ This action cannot be undone.                       │
│  The commission will be permanently marked as PAID.     │
│                                                         │
│         [Cancel]          [✓ Confirm Payment]           │
└─────────────────────────────────────────────────────────┘
```

### 5.3 Payment Methods Enum

| Value           | Display Label           | Description                        |
|-----------------|-------------------------|------------------------------------|
| `bank_transfer` | Bank Transfer           | BDO, BPI, Metrobank, etc.         |
| `gcash`         | GCash                   | GCash mobile wallet                |
| `maya`          | Maya                    | Maya (PayMaya) digital wallet      |
| `cash`          | Cash                    | Physical cash handover             |
| `check`         | Check                   | Bank check / cashier's check       |
| `other`         | Other                   | Any other method                   |

---

## 6. Security & Standards

### 6.1 Input Validation & Sanitization

| Field               | Validation Rules                                                    |
|---------------------|---------------------------------------------------------------------|
| `commission_id`     | Must be positive integer, must exist in DB, cast with `(int)`       |
| `payment_method`    | Must be in whitelist array of allowed values                        |
| `payment_reference` | `trim()`, max 100 chars, no HTML tags (`htmlspecialchars` on output)|
| `payment_notes`     | `trim()`, sanitize on output only (allow free text)                 |
| File upload         | Server-side MIME detection via `finfo`, extension whitelist, size cap|

### 6.2 Secure File Upload Handling

```php
// REQUIRED validations for payment proof uploads:

// 1. MIME validation (server-side, not trusting Content-Type header)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected_mime = $finfo->file($file['tmp_name']);

// 2. Extension whitelist
$allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

// 3. Maximum file size (5 MB)
$max_size = 5 * 1024 * 1024;

// 4. Generate unique filename (never use user-provided name)
$safe_name = 'proof_' . $commission_id . '_' . uniqid('', true) . '.' . $ext;

// 5. Store OUTSIDE web root or in protected directory with .htaccess
// uploads/commission_proofs/.htaccess:
//   Options -Indexes
//   <FilesMatch "\.(php|phtml|php3|php4|php5|phps)$">
//     Deny from all
//   </FilesMatch>

// 6. Serve files through a PHP download handler (not direct URL)
// download_commission_proof.php?id=123

// 7. Validate file contents are not empty
if ($file['size'] === 0) reject();

// 8. Check for double extension attacks
//    "proof.php.jpg" → extract last extension only via pathinfo()
```

### 6.3 SQL Injection Protection

All database operations **already use prepared statements** in the codebase, which is correct. Continue this practice:

```php
// ✅ CORRECT — parameterized
$stmt = $conn->prepare("UPDATE agent_commissions SET status = 'paid' WHERE commission_id = ?");
$stmt->bind_param('i', $commission_id);

// ❌ WRONG — string interpolation
$conn->query("UPDATE agent_commissions SET status = 'paid' WHERE commission_id = $commission_id");
```

### 6.4 Double-Payment Prevention (Multi-Layer)

```
Layer 1: UI — Hide/disable "Mark as Paid" button when status is already 'paid'
Layer 2: Backend — Check status before UPDATE
Layer 3: SQL — WHERE clause includes status check:
           UPDATE ... WHERE commission_id = ? AND status IN ('calculated', 'processing')
Layer 4: DB — Use SELECT ... FOR UPDATE to lock the row during transaction
Layer 5: Audit — Log every status change to commission_payment_logs
```

### 6.5 Race Condition Prevention

```php
// Use SELECT ... FOR UPDATE within a transaction to prevent concurrent modifications:

$conn->begin_transaction();

$stmt = $conn->prepare("SELECT * FROM agent_commissions WHERE commission_id = ? FOR UPDATE");
$stmt->bind_param('i', $commission_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

// Check status AFTER locking
if ($row['status'] === 'paid') {
    throw new Exception('Already paid.');
}

// Perform update (still within the lock)
$upd = $conn->prepare("UPDATE agent_commissions SET status = 'paid', paid_at = NOW() WHERE commission_id = ? AND status IN ('calculated','processing')");
$upd->bind_param('i', $commission_id);
$upd->execute();

if ($upd->affected_rows === 0) {
    throw new Exception('Concurrent modification detected.');
}

$conn->commit();
```

### 6.6 Unauthorized Commission Modification Protection

```php
// 1. Session-based role check (already implemented)
if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit;
}

// 2. CSRF token verification (recommended addition)
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit;
}

// 3. Log WHO made the change
$paid_by = $_SESSION['account_id']; // Always capture

// 4. Log the IP address
$ip = $_SERVER['REMOTE_ADDR'];
```

### 6.7 File Upload Vulnerability Protection

| Attack Vector                | Mitigation                                                     |
|------------------------------|----------------------------------------------------------------|
| PHP file upload disguised     | MIME detection via `finfo`, extension whitelist, `.htaccess`   |
| Path traversal (`../../../`)  | Use `basename()`, generate own filename                        |
| Double extension (`file.php.jpg`) | Parse with `pathinfo()`, match last extension only        |
| ZIP bomb / oversized file     | Enforce `$max_file_size` before processing                     |
| XSS via filename             | Never display original filename without `htmlspecialchars()`   |
| Direct access to uploads      | `.htaccess` deny, serve via PHP script                        |

---

## 7. Role-Based Access Control

### 7.1 Permission Matrix

| Action                          | Admin | Agent | System |
|---------------------------------|-------|-------|--------|
| View all commissions            | ✅    | ❌    | —      |
| View own commissions            | ✅    | ✅    | —      |
| Calculate commission            | ✅    | ❌    | —      |
| Upload payment proof            | ✅    | ❌    | —      |
| Mark commission as paid         | ✅    | ❌    | —      |
| Cancel commission               | ✅    | ❌    | —      |
| View payment proof              | ✅    | ✅*   | —      |
| Download payment proof          | ✅    | ✅*   | —      |
| Edit paid commission            | ❌    | ❌    | —      |
| View payment audit logs         | ✅    | ❌    | —      |
| Receive paid notification       | —     | ✅    | ✅     |
| Create commission record        | —     | —     | ✅     |

> *Agent can only view/download proof for their OWN commissions.

### 7.2 Agent-Side Restrictions

```php
// In agent_commissions.php — always filter by agent's own account_id
$stmt->bind_param('i', $_SESSION['account_id']);

// In download_commission_proof.php — verify ownership
$stmt = $conn->prepare("SELECT * FROM agent_commissions WHERE commission_id = ? AND agent_id = ?");
$stmt->bind_param('ii', $commission_id, $_SESSION['account_id']);
```

---

## 8. Best Practices Summary

### 8.1 Database Integrity

| Practice                                              | Status in Current System | Recommendation            |
|-------------------------------------------------------|--------------------------|---------------------------|
| Use `UNIQUE` key on `sale_id` in commissions          | ❌ Missing               | **Add** `uq_sale_commission` |
| Use proper ENUM for status including all states        | ⚠️ Partial               | **Add** `'processing'`       |
| Foreign keys with proper `ON DELETE` behavior          | ✅ Present               | Keep                         |
| `updated_at` auto-timestamp for change tracking        | ❌ Missing               | **Add** column               |
| Audit table for payment-related changes               | ❌ Missing               | **Add** `commission_payment_logs` |
| Separate `payment_notes` from `payment_reference`     | ❌ Conflated             | **Separate** into two fields |

### 8.2 Data Validation Checklist

- [x] All IDs cast to `(int)` before use
- [x] Email validated with `filter_var(FILTER_VALIDATE_EMAIL)`
- [x] Numeric fields validated with `is_numeric()` and range checks
- [x] Strings trimmed with `trim()`
- [x] Output escaped with `htmlspecialchars()`
- [ ] **Add:** CSRF token validation on all POST forms  
- [ ] **Add:** Rate limiting on payment processing endpoint  
- [ ] **Add:** `Content-Security-Policy` headers  

### 8.3 Preventing Inconsistent States

| Scenario                                    | Prevention Method                              |
|---------------------------------------------|------------------------------------------------|
| Commission paid but `paid_at` is NULL       | Always set `paid_at = NOW()` in same UPDATE    |
| Commission paid but no proof file           | Require proof upload before allowing 'paid'    |
| Two admins paying same commission           | `SELECT ... FOR UPDATE` + check `affected_rows`|
| Commission amount changed after payment     | `is_locked = 1` or status check before edit    |
| Orphaned proof file (DB fails, file saved)  | Upload file last, within transaction; rollback deletes file |
| Sale deleted but commission remains          | FK `ON DELETE CASCADE` (already present)       |

### 8.4 `paid_at` Field — Rules

| Rule                                                                 |
|----------------------------------------------------------------------|
| `paid_at` must ONLY be set when `status` transitions to `'paid'`     |
| `paid_at` must be set using `NOW()` or server-side timestamp         |
| `paid_at` must NEVER be set by client-side input                     |
| `paid_at` must NEVER be NULL when `status = 'paid'`                  |
| `paid_at` must ALWAYS be NULL when `status != 'paid'`                |
| `paid_at` is immutable once set (payment is a terminal state)        |
| `paid_at` must be stored in UTC or a consistent timezone             |

---

## 9. SQL Migration Script

Run this migration to upgrade the existing database structure:

```sql
-- ============================================================
-- MIGRATION: Commission Payment Tracking System
-- Date: 2026-03-05
-- Description: Adds payment tracking fields to agent_commissions
--              and creates commission_payment_logs audit table
-- ============================================================

-- Step 1: Add new columns to agent_commissions
ALTER TABLE `agent_commissions`
  ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL 
      COMMENT 'bank_transfer, gcash, maya, cash, check, other' 
      AFTER `payment_reference`,
  ADD COLUMN `payment_proof_path` VARCHAR(500) DEFAULT NULL 
      COMMENT 'Relative path to uploaded payment proof file' 
      AFTER `payment_method`,
  ADD COLUMN `payment_proof_original_name` VARCHAR(255) DEFAULT NULL 
      COMMENT 'Original filename of uploaded proof' 
      AFTER `payment_proof_path`,
  ADD COLUMN `payment_proof_mime` VARCHAR(100) DEFAULT NULL 
      COMMENT 'MIME type of the proof file' 
      AFTER `payment_proof_original_name`,
  ADD COLUMN `payment_proof_size` INT DEFAULT NULL 
      COMMENT 'File size of proof in bytes' 
      AFTER `payment_proof_mime`,
  ADD COLUMN `paid_by` INT DEFAULT NULL 
      COMMENT 'Admin account_id who processed the payment' 
      AFTER `processed_by`,
  ADD COLUMN `payment_notes` TEXT DEFAULT NULL 
      COMMENT 'Admin notes related to the payment action' 
      AFTER `paid_by`,
  ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP 
      ON UPDATE CURRENT_TIMESTAMP 
      AFTER `created_at`;

-- Step 2: Add foreign key for paid_by
ALTER TABLE `agent_commissions`
  ADD CONSTRAINT `fk_commission_paid_by` 
      FOREIGN KEY (`paid_by`) REFERENCES `accounts` (`account_id`) ON DELETE SET NULL;

-- Step 3: Expand status enum to include 'processing'
ALTER TABLE `agent_commissions`
  MODIFY COLUMN `status` ENUM('pending','calculated','processing','paid','cancelled') 
      NOT NULL DEFAULT 'pending';

-- Step 4: Add unique constraint to prevent duplicate commissions per sale
ALTER TABLE `agent_commissions`
  ADD UNIQUE KEY `uq_sale_commission` (`sale_id`);

-- Step 5: Add indexes for common query patterns
ALTER TABLE `agent_commissions`
  ADD KEY `idx_commission_status` (`status`),
  ADD KEY `idx_commission_paid_at` (`paid_at`);

-- Step 6: Create commission payment audit log table
CREATE TABLE `commission_payment_logs` (
  `log_id`        INT NOT NULL AUTO_INCREMENT,
  `commission_id` INT NOT NULL,
  `action`        ENUM(
      'created','calculated','processing','paid',
      'cancelled','updated','proof_uploaded','proof_replaced'
  ) NOT NULL,
  `old_status`    VARCHAR(20) DEFAULT NULL,
  `new_status`    VARCHAR(20) DEFAULT NULL,
  `details`       TEXT DEFAULT NULL COMMENT 'JSON describing the change',
  `performed_by`  INT NOT NULL COMMENT 'Admin account_id',
  `ip_address`    VARCHAR(45) DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_cpl_commission` (`commission_id`),
  KEY `idx_cpl_action` (`action`),
  KEY `idx_cpl_performed_by` (`performed_by`),
  KEY `idx_cpl_created_at` (`created_at`),
  CONSTRAINT `fk_cpl_commission` 
      FOREIGN KEY (`commission_id`) REFERENCES `agent_commissions` (`commission_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpl_performed_by` 
      FOREIGN KEY (`performed_by`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Step 7: Create upload directory protection
-- NOTE: Manually create this file at uploads/commission_proofs/.htaccess
-- Content:
--   Options -Indexes
--   <FilesMatch "\.(php|phtml|php3|php4|php5|phps|phar|sh|bash|cgi|pl)$">
--     Deny from all
--   </FilesMatch>

-- Step 8: Fix existing data — separate payment_reference from notes
-- The current payment_reference values are actually notes, move them
UPDATE `agent_commissions` 
SET `payment_notes` = `payment_reference`,
    `payment_reference` = NULL
WHERE `status` = 'calculated' 
  AND `payment_reference` IS NOT NULL 
  AND `paid_at` IS NULL;

-- ============================================================
-- MIGRATION COMPLETE
-- ============================================================
```

---

## Appendix A: Entity Relationship (Commission Focus)

```
┌──────────────────┐     1:1     ┌──────────────────────┐     1:1     ┌────────────────────┐
│ sale_verifications│────────────→│   finalized_sales    │────────────→│ agent_commissions  │
│                  │             │                      │             │                    │
│ verification_id  │             │ sale_id (PK)         │             │ commission_id (PK) │
│ property_id      │             │ verification_id (FK) │             │ sale_id (FK, UQ)   │
│ agent_id         │             │ property_id (FK)     │             │ agent_id (FK)      │
│ sale_price       │             │ agent_id (FK)        │             │ commission_amount  │
│ sale_date        │             │ buyer_name           │             │ commission_%       │
│ buyer_name       │             │ buyer_email          │             │ status (ENUM)      │
│ buyer_email      │             │ final_sale_price     │             │ calculated_at      │
│ status (ENUM)    │             │ sale_date            │             │ paid_at ← KEY      │
│ submitted_at     │             │ finalized_by (FK)    │             │ paid_by (FK)       │
│ reviewed_by      │             │ finalized_at         │             │ payment_method     │
│ reviewed_at      │             │ is_locked            │             │ payment_reference  │
└──────────────────┘             └──────────────────────┘             │ payment_proof_path │
        │                                │                            │ payment_notes      │
        │ 1:N                            │ 1:1                        │ processed_by (FK)  │
        ▼                                ▼                            │ created_at         │
┌──────────────────┐             ┌──────────────────┐                 │ updated_at         │
│ sale_verification│             │    property      │                 └────────┬───────────┘
│ _documents       │             │                  │                          │
│                  │             │ property_ID (PK) │                     1:N  │
│ document_id (PK) │             │ Status           │                          ▼
│ verification_id  │             │ is_locked        │                 ┌────────────────────┐
│ original_filename│             │ sold_date        │                 │commission_payment  │
│ stored_filename  │             │ sold_by_agent    │                 │     _logs          │
│ file_path        │             └──────────────────┘                 │                    │
│ file_size        │                                                  │ log_id (PK)        │
│ mime_type        │                                                  │ commission_id (FK) │
└──────────────────┘                                                  │ action (ENUM)      │
                                                                      │ old_status         │
                                                                      │ new_status         │
                                                                      │ details (JSON)     │
                                                                      │ performed_by (FK)  │
                                                                      │ ip_address         │
                                                                      │ created_at         │
                                                                      └────────────────────┘
```

---

## Appendix B: Checklist Before Go-Live

- [ ] Run SQL migration script on development database
- [ ] Create `uploads/commission_proofs/` directory with proper permissions
- [ ] Add `.htaccess` to `uploads/commission_proofs/`
- [ ] Implement `process_commission_payment.php` backend handler
- [ ] Add "Process Payment" button to admin sale approvals UI (visible when status = `calculated`)
- [ ] Build payment modal with file upload, method selector, reference input
- [ ] Update `agent_commissions.php` to show payment proof download link
- [ ] Add download handler: `download_commission_proof.php` with ownership check
- [ ] Fix existing `payment_reference` data (run Step 8 of migration)
- [ ] Implement CSRF tokens on all payment forms
- [ ] Test double-payment prevention (open two tabs, click simultaneously)
- [ ] Test file upload with edge cases (0 bytes, >5 MB, PHP files, double extensions)
- [ ] Verify audit logs populate correctly in `commission_payment_logs`
- [ ] Test agent notification delivery on payment
- [ ] Test email delivery on payment
- [ ] Verify agent can view but not modify payment records
- [ ] Load test with concurrent admin operations

---

*Document generated for HomeEstate Realty Capstone System — Commission Payment Tracking Module*
