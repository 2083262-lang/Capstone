# HomeEstate Realty — Data Flow Diagram (Mermaid)

> **Project:** Capstone Real Estate System — HomeEstate Realty  
> **Version:** 2.0 (Revised from source-code analysis)  
> **Date:** March 24, 2026  
> **Usage:** Copy any diagram's Mermaid code into draw.io (Extras → Edit Diagram → paste) or any Mermaid renderer.

---

## Table of Contents

1. [Context Diagram (Level 0)](#1-context-diagram-level-0)
2. [Level 1 DFD](#2-level-1-dfd)
3. [Level 2 DFD](#3-level-2-dfd)
   - [P1 — Authentication & Access Control](#p1--authentication--access-control)
   - [P2 — Agent Profile Management](#p2--agent-profile-management)
   - [P3 — Property Management](#p3--property-management)
   - [P4 — Sale Management](#p4--sale-management)
   - [P5 — Rental & Lease Management](#p5--rental--lease-management)
   - [P6 — Rental Payment Processing](#p6--rental-payment-processing)
   - [P7 — Commission Management](#p7--commission-management)
   - [P8 — Tour Request Management](#p8--tour-request-management)
   - [P9 — Notification Management](#p9--notification-management)
   - [P10 — Reports & Dashboard](#p10--reports--dashboard)
   - [P11 — System Settings Management](#p11--system-settings-management)
   - [P12 — Public Browsing](#p12--public-browsing)

---

## Node Format Reference

| Node Type | Mermaid Syntax | Shape |
|-----------|---------------|-------|
| External Entity | `E1["E1: Admin"]` | Rectangle |
| Data Store | `D1[("D1: Accounts")]` | Cylinder |
| Process | `P1(("P1\nAuth &\nAccess Control"))` | Double-circle |
| Sub-process | `P1_1(("P1.1\nCredential\nValidation"))` | Double-circle |
| Flow Label | `-->│"Noun phrase"│` | Arrow with label |

---

## External Entity Reference

| ID | Entity | Description |
|----|--------|-------------|
| E1 | Admin | System administrator managing all backend operations |
| E2 | Agent | Licensed real estate agent (registered user) |
| E3 | Public User / Client | Unauthenticated visitor browsing properties |
| E4 | Email Service (SMTP) | PHPMailer-based email delivery via SMTP |
| E5 | Cron Scheduler | Scheduled or admin-triggered lease expiry check |

## Data Store Reference

| ID | Data Store | Database Table |
|----|-----------|---------------|
| D1 | Accounts | `accounts` |
| D2 | User Roles | `user_roles` |
| D3 | 2FA Codes | `two_factor_codes` |
| D5 | Admin Logs | `admin_logs` |
| D6 | Agent Information | `agent_information` |
| D7 | Agent Specializations | `agent_specializations` |
| D8 | Specializations | `specializations` |
| D9 | Properties | `property` |
| D10 | Property Images | `property_images` |
| D11 | Floor Images | `property_floor_images` |
| D12 | Property Amenities | `property_amenities` |
| D13 | Amenities | `amenities` |
| D14 | Property Types | `property_types` |
| D15 | Rental Details | `rental_details` |
| D16 | Price History | `price_history` |
| D17 | Property Log | `property_log` |
| D18 | Status Log | `status_log` |
| D19 | Sale Verifications | `sale_verifications` |
| D20 | Sale Verification Docs | `sale_verification_documents` |
| D21 | Finalized Sales | `finalized_sales` |
| D22 | Agent Commissions | `agent_commissions` |
| D23 | Commission Payment Logs | `commission_payment_logs` |
| D24 | Rental Verifications | `rental_verifications` |
| D25 | Rental Verification Docs | `rental_verification_documents` |
| D26 | Finalized Rentals | `finalized_rentals` |
| D27 | Rental Payments | `rental_payments` |
| D28 | Rental Payment Docs | `rental_payment_documents` |
| D29 | Rental Commissions | `rental_commissions` |
| D30 | Tour Requests | `tour_requests` |
| D31 | Admin Notifications | `notifications` |
| D32 | Agent Notifications | `agent_notifications` |

---

## 1. Context Diagram (Level 0)

The Context Diagram shows the entire system as a single process (P0) and all external entity interactions.

```mermaid
flowchart LR
    P0(("P0\nHomeEstate\nRealty\nSystem"))
    E1["E1: Admin"]
    E2["E2: Agent"]
    E3["E3: Public User / Client"]
    E4["E4: Email Service (SMTP)"]
    E5["E5: Cron Scheduler"]

    E1 -->|"Login credentials, 2FA code"| P0
    E1 -->|"Property data, approval decisions,\nsale/rental finalization,\ncommission payouts, system settings,\nagent approval/rejection/disable"| P0
    P0 -->|"Dashboard statistics, reports,\nin-app notifications"| E1
    P0 -->|"Agent applications, verification requests,\npayment records for review"| E1

    E2 -->|"Registration data, login credentials,\n2FA code, profile data"| P0
    E2 -->|"Property listings, sale/rental verifications\nwith documents, rental payment records,\ntour responses"| P0
    P0 -->|"Dashboard data, commission information,\nin-app notifications"| E2
    P0 -->|"Approval status updates,\nincoming tour requests"| E2

    E3 -->|"Search criteria, tour requests,\nproperty likes, page views"| P0
    P0 -->|"Property listings, agent profiles,\nsearch results, tour confirmations"| E3

    P0 -->|"2FA codes, tour emails,\napproval notices, payment notices,\nlease expiry warnings,\ncommission payment emails"| E4
    E4 -->|"Delivery status"| P0

    E5 -->|"Scheduled trigger\n(lease expiry check)"| P0
    P0 -->|"Lease expiry\nprocessing results"| E5
```

---

## 2. Level 1 DFD — By Module

The Level 1 DFD is presented **per module** below. Each diagram shows only the external entities and inter-process connections relevant to that module. Data stores are intentionally omitted at this level.

---

### Level 1 — P1: Authentication & Access Control

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P1(("P1\nAuth &\nAccess Control"))

    E1 -->|"Login credentials, 2FA code"| P1
    E2 -->|"Registration data,\nlogin credentials, 2FA code"| P1
    P1 -->|"Authenticated session"| E1
    P1 -->|"Authenticated session"| E2
    P1 -->|"2FA verification code"| E4
```

---

### Level 1 — P2: Agent Profile Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P2(("P2\nAgent Profile\nManagement"))
    P9(("P9\nNotification\nSystem"))

    E2 -->|"Profile data, documents,\nlicense, specializations"| P2
    E1 -->|"Approval / rejection /\ndisable decision"| P2
    P2 -->|"Approval / rejection /\ndisable notice email"| E4
    P2 -->|"Profile status notification"| P9
```

---

### Level 1 — P3: Property Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P3(("P3\nProperty\nManagement"))
    P9(("P9\nNotification\nSystem"))
    P12(("P12\nPublic\nBrowsing"))

    E1 -->|"Property data,\napproval decision"| P3
    E2 -->|"Property details,\nphotos, amenities"| P3
    P3 -->|"Approved listings"| P12
    P3 -->|"Property approval email"| E4
    P3 -->|"Property notifications"| P9
```

---

### Level 1 — P4: Sale Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P4(("P4\nSale\nManagement"))
    P7(("P7\nCommission\nManagement"))
    P9(("P9\nNotification\nSystem"))

    E2 -->|"Sale verification data,\ndocuments"| P4
    E1 -->|"Sale verification data,\napproval decision,\nfinalization with commission rate"| P4
    P4 -->|"Sale commission data"| P7
    P4 -->|"Sale notifications"| P9
    P4 -->|"Sale finalization email"| E4
```

---

### Level 1 — P5: Rental & Lease Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    E5["E5: Cron Scheduler"]
    P5(("P5\nRental &\nLease Mgmt"))
    P6(("P6\nRental Payment\nProcessing"))
    P9(("P9\nNotification\nSystem"))

    E2 -->|"Rental verification data,\ndocuments"| P5
    E1 -->|"Rental approval with\ncommission rate"| P5
    E5 -->|"Lease expiry trigger"| P5
    P5 -->|"Active lease data"| P6
    P5 -->|"Rental notifications"| P9
    P5 -->|"Lease emails"| E4
```

---

### Level 1 — P6: Rental Payment Processing

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P6(("P6\nRental Payment\nProcessing"))
    P7(("P7\nCommission\nManagement"))
    P9(("P9\nNotification\nSystem"))

    E2 -->|"Payment proof and period"| P6
    E1 -->|"Payment confirmation /\nrejection"| P6
    P6 -->|"Confirmed payment\ncommission data"| P7
    P6 -->|"Payment notifications"| P9
    P6 -->|"Payment email"| E4
```

---

### Level 1 — P7: Commission Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E4["E4: Email Service (SMTP)"]
    P4(("P4\nSale\nManagement"))
    P6(("P6\nRental Payment\nProcessing"))
    P7(("P7\nCommission\nManagement"))
    P9(("P9\nNotification\nSystem"))

    P4 -->|"Sale commission data"| P7
    P6 -->|"Confirmed payment\ncommission data"| P7
    E1 -->|"Payout details, proof"| P7
    P7 -->|"Commission notifications"| P9
    P7 -->|"Commission email"| E4
```

---

### Level 1 — P8: Tour Scheduling

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E3["E3: Public User / Client"]
    E4["E4: Email Service (SMTP)"]
    P8(("P8\nTour\nScheduling"))
    P9(("P9\nNotification\nSystem"))
    P12(("P12\nPublic\nBrowsing"))

    P12 -->|"Tour request handoff"| P8
    E3 -->|"Tour request"| P8
    E1 -->|"Tour decision"| P8
    E2 -->|"Tour decision"| P8
    P8 -->|"Tour notifications"| P9
    P8 -->|"Tour emails"| E4
```

---

### Level 1 — P9: Notification System

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P9(("P9\nNotification\nSystem"))

    P9 -->|"In-app notifications"| E1
    P9 -->|"In-app notifications"| E2
    P9 -->|"Transactional emails"| E4
```

---

### Level 1 — P10: Reports & Dashboard

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    P10(("P10\nReports &\nDashboard"))

    E1 -->|"Dashboard / report request"| P10
    E2 -->|"Dashboard request"| P10
    P10 -->|"Dashboard metrics, reports"| E1
    P10 -->|"Dashboard metrics"| E2
```

---

### Level 1 — P11: System Settings

```mermaid
flowchart LR
    E1["E1: Admin"]
    P11(("P11\nSystem\nSettings"))

    E1 -->|"Add / delete amenities,\nspecializations, property types"| P11
    P11 -->|"Updated lookup data"| E1
```

---

### Level 1 — P12: Public Browsing

```mermaid
flowchart LR
    E3["E3: Public User / Client"]
    P8(("P8\nTour\nScheduling"))
    P12(("P12\nPublic\nBrowsing"))

    E3 -->|"Search, filter, browse,\nlikes, page views"| P12
    P12 -->|"Property listings,\nagent profiles,\nsearch results"| E3
    P12 -->|"Tour request handoff"| P8
```

---

## 3. Level 2 DFD

The Level 2 diagrams below expand each Level 1 process and include data stores where the system reads or writes persistent records.

---

### P1 — Authentication & Access Control

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    D1[("D1: Accounts")]
    D2[("D2: User Roles")]
    D3[("D3: 2FA Codes")]
    D5[("D5: Admin Logs")]
    D6[("D6: Agent Information")]

    P1_1(("P1.1\nCredential\nValidation"))
    P1_2(("P1.2\nAgent\nRegistration"))
    P1_3(("P1.3\n2FA Code\nGeneration"))
    P1_4(("P1.4\n2FA Code\nVerification"))
    P1_5(("P1.5\nSession &\nLogout"))

    E1 -->|"Login credentials"| P1_1
    E2 -->|"Login credentials"| P1_1
    P1_1 -->|"Account lookup"| D1
    D1 -->|"Account record"| P1_1
    P1_1 -->|"Role lookup"| D2
    D2 -->|"Role record"| P1_1
    D6 -->|"Profile & approval status"| P1_1
    P1_1 -->|"Validated identity"| P1_3

    E2 -->|"Registration data\n(name, email, phone,\nusername, password)"| P1_2
    P1_2 -->|"Role lookup"| D2
    D2 -->|"Agent role ID"| P1_2
    P1_2 -->|"New account record"| D1

    P1_3 -->|"Invalidate old codes"| D3
    P1_3 -->|"New 2FA code hash"| D3
    P1_3 -->|"2FA verification code email"| E4

    E1 -->|"2FA code"| P1_4
    E2 -->|"2FA code"| P1_4
    D3 -->|"Stored code hash & expiry"| P1_4
    P1_4 -->|"Mark code consumed"| D3
    P1_4 -->|"Login audit record"| D5
    P1_4 -->|"Authenticated session"| P1_5

    P1_5 -->|"Active session"| E1
    P1_5 -->|"Active session"| E2
    E1 -->|"Logout request"| P1_5
    E2 -->|"Logout request"| P1_5
    P1_5 -->|"Logout audit record"| D5
```

---

### P2 — Agent Profile Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    D1[("D1: Accounts")]
    D6[("D6: Agent Information")]
    D7[("D7: Agent Specializations")]
    D8[("D8: Specializations")]
    D18[("D18: Status Log")]
    D31[("D31: Admin Notifications")]

    P2_1(("P2.1\nProfile\nSubmission"))
    P2_2(("P2.2\nAdmin\nReview"))
    P2_3(("P2.3\nApproval /\nRejection /\nDisable"))

    E2 -->|"Profile data, license,\nbio, specializations,\nprofile picture"| P2_1
    D8 -->|"Specialization lookup"| P2_1
    D1 -->|"Account record"| P2_1
    P2_1 -->|"Agent profile record"| D6
    P2_1 -->|"Specialization records"| D7
    P2_1 -->|"Submission notice"| D31

    E1 -->|"Review agent application"| P2_2
    D6 -->|"Agent profile data"| P2_2
    D7 -->|"Specialization data"| P2_2
    P2_2 -->|"Review outcome"| P2_3

    E1 -->|"Approve / reject /\ndisable decision"| P2_3
    P2_3 -->|"Updated approval status"| D6
    P2_3 -->|"Account activation /\ndeactivation"| D1
    P2_3 -->|"Status log record"| D18
    P2_3 -->|"Approval / rejection /\ndisable email"| E4
```

---

### P3 — Property Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    D9[("D9: Properties")]
    D10[("D10: Property Images")]
    D11[("D11: Floor Images")]
    D12[("D12: Property Amenities")]
    D13[("D13: Amenities")]
    D14[("D14: Property Types")]
    D15[("D15: Rental Details")]
    D16[("D16: Price History")]
    D17[("D17: Property Log")]
    D18[("D18: Status Log")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P3_1(("P3.1\nProperty\nCreation"))
    P3_2(("P3.2\nProperty\nUpdate"))
    P3_3(("P3.3\nApproval /\nRejection"))
    P3_4(("P3.4\nImage\nManagement"))

    E1 -->|"Property details, amenities"| P3_1
    E2 -->|"Property details, amenities"| P3_1
    D13 -->|"Amenity lookup data"| P3_1
    D14 -->|"Property type lookup data"| P3_1
    P3_1 -->|"Property record"| D9
    P3_1 -->|"Amenity associations"| D12
    P3_1 -->|"Rental details record"| D15
    P3_1 -->|"Price history record"| D16
    P3_1 -->|"Creation log"| D17
    P3_1 -->|"Submission notice"| D31
    P3_1 -->|"Image data"| P3_4

    E1 -->|"Updated property data"| P3_2
    E2 -->|"Updated property data"| P3_2
    D9 -->|"Existing property record"| P3_2
    P3_2 -->|"Updated property record"| D9
    P3_2 -->|"Price change record"| D16
    P3_2 -->|"Update log"| D17
    P3_2 -->|"Updated image data"| P3_4

    E1 -->|"Approval / rejection decision"| P3_3
    D9 -->|"Pending property record"| P3_3
    P3_3 -->|"Updated approval status"| D9
    P3_3 -->|"Price history record"| D16
    P3_3 -->|"Approval log"| D17
    P3_3 -->|"Status log record"| D18
    P3_3 -->|"Approval notification"| D32
    P3_3 -->|"Approval / rejection email"| E4

    P3_4 -->|"Featured image records"| D10
    P3_4 -->|"Floor plan records"| D11
```

---

### P4 — Sale Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P7(("P7\nCommission\nManagement"))
    D9[("D9: Properties")]
    D16[("D16: Price History")]
    D17[("D17: Property Log")]
    D19[("D19: Sale Verifications")]
    D20[("D20: Sale Verification Docs")]
    D21[("D21: Finalized Sales")]
    D22[("D22: Agent Commissions")]
    D23[("D23: Commission Payment Logs")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P4_1(("P4.1\nSale Verification\nSubmission"))
    P4_2(("P4.2\nSale Approval /\nRejection"))
    P4_3(("P4.3\nSale\nFinalization"))

    E2 -->|"Sale price, buyer info,\ndocuments"| P4_1
    E1 -->|"Sale price, buyer info,\ndocuments"| P4_1
    D9 -->|"Property record"| P4_1
    D17 -->|"Listing agent record"| P4_1
    P4_1 -->|"Sale verification record"| D19
    P4_1 -->|"Verification documents"| D20
    P4_1 -->|"Pending Sold status"| D9
    P4_1 -->|"Submission notice"| D31
    P4_1 -->|"Submission notification"| D32

    E1 -->|"Approval / rejection decision"| P4_2
    D19 -->|"Pending verification record"| P4_2
    D20 -->|"Verification documents"| P4_2
    P4_2 -->|"Updated verification status"| D19
    P4_2 -->|"Sold status"| D9
    P4_2 -->|"Status notification"| D32
    P4_2 -->|"Approval / rejection email"| E4

    E1 -->|"Final sale price,\ncommission percentage"| P4_3
    D9 -->|"Property record"| P4_3
    D19 -->|"Approved verification"| P4_3
    P4_3 -->|"Finalized sale record"| D21
    P4_3 -->|"Locked property status"| D9
    P4_3 -->|"Sold price record"| D16
    P4_3 -->|"Sale commission record"| D22
    P4_3 -->|"Commission audit log"| D23
    P4_3 -->|"Commission earned email"| E4
    P4_3 -->|"Sale commission data"| P7
```

---

### P5 — Rental & Lease Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    E5["E5: Cron Scheduler"]
    D9[("D9: Properties")]
    D15[("D15: Rental Details")]
    D16[("D16: Price History")]
    D17[("D17: Property Log")]
    D24[("D24: Rental Verifications")]
    D25[("D25: Rental Verification Docs")]
    D26[("D26: Finalized Rentals")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P5_1(("P5.1\nRental Verification\nSubmission"))
    P5_2(("P5.2\nRental Approval /\nFinalization"))
    P5_3(("P5.3\nLease\nRenewal"))
    P5_4(("P5.4\nLease\nTermination"))
    P5_5(("P5.5\nLease Expiry\nCheck"))

    E2 -->|"Tenant info, lease terms,\ndocuments"| P5_1
    E1 -->|"Tenant info, lease terms,\ndocuments"| P5_1
    D9 -->|"Property record"| P5_1
    D15 -->|"Rental details"| P5_1
    P5_1 -->|"Rental verification record"| D24
    P5_1 -->|"Verification documents"| D25
    P5_1 -->|"Submission notice"| D31

    E1 -->|"Approval decision,\ncommission rate"| P5_2
    D24 -->|"Pending verification record"| P5_2
    P5_2 -->|"Updated verification status"| D24
    P5_2 -->|"Active lease record"| D26
    P5_2 -->|"Rented status, locked"| D9
    P5_2 -->|"Rented price record"| D16
    P5_2 -->|"Rental log entry"| D17
    P5_2 -->|"Approval notification"| D32
    P5_2 -->|"Lease approval email\n(agent + tenant)"| E4

    E2 -->|"Renewal request,\nnew term, rent"| P5_3
    D26 -->|"Active / expired lease"| P5_3
    P5_3 -->|"Renewed lease record"| D26
    P5_3 -->|"Renewal emails"| E4

    E2 -->|"Termination request,\nreason"| P5_4
    D26 -->|"Active / expired lease"| P5_4
    P5_4 -->|"Terminated lease record"| D26
    P5_4 -->|"Unlocked property status"| D9
    P5_4 -->|"Termination emails"| E4

    E5 -->|"Lease expiry check trigger"| P5_5
    D26 -->|"Active / renewed leases"| P5_5
    P5_5 -->|"Expiry warning notifications"| D32
    P5_5 -->|"Expiry warning notifications"| D31
    P5_5 -->|"Expired lease status"| D26
    P5_5 -->|"Expiry warning /\nexpired emails"| E4
```

---

### P6 — Rental Payment Processing

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    P7(("P7\nCommission\nManagement"))
    D26[("D26: Finalized Rentals")]
    D27[("D27: Rental Payments")]
    D28[("D28: Rental Payment Docs")]
    D29[("D29: Rental Commissions")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P6_1(("P6.1\nPayment\nRecording"))
    P6_2(("P6.2\nPayment\nConfirmation"))
    P6_3(("P6.3\nPayment\nRejection"))

    E2 -->|"Payment amount, period,\nproof documents"| P6_1
    D26 -->|"Active lease record"| P6_1
    P6_1 -->|"Pending payment record"| D27
    P6_1 -->|"Payment proof documents"| D28
    P6_1 -->|"Payment submission notice"| D31

    E1 -->|"Confirmation decision,\nadmin notes"| P6_2
    D27 -->|"Pending payment record"| P6_2
    D26 -->|"Commission rate"| P6_2
    P6_2 -->|"Confirmed payment record"| D27
    P6_2 -->|"Rental commission record"| D29
    P6_2 -->|"Confirmation notification"| D32
    P6_2 -->|"Payment confirmation email"| E4
    P6_2 -->|"Commission data"| P7

    E1 -->|"Rejection decision,\nrejection notes"| P6_3
    D27 -->|"Pending payment record"| P6_3
    P6_3 -->|"Rejected payment record"| D27
    P6_3 -->|"Rejection notification"| D32
    P6_3 -->|"Payment rejection email"| E4
```

---

### P7 — Commission Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E4["E4: Email Service (SMTP)"]
    D22[("D22: Agent Commissions")]
    D23[("D23: Commission Payment Logs")]
    D29[("D29: Rental Commissions")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P7_1(("P7.1\nSale Commission\nPayout"))
    P7_2(("P7.2\nRental Commission\nPayout"))

    E1 -->|"Payment method,\nreference, proof,\nnotes"| P7_1
    D22 -->|"Calculated commission"| P7_1
    P7_1 -->|"Paid commission record"| D22
    P7_1 -->|"Payment audit log"| D23
    P7_1 -->|"Payout notification"| D32
    P7_1 -->|"Payout notification"| D31
    P7_1 -->|"Commission paid email"| E4

    E1 -->|"Payment method,\nreference, proof,\nnotes"| P7_2
    D29 -->|"Calculated commission"| P7_2
    P7_2 -->|"Paid commission record"| D29
    P7_2 -->|"Payout notification"| D32
    P7_2 -->|"Commission paid email"| E4
```

---

### P8 — Tour Request Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E3["E3: Public User / Client"]
    E4["E4: Email Service (SMTP)"]
    D9[("D9: Properties")]
    D17[("D17: Property Log")]
    D30[("D30: Tour Requests")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P8_1(("P8.1\nTour Request\nSubmission"))
    P8_2(("P8.2\nTour\nConfirmation"))
    P8_3(("P8.3\nTour Rejection /\nCancellation"))
    P8_4(("P8.4\nTour Completion /\nExpiry"))

    E3 -->|"Name, contact,\ndate, time, tour type,\nmessage"| P8_1
    D9 -->|"Property record"| P8_1
    D17 -->|"Listing agent record"| P8_1
    P8_1 -->|"Pending tour request"| D30
    P8_1 -->|"New tour notification"| D32
    P8_1 -->|"New tour notification"| D31
    P8_1 -->|"Tour request email\n(agent + client)"| E4

    E1 -->|"Acceptance decision"| P8_2
    E2 -->|"Acceptance decision"| P8_2
    D30 -->|"Pending tour request"| P8_2
    P8_2 -->|"Confirmed tour record"| D30
    P8_2 -->|"Tour confirmation email"| E4

    E1 -->|"Rejection / cancellation"| P8_3
    E2 -->|"Rejection / cancellation"| P8_3
    D30 -->|"Tour request record"| P8_3
    P8_3 -->|"Rejected / cancelled record"| D30
    P8_3 -->|"Rejection / cancellation email"| E4

    E1 -->|"Completion decision"| P8_4
    E2 -->|"Completion decision"| P8_4
    D30 -->|"Tour records"| P8_4
    P8_4 -->|"Completed / expired record"| D30
    P8_4 -->|"Tour completion email"| E4
```

---

### P9 — Notification Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    E4["E4: Email Service (SMTP)"]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    P9_1(("P9.1\nAdmin Notification\nManagement"))
    P9_2(("P9.2\nAgent Notification\nManagement"))
    P9_3(("P9.3\nEmail\nDispatch"))

    D31 -->|"Unread notifications"| P9_1
    P9_1 -->|"In-app notifications"| E1
    E1 -->|"Read / delete request"| P9_1
    P9_1 -->|"Updated notification records"| D31

    D32 -->|"Unread notifications"| P9_2
    P9_2 -->|"In-app notifications"| E2
    E2 -->|"Read / delete request"| P9_2
    P9_2 -->|"Updated notification records"| D32

    P9_3 -->|"Transactional emails"| E4
```

---

### P10 — Reports & Dashboard

```mermaid
flowchart LR
    E1["E1: Admin"]
    E2["E2: Agent"]
    D1[("D1: Accounts")]
    D5[("D5: Admin Logs")]
    D6[("D6: Agent Information")]
    D9[("D9: Properties")]
    D17[("D17: Property Log")]
    D18[("D18: Status Log")]
    D21[("D21: Finalized Sales")]
    D22[("D22: Agent Commissions")]
    D26[("D26: Finalized Rentals")]
    D27[("D27: Rental Payments")]
    D29[("D29: Rental Commissions")]
    D30[("D30: Tour Requests")]

    P10_1(("P10.1\nDashboard\nStatistics"))
    P10_2(("P10.2\nProperty\nReports"))
    P10_3(("P10.3\nSales & Rental\nReports"))
    P10_4(("P10.4\nAgent\nReports"))
    P10_5(("P10.5\nActivity\nLogs"))

    D1 -->|"Account records"| P10_1
    D9 -->|"Property records"| P10_1
    D21 -->|"Sales records"| P10_1
    D26 -->|"Rental records"| P10_1
    D30 -->|"Tour records"| P10_1
    P10_1 -->|"Dashboard statistics"| E1
    P10_1 -->|"Dashboard statistics"| E2

    D9 -->|"Property records"| P10_2
    P10_2 -->|"Property reports"| E1

    D21 -->|"Sale records"| P10_3
    D22 -->|"Sale commission records"| P10_3
    D26 -->|"Rental records"| P10_3
    D27 -->|"Payment records"| P10_3
    D29 -->|"Rental commission records"| P10_3
    P10_3 -->|"Sales and rental reports"| E1

    D6 -->|"Agent profile records"| P10_4
    D21 -->|"Agent sale records"| P10_4
    D26 -->|"Agent rental records"| P10_4
    P10_4 -->|"Agent performance reports"| E1

    D5 -->|"Admin log records"| P10_5
    D17 -->|"Property log records"| P10_5
    D18 -->|"Status log records"| P10_5
    P10_5 -->|"Activity log reports"| E1
```

---

### P11 — System Settings Management

```mermaid
flowchart LR
    E1["E1: Admin"]
    D8[("D8: Specializations")]
    D13[("D13: Amenities")]
    D14[("D14: Property Types")]

    P11_1(("P11.1\nAmenity\nManagement"))
    P11_2(("P11.2\nSpecialization\nManagement"))
    P11_3(("P11.3\nProperty Type\nManagement"))

    E1 -->|"Add / delete amenity"| P11_1
    D13 -->|"Existing amenity records"| P11_1
    P11_1 -->|"Updated amenity records"| D13

    E1 -->|"Add / delete specialization"| P11_2
    D8 -->|"Existing specialization records"| P11_2
    P11_2 -->|"Updated specialization records"| D8

    E1 -->|"Add / delete property type"| P11_3
    D14 -->|"Existing property type records"| P11_3
    P11_3 -->|"Updated property type records"| D14
```

---

### P12 — Public Browsing

```mermaid
flowchart LR
    E3["E3: Public User / Client"]
    D6[("D6: Agent Information")]
    D9[("D9: Properties")]
    D10[("D10: Property Images")]
    D11[("D11: Floor Images")]
    D12[("D12: Property Amenities")]
    D15[("D15: Rental Details")]

    P12_1(("P12.1\nProperty Search\n& Filter"))
    P12_2(("P12.2\nProperty Detail\nView"))
    P12_3(("P12.3\nAgent Profile\nView"))
    P12_4(("P12.4\nProperty\nInteraction"))

    E3 -->|"Search criteria,\nfilter parameters"| P12_1
    D9 -->|"Approved property records"| P12_1
    D10 -->|"Property image records"| P12_1
    P12_1 -->|"Search results,\nlisting data"| E3

    E3 -->|"Property page request"| P12_2
    D9 -->|"Property record"| P12_2
    D10 -->|"Featured image records"| P12_2
    D11 -->|"Floor plan records"| P12_2
    D12 -->|"Amenity records"| P12_2
    D15 -->|"Rental details"| P12_2
    D6 -->|"Agent profile record"| P12_2
    P12_2 -->|"Full property details"| E3

    E3 -->|"Agent profile request"| P12_3
    D6 -->|"Agent profile record"| P12_3
    D9 -->|"Agent property listings"| P12_3
    P12_3 -->|"Agent profile data,\nlistings"| E3

    E3 -->|"Property like,\npage view"| P12_4
    D9 -->|"Property record"| P12_4
    P12_4 -->|"Updated view count,\nlike count"| D9
```
