# Rental Feature Implementation Blueprint

> **Project**: Real Estate Capstone System  
> **Date Created**: March 4, 2026  
> **Status**: Ready for Implementation  
> **Total Phases**: 10

---

## Table of Contents

1. [Design Decisions Summary](#1-design-decisions-summary)
2. [Phase 1 — Database Schema Changes](#phase-1--database-schema-changes)
3. [Phase 2 — Property Listing Support for Rentals](#phase-2--property-listing-support-for-rentals)
4. [Phase 3 — Rental Verification Submission (Agent)](#phase-3--rental-verification-submission-agent)
5. [Phase 4 — Rental Verification Review & Lease Finalization (Admin)](#phase-4--rental-verification-review--lease-finalization-admin)
6. [Phase 5 — Rent Payment Recording (Agent)](#phase-5--rent-payment-recording-agent)
7. [Phase 6 — Rent Payment Confirmation & Commission (Admin)](#phase-6--rent-payment-confirmation--commission-admin)
8. [Phase 7 — Lease Expiry Notifications](#phase-7--lease-expiry-notifications)
9. [Phase 8 — Lease Renewal](#phase-8--lease-renewal)
10. [Phase 9 — Move Out & Lease Termination](#phase-9--move-out--lease-termination)
11. [Phase 10 — Navigation, Dashboard & Reports Updates](#phase-10--navigation-dashboard--reports-updates)
12. [Security Checklist](#security-checklist)
13. [Edge Cases & Business Logic Safeguards](#edge-cases--business-logic-safeguards)
14. [Complete File Inventory](#complete-file-inventory)

---

## 1. Design Decisions Summary

| Decision | Choice |
|---|---|
| Listing type | Mutually exclusive — `For Sale` OR `For Rent` |
| Tenant accounts | None — Agent enters tenant info manually |
| Rental workflow | Mirrors sale flow: Verification → Admin Approval → Finalized |
| Payment tracking | Agent records monthly payments with proof → Admin confirms |
| Commission model | Recurring % of each confirmed monthly rent payment |
| Commission rate | Set by admin during lease finalization |
| Lease expiry | System notifies agent & admin; Agent decides continue/move-out |
| Renewal | Simple extension + optional rent update, no re-verification |
| Early termination | Agent can terminate anytime; property returns to For Rent |
| Documents | At least 1 doc for rental verification; at least 1 per payment proof |
| Rental details | Use existing `rental_details` fields (monthly_rent, security_deposit, lease_term_months, furnishing, available_from) |

---

## Phase 1 — Database Schema Changes

### Priority: CRITICAL — Must be completed first
### Estimated effort: 1 session

### 1.1 Alter `property` table — Add `listing_type` column

```sql
ALTER TABLE `property`
  ADD COLUMN `listing_type` ENUM('For Sale', 'For Rent') NOT NULL DEFAULT 'For Sale'
  AFTER `ParkingType`;
```

**Why**: Distinguishes sale vs. rental properties at the data level. All existing properties default to "For Sale" (backward compatible).

### 1.2 Update `property.Status` values

Current status values: `For Sale`, `Pending Sold`, `Sold`

New status values to support:

| Status | Listing Type | Meaning |
|---|---|---|
| `For Sale` | For Sale | Active sale listing |
| `Pending Sold` | For Sale | Sale verification pending admin review |
| `Sold` | For Sale | Sale finalized |
| `For Rent` | For Rent | Active rental listing |
| `Pending Rented` | For Rent | Rental verification pending admin review |
| `Rented` | For Rent | Active lease in progress |

**No schema change needed** — the `Status` column is `varchar(50)`, so it already supports new string values. Just ensure all PHP code references the correct status strings.

### 1.3 Update existing properties

```sql
-- Set listing_type for all existing properties
UPDATE `property` SET `listing_type` = 'For Sale' WHERE `listing_type` = '' OR `listing_type` IS NULL;
```

### 1.4 Existing `rental_details` table — No changes needed

The existing table already has the required fields:

```
rental_details
├── rental_id (PK, AUTO_INCREMENT)
├── property_id (FK → property.property_ID)
├── monthly_rent (DECIMAL 12,2)
├── security_deposit (DECIMAL 12,2)
├── lease_term_months (INT)
├── furnishing (ENUM: Unfurnished, Semi-Furnished, Fully Furnished)
├── available_from (DATE)
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)
```

### 1.5 Create `rental_verifications` table

```sql
CREATE TABLE `rental_verifications` (
  `verification_id` INT(11) NOT NULL AUTO_INCREMENT,
  `property_id` INT(11) NOT NULL,
  `agent_id` INT(11) NOT NULL,
  `monthly_rent` DECIMAL(12,2) NOT NULL,
  `security_deposit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `lease_start_date` DATE NOT NULL,
  `lease_term_months` INT(11) NOT NULL,
  `tenant_name` VARCHAR(255) NOT NULL,
  `tenant_email` VARCHAR(255) DEFAULT NULL,
  `tenant_phone` VARCHAR(20) DEFAULT NULL,
  `additional_notes` TEXT DEFAULT NULL,
  `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_by` INT(11) DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`verification_id`),
  KEY `idx_rv_property_id` (`property_id`),
  KEY `idx_rv_agent_id` (`agent_id`),
  KEY `idx_rv_status` (`status`),
  CONSTRAINT `fk_rv_property` FOREIGN KEY (`property_id`) REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_rv_agent` FOREIGN KEY (`agent_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 1.6 Create `rental_verification_documents` table

```sql
CREATE TABLE `rental_verification_documents` (
  `document_id` INT(11) NOT NULL AUTO_INCREMENT,
  `verification_id` INT(11) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_rvd_verification_id` (`verification_id`),
  CONSTRAINT `fk_rvd_verification` FOREIGN KEY (`verification_id`) REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 1.7 Create `finalized_rentals` table

```sql
CREATE TABLE `finalized_rentals` (
  `rental_id` INT(11) NOT NULL AUTO_INCREMENT,
  `verification_id` INT(11) NOT NULL,
  `property_id` INT(11) NOT NULL,
  `agent_id` INT(11) NOT NULL,
  `tenant_name` VARCHAR(255) NOT NULL,
  `tenant_email` VARCHAR(255) DEFAULT NULL,
  `tenant_phone` VARCHAR(20) DEFAULT NULL,
  `monthly_rent` DECIMAL(12,2) NOT NULL,
  `security_deposit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `lease_start_date` DATE NOT NULL,
  `lease_end_date` DATE NOT NULL,
  `lease_term_months` INT(11) NOT NULL,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentage per confirmed payment',
  `additional_notes` TEXT DEFAULT NULL,
  `lease_status` ENUM('Active', 'Renewed', 'Terminated', 'Expired') NOT NULL DEFAULT 'Active',
  `terminated_at` TIMESTAMP NULL DEFAULT NULL,
  `terminated_by` INT(11) DEFAULT NULL,
  `termination_reason` TEXT DEFAULT NULL,
  `finalized_by` INT(11) NOT NULL,
  `finalized_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rental_id`),
  UNIQUE KEY `uq_fr_verification` (`verification_id`),
  KEY `idx_fr_property_id` (`property_id`),
  KEY `idx_fr_agent_id` (`agent_id`),
  KEY `idx_fr_lease_status` (`lease_status`),
  KEY `idx_fr_lease_end_date` (`lease_end_date`),
  CONSTRAINT `fk_fr_verification` FOREIGN KEY (`verification_id`) REFERENCES `rental_verifications` (`verification_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_property` FOREIGN KEY (`property_id`) REFERENCES `property` (`property_ID`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_agent` FOREIGN KEY (`agent_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_finalized_by` FOREIGN KEY (`finalized_by`) REFERENCES `accounts` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Key design notes:**
- `commission_rate` is stored per lease so admin sets it during finalization
- `lease_end_date` is auto-calculated: `lease_start_date + lease_term_months`
- `lease_status` tracks the lifecycle: Active → Renewed/Terminated/Expired

### 1.8 Create `rental_payments` table

```sql
CREATE TABLE `rental_payments` (
  `payment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `rental_id` INT(11) NOT NULL COMMENT 'FK to finalized_rentals',
  `agent_id` INT(11) NOT NULL,
  `payment_amount` DECIMAL(12,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `period_start` DATE NOT NULL COMMENT 'Rental period start (e.g., 2026-04-01)',
  `period_end` DATE NOT NULL COMMENT 'Rental period end (e.g., 2026-04-30)',
  `additional_notes` TEXT DEFAULT NULL,
  `status` ENUM('Pending', 'Confirmed', 'Rejected') NOT NULL DEFAULT 'Pending',
  `admin_notes` TEXT DEFAULT NULL,
  `confirmed_by` INT(11) DEFAULT NULL,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_rp_rental_id` (`rental_id`),
  KEY `idx_rp_agent_id` (`agent_id`),
  KEY `idx_rp_status` (`status`),
  KEY `idx_rp_period` (`period_start`, `period_end`),
  CONSTRAINT `fk_rp_rental` FOREIGN KEY (`rental_id`) REFERENCES `finalized_rentals` (`rental_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_agent` FOREIGN KEY (`agent_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 1.9 Create `rental_payment_documents` table

```sql
CREATE TABLE `rental_payment_documents` (
  `document_id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_rpd_payment_id` (`payment_id`),
  CONSTRAINT `fk_rpd_payment` FOREIGN KEY (`payment_id`) REFERENCES `rental_payments` (`payment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 1.10 Create `rental_commissions` table

```sql
CREATE TABLE `rental_commissions` (
  `commission_id` INT(11) NOT NULL AUTO_INCREMENT,
  `rental_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `agent_id` INT(11) NOT NULL,
  `commission_amount` DECIMAL(12,2) NOT NULL,
  `commission_percentage` DECIMAL(5,2) NOT NULL,
  `status` ENUM('pending', 'calculated', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
  `calculated_at` TIMESTAMP NULL DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `processed_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`commission_id`),
  KEY `idx_rc_rental_id` (`rental_id`),
  KEY `idx_rc_payment_id` (`payment_id`),
  KEY `idx_rc_agent_id` (`agent_id`),
  CONSTRAINT `fk_rc_rental` FOREIGN KEY (`rental_id`) REFERENCES `finalized_rentals` (`rental_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rc_payment` FOREIGN KEY (`payment_id`) REFERENCES `rental_payments` (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rc_agent` FOREIGN KEY (`agent_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 1.11 Update `property_log.action` ENUM

```sql
ALTER TABLE `property_log`
  MODIFY `action` ENUM('CREATED','UPDATED','DELETED','SOLD','REJECTED','RENTED','LEASE_RENEWED','LEASE_TERMINATED') NOT NULL DEFAULT 'CREATED';
```

### 1.12 Update `notifications.item_type` ENUM

```sql
ALTER TABLE `notifications`
  MODIFY `item_type` ENUM('agent','property','property_sale','property_rental','rental_payment','tour') NOT NULL DEFAULT 'agent';
```

### 1.13 Update `agent_notifications.notif_type` ENUM

```sql
ALTER TABLE `agent_notifications`
  MODIFY `notif_type` ENUM(
    'tour_new','tour_cancelled','tour_completed',
    'property_approved','property_rejected',
    'sale_approved','sale_rejected',
    'commission_paid',
    'rental_approved','rental_rejected',
    'rental_payment_confirmed','rental_payment_rejected',
    'rental_commission_paid',
    'lease_expiring','lease_expired',
    'general'
  ) NOT NULL DEFAULT 'general';
```

### 1.14 Update `price_history.event_type` ENUM

```sql
ALTER TABLE `price_history`
  MODIFY `event_type` ENUM('Listed','Price Change','Sold','Off Market','Rented','Lease Ended') NOT NULL;
```

### Phase 1 Validation Checklist

- [ ] All new tables created without errors
- [ ] All foreign keys reference correct tables and columns
- [ ] All ENUMs updated on existing tables
- [ ] `listing_type` column added to `property` table
- [ ] Existing data is not broken (all existing properties default to 'For Sale')
- [ ] Run `SHOW CREATE TABLE` on each modified table to verify

---

## Phase 2 — Property Listing Support for Rentals

### Priority: HIGH
### Estimated effort: 1-2 sessions
### Dependencies: Phase 1 complete

### 2.1 Files to Modify

| File | Change |
|---|---|
| `add_property.php` | Add listing type selector (For Sale / For Rent); conditionally show rental detail fields |
| `save_property.php` | Save `listing_type` + insert into `rental_details` when For Rent |
| `update_property.php` | Support editing rental details |
| `view_property.php` | Display rental details (rent, deposit, term, furnishing) for rental properties |
| `property.php` | Filter by listing type; show rent price instead of sale price for rentals |
| `admin_property_card_template.php` | Show "For Rent" badge, display monthly rent |
| `agent_pages/property_card_template.php` | Show "For Rent" badge, display monthly rent |
| `agent_pages/agent_property.php` | Filter by listing type; show rental properties |
| `agent_pages/add_property_process.php` | Handle rental details on property creation |
| `get_property_data.php` | Return rental details in API response |

### 2.2 Listing Type Selector (add_property.php)

Add a radio button or select dropdown **before** the price field:

```html
<div class="mb-3">
  <label class="form-label fw-semibold">Listing Type <span class="text-danger">*</span></label>
  <div class="d-flex gap-3">
    <div class="form-check">
      <input class="form-check-input" type="radio" name="listing_type" id="listingForSale" value="For Sale" checked>
      <label class="form-check-label" for="listingForSale">For Sale</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="radio" name="listing_type" id="listingForRent" value="For Rent">
      <label class="form-check-label" for="listingForRent">For Rent</label>
    </div>
  </div>
</div>
```

### 2.3 Conditional Rental Fields (add_property.php)

When "For Rent" is selected, show additional fields and change the price label:

```html
<!-- Shown only when listing_type = 'For Rent' -->
<div id="rentalFields" style="display:none;">
  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label fw-semibold">Monthly Rent (₱) <span class="text-danger">*</span></label>
      <input type="number" class="form-control" name="monthly_rent" min="0" step="0.01">
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label fw-semibold">Security Deposit (₱)</label>
      <input type="number" class="form-control" name="security_deposit" min="0" step="0.01" value="0">
    </div>
  </div>
  <div class="row">
    <div class="col-md-4 mb-3">
      <label class="form-label fw-semibold">Lease Term (Months) <span class="text-danger">*</span></label>
      <input type="number" class="form-control" name="lease_term_months" min="1" max="120">
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label fw-semibold">Furnishing <span class="text-danger">*</span></label>
      <select class="form-select" name="furnishing">
        <option value="Unfurnished">Unfurnished</option>
        <option value="Semi-Furnished">Semi-Furnished</option>
        <option value="Fully Furnished">Fully Furnished</option>
      </select>
    </div>
    <div class="col-md-4 mb-3">
      <label class="form-label fw-semibold">Available From <span class="text-danger">*</span></label>
      <input type="date" class="form-control" name="available_from">
    </div>
  </div>
</div>
```

**JavaScript toggle:**

```javascript
document.querySelectorAll('input[name="listing_type"]').forEach(radio => {
  radio.addEventListener('change', function() {
    const rentalFields = document.getElementById('rentalFields');
    const priceLabel = document.getElementById('priceLabel');
    if (this.value === 'For Rent') {
      rentalFields.style.display = 'block';
      priceLabel.textContent = 'Listing Price / Monthly Rent (₱)';
    } else {
      rentalFields.style.display = 'none';
      priceLabel.textContent = 'Listing Price (₱)';
    }
  });
});
```

### 2.4 Save Logic (save_property.php)

```php
// After inserting into property table:
$listing_type = $_POST['listing_type']; // 'For Sale' or 'For Rent'

// Set initial status based on listing type
$initial_status = ($listing_type === 'For Rent') ? 'For Rent' : 'For Sale';

// Include listing_type in the INSERT statement for property table
// ...

// If For Rent, also insert rental details
if ($listing_type === 'For Rent') {
    $monthly_rent = floatval($_POST['monthly_rent']);
    $security_deposit = floatval($_POST['security_deposit'] ?? 0);
    $lease_term_months = intval($_POST['lease_term_months']);
    $furnishing = $_POST['furnishing'];
    $available_from = $_POST['available_from'];
    
    $stmt = $conn->prepare("
        INSERT INTO rental_details (property_id, monthly_rent, security_deposit, lease_term_months, furnishing, available_from)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iddiss", $property_id, $monthly_rent, $security_deposit, $lease_term_months, $furnishing, $available_from);
    $stmt->execute();
}
```

### 2.5 Display Logic (view_property.php)

```php
// Fetch rental details if property is For Rent
if ($property['listing_type'] === 'For Rent') {
    $rental_stmt = $conn->prepare("SELECT * FROM rental_details WHERE property_id = ?");
    $rental_stmt->bind_param("i", $property_id);
    $rental_stmt->execute();
    $rental_details = $rental_stmt->get_result()->fetch_assoc();
}
```

Display sections:
- **Monthly Rent**: ₱XX,XXX.XX /month
- **Security Deposit**: ₱XX,XXX.XX
- **Lease Term**: XX months
- **Furnishing**: Unfurnished / Semi-Furnished / Fully Furnished
- **Available From**: Month DD, YYYY

### 2.6 Property Card Changes

For rental properties, the card should display:
- Badge: `For Rent` (use a distinct color, e.g., `bg-info` or `bg-primary` instead of `bg-success` used for For Sale)
- Price shows: `₱XX,XXX /mo` instead of `₱XX,XXX,XXX`
- Show furnishing status as a small tag

### 2.7 Validation Rules

| Field | Rule |
|---|---|
| `listing_type` | Required, must be 'For Sale' or 'For Rent' |
| `monthly_rent` | Required if For Rent, > 0, max 99999999.99 |
| `security_deposit` | Optional, >= 0 |
| `lease_term_months` | Required if For Rent, 1-120 |
| `furnishing` | Required if For Rent, must be valid ENUM value |
| `available_from` | Required if For Rent, valid date, cannot be in the past |
| `ListingPrice` | For Rent properties: set to monthly_rent value (for search/filter compatibility) |

### Phase 2 Validation Checklist

- [ ] Can create a "For Rent" property with all rental details
- [ ] Can create a "For Sale" property (unchanged behavior)
- [ ] Rental fields only show when "For Rent" is selected
- [ ] Property cards display correctly for both types
- [ ] View property page shows rental details for rental properties
- [ ] Filters work for both listing types
- [ ] Edit/update property preserves listing type and rental details
- [ ] Listing type cannot be changed after property has active lease

---

## Phase 3 — Rental Verification Submission (Agent)

### Priority: HIGH
### Estimated effort: 1-2 sessions
### Dependencies: Phase 2 complete

### 3.1 Files to Create

| File | Purpose |
|---|---|
| `agent_pages/mark_as_rented_process.php` | Backend: Process rental verification submission |

### 3.2 Files to Modify

| File | Change |
|---|---|
| `agent_pages/agent_property.php` | Add "Mark as Rented" button for rental properties |
| `agent_pages/property_card_template.php` | Show "Mark as Rented" option in dropdown for For Rent properties |
| `agent_pages/agent_notification_helper.php` | Add rental notification types |

### 3.3 Mark as Rented Modal (in agent_property.php or separate modal file)

The modal mirrors the "Mark as Sold" modal but with rental-specific fields:

```html
<!-- Mark as Rented Modal -->
<div class="modal fade" id="markAsRentedModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Submit Rental Verification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="rentalVerificationForm" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="property_id" id="rental_property_id">
          
          <!-- Tenant Information -->
          <h6 class="fw-bold mb-3">Tenant Information</h6>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Tenant Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="tenant_name" required maxlength="255">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Tenant Email</label>
              <input type="email" class="form-control" name="tenant_email" maxlength="255">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tenant Phone</label>
            <input type="text" class="form-control" name="tenant_phone" maxlength="20">
          </div>
          
          <!-- Lease Details -->
          <h6 class="fw-bold mb-3 mt-4">Lease Details</h6>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Monthly Rent (₱) <span class="text-danger">*</span></label>
              <input type="number" class="form-control" name="monthly_rent" id="rental_monthly_rent" required min="1" step="0.01">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Security Deposit (₱)</label>
              <input type="number" class="form-control" name="security_deposit" min="0" step="0.01" value="0">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Lease Start Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="lease_start_date" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Lease Term (Months) <span class="text-danger">*</span></label>
              <input type="number" class="form-control" name="lease_term_months" required min="1" max="120">
            </div>
          </div>
          
          <!-- Documents -->
          <h6 class="fw-bold mb-3 mt-4">Supporting Documents</h6>
          <p class="text-muted small">Upload lease contract, tenant ID, deposit receipt, etc. (PDF, JPG, PNG, DOC — max 120MB each)</p>
          <div class="mb-3">
            <input type="file" class="form-control" name="rental_documents[]" multiple required
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
          </div>
          
          <!-- Additional Notes -->
          <div class="mb-3">
            <label class="form-label">Additional Notes</label>
            <textarea class="form-control" name="additional_notes" rows="3" maxlength="2000"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="submitRentalBtn">
            <span class="spinner-border spinner-border-sm d-none" id="rentalSpinner"></span>
            Submit Rental Verification
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
```

### 3.4 Backend Process (agent_pages/mark_as_rented_process.php)

**Logic flow:**

```
1. Verify session: user is logged in AND is an agent
2. Verify request method: POST only
3. Validate required fields:
   - property_id (integer, > 0)
   - tenant_name (non-empty string, max 255)
   - monthly_rent (numeric, > 0)
   - lease_start_date (valid date)
   - lease_term_months (integer, 1-120)
   - At least 1 file uploaded
4. Verify property:
   - Exists in database
   - Belongs to this agent (via property_log CREATED action)
   - Has listing_type = 'For Rent'
   - Has approval_status = 'approved'
   - Has Status = 'For Rent' (not already Pending Rented or Rented)
5. Check no existing pending/approved rental verification exists
6. Validate files (type, size)
7. BEGIN TRANSACTION
   a. Insert into rental_verifications (status = 'Pending')
   b. Upload files → insert into rental_verification_documents
   c. Update property Status to 'Pending Rented'
   d. Insert property_log entry (action = 'UPDATED', reason = 'Rental verification submitted')
   e. Create admin notification
   f. COMMIT
8. Return JSON success response
```

**Upload directory**: `rental_documents/{property_id}/`

### 3.5 Validation Rules

| Field | Server-side Validation |
|---|---|
| `property_id` | Integer, exists, belongs to agent, listing_type='For Rent', Status='For Rent', approved |
| `tenant_name` | Required, trim, 1-255 chars, no HTML tags |
| `tenant_email` | Optional, valid email format if provided |
| `tenant_phone` | Optional, max 20 chars |
| `monthly_rent` | Required, numeric, > 0, max 99999999.99 |
| `security_deposit` | Optional, numeric, >= 0 |
| `lease_start_date` | Required, valid Y-m-d, cannot be more than 30 days in the past |
| `lease_term_months` | Required, integer, 1-120 |
| `rental_documents[]` | At least 1 file, allowed types: pdf/jpg/jpeg/png/doc/docx, max 120MB each |
| `additional_notes` | Optional, max 2000 chars |

### 3.6 Security Considerations

- **CSRF protection**: Validate session token
- **File upload security**: Validate MIME type server-side (not just extension), use `finfo_file()` 
- **Path traversal**: Use `basename()` on filenames, generate unique stored names
- **SQL injection**: All queries use prepared statements
- **XSS**: `htmlspecialchars()` on all output
- **Authorization**: Verify agent owns the property before allowing submission
- **Race condition**: Check for existing pending verification inside the transaction

### Phase 3 Validation Checklist

- [ ] Agent can submit rental verification for a "For Rent" property
- [ ] Property status changes to "Pending Rented"
- [ ] Files are uploaded to correct directory
- [ ] Admin notification is created
- [ ] Cannot submit for already-pending/rented properties
- [ ] Cannot submit for properties not belonging to the agent
- [ ] Cannot submit for "For Sale" properties
- [ ] All validation rules enforced server-side

---

## Phase 4 — Rental Verification Review & Lease Finalization (Admin)

### Priority: HIGH
### Estimated effort: 1-2 sessions
### Dependencies: Phase 3 complete

### 4.1 Files to Create

| File | Purpose |
|---|---|
| `admin_rental_approvals.php` | Page: List all rental verifications for admin review |
| `admin_finalize_rental.php` | Backend: Approve rental → create finalized rental record |
| `admin_reject_rental.php` | Backend: Reject rental verification |
| `admin_rental_details.php` | Page: View full rental verification details + documents |

### 4.2 Files to Modify

| File | Change |
|---|---|
| `admin_sidebar.php` | Add "Rental Approvals" menu item |
| `admin_dashboard.php` | Add rental statistics cards (Pending Rentals, Active Leases) |
| `admin_navbar.php` | Rental notification badge count |
| `admin_notifications.php` | Handle rental notification types |

### 4.3 Rental Approvals Page (admin_rental_approvals.php)

**Layout**: Mirror `admin_property_sale_approvals.php` but for rentals.

Display table with columns:
- Verification ID
- Property Address (linked)
- Agent Name
- Tenant Name
- Monthly Rent
- Lease Term
- Submitted Date
- Status (badge: Pending/Approved/Rejected)
- Actions (View Details / Approve / Reject)

**Filters**: Status filter (All / Pending / Approved / Rejected)

### 4.4 Rental Details Page (admin_rental_details.php)

Display:
- **Property info**: Address, type, photo thumbnail
- **Agent info**: Name, license number
- **Tenant info**: Name, email, phone
- **Lease details**: Monthly rent, deposit, start date, term, calculated end date
- **Uploaded documents**: List with download links
- **Additional notes**
- **Action buttons**: Approve (with commission rate input) / Reject (with reason)

### 4.5 Approval Process (admin_finalize_rental.php)

**Logic flow:**

```
1. Verify session: user is logged in AND is admin
2. Verify request method: POST
3. Get verification_id and commission_rate from POST
4. Validate:
   - verification_id exists and status = 'Pending'
   - commission_rate is numeric, 0.01 - 100.00
5. Fetch the verification record
6. BEGIN TRANSACTION
   a. Update rental_verifications: status = 'Approved', reviewed_by, reviewed_at
   b. Calculate lease_end_date = lease_start_date + lease_term_months
   c. Insert into finalized_rentals (with commission_rate)
   d. Update property: Status = 'Rented', is_locked = 1
   e. Insert property_log entry (action = 'RENTED')
   f. Insert price_history entry (event_type = 'Rented', price = monthly_rent)
   g. Create agent notification (notif_type = 'rental_approved')
   h. Create admin notification log
   i. COMMIT
7. Return JSON success
```

**Commission rate input**: Admin enters the rate (e.g., `5.00` for 5%) during approval. This rate will apply to every confirmed rent payment for this lease.

### 4.6 Rejection Process (admin_reject_rental.php)

```
1. Verify admin session
2. Get verification_id and admin_notes (rejection reason)
3. BEGIN TRANSACTION
   a. Update rental_verifications: status = 'Rejected', admin_notes, reviewed_by, reviewed_at
   b. Update property: Status = 'For Rent' (back to active listing)
   c. Insert property_log entry
   d. Notify agent (notif_type = 'rental_rejected')
   e. COMMIT
```

### 4.7 Admin sets commission rate — UI detail

```html
<!-- Inside approval modal/form -->
<div class="mb-3">
  <label class="form-label fw-semibold">Commission Rate (%) <span class="text-danger">*</span></label>
  <input type="number" class="form-control" name="commission_rate" min="0.01" max="100" step="0.01" required
         placeholder="e.g., 5.00">
  <small class="text-muted">This percentage will be applied to each confirmed monthly rent payment.</small>
</div>
```

### 4.8 Validation Rules

| Field | Rule |
|---|---|
| `verification_id` | Required, integer, exists, status must be 'Pending' |
| `commission_rate` | Required for approval, numeric, 0.01–100.00 |
| `admin_notes` | Required for rejection (reason), optional for approval, max 2000 chars |

### Phase 4 Validation Checklist

- [ ] Admin can view list of rental verifications
- [ ] Admin can view detailed rental verification with documents
- [ ] Admin can download uploaded documents
- [ ] Admin can approve with commission rate → property becomes Rented
- [ ] Admin can reject with reason → property returns to For Rent
- [ ] Agent receives notification on approval/rejection
- [ ] Property is locked after approval
- [ ] Cannot approve already-approved/rejected verifications

---

## Phase 5 — Rent Payment Recording (Agent)

### Priority: HIGH
### Estimated effort: 1-2 sessions
### Dependencies: Phase 4 complete

### 5.1 Files to Create

| File | Purpose |
|---|---|
| `agent_pages/rental_payments.php` | Page: View active lease + payment history + record new payment |
| `agent_pages/record_rental_payment.php` | Backend: Process payment recording |
| `agent_pages/rental_payment_details.php` | Page: View individual payment details |

### 5.2 Files to Modify

| File | Change |
|---|---|
| `agent_pages/agent_property.php` | Add "Manage Lease" button for Rented properties |
| `agent_pages/agent_navbar.php` | Add link to rental payments / active leases |
| `agent_pages/agent_dashboard.php` | Show active lease count + pending payments |

### 5.3 Rental Payments Page (agent_pages/rental_payments.php)

**URL**: `rental_payments.php?rental_id=X`

**Layout sections:**

1. **Lease Summary Card**
   - Property address + thumbnail
   - Tenant name, email, phone
   - Monthly rent, deposit
   - Lease period: start → end
   - Lease status badge (Active/Renewed/Terminated)
   - Commission rate
   - Next payment due (calculated from last confirmed payment)

2. **Record New Payment Button** → Opens modal

3. **Payment History Table**
   - Payment # (chronological)
   - Payment Date
   - Period Covered (e.g., Apr 1, 2026 – Apr 30, 2026)
   - Amount
   - Status (Pending / Confirmed / Rejected)
   - Commission Earned (if confirmed)
   - Actions (View Details)

### 5.4 Record Payment Modal

```html
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="recordPaymentForm" enctype="multipart/form-data">
        <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
        <div class="modal-header">
          <h5 class="modal-title">Record Rent Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Payment Amount (₱) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="payment_amount" min="1" step="0.01" required
                   value="<?= $lease['monthly_rent'] ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="payment_date" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Period Start <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="period_start" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Period End <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="period_end" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Proof of Payment <span class="text-danger">*</span></label>
            <input type="file" class="form-control" name="payment_documents[]" multiple required
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <small class="text-muted">Upload receipt, bank transfer screenshot, or signed acknowledgment</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="additional_notes" rows="2" maxlength="2000"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit Payment Record</button>
        </div>
      </form>
    </div>
  </div>
</div>
```

### 5.5 Backend: Record Payment (agent_pages/record_rental_payment.php)

**Logic flow:**

```
1. Verify session: agent
2. Validate POST data:
   - rental_id: exists, belongs to this agent, lease_status = 'Active' or 'Renewed'
   - payment_amount: numeric, > 0
   - payment_date: valid date, not future
   - period_start, period_end: valid dates, period_start < period_end
   - At least 1 file uploaded
3. Check for duplicate: no existing payment for the same period (overlap check)
4. BEGIN TRANSACTION
   a. Insert into rental_payments (status = 'Pending')
   b. Upload files → insert into rental_payment_documents
   c. Create admin notification (type = 'rental_payment', message = "Agent recorded rent payment for Property #X")
   d. COMMIT
5. Return JSON success
```

**Upload directory**: `rental_payment_documents/{rental_id}/`

### 5.6 Period Overlap Check (SQL)

```sql
SELECT payment_id FROM rental_payments 
WHERE rental_id = ? 
  AND status IN ('Pending', 'Confirmed')
  AND period_start < ? -- new period_end
  AND period_end > ?   -- new period_start
```

If any row returned → reject with "A payment record already exists for this period."

### 5.7 Auto-suggest Next Period

When opening the "Record Payment" modal, auto-populate period fields:

```php
// Get last confirmed/pending payment for this lease
$last_payment = $conn->prepare("
    SELECT period_end FROM rental_payments 
    WHERE rental_id = ? AND status IN ('Pending', 'Confirmed')
    ORDER BY period_end DESC LIMIT 1
");
// If found: next period_start = last period_end + 1 day
// If not found: period_start = lease_start_date
// period_end = period_start + 1 month - 1 day
```

### 5.8 Validation Rules

| Field | Rule |
|---|---|
| `rental_id` | Required, integer, exists, belongs to agent, lease active |
| `payment_amount` | Required, numeric, > 0, max 99999999.99 |
| `payment_date` | Required, valid date, not in future |
| `period_start` | Required, valid date, >= lease_start_date |
| `period_end` | Required, valid date, > period_start, <= lease_end_date (warn if beyond) |
| `payment_documents[]` | At least 1 file, same type/size rules as rental verification |
| `additional_notes` | Optional, max 2000 chars |

### Phase 5 Validation Checklist

- [ ] Agent can view active lease details
- [ ] Agent can record a rent payment with proof
- [ ] Payment periods auto-suggest correctly
- [ ] Period overlap is detected and blocked
- [ ] Admin receives notification for each submitted payment
- [ ] Cannot record payments for terminated/expired leases
- [ ] Payment history displays correctly

---

## Phase 6 — Rent Payment Confirmation & Commission (Admin)

### Priority: HIGH
### Estimated effort: 1-2 sessions
### Dependencies: Phase 5 complete

### 6.1 Files to Create

| File | Purpose |
|---|---|
| `admin_rental_payments.php` | Page: List all pending rent payments for admin review |
| `admin_confirm_rental_payment.php` | Backend: Confirm payment → calculate & create commission |
| `admin_reject_rental_payment.php` | Backend: Reject payment record |
| `admin_rental_payment_details.php` | Page: View individual payment details + documents |

### 6.2 Files to Modify

| File | Change |
|---|---|
| `admin_sidebar.php` | Add "Rental Payments" menu item |
| `admin_dashboard.php` | Add pending payment count |
| `reports.php` | Add rental revenue and commission data |

### 6.3 Admin Rental Payments Page (admin_rental_payments.php)

**Table columns:**
- Payment ID
- Property Address
- Agent Name
- Tenant Name
- Payment Amount
- Period Covered
- Payment Date
- Status (badge)
- Actions (View / Confirm / Reject)

**Filters**: Status (All / Pending / Confirmed / Rejected), Agent, Property

### 6.4 Confirm Payment Process (admin_confirm_rental_payment.php)

**Logic flow:**

```
1. Verify admin session
2. Validate payment_id, fetch payment record
3. Verify payment status = 'Pending'
4. Fetch associated finalized_rental (for commission_rate)
5. BEGIN TRANSACTION
   a. Update rental_payments: status = 'Confirmed', confirmed_by, confirmed_at
   b. Calculate commission:
      commission_amount = payment_amount × (commission_rate / 100)
   c. Insert into rental_commissions:
      - rental_id, payment_id, agent_id
      - commission_amount, commission_percentage = commission_rate
      - status = 'calculated', calculated_at = NOW()
   d. Notify agent: "Rent payment confirmed for Property #X. Commission: ₱X,XXX.XX"
      (notif_type = 'rental_payment_confirmed')
   e. COMMIT
6. Return JSON success with commission amount
```

### 6.5 Reject Payment Process (admin_reject_rental_payment.php)

```
1. Verify admin session
2. Validate payment_id + admin_notes (rejection reason required)
3. BEGIN TRANSACTION
   a. Update rental_payments: status = 'Rejected', admin_notes, confirmed_by, confirmed_at
   b. Notify agent: "Rent payment rejected for Property #X. Reason: ..."
      (notif_type = 'rental_payment_rejected')
   c. COMMIT
```

**Important**: Rejected payments do NOT change property status. The agent can submit a corrected payment record for the same period after rejection.

### 6.6 Commission Calculation Formula

```
Commission = Payment Amount × (Commission Rate / 100)

Example:
- Monthly Rent: ₱15,000
- Commission Rate: 5%
- Agent's Commission per month: ₱15,000 × 0.05 = ₱750
```

### 6.7 Admin Commission Overview

On the admin dashboard or reports page, show:
- Total rental commissions (calculated)
- Total rental commissions (paid)
- Breakdown per agent

### Phase 6 Validation Checklist

- [ ] Admin can view all pending rent payments
- [ ] Admin can confirm payment → commission is auto-calculated
- [ ] Admin can reject payment with reason
- [ ] Agent receives notification for confirm/reject
- [ ] Commission amount is correctly calculated
- [ ] Rejected payment allows re-submission for same period
- [ ] Cannot confirm already-confirmed payments
- [ ] Commission record links correctly to payment and rental

---

## Phase 7 — Lease Expiry Notifications

### Priority: MEDIUM
### Estimated effort: 0.5-1 session
### Dependencies: Phase 4 complete

### 7.1 Files to Create

| File | Purpose |
|---|---|
| `cron_lease_expiry_check.php` | Script: Check for expiring leases and send notifications (can also be triggered on page load) |

### 7.2 Files to Modify

| File | Change |
|---|---|
| `agent_pages/agent_dashboard.php` | Show "Leases Expiring Soon" alert |
| `admin_dashboard.php` | Show "Leases Expiring Soon" alert |

### 7.3 Expiry Check Logic

Since this is a XAMPP-based system (no native cron), implement the check on **dashboard page load**:

```php
// Check for leases expiring within 30 days that haven't been notified yet
function checkExpiringLeases($conn) {
    $today = date('Y-m-d');
    $threshold = date('Y-m-d', strtotime('+30 days'));
    
    $stmt = $conn->prepare("
        SELECT fr.*, p.StreetAddress, p.City
        FROM finalized_rentals fr
        JOIN property p ON fr.property_id = p.property_ID
        WHERE fr.lease_status = 'Active'
          AND fr.lease_end_date BETWEEN ? AND ?
          AND fr.rental_id NOT IN (
            SELECT reference_id FROM agent_notifications 
            WHERE notif_type = 'lease_expiring' 
            AND reference_id IS NOT NULL
          )
    ");
    $stmt->bind_param("ss", $today, $threshold);
    $stmt->execute();
    $expiring = $stmt->get_result();
    
    while ($lease = $expiring->fetch_assoc()) {
        // Create agent notification
        $days_left = (strtotime($lease['lease_end_date']) - strtotime($today)) / 86400;
        $message = "Lease for {$lease['StreetAddress']}, {$lease['City']} expires in {$days_left} days (on {$lease['lease_end_date']}). Please update the tenant's status.";
        
        // Insert agent_notification (notif_type = 'lease_expiring')
        // Insert admin notification
    }
}
```

### 7.4 Auto-Expire Leases

If lease end date has passed and agent hasn't taken action:

```php
// Auto-expire leases past their end date
$stmt = $conn->prepare("
    UPDATE finalized_rentals 
    SET lease_status = 'Expired' 
    WHERE lease_status = 'Active' 
      AND lease_end_date < CURDATE()
");
// Note: Property status remains 'Rented' until agent explicitly marks move-out
// This just flags the lease as expired for visibility
```

**Important**: Auto-expiry does NOT change property status. The agent must explicitly handle the tenant status (continue staying → renew, or move out → terminate). The "Expired" status is just a flag indicating the lease term has passed.

### 7.5 Dashboard Alerts

**Agent dashboard:**
```html
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle"></i>
  <strong>Lease Expiring Soon:</strong> The lease for [Property Address] expires on [Date]. 
  <a href="rental_payments.php?rental_id=X">Manage Lease</a>
</div>
```

**Admin dashboard:**
```html
<div class="alert alert-info">
  <i class="bi bi-clock-history"></i>
  <strong>X leases</strong> expiring within the next 30 days.
  <a href="admin_rental_approvals.php?filter=expiring">View Details</a>
</div>
```

### Phase 7 Validation Checklist

- [ ] Expiring leases (within 30 days) trigger notifications
- [ ] Notifications are sent only once per lease (no duplicates)
- [ ] Agent dashboard shows expiring lease alert
- [ ] Admin dashboard shows expiring lease count
- [ ] Past-due leases are flagged as "Expired"
- [ ] Expired status does not auto-change property status

---

## Phase 8 — Lease Renewal

### Priority: MEDIUM
### Estimated effort: 1 session
### Dependencies: Phase 7 complete

### 8.1 Files to Create

| File | Purpose |
|---|---|
| `agent_pages/renew_lease.php` | Backend: Process lease renewal |

### 8.2 How Renewal Works

1. Agent navigates to the lease management page (`rental_payments.php?rental_id=X`)
2. When lease is Active or Expired, a "Renew Lease" button appears
3. Agent clicks it → modal with:
   - New lease term (months) — pre-filled with original term
   - New monthly rent (optional update) — pre-filled with current rent
   - New lease start date — auto-set to day after current lease_end_date
4. Agent confirms → lease is extended

### 8.3 Renewal Modal

```html
<div class="modal fade" id="renewLeaseModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="renewLeaseForm">
        <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
        <div class="modal-header">
          <h5 class="modal-title">Renew Lease</h5>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">
            Current lease ends: <strong><?= date('M d, Y', strtotime($lease['lease_end_date'])) ?></strong>
          </div>
          <div class="mb-3">
            <label class="form-label">New Lease Term (Months) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="new_term_months" 
                   value="<?= $lease['lease_term_months'] ?>" min="1" max="120" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Monthly Rent (₱) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="new_monthly_rent" 
                   value="<?= $lease['monthly_rent'] ?>" min="1" step="0.01" required>
            <small class="text-muted">Update if rent amount has changed</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Confirm Renewal</button>
        </div>
      </form>
    </div>
  </div>
</div>
```

### 8.4 Backend Logic (agent_pages/renew_lease.php)

```
1. Verify agent session
2. Validate:
   - rental_id exists, belongs to agent
   - lease_status is 'Active' or 'Expired'
   - new_term_months: 1-120
   - new_monthly_rent: > 0
3. Calculate:
   - new_lease_start = current lease_end_date + 1 day
   - new_lease_end = new_lease_start + new_term_months
4. BEGIN TRANSACTION
   a. Update finalized_rentals:
      - lease_start_date = new_lease_start
      - lease_end_date = new_lease_end
      - lease_term_months = new_term_months
      - monthly_rent = new_monthly_rent
      - lease_status = 'Renewed'
   b. Update rental_details (if exists):
      - monthly_rent = new_monthly_rent
      - lease_term_months = new_term_months
   c. Update property ListingPrice = new_monthly_rent (for search compatibility)
   d. Insert property_log (action = 'LEASE_RENEWED')
   e. Notify admin: "Lease renewed for Property #X. New term: X months, Rent: ₱XX,XXX"
   f. COMMIT
```

**Note**: After renewal, `lease_status` changes to 'Renewed' (which is still considered active for all payment/commission purposes). The system treats 'Active' and 'Renewed' the same way for operations.

### 8.5 Validation Rules

| Field | Rule |
|---|---|
| `rental_id` | Required, integer, belongs to agent, status Active or Expired |
| `new_term_months` | Required, integer, 1-120 |
| `new_monthly_rent` | Required, numeric, > 0 |

### Phase 8 Validation Checklist

- [ ] Agent can renew an active or expired lease
- [ ] Lease dates are recalculated correctly
- [ ] Monthly rent can be updated
- [ ] Lease status changes to 'Renewed'
- [ ] Property log records the renewal
- [ ] Admin is notified
- [ ] Subsequent payments use the new monthly rent
- [ ] Cannot renew a terminated lease

---

## Phase 9 — Move Out & Lease Termination

### Priority: MEDIUM
### Estimated effort: 1 session
### Dependencies: Phase 5 complete

### 9.1 Files to Create

| File | Purpose |
|---|---|
| `agent_pages/terminate_lease.php` | Backend: Process lease termination / move-out |

### 9.2 How Move-Out Works

1. Agent navigates to the lease management page
2. Clicks "Tenant Move Out" button
3. Confirmation modal appears
4. Agent confirms → lease is terminated

### 9.3 Termination Modal

```html
<div class="modal fade" id="terminateLeaseModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="terminateLeaseForm">
        <input type="hidden" name="rental_id" value="<?= $rental_id ?>">
        <div class="modal-header">
          <h5 class="modal-title text-danger">Terminate Lease / Tenant Move-Out</h5>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            This action will:
            <ul class="mb-0 mt-2">
              <li>Mark the lease as <strong>Terminated</strong></li>
              <li>Return the property to <strong>For Rent</strong> status</li>
              <li>Stop future commission accrual for this lease</li>
            </ul>
          </div>
          <p>Pending rent payments (if any) will remain as-is for admin review.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Confirm Termination</button>
        </div>
      </form>
    </div>
  </div>
</div>
```

### 9.4 Backend Logic (agent_pages/terminate_lease.php)

```
1. Verify agent session
2. Validate:
   - rental_id exists, belongs to agent
   - lease_status is 'Active' or 'Renewed' or 'Expired'
   - (Cannot terminate an already-terminated lease)
3. BEGIN TRANSACTION
   a. Update finalized_rentals:
      - lease_status = 'Terminated'
      - terminated_at = NOW()
      - terminated_by = agent account_id
   b. Update property:
      - Status = 'For Rent'
      - is_locked = 0
   c. Insert property_log (action = 'LEASE_TERMINATED')
   d. Insert price_history (event_type = 'Lease Ended')
   e. Notify admin: "Lease terminated for Property #X by agent [Agent Name]"
   f. COMMIT
4. Return JSON success
```

### 9.5 What Happens to Pending Payments

- **Pending payments** remain in "Pending" status. Admin can still confirm or reject them.
- **No new payments** can be recorded after termination.
- **Commission** for already-confirmed payments is preserved.

### 9.6 Edge Case: Re-renting After Termination

After termination, the property returns to "For Rent" — it can be rented again through the full verification flow (Phase 3). The previous finalized_rental record remains in the database for historical/audit purposes with `lease_status = 'Terminated'`.

### Phase 9 Validation Checklist

- [ ] Agent can terminate an active/renewed/expired lease
- [ ] Property returns to "For Rent" status and is unlocked
- [ ] Lease status changes to "Terminated"
- [ ] Pending payments remain for admin review
- [ ] No new payments can be recorded after termination
- [ ] Property can be re-rented (new verification cycle)
- [ ] Cannot terminate an already-terminated lease
- [ ] Admin is notified
- [ ] Historical data preserved

---

## Phase 10 — Navigation, Dashboard & Reports Updates

### Priority: MEDIUM
### Estimated effort: 1-2 sessions
### Dependencies: Phases 1-9 complete

### 10.1 Admin Sidebar (admin_sidebar.php)

Add new menu items:

```html
<!-- Under existing Property Sales section or as new section -->
<li class="nav-item">
  <a class="nav-link" href="admin_rental_approvals.php">
    <i class="bi bi-house-door"></i> Rental Approvals
    <?php if ($pending_rentals > 0): ?>
      <span class="badge bg-warning"><?= $pending_rentals ?></span>
    <?php endif; ?>
  </a>
</li>
<li class="nav-item">
  <a class="nav-link" href="admin_rental_payments.php">
    <i class="bi bi-cash-stack"></i> Rental Payments
    <?php if ($pending_payments > 0): ?>
      <span class="badge bg-info"><?= $pending_payments ?></span>
    <?php endif; ?>
  </a>
</li>
```

### 10.2 Agent Sidebar/Navbar

Add:
- "My Active Leases" link (list of finalized_rentals where agent_id = current agent and lease_status IN ('Active', 'Renewed'))
- Badge count for pending payment confirmations

### 10.3 Admin Dashboard Cards

Add new statistics cards:

| Card | Query |
|---|---|
| Pending Rental Approvals | `COUNT(*) FROM rental_verifications WHERE status = 'Pending'` |
| Active Leases | `COUNT(*) FROM finalized_rentals WHERE lease_status IN ('Active', 'Renewed')` |
| Pending Rent Payments | `COUNT(*) FROM rental_payments WHERE status = 'Pending'` |
| Rental Revenue (This Month) | `SUM(payment_amount) FROM rental_payments WHERE status = 'Confirmed' AND MONTH(confirmed_at) = MONTH(NOW())` |

### 10.4 Agent Dashboard Cards

| Card | Query |
|---|---|
| My Active Leases | `COUNT(*) FROM finalized_rentals WHERE agent_id = ? AND lease_status IN ('Active', 'Renewed')` |
| Pending Payments | `COUNT(*) FROM rental_payments WHERE agent_id = ? AND status = 'Pending'` |
| Total Rental Commission | `SUM(commission_amount) FROM rental_commissions WHERE agent_id = ? AND status = 'calculated'` |
| Expiring Leases | Leases ending within 30 days |

### 10.5 Agent Commissions Page (agent_pages/agent_commissions.php)

Modify to include rental commissions tab or section:

```php
// Sale commissions (existing)
$sale_commissions = $conn->prepare("
    SELECT ac.*, fs.final_sale_price, p.StreetAddress 
    FROM agent_commissions ac
    JOIN finalized_sales fs ON ac.sale_id = fs.sale_id
    JOIN property p ON fs.property_id = p.property_ID
    WHERE ac.agent_id = ?
");

// Rental commissions (new)
$rental_commissions = $conn->prepare("
    SELECT rc.*, rp.payment_amount, rp.period_start, rp.period_end, 
           fr.tenant_name, p.StreetAddress
    FROM rental_commissions rc
    JOIN rental_payments rp ON rc.payment_id = rp.payment_id
    JOIN finalized_rentals fr ON rc.rental_id = fr.rental_id
    JOIN property p ON fr.property_id = p.property_ID
    WHERE rc.agent_id = ?
    ORDER BY rc.created_at DESC
");
```

### 10.6 Reports Page (reports.php)

Add rental-specific report sections:

- **Rental Activity Summary**: Total properties For Rent, Active Leases, Terminated, Revenue
- **Rental Commission Report**: By agent, by month
- **Lease Expiry Report**: Upcoming expirations
- **Payment Status Report**: Pending vs Confirmed vs Rejected payments

### 10.7 Property Search/Filter Updates

Modify property listing pages to allow filtering by listing type:

```html
<select name="listing_type" class="form-select">
  <option value="">All Types</option>
  <option value="For Sale">For Sale</option>
  <option value="For Rent">For Rent</option>
</select>
```

### 10.8 Notification System Updates

Ensure the notification system handles all new types:

**Admin notifications (`notifications` table):**
| item_type | Scenario |
|---|---|
| `property_rental` | Rental verification submitted, approved, rejected |
| `rental_payment` | Rent payment submitted, confirmed, rejected |

**Agent notifications (`agent_notifications` table):**
| notif_type | Scenario |
|---|---|
| `rental_approved` | Admin approved rental verification |
| `rental_rejected` | Admin rejected rental verification |
| `rental_payment_confirmed` | Admin confirmed rent payment |
| `rental_payment_rejected` | Admin rejected rent payment |
| `rental_commission_paid` | Rental commission marked as paid |
| `lease_expiring` | Lease expiring within 30 days |
| `lease_expired` | Lease has expired |

### Phase 10 Validation Checklist

- [ ] Admin sidebar shows Rental Approvals + Rental Payments links with badges
- [ ] Agent navbar/sidebar shows Active Leases link
- [ ] Admin dashboard shows rental statistics
- [ ] Agent dashboard shows rental statistics
- [ ] Agent commissions page shows both sale and rental commissions
- [ ] Reports include rental data
- [ ] Property filters include listing type
- [ ] All notification types work correctly
- [ ] Navigation links are correct (no broken redirects)

---

## Security Checklist

Apply these across ALL phases:

### Authentication & Authorization

- [ ] Every PHP file starts with `session_start()` and session validation
- [ ] Every admin page checks `$_SESSION['user_role'] === 'admin'`
- [ ] Every agent page checks `$_SESSION['user_role'] === 'agent'`
- [ ] Include `session_timeout.php` on every protected page
- [ ] Agents can only access their own properties/leases (verify ownership in every query)

### Input Validation

- [ ] All user inputs validated server-side (never rely on client-side only)
- [ ] Use prepared statements (`bind_param`) for ALL database queries — no string concatenation
- [ ] Validate data types: `intval()` for IDs, `floatval()` for amounts
- [ ] Validate ENUM values against allowed lists (whitelist approach)
- [ ] Sanitize text inputs: `trim()`, length limits, `htmlspecialchars()` on output
- [ ] Email validation with `filter_var($email, FILTER_VALIDATE_EMAIL)`
- [ ] Date validation with `DateTime::createFromFormat()`

### File Upload Security

- [ ] Validate MIME type server-side using `finfo_file()` — NOT the user-supplied `$_FILES['type']`
- [ ] Whitelist allowed extensions: pdf, jpg, jpeg, png, doc, docx
- [ ] Enforce max file size (120MB) server-side
- [ ] Generate random stored filenames (never use original filename for storage)
- [ ] Use `basename()` to prevent path traversal
- [ ] Store uploads OUTSIDE the web root if possible, or use `.htaccess` to prevent direct execution
- [ ] Create upload directories with proper permissions (0755)

### Database Security

- [ ] All queries use prepared statements
- [ ] Use transactions for multi-step operations
- [ ] Proper error handling in transactions (rollback on failure)
- [ ] Foreign key constraints enforced at database level
- [ ] No sensitive data in error messages returned to client

### Output Security

- [ ] All dynamic output escaped with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`
- [ ] JSON responses use `json_encode()` — never manual string building
- [ ] File download endpoint validates file path and user authorization
- [ ] Content-Type headers set correctly for downloads

### Business Logic Security

- [ ] Property status transitions validated (e.g., can't go from 'Sold' to 'For Rent')
- [ ] Prevent double-submission (check existing pending records before insert)
- [ ] Amount calculations done server-side (never trust client-calculated values)
- [ ] Commission rate validated within reasonable bounds (0.01% - 100%)
- [ ] Lease date calculations done server-side

---

## Edge Cases & Business Logic Safeguards

### Property Status Transitions

Valid transitions for rental properties:

```
For Rent → Pending Rented (rental verification submitted)
Pending Rented → Rented (admin approved)
Pending Rented → For Rent (admin rejected)
Rented → For Rent (lease terminated/move-out)
```

**Invalid transitions to block:**
- `For Sale` → `Pending Rented` (wrong listing type)
- `Sold` → anything rental-related
- `Rented` → `Pending Rented` (already rented, can't submit new verification)
- `Rented` → `For Sale` (must terminate lease first)

### Edge Case Handling

| Edge Case | How to Handle |
|---|---|
| Agent submits rental verification while another is pending | Block: check for existing Pending verification |
| Admin approves rental but property was already rented (race condition) | Transaction isolation + re-check status inside transaction |
| Agent records payment for terminated lease | Block: check lease_status before allowing |
| Agent records payment with period beyond lease_end_date | Allow with warning (covers month-to-month scenarios) |
| Payment amount differs from monthly_rent | Allow (covers partial payments, late fees, etc.) |
| Agent tries to renew a terminated lease | Block: only Active or Expired can be renewed |
| Property deleted while lease is active | ON DELETE CASCADE handles DB records; handle gracefully in UI |
| Two agents try to mark same property as rented | First submission sets status to Pending Rented; second is blocked |
| Lease renewed with different rent amount | Subsequent payments should reference the NEW amount; previous confirmed payments are unchanged |
| Admin rejects payment, agent re-submits for same period | Allow: the overlap check excludes rejected payments |

### Commission Edge Cases

| Scenario | Behavior |
|---|---|
| Payment confirmed, then lease terminated | Commission remains valid (already earned) |
| Zero commission rate | Allowed (admin may choose not to give commission) |
| Payment amount = 0 | Blocked by validation (must be > 0) |
| Multiple payments for same period | Blocked by overlap check |
| Admin changes commission rate after lease finalized | Rate is stored per lease; existing commissions unaffected. To change, would need a manual DB update (intentionally not supported in UI to prevent abuse) |

---

## Complete File Inventory

### New Files to Create

| # | File Path | Phase | Purpose |
|---|---|---|---|
| 1 | `agent_pages/mark_as_rented_process.php` | 3 | Agent submits rental verification |
| 2 | `admin_rental_approvals.php` | 4 | Admin views rental verifications list |
| 3 | `admin_rental_details.php` | 4 | Admin views rental verification details |
| 4 | `admin_finalize_rental.php` | 4 | Admin approves rental → finalize |
| 5 | `admin_reject_rental.php` | 4 | Admin rejects rental verification |
| 6 | `agent_pages/rental_payments.php` | 5 | Agent manages lease + payment history |
| 7 | `agent_pages/record_rental_payment.php` | 5 | Agent records rent payment |
| 8 | `agent_pages/rental_payment_details.php` | 5 | Agent views payment details |
| 9 | `admin_rental_payments.php` | 6 | Admin views rent payments list |
| 10 | `admin_rental_payment_details.php` | 6 | Admin views payment details + docs |
| 11 | `admin_confirm_rental_payment.php` | 6 | Admin confirms rent payment |
| 12 | `admin_reject_rental_payment.php` | 6 | Admin rejects rent payment |
| 13 | `cron_lease_expiry_check.php` | 7 | Lease expiry check (triggered on page load) |
| 14 | `agent_pages/renew_lease.php` | 8 | Agent renews lease |
| 15 | `agent_pages/terminate_lease.php` | 9 | Agent terminates lease / move-out |
| 16 | `rental_documents/` (directory) | 3 | Upload directory for rental verification documents |
| 17 | `rental_payment_documents/` (directory) | 5 | Upload directory for payment proof documents |

### Existing Files to Modify

| # | File Path | Phase(s) | Changes |
|---|---|---|---|
| 1 | `add_property.php` | 2 | Listing type selector + rental fields |
| 2 | `save_property.php` | 2 | Save listing_type + rental_details |
| 3 | `update_property.php` | 2 | Edit rental details |
| 4 | `view_property.php` | 2 | Display rental info |
| 5 | `property.php` | 2, 10 | Listing type filter + rental cards |
| 6 | `admin_property_card_template.php` | 2 | For Rent badge + rent price display |
| 7 | `agent_pages/property_card_template.php` | 2, 3 | For Rent badge + Mark as Rented button |
| 8 | `agent_pages/agent_property.php` | 2, 3, 5 | Rental property handling + Mark as Rented + Manage Lease |
| 9 | `agent_pages/add_property_process.php` | 2 | Handle rental details on creation |
| 10 | `get_property_data.php` | 2 | Return rental details in API |
| 11 | `admin_sidebar.php` | 4, 6, 10 | Add Rental Approvals + Payments links |
| 12 | `admin_dashboard.php` | 4, 6, 7, 10 | Rental stats + expiry alerts |
| 13 | `admin_navbar.php` | 4, 10 | Rental notification badges |
| 14 | `admin_notifications.php` | 4, 6, 10 | Handle rental notification types |
| 15 | `agent_pages/agent_dashboard.php` | 5, 7, 10 | Active leases + pending payments + expiry |
| 16 | `agent_pages/agent_navbar.php` | 5, 10 | Lease/payment links |
| 17 | `agent_pages/agent_commissions.php` | 10 | Add rental commissions tab |
| 18 | `agent_pages/agent_notification_helper.php` | 3, 10 | Rental notification types |
| 19 | `agent_pages/agent_notifications.php` | 10 | Display rental notifications |
| 20 | `reports.php` | 10 | Rental revenue + commission reports |

### Database Changes Summary

| # | Change | Phase |
|---|---|---|
| 1 | `ALTER property` — add `listing_type` column | 1 |
| 2 | `ALTER property_log` — add ENUM values to `action` | 1 |
| 3 | `ALTER notifications` — add ENUM values to `item_type` | 1 |
| 4 | `ALTER agent_notifications` — add ENUM values to `notif_type` | 1 |
| 5 | `ALTER price_history` — add ENUM values to `event_type` | 1 |
| 6 | `CREATE rental_verifications` | 1 |
| 7 | `CREATE rental_verification_documents` | 1 |
| 8 | `CREATE finalized_rentals` | 1 |
| 9 | `CREATE rental_payments` | 1 |
| 10 | `CREATE rental_payment_documents` | 1 |
| 11 | `CREATE rental_commissions` | 1 |

---

## Implementation Order (Recommended)

```
Phase 1  ─── Database Schema ──────────────────────── [FOUNDATION]
   │
Phase 2  ─── Property Listing (For Rent support) ──── [CORE]
   │
Phase 3  ─── Rental Verification (Agent submits) ──── [CORE]
   │
Phase 4  ─── Admin Review & Finalization ──────────── [CORE]
   │
Phase 5  ─── Payment Recording (Agent) ────────────── [CORE]
   │
Phase 6  ─── Payment Confirmation & Commission ────── [CORE]
   │
Phase 7  ─── Lease Expiry Notifications ───────────── [ENHANCEMENT]
   │
Phase 8  ─── Lease Renewal ────────────────────────── [ENHANCEMENT]
   │
Phase 9  ─── Move Out & Termination ───────────────── [ENHANCEMENT]
   │
Phase 10 ─── Navigation, Dashboard & Reports ──────── [POLISH]
```

**Minimum Viable Feature**: Phases 1–6 give you a complete rental workflow (list → verify → approve → pay → commission).

**Full Feature**: Phases 7–10 add lifecycle management and polish.

---

## Quick Reference: Status Flow Diagram

```
SALE PROPERTY:
  For Sale → Pending Sold → Sold ✓
                          → For Sale (rejected)

RENTAL PROPERTY:
  For Rent → Pending Rented → Rented (Active Lease)
                             → For Rent (rejected)
  
  Rented → [Lease Expires] → Agent: Renew OR Move Out
  Rented → [Agent: Renew]  → Rented (Renewed)
  Rented → [Agent: Move Out] → For Rent (available again)
  
  Payment Cycle (while Rented):
  Agent Records Payment → Pending → Admin Confirms → Commission Calculated
                                  → Admin Rejects → Agent can re-submit
```

---

*End of Blueprint — Version 1.0*
