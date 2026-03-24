# HomeEstate Realty – Data Dictionary

> **Database Name:** `realestatesystem`  
> **Database Engine:** InnoDB (MariaDB 10.4.32)  
> **Character Set:** utf8mb4 / utf8mb4_general_ci  
> **Document Date:** March 11, 2026  
> **Total Tables:** 27

---

## Table of Contents

1. [accounts](#table-accounts)
2. [admin_information](#table-admin_information)
3. [admin_logs](#table-admin_logs)
4. [agent_commissions](#table-agent_commissions)
5. [agent_information](#table-agent_information)
6. [agent_notifications](#table-agent_notifications)
7. [agent_specializations](#table-agent_specializations)
8. [amenities](#table-amenities)
9. [commission_payment_logs](#table-commission_payment_logs)
10. [finalized_rentals](#table-finalized_rentals)
11. [finalized_sales](#table-finalized_sales)
12. [notifications](#table-notifications)
13. [price_history](#table-price_history)
14. [property](#table-property)
15. [property_amenities](#table-property_amenities)
16. [property_floor_images](#table-property_floor_images)
17. [property_images](#table-property_images)
18. [property_log](#table-property_log)
19. [property_types](#table-property_types)
20. [rental_commissions](#table-rental_commissions)
21. [rental_details](#table-rental_details)
22. [rental_payments](#table-rental_payments)
23. [rental_payment_documents](#table-rental_payment_documents)
24. [rental_verifications](#table-rental_verifications)
25. [rental_verification_documents](#table-rental_verification_documents)
26. [sale_verifications](#table-sale_verifications)
27. [sale_verification_documents](#table-sale_verification_documents)
28. [specializations](#table-specializations)
29. [status_log](#table-status_log)
30. [tour_requests](#table-tour_requests)
31. [two_factor_codes](#table-two_factor_codes)
32. [user_roles](#table-user_roles)

---

## Table: accounts

Stores all user accounts in the system, including administrators and real estate agents. Each account is linked to a role that determines the user's access level and permissions.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| account_id | INT | 11 | Primary key. Unique identifier for each user account (auto-increment). |
| role_id | INT | 11 | Foreign key referencing `user_roles.role_id`. Determines the user's role (e.g., admin, agent). |
| first_name | VARCHAR | 50 | User's first name. |
| middle_name | VARCHAR | 50 | User's middle name. Nullable. |
| last_name | VARCHAR | 50 | User's last name. |
| phone_number | VARCHAR | 20 | User's contact phone number. Nullable. |
| email | VARCHAR | 100 | User's email address. Must be unique across all accounts. Used for login and notifications. |
| username | VARCHAR | 50 | User's login username. Must be unique across all accounts. |
| password_hash | VARCHAR | 255 | Bcrypt-hashed password for secure authentication. |
| date_registered | DATETIME | – | Timestamp when the account was created. Defaults to the current date and time. |
| is_active | TINYINT | 1 | Indicates whether the account is active (1) or deactivated (0). Defaults to 1. |
| two_factor_enabled | TINYINT | 1 | Indicates whether two-factor authentication is enabled (1) or disabled (0). Defaults to 1. |

**Indexes:** PRIMARY KEY (`account_id`), UNIQUE (`email`), UNIQUE (`username`), INDEX (`role_id`)  
**Foreign Keys:** `role_id` → `user_roles.role_id`

---

## Table: admin_information

Stores extended profile information for administrator accounts, including professional details and profile completion status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| admin_info_id | INT | 11 | Primary key. Unique identifier for each admin profile record (auto-increment). |
| account_id | INT | 11 | Foreign key referencing `accounts.account_id`. Links the profile to a specific admin account. Unique per account. |
| license_number | VARCHAR | 100 | Admin's real estate broker license number. Nullable. |
| specialization | VARCHAR | 255 | Comma-separated list of the admin's areas of specialization (e.g., First-Time Buyers, Rentals). Nullable. |
| years_experience | INT | 11 | Number of years of real estate experience. Nullable. |
| bio | TEXT | – | Biographical description or professional summary of the admin. Nullable. |
| profile_picture_url | VARCHAR | 255 | Relative file path to the admin's uploaded profile picture. Nullable. |
| profile_completed | TINYINT | 1 | Indicates whether the admin has completed their profile (1) or not (0). Defaults to 0. |

**Indexes:** PRIMARY KEY (`admin_info_id`), UNIQUE (`account_id`)  
**Foreign Keys:** `account_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: admin_logs

Records login and logout activity for administrator accounts, providing an audit trail for security and monitoring purposes.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| log_id | INT | 11 | Primary key. Unique identifier for each log entry (auto-increment). |
| admin_account_id | INT | 11 | Foreign key referencing `accounts.account_id`. Identifies the admin who performed the action. |
| action | ENUM | – | Type of action performed. Values: `login`, `logout`. |
| action_type | VARCHAR | 50 | Additional classification of the action. Defaults to 'login'. |
| description | TEXT | – | Optional descriptive text providing additional context about the action. Nullable. |
| log_timestamp | DATETIME | – | Timestamp of when the action occurred. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`log_id`), INDEX (`admin_account_id`)  
**Foreign Keys:** `admin_account_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: agent_commissions

Tracks commission payments owed to agents for finalized property sales. Includes commission calculation details, payment method, proof of payment, and processing status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| commission_id | INT | 11 | Primary key. Unique identifier for each commission record (auto-increment). |
| sale_id | INT | 11 | Foreign key referencing `finalized_sales.sale_id`. The sale transaction this commission is based on. Unique per sale. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who earned the commission. |
| commission_amount | DECIMAL | 12,2 | Calculated commission amount in PHP (₱). |
| commission_percentage | DECIMAL | 5,2 | Commission rate applied as a percentage of the final sale price. |
| status | ENUM | – | Current status of the commission. Values: `pending`, `calculated`, `processing`, `paid`, `cancelled`. Defaults to 'pending'. |
| calculated_at | TIMESTAMP | – | Timestamp when the commission was calculated. Nullable. |
| paid_at | TIMESTAMP | – | Timestamp when the commission was paid out to the agent. Nullable. |
| payment_reference | VARCHAR | 100 | Reference number of the payment transaction (e.g., GCash reference). Nullable. |
| payment_method | VARCHAR | 50 | Method used to pay the commission (e.g., bank_transfer, gcash, maya, cash, check, other). Nullable. |
| payment_proof_path | VARCHAR | 500 | Relative file path to the uploaded payment proof document. Nullable. |
| payment_proof_original_name | VARCHAR | 255 | Original filename of the uploaded proof document. Nullable. |
| payment_proof_mime | VARCHAR | 100 | MIME type of the uploaded proof file (e.g., image/jpeg, application/pdf). Nullable. |
| payment_proof_size | INT | 11 | File size of the uploaded proof document in bytes. Nullable. |
| processed_by | INT | 11 | Foreign key referencing `accounts.account_id`. Admin who processed the commission calculation. Nullable. |
| paid_by | INT | 11 | Foreign key referencing `accounts.account_id`. Admin who processed the actual payment. Nullable. |
| payment_notes | TEXT | – | Admin notes related to the commission payment action. Nullable. |
| created_at | TIMESTAMP | – | Timestamp when the commission record was created. Defaults to current timestamp. |
| updated_at | TIMESTAMP | – | Timestamp of the last update to the record. Auto-updates on modification. |

**Indexes:** PRIMARY KEY (`commission_id`), UNIQUE (`sale_id`), INDEX (`agent_id`), INDEX (`processed_by`), INDEX (`paid_by`), INDEX (`status`), INDEX (`paid_at`)  
**Foreign Keys:** `sale_id` → `finalized_sales.sale_id` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE), `processed_by` → `accounts.account_id` (ON DELETE SET NULL), `paid_by` → `accounts.account_id` (ON DELETE SET NULL)

---

## Table: agent_information

Stores extended profile information for agent accounts, including license details, experience, approval status, and profile completion.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| agent_info_id | INT | 11 | Primary key. Unique identifier for each agent profile record (auto-increment). |
| account_id | INT | 11 | Foreign key referencing `accounts.account_id`. Links the profile to a specific agent account. Unique per account. |
| license_number | VARCHAR | 100 | Agent's real estate broker license number. Must be unique. |
| years_experience | INT | 11 | Number of years of real estate experience. Defaults to 0. |
| bio | TEXT | – | Biographical description or professional summary of the agent. Nullable. |
| profile_picture_url | TEXT | – | Relative file path or URL to the agent's uploaded profile picture. Nullable. |
| profile_completed | TINYINT | 1 | Indicates whether the agent has completed their profile (1) or not (0). Defaults to 0. |
| is_approved | TINYINT | 1 | Indicates whether the agent's profile has been approved by an admin (1) or is still pending (0). Defaults to 0. |

**Indexes:** PRIMARY KEY (`agent_info_id`), UNIQUE (`account_id`), UNIQUE (`license_number`)  
**Foreign Keys:** `account_id` → `accounts.account_id`

---

## Table: agent_notifications

Stores notification messages sent to agents regarding tour requests, property approvals, sales, commissions, rental activities, and lease events.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| notification_id | INT | 11 | Primary key. Unique identifier for each notification (auto-increment). |
| agent_account_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who receives the notification. |
| notif_type | ENUM | – | Type of notification. Values: `tour_new`, `tour_cancelled`, `tour_completed`, `property_approved`, `property_rejected`, `sale_approved`, `sale_rejected`, `commission_paid`, `rental_approved`, `rental_rejected`, `rental_payment_confirmed`, `rental_payment_rejected`, `rental_commission_paid`, `lease_expiring`, `lease_expired`, `general`. Defaults to 'general'. |
| reference_id | INT | 11 | ID of the related item (e.g., tour_id, property_id, sale_id) depending on the notification type. Nullable. |
| title | VARCHAR | 150 | Short title or heading of the notification. |
| message | TEXT | – | Full notification message body with details. |
| is_read | TINYINT | 1 | Indicates whether the agent has read the notification (1) or not (0). Defaults to 0. |
| created_at | DATETIME | – | Timestamp when the notification was created. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`notification_id`), INDEX (`agent_account_id`, `is_read`, `created_at`), INDEX (`agent_account_id`, `notif_type`)  
**Foreign Keys:** `agent_account_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: agent_specializations

Junction (pivot) table that links agents to their areas of specialization. Supports a many-to-many relationship between `agent_information` and `specializations`.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| agent_info_id | INT | 11 | Foreign key referencing `agent_information.agent_info_id`. Identifies the agent. Part of composite primary key. |
| specialization_id | INT | 11 | Foreign key referencing `specializations.specialization_id`. Identifies the specialization. Part of composite primary key. |

**Indexes:** PRIMARY KEY (`agent_info_id`, `specialization_id`), INDEX (`specialization_id`)  
**Foreign Keys:** `agent_info_id` → `agent_information.agent_info_id` (ON DELETE CASCADE), `specialization_id` → `specializations.specialization_id` (ON DELETE CASCADE)

---

## Table: amenities

Lookup table containing the master list of property amenities available in the system (e.g., Swimming Pool, Garage, Air Conditioning).

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| amenity_id | INT | 11 | Primary key. Unique identifier for each amenity (auto-increment). |
| amenity_name | VARCHAR | 100 | Name of the amenity. Must be unique (e.g., Swimming Pool, Garage, Air Conditioning). |

**Indexes:** PRIMARY KEY (`amenity_id`), UNIQUE (`amenity_name`)

---

## Table: commission_payment_logs

Audit log that records every action and status change related to agent commission payments. Provides a full history of commission lifecycle events for accountability.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| log_id | INT | 11 | Primary key. Unique identifier for each log entry (auto-increment). |
| commission_id | INT | 11 | Foreign key referencing `agent_commissions.commission_id`. The commission this log entry pertains to. |
| action | ENUM | – | Type of action logged. Values: `created`, `calculated`, `processing`, `paid`, `cancelled`, `updated`, `proof_uploaded`, `proof_replaced`. |
| old_status | VARCHAR | 20 | Previous commission status before the change. Nullable. |
| new_status | VARCHAR | 20 | New commission status after the change. Nullable. |
| details | TEXT | – | JSON-encoded string describing the details of the change (e.g., amounts, references, IPs). Nullable. |
| performed_by | INT | 11 | Foreign key referencing `accounts.account_id`. Admin who performed the action. |
| ip_address | VARCHAR | 45 | IP address of the admin who performed the action (supports IPv6). Nullable. |
| created_at | TIMESTAMP | – | Timestamp when the log entry was created. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`log_id`), INDEX (`commission_id`), INDEX (`action`), INDEX (`performed_by`), INDEX (`created_at`)  
**Foreign Keys:** `commission_id` → `agent_commissions.commission_id` (ON DELETE CASCADE), `performed_by` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: finalized_rentals

Stores finalized rental agreements after an admin approves a rental verification. Contains lease terms, tenant information, commission rates, and lease lifecycle status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| rental_id | INT | 11 | Primary key. Unique identifier for each finalized rental record (auto-increment). |
| verification_id | INT | 11 | Foreign key referencing `rental_verifications.verification_id`. The approved rental verification this record originated from. Unique. |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The rented property. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who facilitated the rental. |
| tenant_name | VARCHAR | 255 | Full name of the tenant. |
| tenant_email | VARCHAR | 255 | Tenant's email address. Nullable. |
| tenant_phone | VARCHAR | 20 | Tenant's contact phone number. Nullable. |
| monthly_rent | DECIMAL | 12,2 | Monthly rental amount in PHP (₱). |
| security_deposit | DECIMAL | 12,2 | Security deposit amount collected. Defaults to 0.00. |
| lease_start_date | DATE | – | Start date of the lease agreement. |
| lease_end_date | DATE | – | End date of the lease agreement. |
| lease_term_months | INT | 11 | Duration of the lease in months. |
| commission_rate | DECIMAL | 5,2 | Agent's commission rate as a percentage of each confirmed rental payment. Defaults to 0.00. |
| additional_notes | TEXT | – | Any additional notes or remarks about the rental. Nullable. |
| lease_status | ENUM | – | Current status of the lease. Values: `Active`, `Renewed`, `Terminated`, `Expired`. Defaults to 'Active'. |
| renewed_at | TIMESTAMP | – | Timestamp when the lease was renewed. Nullable. |
| terminated_at | TIMESTAMP | – | Timestamp when the lease was terminated. Nullable. |
| terminated_by | INT | 11 | Account ID of the user who terminated the lease. Nullable. |
| termination_reason | TEXT | – | Reason provided for terminating the lease. Nullable. |
| finalized_by | INT | 11 | Foreign key referencing `accounts.account_id`. Admin who finalized and approved the rental. |
| finalized_at | TIMESTAMP | – | Timestamp when the rental was finalized. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`rental_id`), UNIQUE (`verification_id`), INDEX (`property_id`), INDEX (`agent_id`), INDEX (`lease_status`), INDEX (`lease_end_date`), INDEX (`finalized_by`)  
**Foreign Keys:** `verification_id` → `rental_verifications.verification_id` (ON DELETE CASCADE), `property_id` → `property.property_ID` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE), `finalized_by` → `accounts.account_id`

---

## Table: finalized_sales

Stores finalized property sale records after an admin approves a sale verification. Contains buyer details, final sale price, and locking status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| sale_id | INT | 11 | Primary key. Unique identifier for each finalized sale record (auto-increment). |
| verification_id | INT | 11 | Foreign key referencing `sale_verifications.verification_id`. The approved sale verification this record originated from. Unique. |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property that was sold. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who facilitated the sale. |
| buyer_name | VARCHAR | 255 | Full name of the property buyer. |
| buyer_email | VARCHAR | 255 | Buyer's email address. Nullable. |
| final_sale_price | DECIMAL | 15,2 | The final agreed-upon sale price in PHP (₱). |
| sale_date | DATE | – | Official date of the property sale. |
| additional_notes | TEXT | – | Any additional notes or remarks about the sale. Nullable. |
| finalized_by | INT | 11 | Foreign key referencing `accounts.account_id`. Admin who finalized and approved the sale. |
| finalized_at | TIMESTAMP | – | Timestamp when the sale was finalized. Defaults to current timestamp. |
| is_locked | TINYINT | 1 | Indicates whether the sale record is locked from further edits (1 = locked). Defaults to 1. |

**Indexes:** PRIMARY KEY (`sale_id`), UNIQUE (`verification_id`), INDEX (`property_id`), INDEX (`agent_id`), INDEX (`finalized_by`)  
**Foreign Keys:** `verification_id` → `sale_verifications.verification_id` (ON DELETE CASCADE), `property_id` → `property.property_ID` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE), `finalized_by` → `accounts.account_id`

---

## Table: notifications

Stores system-wide notifications for the admin dashboard, covering events such as agent submissions, property sales, rentals, tour requests, and payment activities.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| notification_id | INT | 11 | Primary key. Unique identifier for each notification (auto-increment). |
| item_id | INT | 11 | ID of the related entity (e.g., agent account ID, verification ID, rental ID). Context depends on `item_type`. |
| item_type | ENUM | – | Type of entity the notification relates to. Values: `agent`, `property`, `property_sale`, `property_rental`, `rental_payment`, `tour`. Defaults to 'agent'. |
| title | VARCHAR | 255 | Short title or heading of the notification. Defaults to empty string. |
| message | VARCHAR | 255 | Notification message summarizing the event. |
| category | ENUM | – | Category of the notification. Values: `request`, `update`, `alert`, `system`. Defaults to 'update'. |
| priority | ENUM | – | Priority level of the notification. Values: `low`, `normal`, `high`, `urgent`. Defaults to 'normal'. |
| action_url | VARCHAR | 500 | URL path the admin can navigate to for taking action on the notification. Nullable. |
| icon | VARCHAR | 50 | Bootstrap icon class name for visual display (e.g., bi-person-badge, bi-calendar-check). Nullable. |
| is_read | TINYINT | 1 | Indicates whether the notification has been read (1) or not (0). Defaults to 0. |
| created_at | DATETIME | – | Timestamp when the notification was created. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`notification_id`), INDEX (`is_read`, `created_at`), INDEX (`category`, `priority`)

---

## Table: price_history

Tracks the historical price events for each property, including listing, price changes, sales, rentals, and off-market events. Used for price trend analysis.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| history_id | INT | 11 | Primary key. Unique identifier for each price history entry (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property this price event relates to. |
| event_date | DATE | – | Date when the price event occurred. |
| event_type | ENUM | – | Type of price event. Values: `Listed`, `Price Change`, `Sold`, `Off Market`, `Rented`, `Lease Ended`. |
| price | DECIMAL | 12,2 | The price associated with the event in PHP (₱). |

**Indexes:** PRIMARY KEY (`history_id`), INDEX (`property_id`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE)

---

## Table: property

Core table storing all property listings in the system. Contains location details, physical attributes, pricing, listing metadata, and status tracking.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| property_ID | INT | 11 | Primary key. Unique identifier for each property listing (auto-increment). |
| StreetAddress | TEXT | – | Street address or lot/unit identifier of the property. |
| City | VARCHAR | 100 | City where the property is located. |
| Barangay | VARCHAR | 100 | Barangay (neighborhood or district) of the property. Nullable. |
| Province | VARCHAR | 100 | Province or region where the property is located. |
| ZIP | VARCHAR | 10 | ZIP or postal code of the property location. |
| PropertyType | VARCHAR | 50 | Type of property (e.g., Single-Family Home, Condominium, Townhouse, Commercial, Multi-Family). |
| YearBuilt | INT | 11 | Year the property was constructed. Nullable. |
| SquareFootage | INT | 11 | Total interior area of the property in square feet. Nullable. |
| LotSize | DECIMAL | 8,2 | Lot or land area size in hectares. Nullable. |
| Bedrooms | INT | 11 | Number of bedrooms. Nullable. |
| Bathrooms | DECIMAL | 3,1 | Number of bathrooms (supports half baths, e.g., 2.5). Nullable. |
| ListingPrice | DECIMAL | 12,2 | Listed price of the property in PHP (₱). |
| Status | VARCHAR | 50 | Current property status (e.g., For Sale, For Rent, Sold, Rented). |
| ViewsCount | INT | 11 | Total number of times the property listing has been viewed. Defaults to 0. |
| Likes | INT | 255 | Total number of likes or favorites the listing has received. Defaults to 0. |
| ListingDate | DATE | – | Date the property was listed on the platform. Nullable. |
| Source | VARCHAR | 100 | Source or board where the listing originated (e.g., PAREB-NCR, PAREB-CEBU). Nullable. |
| MLSNumber | VARCHAR | 20 | Multiple Listing Service (MLS) reference number for the property. Nullable. |
| ListingDescription | TEXT | – | Detailed description of the property listing. Nullable. |
| ParkingType | VARCHAR | 100 | Type of parking available (e.g., 2-Car Garage, Basement Parking Slot). Nullable. |
| listing_type | ENUM | – | Listing classification. Values: `For Sale`, `For Rent`. Defaults to 'For Sale'. |
| approval_status | ENUM | – | Admin approval status of the listing. Values: `pending`, `approved`, `rejected`. Defaults to 'pending'. |
| is_locked | TINYINT | 1 | Indicates whether the property is locked from edits (1 = locked, typically after sale or rental). Defaults to 0. |
| sold_date | DATE | – | Date the property was sold. Nullable (only set when the property is sold). |
| sold_by_agent | INT | 11 | Foreign key referencing `accounts.account_id`. Agent who sold the property. Nullable. |

**Indexes:** PRIMARY KEY (`property_ID`), INDEX (`Status`, `is_locked`), INDEX (`sold_by_agent`), INDEX (`ListingDate`), INDEX (`City`), INDEX (`PropertyType`), INDEX (`Bedrooms`, `Bathrooms`), INDEX (`ListingPrice`), INDEX (`approval_status`, `Status`, `City`, `PropertyType`, `ListingPrice`), FULLTEXT (`StreetAddress`, `City`, `Barangay`, `ListingDescription`, `PropertyType`)  
**Foreign Keys:** `sold_by_agent` → `accounts.account_id` (ON DELETE SET NULL)

---

## Table: property_amenities

Junction (pivot) table linking properties to their associated amenities. Supports a many-to-many relationship between `property` and `amenities`.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. Identifies the property. Part of composite primary key. |
| amenity_id | INT | 11 | Foreign key referencing `amenities.amenity_id`. Identifies the amenity. Part of composite primary key. |

**Indexes:** PRIMARY KEY (`property_id`, `amenity_id`), INDEX (`amenity_id`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE), `amenity_id` → `amenities.amenity_id` (ON DELETE CASCADE)

---

## Table: property_floor_images

Stores floor plan images for properties. Each property can have multiple floors, and each floor can have multiple photos with a defined sort order.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| id | INT | 11 | Primary key. Unique identifier for each floor image record (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property this floor image belongs to. |
| floor_number | INT | 11 | Floor number the image represents (e.g., 1 for ground floor, 2 for second floor). |
| photo_url | VARCHAR | 255 | Relative file path to the uploaded floor plan image. |
| sort_order | INT | 11 | Display order of the image within its floor. Defaults to 0. |
| created_at | TIMESTAMP | – | Timestamp when the image record was created. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`id`), INDEX (`property_id`, `floor_number`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE)

---

## Table: property_images

Stores the main gallery/featured images for each property listing. Each property can have multiple photos displayed in a specified order.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| PhotoID | INT | 11 | Primary key. Unique identifier for each property image (auto-increment). |
| property_ID | INT | 11 | Foreign key referencing `property.property_ID`. The property this image belongs to. |
| PhotoURL | TEXT | – | Relative file path to the uploaded property photo. |
| SortOrder | INT | 11 | Display order of the image in the property gallery. Nullable. |

**Indexes:** PRIMARY KEY (`PhotoID`), INDEX (`property_ID`)  
**Foreign Keys:** `property_ID` → `property.property_ID` (ON DELETE CASCADE)

---

## Table: property_log

Audit log that records all significant actions performed on properties, such as creation, updates, sales, rentals, and rejections. Provides a complete property lifecycle history.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| log_id | INT | 11 | Primary key. Unique identifier for each log entry (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property the action was performed on. |
| account_id | INT | 11 | Foreign key referencing `accounts.account_id`. The user who performed the action. |
| action | ENUM | – | Type of action performed. Values: `CREATED`, `UPDATED`, `DELETED`, `SOLD`, `REJECTED`, `RENTED`, `LEASE_RENEWED`, `LEASE_TERMINATED`. Defaults to 'CREATED'. |
| log_timestamp | DATETIME | – | Timestamp when the action occurred. Defaults to the current date and time. |
| reason_message | TEXT | – | Optional message describing the reason or context for the action. Nullable. |
| reference_id | INT | 11 | Optional reference to a related record (e.g., verification_id). Nullable. |

**Indexes:** PRIMARY KEY (`log_id`), INDEX (`property_id`), INDEX (`account_id`), INDEX (`property_id`, `action`), INDEX (`action`, `log_timestamp`)  
**Foreign Keys:** `property_id` → `property.property_ID`, `account_id` → `accounts.account_id`

---

## Table: property_types

Lookup table containing the predefined categories of property types available in the system.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| property_type_id | INT | 11 | Primary key. Unique identifier for each property type (auto-increment). |
| type_name | VARCHAR | 100 | Name of the property type (e.g., Single-Family Home, Condominium, Townhouse, Multi-Family, Commercial). Must be unique. |

**Indexes:** PRIMARY KEY (`property_type_id`), UNIQUE (`type_name`)

---

## Table: rental_commissions

Tracks commissions earned by agents from confirmed rental payments. Each record links a rental payment to the agent's calculated commission.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| commission_id | INT | 11 | Primary key. Unique identifier for each rental commission record (auto-increment). |
| rental_id | INT | 11 | Foreign key referencing `finalized_rentals.rental_id`. The finalized rental this commission is associated with. |
| payment_id | INT | 11 | Foreign key referencing `rental_payments.payment_id`. The specific rental payment that triggered the commission. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who earned the commission. |
| commission_amount | DECIMAL | 12,2 | Calculated commission amount in PHP (₱). |
| commission_percentage | DECIMAL | 5,2 | Commission rate applied as a percentage of the rental payment. |
| status | ENUM | – | Current status of the commission. Values: `pending`, `calculated`, `paid`, `cancelled`. Defaults to 'pending'. |
| calculated_at | TIMESTAMP | – | Timestamp when the commission was calculated. Nullable. |
| paid_at | TIMESTAMP | – | Timestamp when the commission was paid to the agent. Nullable. |
| payment_reference | VARCHAR | 100 | Payment transaction reference number. Nullable. |
| processed_by | INT | 11 | Account ID of the admin who processed the payment. Nullable. |
| created_at | TIMESTAMP | – | Timestamp when the commission record was created. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`commission_id`), INDEX (`rental_id`), INDEX (`payment_id`), INDEX (`agent_id`)  
**Foreign Keys:** `rental_id` → `finalized_rentals.rental_id` (ON DELETE CASCADE), `payment_id` → `rental_payments.payment_id` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: rental_details

Stores rental-specific information for properties listed as "For Rent". Contains rent amount, deposit, lease terms, furnishing level, and availability date.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| rental_id | INT | 11 | Primary key. Unique identifier for each rental detail record (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The rental property this detail belongs to. |
| monthly_rent | DECIMAL | 12,2 | Monthly rental price in PHP (₱). |
| security_deposit | DECIMAL | 12,2 | Required security deposit amount. Defaults to 0.00. |
| lease_term_months | INT | 11 | Standard lease duration in months. |
| furnishing | ENUM | – | Level of furnishing included. Values: `Unfurnished`, `Semi-Furnished`, `Fully Furnished`. |
| available_from | DATE | – | Date when the property becomes available for rent. |
| created_at | TIMESTAMP | – | Timestamp when the rental detail was created. Defaults to current timestamp. |
| updated_at | TIMESTAMP | – | Timestamp of the last update to the record. Auto-updates on modification. |

**Indexes:** PRIMARY KEY (`rental_id`), INDEX (`property_id`), INDEX (`available_from`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE)

---

## Table: rental_payments

Records monthly rent payments submitted by agents on behalf of tenants for finalized rental agreements. Includes payment period, status, and admin confirmation details.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| payment_id | INT | 11 | Primary key. Unique identifier for each rental payment record (auto-increment). |
| rental_id | INT | 11 | Foreign key referencing `finalized_rentals.rental_id`. The finalized rental this payment belongs to. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent who submitted the payment record. |
| payment_amount | DECIMAL | 12,2 | Amount of the payment in PHP (₱). |
| payment_date | DATE | – | Date when the payment was received or made. |
| period_start | DATE | – | Start date of the rental period covered by this payment. |
| period_end | DATE | – | End date of the rental period covered by this payment. |
| additional_notes | TEXT | – | Agent's notes about the payment (e.g., transaction reference numbers). Nullable. |
| status | ENUM | – | Payment verification status. Values: `Pending`, `Confirmed`, `Rejected`. Defaults to 'Pending'. |
| admin_notes | TEXT | – | Admin's notes regarding the payment confirmation or rejection. Nullable. |
| confirmed_by | INT | 11 | Account ID of the admin who confirmed the payment. Nullable. |
| confirmed_at | TIMESTAMP | – | Timestamp when the payment was confirmed. Nullable. |
| submitted_at | TIMESTAMP | – | Timestamp when the payment record was submitted. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`payment_id`), INDEX (`rental_id`), INDEX (`agent_id`), INDEX (`status`), INDEX (`period_start`, `period_end`)  
**Foreign Keys:** `rental_id` → `finalized_rentals.rental_id` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: rental_payment_documents

Stores uploaded supporting documents for rental payment records (e.g., receipts, deposit slips, proof of payment).

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| document_id | INT | 11 | Primary key. Unique identifier for each document record (auto-increment). |
| payment_id | INT | 11 | Foreign key referencing `rental_payments.payment_id`. The rental payment this document supports. |
| original_filename | VARCHAR | 255 | Original filename of the uploaded document as named by the user. |
| stored_filename | VARCHAR | 255 | System-generated unique filename used for storage on the server. |
| file_path | VARCHAR | 500 | Relative file path to the stored document. |
| file_size | INT | 11 | Size of the file in bytes. Nullable. |
| mime_type | VARCHAR | 100 | MIME type of the file (e.g., application/pdf, image/jpeg). Nullable. |
| uploaded_at | TIMESTAMP | – | Timestamp when the document was uploaded. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`document_id`), INDEX (`payment_id`)  
**Foreign Keys:** `payment_id` → `rental_payments.payment_id` (ON DELETE CASCADE)

---

## Table: rental_verifications

Stores rental verification submissions made by agents when they find a tenant for a property. Contains tenant details, proposed lease terms, and admin review status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| verification_id | INT | 11 | Primary key. Unique identifier for each rental verification submission (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property being rented. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent submitting the rental verification. |
| monthly_rent | DECIMAL | 12,2 | Proposed monthly rent amount in PHP (₱). |
| security_deposit | DECIMAL | 12,2 | Proposed security deposit amount. Defaults to 0.00. |
| lease_start_date | DATE | – | Proposed start date of the lease. |
| lease_term_months | INT | 11 | Proposed duration of the lease in months. |
| tenant_name | VARCHAR | 255 | Full name of the proposed tenant. |
| tenant_email | VARCHAR | 255 | Proposed tenant's email address. Nullable. |
| tenant_phone | VARCHAR | 20 | Proposed tenant's phone number. Nullable. |
| additional_notes | TEXT | – | Agent's additional notes about the rental. Nullable. |
| status | ENUM | – | Admin review status. Values: `Pending`, `Approved`, `Rejected`. Defaults to 'Pending'. |
| admin_notes | TEXT | – | Admin's review notes or feedback. Nullable. |
| reviewed_by | INT | 11 | Account ID of the admin who reviewed the submission. Nullable. |
| reviewed_at | TIMESTAMP | – | Timestamp when the submission was reviewed. Nullable. |
| submitted_at | TIMESTAMP | – | Timestamp when the verification was submitted. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`verification_id`), INDEX (`property_id`), INDEX (`agent_id`), INDEX (`status`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: rental_verification_documents

Stores uploaded supporting documents for rental verification submissions (e.g., lease agreements, identification, tenant application forms).

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| document_id | INT | 11 | Primary key. Unique identifier for each document record (auto-increment). |
| verification_id | INT | 11 | Foreign key referencing `rental_verifications.verification_id`. The rental verification this document supports. |
| original_filename | VARCHAR | 255 | Original filename of the uploaded document as named by the user. |
| stored_filename | VARCHAR | 255 | System-generated unique filename used for storage on the server. |
| file_path | VARCHAR | 500 | Relative file path to the stored document. |
| file_size | INT | 11 | Size of the file in bytes. Nullable. |
| mime_type | VARCHAR | 100 | MIME type of the file (e.g., application/pdf, image/jpeg). Nullable. |
| uploaded_at | TIMESTAMP | – | Timestamp when the document was uploaded. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`document_id`), INDEX (`verification_id`)  
**Foreign Keys:** `verification_id` → `rental_verifications.verification_id` (ON DELETE CASCADE)

---

## Table: sale_verifications

Stores sale verification submissions made by agents when a property sale is completed. Contains buyer details, sale price, and admin review status.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| verification_id | INT | 11 | Primary key. Unique identifier for each sale verification submission (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property that was sold. |
| agent_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent submitting the sale verification. |
| sale_price | DECIMAL | 15,2 | Reported sale price in PHP (₱). |
| sale_date | DATE | – | Date the sale was completed. |
| buyer_name | VARCHAR | 255 | Full name of the buyer. |
| buyer_email | VARCHAR | 255 | Buyer's email address. Nullable. |
| additional_notes | TEXT | – | Agent's additional notes about the sale. Nullable. |
| status | ENUM | – | Admin review status. Values: `Pending`, `Approved`, `Rejected`. Defaults to 'Pending'. |
| admin_notes | TEXT | – | Admin's review notes or feedback. Nullable. |
| reviewed_by | INT | 11 | Account ID of the admin who reviewed the submission. Nullable. |
| reviewed_at | TIMESTAMP | – | Timestamp when the submission was reviewed. Nullable. |
| submitted_at | TIMESTAMP | – | Timestamp when the verification was submitted. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`verification_id`), INDEX (`property_id`), INDEX (`agent_id`), INDEX (`status`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE), `agent_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: sale_verification_documents

Stores uploaded supporting documents for sale verification submissions (e.g., deed of sale, transfer certificates, buyer identification).

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| document_id | INT | 11 | Primary key. Unique identifier for each document record (auto-increment). |
| verification_id | INT | 11 | Foreign key referencing `sale_verifications.verification_id`. The sale verification this document supports. |
| original_filename | VARCHAR | 255 | Original filename of the uploaded document as named by the user. |
| stored_filename | VARCHAR | 255 | System-generated unique filename used for storage on the server. |
| file_path | VARCHAR | 500 | Relative file path to the stored document. |
| file_size | INT | 11 | Size of the file in bytes. Nullable. |
| mime_type | VARCHAR | 100 | MIME type of the file (e.g., application/pdf, image/jpeg). Nullable. |
| uploaded_at | TIMESTAMP | – | Timestamp when the document was uploaded. Defaults to current timestamp. |

**Indexes:** PRIMARY KEY (`document_id`), INDEX (`verification_id`)  
**Foreign Keys:** `verification_id` → `sale_verifications.verification_id` (ON DELETE CASCADE)

---

## Table: specializations

Lookup table containing the master list of agent specialization categories available in the system.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| specialization_id | INT | 11 | Primary key. Unique identifier for each specialization (auto-increment). |
| specialization_name | VARCHAR | 100 | Name of the specialization area (e.g., Luxury Homes, Commercial, Rentals, Condos). Must be unique. |

**Indexes:** PRIMARY KEY (`specialization_id`), UNIQUE (`specialization_name`)

---

## Table: status_log

Records approval and rejection actions performed by admins on agents and properties. Provides an audit trail for administrative decisions.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| log_id | INT | 11 | Primary key. Unique identifier for each status log entry (auto-increment). |
| item_id | INT | 11 | ID of the agent or property that the action was performed on. |
| item_type | ENUM | – | Type of entity. Values: `agent`, `property`. |
| action | ENUM | – | Action performed. Values: `approved`, `rejected`. |
| reason_message | TEXT | – | Reason or description for the action taken. Nullable. |
| action_by_account_id | INT | 11 | Foreign key referencing `accounts.account_id`. The admin who performed the action. Nullable. |
| log_timestamp | DATETIME | – | Timestamp when the action was performed. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`log_id`), INDEX (`action_by_account_id`)  
**Foreign Keys:** `action_by_account_id` → `accounts.account_id` (ON DELETE SET NULL)

---

## Table: tour_requests

Stores property tour requests made by prospective buyers or renters. Tracks scheduling, request status, confirmation, completion, and decision details.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| tour_id | INT | 11 | Primary key. Unique identifier for each tour request (auto-increment). |
| property_id | INT | 11 | Foreign key referencing `property.property_ID`. The property the tour is requested for. |
| agent_account_id | INT | 11 | Foreign key referencing `accounts.account_id`. The agent assigned to handle the tour. |
| user_name | VARCHAR | 100 | Full name of the person requesting the tour. |
| user_email | VARCHAR | 100 | Email address of the person requesting the tour. |
| user_phone | VARCHAR | 20 | Phone number of the person requesting the tour. Nullable. |
| tour_date | DATE | – | Requested date for the property tour. |
| tour_time | TIME | – | Requested time for the property tour. |
| tour_type | ENUM | – | Type of tour. Values: `public`, `private`. Defaults to 'private'. |
| message | TEXT | – | Optional message or special requests from the requester. Nullable. |
| request_status | ENUM | – | Current status of the tour request. Values: `Pending`, `Confirmed`, `Cancelled`, `Completed`, `Rejected`, `Expired`. Defaults to 'Pending'. |
| confirmed_at | DATETIME | – | Timestamp when the tour was confirmed by the agent. Nullable. |
| completed_at | DATETIME | – | Timestamp when the tour was marked as completed. Nullable. |
| expired_at | DATETIME | – | Timestamp when the tour request expired (past tour date without response). Nullable. |
| decision_reason | TEXT | – | Reason provided for cancellation, rejection, or expiry. Nullable. |
| decision_by | ENUM | – | Who made the decision. Values: `agent`, `user`. Nullable. |
| decision_at | DATETIME | – | Timestamp when the decision (cancel/reject) was made. Nullable. |
| is_read_by_agent | TINYINT | 1 | Indicates whether the agent has seen the tour request (1) or not (0). Defaults to 0. |
| requested_at | DATETIME | – | Timestamp when the tour request was initially submitted. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`tour_id`), INDEX (`property_id`), INDEX (`agent_account_id`), INDEX (`request_status`), INDEX (`confirmed_at`), INDEX (`completed_at`), INDEX (`agent_account_id`, `tour_date`, `tour_time`)  
**Foreign Keys:** `property_id` → `property.property_ID` (ON DELETE CASCADE), `agent_account_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: two_factor_codes

Stores temporary two-factor authentication (2FA) verification codes sent to users during the login process. Each code has an expiration time and is consumed upon successful verification.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| code_id | INT | 11 | Primary key. Unique identifier for each 2FA code record (auto-increment). |
| account_id | INT | 11 | Foreign key referencing `accounts.account_id`. The user account this code was generated for. |
| code_hash | VARCHAR | 255 | Bcrypt-hashed version of the 2FA verification code for secure storage. |
| expires_at | DATETIME | – | Timestamp when the code expires and can no longer be used. |
| attempts | INT | 11 | Number of failed verification attempts for this code. Defaults to 0. |
| consumed | TINYINT | 1 | Indicates whether the code has been successfully used (1) or is still valid (0). Defaults to 0. |
| delivery | VARCHAR | 32 | Delivery method of the code (e.g., email). Defaults to 'email'. |
| created_at | DATETIME | – | Timestamp when the code was generated. Defaults to the current date and time. |

**Indexes:** PRIMARY KEY (`code_id`), INDEX (`account_id`, `expires_at`)  
**Foreign Keys:** `account_id` → `accounts.account_id` (ON DELETE CASCADE)

---

## Table: user_roles

Lookup table defining the available user roles in the system. Determines access levels and permissions for each account.

| Field Name | Data Type | Length | Description |
|---|---|---|---|
| role_id | INT | 11 | Primary key. Unique identifier for each role (auto-increment). |
| role_name | VARCHAR | 50 | Name of the role (e.g., admin, agent). Must be unique. |

**Indexes:** PRIMARY KEY (`role_id`), UNIQUE (`role_name`)

---

## Entity Relationship Summary

The diagram below summarizes the key relationships between tables:

```
user_roles ──< accounts ──< admin_information
                    │──< admin_logs
                    │──< agent_information ──< agent_specializations >── specializations
                    │──< agent_notifications
                    │──< agent_commissions
                    │──< finalized_sales
                    │──< finalized_rentals
                    │──< sale_verifications
                    │──< rental_verifications
                    │──< rental_payments
                    │──< rental_commissions
                    │──< tour_requests
                    │──< two_factor_codes
                    │──< property_log
                    │──< status_log
                    │──< commission_payment_logs
                    │
property ──< property_images
    │──< property_floor_images
    │──< property_amenities >── amenities
    │──< property_log
    │──< price_history
    │──< rental_details
    │──< sale_verifications ──< sale_verification_documents
    │                       ──< finalized_sales ──< agent_commissions ──< commission_payment_logs
    │──< rental_verifications ──< rental_verification_documents
    │                         ──< finalized_rentals ──< rental_payments ──< rental_payment_documents
    │                                                                   ──< rental_commissions
    │──< tour_requests
```

---

*End of Data Dictionary – HomeEstate Realty System*
