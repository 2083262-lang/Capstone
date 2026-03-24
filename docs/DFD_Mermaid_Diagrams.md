# Real Estate Management System — DFD Mermaid Diagrams

> **Project:** Capstone Real Estate System  
> **Version:** 1.0  
> **Date:** March 10, 2026  
> **Usage:** Copy any diagram's Mermaid code into draw.io (Extras → Edit Diagram → paste) or any Mermaid renderer.

---

## Table of Contents

1. [Context Diagram](#1-context-diagram)
2. [Level 0 DFD](#2-level-0-dfd)
3. [Level 1 DFD](#3-level-1-dfd)
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

## 1. Context Diagram

The Context Diagram shows the entire system as a single process (P0) and all external entity interactions.

```mermaid
flowchart LR
    P0(("P0\nReal Estate\nManagement\nSystem"))
    E1["E1: Admin"]
    E2["E2: Agent"]
    E3["E3: Public User / Client"]
    E4["E4: Email Service (SMTP)"]
    E5["E5: Cron Scheduler"]

    E1 -->|"Login credentials, 2FA code"| P0
    E1 -->|"Property data, approval decisions,\nsale/rental finalization,\ncommission payouts, system settings"| P0
    P0 -->|"Dashboard statistics, reports,\nin-app notifications"| E1
    P0 -->|"Agent applications, verification requests,\npayment records for review"| E1

    E2 -->|"Registration data, login credentials,\n2FA code, profile data"| P0
    E2 -->|"Property listings, sale/rental verifications\nwith documents, payment records,\ntour responses"| P0
    P0 -->|"Dashboard data, commission information,\nin-app notifications"| E2
    P0 -->|"Approval status updates,\nincoming tour requests"| E2

    E3 -->|"Search criteria, tour requests,\nproperty likes, page views"| P0
    P0 -->|"Property listings, agent profiles,\nsearch results, tour confirmations"| E3

    P0 -->|"2FA codes, tour emails,\napproval notices, payment notices,\nlease expiry warnings"| E4
    E4 -->|"Delivery status"| P0

    E5 -->|"Scheduled trigger\n(lease expiry check)"| P0
    P0 -->|"Lease expiry\nprocessing results"| E5
```

---

## 2. Level 0 DFD

The Level 0 DFD decomposes the system into 12 major processes (P1–P12), their interactions with external entities and data stores.

```mermaid
flowchart TB
    %% External Entities
    E1["E1: Admin"]
    E2["E2: Agent"]
    E3["E3: Public User / Client"]
    E4["E4: Email Service (SMTP)"]
    E5["E5: Cron Scheduler"]

    %% Processes
    P1(("P1\nAuth &\nAccess Control"))
    P2(("P2\nAgent Profile\nManagement"))
    P3(("P3\nProperty\nManagement"))
    P4(("P4\nSale\nManagement"))
    P5(("P5\nRental &\nLease Mgmt"))
    P6(("P6\nPayment\nProcessing"))
    P7(("P7\nCommission\nManagement"))
    P8(("P8\nTour\nScheduling"))
    P9(("P9\nNotification\nSystem"))
    P10(("P10\nReports &\nDashboard"))
    P11(("P11\nSystem\nSettings"))
    P12(("P12\nPublic\nBrowsing"))

    %% Data Stores
    D1[("D1: Accounts")]
    D3[("D3: 2FA Codes")]
    D5[("D5: Admin Logs")]
    D6[("D6: Agent Information")]
    D9[("D9: Properties")]
    D19[("D19: Sale Verifications")]
    D21[("D21: Finalized Sales")]
    D22[("D22: Agent Commissions")]
    D24[("D24: Rental Verifications")]
    D26[("D26: Finalized Rentals")]
    D27[("D27: Rental Payments")]
    D29[("D29: Rental Commissions")]
    D30[("D30: Tour Requests")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]

    %% P1 Authentication & Access Control
    E1 -->|"Login credentials, 2FA code"| P1
    E2 -->|"Registration data, login credentials, 2FA code"| P1
    P1 -->|"2FA verification code"| E4
    P1 <-->|"Account records"| D1
    P1 <-->|"2FA code records"| D3
    P1 -->|"Login/logout audit records"| D5

    %% P2 Agent Profile Management
    E2 -->|"Profile data, license, specializations"| P2
    E1 -->|"Approval decision"| P2
    P2 -->|"Approval notice email"| E4
    P2 <-->|"Agent profile records"| D6
    P2 -->|"Profile submission notice"| D31

    %% P3 Property Management
    E1 -->|"Property details, images, amenities"| P3
    E2 -->|"Property details, images, amenities"| P3
    P3 -->|"Approval email"| E4
    P3 <-->|"Property records"| D9
    P3 -->|"Submission notice"| D31
    P3 -->|"Approval notification"| D32

    %% P4 Sale Management
    E2 -->|"Sale price, buyer info, documents"| P4
    E1 -->|"Sale approval decision"| P4
    P4 -->|"Sale approval email"| E4
    P4 <-->|"Sale verification records"| D19
    P4 -->|"Finalized sale record"| D21
    P4 <-->|"Property status update"| D9
    P4 -->|"Sale commission data"| P7
    P4 -->|"Submission notice"| D31
    P4 -->|"Sale approval notification"| D32

    %% P5 Rental & Lease Management
    E2 -->|"Tenant info, lease terms, documents"| P5
    E1 -->|"Rental approval decision, commission rate"| P5
    E5 -->|"Lease expiry check signal"| P5
    P5 -->|"Lease emails"| E4
    P5 <-->|"Rental verification records"| D24
    P5 <-->|"Active lease records"| D26
    P5 <-->|"Property status update"| D9
    P5 -->|"Submission notice"| D31
    P5 -->|"Rental approval notification"| D32

    %% P6 Payment Processing
    E2 -->|"Payment amount, period, proof documents"| P6
    E1 -->|"Confirmation or rejection decision"| P6
    P6 -->|"Payment status email"| E4
    P6 <-->|"Payment records"| D27
    P6 -->|"Rental commission data"| P7
    P6 -->|"Payment submission notice"| D31
    P6 -->|"Payment notification"| D32

    %% P7 Commission Management
    E1 -->|"Payout details, proof documents"| P7
    P7 -->|"Commission payment email"| E4
    P7 <-->|"Sale commission records"| D22
    P7 <-->|"Rental commission records"| D29
    P7 -->|"Commission payment notification"| D32

    %% P8 Tour Scheduling
    E3 -->|"Client name, contact, preferred date, tour type"| P8
    E1 -->|"Tour accept/reject/complete decision"| P8
    E2 -->|"Tour accept/reject/complete decision"| P8
    P8 -->|"Tour status email"| E4
    P8 <-->|"Tour request records"| D30
    P8 -->|"New tour notification"| D31
    P8 -->|"New tour notification"| D32

    %% P9 Notification System
    P9 -->|"In-app notifications"| E1
    P9 -->|"In-app notifications"| E2
    P9 -->|"Transactional emails"| E4
    P9 <-->|"Admin notification records"| D31
    P9 <-->|"Agent notification records"| D32

    %% P10 Reports & Dashboard
    P10 -->|"Dashboard statistics, reports"| E1
    P10 -->|"Dashboard statistics"| E2
    D1 -->|"Account records"| P10
    D9 -->|"Property records"| P10
    D21 -->|"Sales records"| P10
    D26 -->|"Rental records"| P10
    D30 -->|"Tour records"| P10
    D5 -->|"Admin log records"| P10
    D22 -->|"Commission records"| P10
    D27 -->|"Payment records"| P10
    D29 -->|"Rental commission records"| P10

    %% P11 System Settings
    E1 -->|"Amenity, specialization, property type data"| P11

    %% P12 Public Browsing
    E3 -->|"Search criteria, property likes, page views"| P12
    P12 -->|"Property listings, agent profiles, search results"| E3
    D9 -->|"Approved property records"| P12
    D6 -->|"Agent profile records"| P12
```

---

## 3. Level 1 DFD

Each major process (P1–P12) is decomposed into its sub-processes below.

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
    P1_1 -->|"Account query"| D1
    D1 -->|"Account record"| P1_1
    P1_1 -->|"Role query"| D2
    D2 -->|"Role record"| P1_1
    D6 -->|"Agent profile status"| P1_1
    P1_1 -->|"Validated identity"| P1_3
    E2 -->|"Registration data"| P1_2
    P1_2 -->|"New account record"| D1
    P1_3 -->|"2FA code hash"| D3
    P1_3 -->|"2FA verification code"| E4
    P1_3 -->|"Code generation confirmation"| P1_4
    D3 -->|"Stored code hash"| P1_4
    P1_4 -->|"Consumed code record"| D3
    P1_4 -->|"Login audit record"| D5
    P1_4 -->|"Authenticated session"| P1_5
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
    P2_3(("P2.3\nApproval /\nRejection"))
    E2 -->|"Profile data, license, bio, specializations"| P2_1
    P2_1 -->|"Agent profile record"| D6
    P2_1 -->|"Specialization associations"| D7
    D8 -->|"Specialization lookup data"| P2_1
    D1 -->|"Account record"| P2_1
    P2_1 -->|"Profile submission notice"| D31
    P2_1 -->|"Submitted profile data"| P2_2
    E1 -->|"Agent application records"| P2_2
    P2_2 -->|"Review outcome"| P2_3
    P2_3 -->|"Updated approval status"| D6
    P2_3 -->|"Approval status log record"| D18
    P2_3 -->|"Approval notice email"| E4
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
    P3_1 -->|"Property creation log"| D17
    P3_1 -->|"Submission notice"| D31
    P3_1 -->|"Image data"| P3_4
    E1 -->|"Updated property data"| P3_2
    E2 -->|"Updated property data"| P3_2
    D9 -->|"Existing property record"| P3_2
    P3_2 -->|"Updated property record"| D9
    P3_2 -->|"Price change record"| D16
    P3_2 -->|"Property update log"| D17
    P3_2 -->|"Updated image data"| P3_4
    E1 -->|"Approval decision"| P3_3
    D9 -->|"Pending property record"| P3_3
    P3_3 -->|"Updated approval status"| D9
    P3_3 -->|"Price history record"| D16
    P3_3 -->|"Approval log entry"| D17
    P3_3 -->|"Status log record"| D18
    P3_3 -->|"Approval notification"| D32
    P3_3 -->|"Approval email"| E4
    P3_4 -->|"Featured image records"| D10
    P3_4 -->|"Floor plan image records"| D11
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
    D18[("D18: Status Log")]
    D19[("D19: Sale Verifications")]
    D20[("D20: Sale Verification Docs")]
    D21[("D21: Finalized Sales")]
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]
    P4_1(("P4.1\nSale Verification\nSubmission"))
    P4_2(("P4.2\nSale Approval /\nRejection"))
    P4_3(("P4.3\nSale\nFinalization"))
    E2 -->|"Sale price, buyer info, documents"| P4_1
    E1 -->|"Sale price, buyer info, documents"| P4_1
    D9 -->|"Property record"| P4_1
    P4_1 -->|"Sale verification record"| D19
    P4_1 -->|"Sale verification documents"| D20
    P4_1 -->|"Pending sold property status"| D9
    P4_1 -->|"Submission notice"| D31
    P4_1 -->|"Verification data"| P4_2
    E1 -->|"Approval decision"| P4_2
    D19 -->|"Sale verification record"| P4_2
    D20 -->|"Verification documents"| P4_2
    P4_2 -->|"Updated verification status"| D19
    P4_2 -->|"Verification outcome"| P4_3
    P4_3 -->|"Finalized sale record"| D21
    P4_3 -->|"Sold property status"| D9
    P4_3 -->|"Sold price record"| D16
    P4_3 -->|"Sale log entry"| D17
    P4_3 -->|"Status log entry"| D18
    P4_3 -->|"Sale commission data"| P7
    P4_3 -->|"Sale approval notification"| D32
    P4_3 -->|"Sale approval email"| E4
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
    E2 -->|"Tenant info, lease terms, documents"| P5_1
    E1 -->|"Tenant info, lease terms, documents"| P5_1
    D9 -->|"Property record"| P5_1
    D15 -->|"Rental details"| P5_1
    P5_1 -->|"Rental verification record"| D24
    P5_1 -->|"Rental verification documents"| D25
    P5_1 -->|"Submission notice"| D31
    P5_1 -->|"Verification data"| P5_2
    E1 -->|"Approval decision, commission rate"| P5_2
    D24 -->|"Verification record"| P5_2
    P5_2 -->|"Updated verification status"| D24
    P5_2 -->|"Active lease record"| D26
    P5_2 -->|"Rented property status"| D9
    P5_2 -->|"Rented price record"| D16
    P5_2 -->|"Rental log entry"| D17
    P5_2 -->|"Rental approval notification"| D32
    P5_2 -->|"Lease approval emails"| E4
    E2 -->|"Renewal request, new term, rent amount"| P5_3
    D26 -->|"Active lease record"| P5_3
    P5_3 -->|"Renewed lease record"| D26
    P5_3 -->|"Renewal notice emails"| E4
    E2 -->|"Termination request, reason"| P5_4
    D26 -->|"Active lease record"| P5_4
    P5_4 -->|"Terminated lease record"| D26
    P5_4 -->|"Unlocked property status"| D9
    P5_4 -->|"Termination notice emails"| E4
    E5 -->|"Lease expiry check signal"| P5_5
    D26 -->|"Active lease records"| P5_5
    P5_5 -->|"Expired lease records"| D26
    P5_5 -->|"Expiry warning notifications"| D32
    P5_5 -->|"Expiry warning notifications"| D31
    P5_5 -->|"Lease expiry warning emails"| E4
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
    D31[("D31: Admin Notifications")]
    D32[("D32: Agent Notifications")]
    P6_1(("P6.1\nPayment\nRecording"))
    P6_2(("P6.2\nPayment\nConfirmation"))
    P6_3(("P6.3\nPayment\nRejection"))
    E2 -->|"Payment amount, period, proof documents"| P6_1
    D26 -->|"Active lease record"| P6_1
    P6_1 -->|"Pending payment record"| D27
    P6_1 -->|"Payment proof documents"| D28
    P6_1 -->|"Payment submission notice"| D31
    P6_1 -->|"Payment record"| P6_2
    E1 -->|"Confirmation decision"| P6_2
    D27 -->|"Pending payment record"| P6_2
    P6_2 -->|"Confirmed payment record"| D27
    P6_2 -->|"Rental commission data"| P7
    P6_2 -->|"Confirmation notification"| D32
    P6_2 -->|"Payment confirmation email"| E4
    E1 -->|"Rejection decision, rejection notes"| P6_3
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
    P4(("P4\nSale\nManagement"))
    P6(("P6\nPayment\nProcessing"))
    D22[("D22: Agent Commissions")]
    D23[("D23: Commission Payment Logs")]
    D29[("D29: Rental Commissions")]
    D32[("D32: Agent Notifications")]
    P7_1(("P7.1\nSale Commission\nCalculation"))
    P7_2(("P7.2\nRental Commission\nCalculation"))
    P7_3(("P7.3\nCommission\nPayout"))
    P4 -->|"Sale finalization data"| P7_1
    P7_1 -->|"Sale commission record"| D22
    P6 -->|"Confirmed payment data"| P7_2
    P7_2 -->|"Rental commission record"| D29
    E1 -->|"Payout details, proof documents"| P7_3
    D22 -->|"Unpaid commission record"| P7_3
    D29 -->|"Unpaid commission record"| P7_3
    P7_3 -->|"Paid commission record"| D22
    P7_3 -->|"Paid rental commission record"| D29
    P7_3 -->|"Commission payment audit record"| D23
    P7_3 -->|"Commission payment notification"| D32
    P7_3 -->|"Commission payment email"| E4
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
    E3 -->|"Client name, contact, preferred date, tour type"| P8_1
    D9 -->|"Property record"| P8_1
    D17 -->|"Listing agent record"| P8_1
    P8_1 -->|"Pending tour request record"| D30
    P8_1 -->|"New tour notification"| D32
    P8_1 -->|"New tour notification"| D31
    P8_1 -->|"Tour request confirmation email"| E4
    E1 -->|"Acceptance decision"| P8_2
    E2 -->|"Acceptance decision"| P8_2
    D30 -->|"Pending tour request record"| P8_2
    P8_2 -->|"Confirmed tour record"| D30
    P8_2 -->|"Tour confirmation email"| E4
    E1 -->|"Rejection or cancellation decision"| P8_3
    E2 -->|"Rejection or cancellation decision"| P8_3
    D30 -->|"Tour request record"| P8_3
    P8_3 -->|"Rejected or cancelled tour record"| D30
    P8_3 -->|"Rejection or cancellation email"| E4
    E1 -->|"Completion decision"| P8_4
    E2 -->|"Completion decision"| P8_4
    D30 -->|"Tour records"| P8_4
    P8_4 -->|"Completed or expired tour record"| D30
    P8_4 -->|"Tour expiry email"| E4
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
    D31 -->|"Unread notification records"| P9_1
    P9_1 -->|"In-app notifications"| E1
    E1 -->|"Read or delete request"| P9_1
    P9_1 -->|"Updated notification records"| D31
    D32 -->|"Unread notification records"| P9_2
    P9_2 -->|"In-app notifications"| E2
    E2 -->|"Read or delete request"| P9_2
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
    D22 -->|"Commission records"| P10_3
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
    E1 -->|"Amenity names"| P11_1
    D13 -->|"Existing amenity records"| P11_1
    P11_1 -->|"Updated amenity records"| D13
    E1 -->|"Specialization names"| P11_2
    D8 -->|"Existing specialization records"| P11_2
    P11_2 -->|"Updated specialization records"| D8
    E1 -->|"Property type names"| P11_3
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
    P12_1(("P12.1\nProperty Search\n& Filter"))
    P12_2(("P12.2\nProperty Detail\nView"))
    P12_3(("P12.3\nAgent Profile\nView"))
    P12_4(("P12.4\nProperty\nInteraction"))
    E3 -->|"Search criteria, filter parameters"| P12_1
    D9 -->|"Approved property records"| P12_1
    D10 -->|"Property image records"| P12_1
    P12_1 -->|"Search results, listing data"| E3
    E3 -->|"Property page request"| P12_2
    D9 -->|"Property record"| P12_2
    D10 -->|"Featured image records"| P12_2
    D11 -->|"Floor plan image records"| P12_2
    D12 -->|"Property amenity records"| P12_2
    D6 -->|"Agent profile record"| P12_2
    P12_2 -->|"Full property details"| E3
    E3 -->|"Agent profile page request"| P12_3
    D6 -->|"Agent profile record"| P12_3
    D9 -->|"Agent property listings"| P12_3
    P12_3 -->|"Agent profile data, listings"| E3
    E3 -->|"Property like, page view"| P12_4
    D9 -->|"Property record"| P12_4
    P12_4 -->|"Updated view count, like count"| D9
```
