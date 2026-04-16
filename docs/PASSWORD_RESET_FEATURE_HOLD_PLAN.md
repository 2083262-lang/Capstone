# Password Reset Feature (On Hold)

## Status
This feature is intentionally on hold pending client approval.

## Summary of Proposed Feature
A secure forgot-password flow was prepared using email-based reset links and one-time tokens.

Planned flow:
1. User clicks Forgot Password from login page.
2. User submits email address.
3. System generates secure selector + token, stores hashed token in database, and sends reset link to email.
4. User opens reset link and submits a new password.
5. System verifies token, enforces expiration and one-time use, updates password hash, and invalidates remaining reset tokens.

## Security Design (Planned)
- Token-based reset (no plain-text password handling)
- Password hashing using `password_hash`
- CSRF protection on request/reset forms
- Generic response to avoid account enumeration
- Request throttling/rate-limit per IP and account
- Expiration handling (30-minute window)
- One-time token consumption
- Cleanup of stale/expired records

## Planned Files (Now Reverted)
- `forgot_password.php`
- `reset_password.php`
- `add_password_reset_table.php` (one-time migration script)
- `login.php` (forgot-password link + success notice integration)

## Database Change (Planned)
Create table:
- `password_resets`

Core columns:
- `reset_id`
- `account_id` (nullable FK to `accounts.account_id`)
- `email`
- `selector`
- `token_hash`
- `expires_at`
- `consumed_at`
- `attempts`
- `request_ip`
- `user_agent`
- `created_at`

## SQL (Create Table Later)
```sql
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT(11) NOT NULL AUTO_INCREMENT,
    account_id INT(11) DEFAULT NULL,
    email VARCHAR(100) NOT NULL,
    selector CHAR(16) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    attempts INT(11) NOT NULL DEFAULT 0,
    request_ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reset_id),
    UNIQUE KEY uq_password_reset_selector (selector),
    KEY idx_pr_account_created (account_id, created_at),
    KEY idx_pr_email_created (email, created_at),
    KEY idx_pr_ip_created (request_ip, created_at),
    KEY idx_pr_expires (expires_at),
    CONSTRAINT fk_password_resets_account
        FOREIGN KEY (account_id) REFERENCES accounts (account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

## SQL Rollback (If Table Was Already Created)
```sql
DROP TABLE IF EXISTS password_resets;
```

## Reactivation Checklist (When Client Approves)
1. Re-introduce forgot/reset pages.
2. Add migration for `password_resets`.
3. Reconnect login Forgot Password link.
4. Test email delivery and reset link expiry.
5. Validate security checks (CSRF, throttling, token replay).
6. Document deployment and rollback.
