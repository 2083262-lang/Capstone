# Real Estate Management System — Data Flow Diagram (DFD) Blueprint

> **Project:** Capstone Real Estate System  
> **Version:** 1.0  
> **Date:** March 9, 2026  
> **Based on:** Deep analysis of all PHP source code, database schema (`realestatesystem`), and AJAX endpoints

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [External Entities](#2-external-entities)
3. [System Processes](#3-system-processes)
4. [Data Stores (Database Tables)](#4-data-stores-database-tables)
5. [Context Diagram](#5-context-diagram)
6. [Level 0 DFD — Detailed Breakdown](#6-level-0-dfd--detailed-breakdown)
7. [Detailed Data Flow Descriptions](#7-detailed-data-flow-descriptions)
8. [Notes on Key Data Flows](#8-notes-on-key-data-flows)

---

## 1. System Overview

The **Real Estate Management System** is a web-based platform built with PHP and MySQL (MariaDB) that manages the entire lifecycle of property listings — from creation and approval through to sale/rental finalization, commission tracking, and reporting. The system serves three categories of users (Admin, Agent, and Public User/Client) and integrates with an external email service for two-factor authentication and transactional notifications.

### Key Capabilities

| Module | Description |
|--------|-------------|
| **Authentication & 2FA** | Login, registration, email-based two-factor authentication, session management |
| **Property Management** | CRUD for property listings, image/floor plan uploads, amenity management, approval workflow |
| **Sale Management** | Sale verification submission, admin approval, finalized sale records |
| **Rental Management** | Rental verification, lease finalization, lease renewal/termination, auto-expiry |
| **Payment Processing** | Rental payment recording, admin confirmation/rejection, commission calculation |
| **Commission Tracking** | Sale and rental commissions, payment proof uploads, payout processing |
| **Tour Scheduling** | Public tour requests, agent/admin confirmation, conflict detection, completion tracking |
| **Notifications** | In-app notifications for admin and agent, email notifications for all parties |
| **Reporting** | Property, sales, rental, agent performance, tour, and activity log reports |
| **System Settings** | Admin management of amenities, specializations, and property types |

---

## 2. External Entities

External entities represent actors or systems outside the system boundary that interact with the application.

| ID | Entity | Description |
|----|--------|-------------|
| **E1** | **Admin** | System administrator. Manages all property listings (create, approve/reject), finalizes sales and rentals, confirms payments, processes commissions, reviews agent applications, manages system settings, and generates reports. Role = `admin` (role_id = 1). |
| **E2** | **Agent** | Licensed real estate agent. Registers, completes profile, submits property listings (pending approval), submits sale/rental verifications, records rental payments, manages tours, and views commissions. Role = `agent` (role_id = 2). |
| **E3** | **Public User / Client** | Unauthenticated visitor. Browses property listings, searches/filters properties, views property details, views agent profiles, submits tour requests, likes properties. No login required. |
| **E4** | **Email Service (SMTP)** | External SMTP server (configured via PHPMailer with STARTTLS on port 587). Delivers 2FA codes, tour confirmations, sale/rental approval notices, commission payment notifications, lease expiry warnings, and other transactional emails. |
| **E5** | **Cron Scheduler** | Server-level scheduled task that triggers `cron_lease_expiry_check.php` to auto-expire leases and send warning notifications. |

---

## 3. System Processes

These are the major internal processes (subsystems) that transform data within the system.

| ID | Process | Description |
|----|---------|-------------|
| **P1** | **Authentication & Access Control** | Handles login, registration, 2FA verification, session management, logout, and role-based routing. |
| **P2** | **Agent Profile Management** | Agent profile completion, admin review/approval of agent applications, profile updates. |
| **P3** | **Property Management** | Property CRUD (create, read, update), image/floor plan uploads, amenity associations, approval workflow (pending → approved/rejected). |
| **P4** | **Sale Management** | Sale verification submission, admin review and approval, finalized sale record creation. |
| **P5** | **Rental Management** | Rental verification submission, admin approval and lease finalization, lease renewal, termination, and automated expiry checking. |
| **P6** | **Payment Processing** | Rental payment recording by agents, admin confirmation/rejection, document uploads. |
| **P7** | **Commission Management** | Commission calculation (for both sales and rentals), admin payout processing with proof uploads. |
| **P8** | **Tour Scheduling** | Public tour request submission, agent/admin confirmation with conflict detection, completion/cancellation/expiration handling. |
| **P9** | **Notification System** | In-app notifications (admin and agent dashboards), email delivery for all transactional events. |
| **P10** | **Reporting & Analytics** | Dashboard statistics, property/sales/rental/agent/tour/activity reports. |
| **P11** | **System Settings** | Admin management of lookup tables: amenities, specializations, property types. |
| **P12** | **Public Property Browsing** | Search, filter, view property details, view agent profiles, like/view tracking. |

---

## 4. Data Stores (Database Tables)

| ID | Data Store | Table Name | Description |
|----|-----------|------------|-------------|
| **D1** | Accounts | `accounts` | User credentials (account_id, role_id, email, username, password_hash, is_active, two_factor_enabled) |
| **D2** | User Roles | `user_roles` | Role definitions (role_id: 1=admin, 2=agent) |
| **D3** | Two-Factor Codes | `two_factor_codes` | 2FA verification codes with bcrypt hash, expiry, attempt tracking |
| **D4** | Admin Information | `admin_information` | Admin profile (license, specialization, bio, profile picture) |
| **D5** | Admin Logs | `admin_logs` | Admin login/logout audit trail |
| **D6** | Agent Information | `agent_information` | Agent profile (license, experience, bio, profile_completed, is_approved) |
| **D7** | Agent Specializations | `agent_specializations` | Junction: agent ↔ specialization |
| **D8** | Specializations | `specializations` | Lookup: specialization names |
| **D9** | Properties | `property` | Property listings (address, type, price, status, approval_status, views, likes, is_locked) |
| **D10** | Property Images | `property_images` | Featured photos (PhotoURL, SortOrder) |
| **D11** | Property Floor Images | `property_floor_images` | Floor plan photos organized by floor number |
| **D12** | Property Amenities | `property_amenities` | Junction: property ↔ amenity |
| **D13** | Amenities | `amenities` | Lookup: amenity names |
| **D14** | Property Types | `property_types` | Lookup: property type names |
| **D15** | Rental Details | `rental_details` | Rental-specific data (monthly_rent, deposit, lease term, furnishing, available date) |
| **D16** | Price History | `price_history` | Property price change log (Listed, Price Change, Sold, Rented, etc.) |
| **D17** | Property Log | `property_log` | Full property audit trail (CREATED, UPDATED, SOLD, RENTED, etc.) |
| **D18** | Status Log | `status_log` | Approval/rejection audit trail for agents and properties |
| **D19** | Sale Verifications | `sale_verifications` | Sale verification requests (property, agent, buyer, price, status) |
| **D20** | Sale Verification Docs | `sale_verification_documents` | Supporting documents for sale verifications |
| **D21** | Finalized Sales | `finalized_sales` | Completed/approved sale records |
| **D22** | Agent Commissions | `agent_commissions` | Sale commission records (amount, percentage, status, payment proof) |
| **D23** | Commission Payment Logs | `commission_payment_logs` | Commission action audit trail |
| **D24** | Rental Verifications | `rental_verifications` | Rental verification requests (tenant, lease terms, status) |
| **D25** | Rental Verification Docs | `rental_verification_documents` | Supporting documents for rental verifications |
| **D26** | Finalized Rentals | `finalized_rentals` | Active/renewed/terminated/expired lease records |
| **D27** | Rental Payments | `rental_payments` | Monthly rent payment records (amount, period, status) |
| **D28** | Rental Payment Docs | `rental_payment_documents` | Proof of payment files |
| **D29** | Rental Commissions | `rental_commissions` | Rental commission records (linked to confirmed payments) |
| **D30** | Tour Requests | `tour_requests` | Tour scheduling records (property, client, date/time, status) |
| **D31** | Admin Notifications | `notifications` | Admin-facing notification records |
| **D32** | Agent Notifications | `agent_notifications` | Agent-facing notification records |

---

## 5. Context Diagram

The Context Diagram is the highest-level view of the system. It represents the entire **Real Estate Management System** as a single process and shows how it interacts with all external entities. No internal processes or data stores are shown — only the boundaries of the system and the data that crosses them.

```
                          ┌───────────────────────┐
                          │                       │
     Credentials,         │                       │         Search Criteria,
     Property Data,       │                       │         Tour Requests,
     Approvals,           │                       │         Likes / Views
     Commissions,         │                       │       ┌────────────────┐
     Settings             │                       │◀──────│  E3: Public    │
   ┌──────────────┐       │                       │──────▶│  User / Client │
   │  E1: Admin   │──────▶│                       │       └────────────────┘
   │              │◀──────│    REAL ESTATE         │         Property Listings,
   └──────────────┘       │    MANAGEMENT          │         Agent Profiles,
     Dashboard,           │    SYSTEM              │         Tour Confirmations
     Reports,             │                       │
     Notifications,       │         (P0)           │
     Agent Apps,          │                       │       ┌────────────────┐
     Verification         │                       │──────▶│  E4: Email     │
     Requests             │                       │◀──────│  Service       │
                          │                       │       │  (SMTP)        │
   ┌──────────────┐       │                       │       └────────────────┘
   │  E2: Agent   │──────▶│                       │         2FA Codes,
   │              │◀──────│                       │         Tour Emails,
   └──────────────┘       │                       │         Approval Notices,
     Registration,        │                       │         Payment Notices
     Login / 2FA,         │                       │
     Profile Data,        │                       │       ┌────────────────┐
     Property Listings,   │                       │◀──────│  E5: Cron      │
     Verifications,       │                       │       │  Scheduler     │
     Payments,            └───────────────────────┘       └────────────────┘
     Tour Responses                                         Scheduled Trigger
                            Dashboard,                      (Lease Expiry Check)
                            Commissions,
                            Approval Status,
                            Notifications
```

### Context Diagram — Summary of External Interactions

| External Entity | Data Flowing **Into** the System | Data Flowing **Out of** the System |
|----------------|----------------------------------|------------------------------------|
| **E1: Admin** | Login credentials, 2FA codes, property data, approval/rejection decisions, sale & rental finalizations, commission payouts, system settings | Dashboard statistics, reports, in-app notifications, agent applications, verification requests, payment records |
| **E2: Agent** | Registration data, login credentials, 2FA codes, profile information, property listings, sale & rental verifications with documents, payment records, tour responses | Dashboard data, commission information, approval status updates, in-app notifications, incoming tour requests |
| **E3: Public User / Client** | Search & filter criteria, tour requests, property likes, page views | Property listings, search results, agent profiles, tour confirmations |
| **E4: Email Service (SMTP)** | Delivery status (success/failure) | 2FA codes, tour confirmation/rejection emails, approval notices, payment notices, lease expiry warnings |
| **E5: Cron Scheduler** | Scheduled trigger for lease expiry check | Lease expiry processing results |

### Context Diagram — Detailed Data Flow Summary

The following table provides a more granular breakdown of each data flow crossing the system boundary, with assigned flow identifiers.

| Flow ID | From | To | Data Flow Description |
|---------|------|----|-----------------------|
| F0.1 | E1 (Admin) | System | Login credentials, 2FA code |
| F0.2 | System | E1 (Admin) | Dashboard statistics, reports, in-app notifications |
| F0.3 | E1 (Admin) | System | Property data, approval decisions, sale/rental finalization, commission payouts, system settings |
| F0.4 | System | E1 (Admin) | Agent applications, sale/rental verification requests, payment records for review |
| F0.5 | E2 (Agent) | System | Registration data, login credentials, 2FA code, profile data |
| F0.6 | System | E2 (Agent) | Dashboard data, commission information, in-app notifications |
| F0.7 | E2 (Agent) | System | Property listings, sale/rental verifications with documents, payment records, tour responses |
| F0.8 | System | E2 (Agent) | Approval status updates, incoming tour requests |
| F0.9 | E3 (User) | System | Search criteria, tour requests, property likes, page views |
| F0.10 | System | E3 (User) | Property listings, agent profiles, search results |
| F0.11 | System | E4 (Email) | 2FA codes, tour confirmation/rejection emails, approval notices, payment notices, lease expiry warnings |
| F0.12 | E4 (Email) | System | Delivery status (success/failure) |
| F0.13 | E5 (Cron) | System | Scheduled trigger for lease expiry check |
| F0.14 | System | E5 (Cron) | Lease expiry processing results |

---

## 6. Level 0 DFD — Detailed Breakdown

The Level 0 DFD is the first decomposition of the system. It breaks the single process from the Context Diagram into 12 major subsystems (P1–P12), showing the data flows between processes, data stores, and external entities.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                              SYSTEM BOUNDARY                                           │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P1: Auth &      │◀── Login Credentials, 2FA Code ──── E1 (Admin)                  │
│  │  Access Control   │◀── Registration, Login, 2FA ─────── E2 (Agent)                  │
│  │                  │──── 2FA Email ──────────────────────▶ E4 (Email)                 │
│  │  login.php       │                                                                  │
│  │  register.php    │◀──▶ D1 (Accounts)                                               │
│  │  send_2fa.php    │◀──▶ D2 (User Roles)                                             │
│  │  verify_2fa.php  │◀──▶ D3 (Two-Factor Codes)                                       │
│  │  logout.php      │────▶ D5 (Admin Logs)                                            │
│  └────────┬─────────┘                                                                  │
│           │ Session (account_id, role)                                                  │
│           ▼                                                                            │
│  ┌──────────────────┐                                                                  │
│  │  P2: Agent       │◀── Profile Data ────────────────── E2 (Agent)                    │
│  │  Profile Mgmt    │──── Approval request ───────────── E1 (Admin)                    │
│  │                  │──── Approval/Rejection Email ────▶ E4 (Email)                    │
│  │  agent_info_form │◀──▶ D1 (Accounts)                                               │
│  │  save_agent.php  │◀──▶ D6 (Agent Information)                                      │
│  │  review_agent    │◀──▶ D7 (Agent Specializations)                                  │
│  │  _details*.php   │◀──  D8 (Specializations)                                        │
│  │                  │────▶ D18 (Status Log)                                            │
│  │                  │────▶ D31 (Admin Notifications)                                   │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P3: Property    │◀── Property Data, Images ───────── E1 (Admin)                    │
│  │  Management      │◀── Property Data, Images ───────── E2 (Agent)                    │
│  │                  │──── Approval Email ─────────────▶ E4 (Email)                     │
│  │  save_property   │◀──▶ D9 (Properties)                                             │
│  │  add_property    │◀──▶ D10 (Property Images)                                       │
│  │  update_property │◀──▶ D11 (Floor Images)                                          │
│  │  view_property   │◀──▶ D12 (Property Amenities)                                    │
│  │                  │◀──  D13 (Amenities)                                              │
│  │                  │◀──  D14 (Property Types)                                         │
│  │                  │◀──▶ D15 (Rental Details)                                         │
│  │                  │────▶ D16 (Price History)                                         │
│  │                  │────▶ D17 (Property Log)                                          │
│  │                  │────▶ D18 (Status Log)                                            │
│  │                  │────▶ D31 (Admin Notifications)                                   │
│  │                  │────▶ D32 (Agent Notifications)                                   │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P4: Sale        │◀── Sale Verification + Docs ────── E2 (Agent) / E1 (Admin)       │
│  │  Management      │──── Approval Decision ───────────── E1 (Admin)                   │
│  │                  │──── Sale Approval Email ─────────▶ E4 (Email)                    │
│  │  mark_as_sold    │◀──▶ D9 (Properties)                                             │
│  │  admin_property  │◀──▶ D19 (Sale Verifications)                                    │
│  │  _sale_approvals │◀──▶ D20 (Sale Verification Docs)                                │
│  │  admin_finalize  │────▶ D21 (Finalized Sales)                                      │
│  │  _sale.php       │────▶ D16 (Price History)                                         │
│  │                  │────▶ D17 (Property Log)                                          │
│  │                  │────▶ D18 (Status Log)                                            │
│  │                  │────▶ D31 (Admin Notifications)                                   │
│  │                  │────▶ D32 (Agent Notifications)                                   │
│  │                  │                                                                  │
│  │                  │────▶ P7 (Commission Mgmt) ── triggers commission calculation     │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P5: Rental      │◀── Rental Verification + Docs ─── E2 (Agent) / E1 (Admin)       │
│  │  Management      │──── Approval Decision ──────────── E1 (Admin)                    │
│  │                  │──── Lease Emails ────────────────▶ E4 (Email)                    │
│  │                  │◀── Scheduled Trigger ──────────── E5 (Cron)                      │
│  │  mark_as_rented  │◀──▶ D9 (Properties)                                             │
│  │  admin_rental    │◀──▶ D24 (Rental Verifications)                                  │
│  │  _approvals      │◀──▶ D25 (Rental Verification Docs)                              │
│  │  admin_finalize  │────▶ D26 (Finalized Rentals)                                    │
│  │  _rental.php     │◀──▶ D15 (Rental Details)                                        │
│  │  renew_lease     │────▶ D16 (Price History)                                         │
│  │  terminate_lease │────▶ D17 (Property Log)                                          │
│  │  cron_lease      │────▶ D31 (Admin Notifications)                                   │
│  │  _expiry_check   │────▶ D32 (Agent Notifications)                                   │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P6: Payment     │◀── Payment Record + Docs ──────── E2 (Agent)                    │
│  │  Processing      │──── Confirm/Reject Decision ────── E1 (Admin)                    │
│  │                  │──── Payment Status Email ────────▶ E4 (Email)                    │
│  │  record_rental   │◀──▶ D27 (Rental Payments)                                       │
│  │  _payment.php    │◀──▶ D28 (Rental Payment Docs)                                   │
│  │  admin_confirm   │◀──  D26 (Finalized Rentals)                                     │
│  │  _rental_payment │────▶ D31 (Admin Notifications)                                   │
│  │  admin_reject    │────▶ D32 (Agent Notifications)                                   │
│  │  _rental_payment │                                                                  │
│  │                  │────▶ P7 (Commission Mgmt) ── triggers commission calculation     │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P7: Commission  │◀── Payout Decision + Proof ────── E1 (Admin)                    │
│  │  Management      │──── Commission Paid Email ───────▶ E4 (Email)                   │
│  │                  │◀── Sale finalization data ──────── P4 (Sale Mgmt)                │
│  │  process_comm    │◀── Payment confirmation data ──── P6 (Payment Proc.)             │
│  │  _payment.php    │◀──▶ D22 (Agent Commissions)                                     │
│  │  admin_finalize  │◀──▶ D29 (Rental Commissions)                                    │
│  │  _sale.php       │────▶ D23 (Commission Payment Logs)                               │
│  │                  │────▶ D31 (Admin Notifications)                                   │
│  │                  │────▶ D32 (Agent Notifications)                                   │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P8: Tour        │◀── Tour Request ────────────────── E3 (User)                     │
│  │  Scheduling      │──── Accept/Reject/Complete ──────── E1 (Admin) / E2 (Agent)      │
│  │                  │──── Tour Status Email ───────────▶ E4 (Email)                    │
│  │  request_tour    │◀──▶ D30 (Tour Requests)                                         │
│  │  _process.php    │◀──  D9 (Properties)                                              │
│  │  tour_request    │◀──  D17 (Property Log) ── to find listing agent                  │
│  │  _accept/reject  │────▶ D31 (Admin Notifications)                                   │
│  │  _complete/cancel│────▶ D32 (Agent Notifications)                                   │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P9: Notification│──── In-App Notifications ────────▶ E1 (Admin)                    │
│  │  System          │──── In-App Notifications ────────▶ E2 (Agent)                    │
│  │                  │──── Transactional Emails ────────▶ E4 (Email)                    │
│  │  admin_notif.php │◀──▶ D31 (Admin Notifications)                                   │
│  │  agent_notif.php │◀──▶ D32 (Agent Notifications)                                   │
│  │  mail_helper.php │                                                                  │
│  │  email_template  │         Receives events from:                                    │
│  │                  │         P1, P2, P3, P4, P5, P6, P7, P8                           │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P10: Reporting  │──── Reports & Statistics ─────────▶ E1 (Admin)                   │
│  │  & Analytics     │──── Dashboard Data ────────────────▶ E2 (Agent)                  │
│  │                  │                                                                  │
│  │  reports.php     │◀── D9, D21, D26, D27, D22, D29, D30 (Read-only)                 │
│  │  admin_dashboard │◀── D5 (Admin Logs), D17 (Property Log), D18 (Status Log)        │
│  │  agent_dashboard │◀── D1 (Accounts), D6 (Agent Info)                               │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P11: System     │◀── Settings Changes ───────────── E1 (Admin)                     │
│  │  Settings        │                                                                  │
│  │                  │◀──▶ D13 (Amenities)                                              │
│  │  admin_settings  │◀──▶ D8 (Specializations)                                        │
│  │  _api.php        │◀──▶ D14 (Property Types)                                        │
│  └──────────────────┘                                                                  │
│                                                                                        │
│  ┌──────────────────┐                                                                  │
│  │  P12: Public     │◀── Search/Filter/View/Like ────── E3 (User)                      │
│  │  Browsing        │──── Listings, Profiles ───────────▶ E3 (User)                    │
│  │                  │                                                                  │
│  │  index.php       │◀── D9 (Properties)                                               │
│  │  search_results  │◀── D10 (Property Images)                                        │
│  │  property_details│◀── D11 (Floor Images)                                            │
│  │  agents.php      │◀── D12 (Property Amenities)                                     │
│  │  agent_profile   │◀── D6 (Agent Information)                                       │
│  │  like_property   │──▶ D9 (Properties) ── update ViewsCount, Likes                  │
│  └──────────────────┘                                                                  │
│                                                                                        │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### Level 0 Data Flow Table

| Flow ID | From → To | Data Description |
|---------|-----------|------------------|
| **Authentication (P1)** | | |
| F1.1 | E1/E2 → P1 | Username + password (POST) |
| F1.2 | P1 → D1 | Query account by username; validate password_hash |
| F1.3 | P1 → D2 | Lookup role_name by role_id |
| F1.4 | P1 → D3 | Insert 2FA code_hash, check expiry and attempts |
| F1.5 | P1 → E4 | 6-digit 2FA code via email (STARTTLS) |
| F1.6 | P1 → D5 | Insert admin login/logout log entry |
| F1.7 | E2 → P1 | Registration data: name, email, phone, username, password |
| F1.8 | P1 → D1 | Insert new agent account (role_id=2) |
| **Agent Profile (P2)** | | |
| F2.1 | E2 → P2 | License number, experience, bio, specializations, profile picture |
| F2.2 | P2 → D6 | Insert/update agent_information (profile_completed=1) |
| F2.3 | P2 → D7 | Sync agent_specializations (delete old, insert new) |
| F2.4 | E1 → P2 | Approve/reject agent application |
| F2.5 | P2 → D6 | Update is_approved=1 or remain 0 |
| F2.6 | P2 → D18 | Insert status_log entry (agent approved/rejected) |
| F2.7 | P2 → D31 | Insert admin notification (agent profile submitted) |
| F2.8 | P2 → E4 | Agent approval/rejection email |
| **Property Management (P3)** | | |
| F3.1 | E1 → P3 | Property details (address, type, price, status, description, amenities) |
| F3.2 | E2 → P3 | Property details (same as admin, but approval_status=pending) |
| F3.3 | P3 → D9 | Insert/update property record |
| F3.4 | P3 → D10 | Insert featured images (uploaded files with MIME validation) |
| F3.5 | P3 → D11 | Insert floor plan images (organized by floor number) |
| F3.6 | P3 → D12 | Insert property_amenities associations |
| F3.7 | P3 → D15 | Insert rental_details (if listing_type=For Rent) |
| F3.8 | P3 → D16 | Insert price_history entry (Listed, Price Change) |
| F3.9 | P3 → D17 | Insert property_log (CREATED, UPDATED, APPROVED, REJECTED) |
| F3.10 | P3 → D18 | Insert status_log (property approved/rejected) |
| F3.11 | P3 → D32 | Insert agent notification (property approved/rejected) |
| F3.12 | P3 → E4 | Property approval/rejection email to agent |
| **Sale Management (P4)** | | |
| F4.1 | E2/E1 → P4 | Sale price, buyer name, buyer email, sale date, supporting documents |
| F4.2 | P4 → D19 | Insert sale_verifications (status=Pending) |
| F4.3 | P4 → D20 | Insert sale_verification_documents (uploaded proof files) |
| F4.4 | E1 → P4 | Approve/reject sale verification |
| F4.5 | P4 → D19 | Update sale_verifications status (Approved/Rejected) |
| F4.6 | P4 → D21 | Insert finalized_sales record (on approval) |
| F4.7 | P4 → D9 | Update property: Status=Sold, is_locked=1, sold_date, sold_by_agent |
| F4.8 | P4 → D16 | Insert price_history (event_type=Sold) |
| F4.9 | P4 → D17 | Insert property_log (action=SOLD) |
| F4.10 | P4 → P7 | Trigger commission calculation (sale_id, agent_id, commission_percentage) |
| F4.11 | P4 → D32 | Insert agent notification (sale_approved) |
| F4.12 | P4 → E4 | Sale approval email to agent |
| **Rental Management (P5)** | | |
| F5.1 | E2/E1 → P5 | Tenant info, monthly rent, deposit, lease start/term, documents |
| F5.2 | P5 → D24 | Insert rental_verifications (status=Pending) |
| F5.3 | P5 → D25 | Insert rental_verification_documents |
| F5.4 | E1 → P5 | Approve rental + set commission rate |
| F5.5 | P5 → D24 | Update rental_verifications status (Approved/Rejected) |
| F5.6 | P5 → D26 | Insert finalized_rentals (lease_status=Active) |
| F5.7 | P5 → D9 | Update property: Status=Rented, is_locked=1 |
| F5.8 | P5 → D16 | Insert price_history (event_type=Rented) |
| F5.9 | P5 → D17 | Insert property_log (action=RENTED) |
| F5.10 | E2 → P5 | Lease renewal request (new term, new rent) |
| F5.11 | P5 → D26 | Update finalized_rentals (lease_status=Renewed, new dates) |
| F5.12 | E2 → P5 | Lease termination request (reason) |
| F5.13 | P5 → D26 | Update finalized_rentals (lease_status=Terminated) |
| F5.14 | P5 → D9 | Unlock property: Status=For Rent, is_locked=0 (on termination) |
| F5.15 | E5 → P5 | Cron trigger for lease expiry check |
| F5.16 | P5 → D26 | Update lease_status=Expired (if past end date) |
| F5.17 | P5 → D32 | Agent notification (rental_approved, lease_expiring, lease_expired) |
| F5.18 | P5 → D31 | Admin notification (rental verification submitted, lease expired) |
| F5.19 | P5 → E4 | Lease approval/renewal/termination/expiry emails |
| **Payment Processing (P6)** | | |
| F6.1 | E2 → P6 | Payment amount, date, period, supporting documents |
| F6.2 | P6 → D27 | Insert rental_payments (status=Pending) |
| F6.3 | P6 → D28 | Insert rental_payment_documents |
| F6.4 | E1 → P6 | Confirm or reject payment |
| F6.5 | P6 → D27 | Update rental_payments status (Confirmed/Rejected) |
| F6.6 | P6 → P7 | Trigger rental commission calculation (on confirm) |
| F6.7 | P6 → D31 | Admin notification (rental_payment submitted) |
| F6.8 | P6 → D32 | Agent notification (payment confirmed/rejected) |
| F6.9 | P6 → E4 | Payment confirmation/rejection email to agent |
| **Commission Management (P7)** | | |
| F7.1 | P4 → P7 | Sale finalization data (sale_id, agent_id, sale_price, commission_%) |
| F7.2 | P7 → D22 | Insert agent_commissions (status=calculated) |
| F7.3 | P6 → P7 | Rental payment data (payment_id, rental_id, commission_rate) |
| F7.4 | P7 → D29 | Insert rental_commissions (status=calculated) |
| F7.5 | E1 → P7 | Payout: payment_method, reference, notes, proof file |
| F7.6 | P7 → D22 | Update agent_commissions (status=paid, proof_path) |
| F7.7 | P7 → D23 | Insert commission_payment_logs (audit trail) |
| F7.8 | P7 → D32 | Agent notification (commission_paid) |
| F7.9 | P7 → E4 | Commission payment email to agent |
| **Tour Scheduling (P8)** | | |
| F8.1 | E3 → P8 | Name, email, phone, preferred date/time, message, tour type |
| F8.2 | P8 → D30 | Insert tour_requests (status=Pending) |
| F8.3 | P8 → D17 | Read property_log to identify listing agent |
| F8.4 | E1/E2 → P8 | Accept/reject/cancel/complete tour |
| F8.5 | P8 → D30 | Update tour status (Confirmed/Rejected/Cancelled/Completed/Expired) |
| F8.6 | P8 → D31 | Admin notification (new tour request) |
| F8.7 | P8 → D32 | Agent notification (tour_new) |
| F8.8 | P8 → E4 | Tour confirmation/rejection/cancellation email to user |
| **Notification System (P9)** | | |
| F9.1 | P1–P8 → P9 | Event triggers (approval, payment, tour, etc.) |
| F9.2 | P9 → D31 | Write admin notifications |
| F9.3 | P9 → D32 | Write agent notifications |
| F9.4 | P9 → E4 | Send transactional email |
| F9.5 | P9 → E1 | Display admin notification feed (mark read, delete) |
| F9.6 | P9 → E2 | Display agent notification feed (mark read, delete) |
| **Reporting (P10)** | | |
| F10.1 | P10 → E1 | Admin dashboard KPIs, reports (property, sales, rental, agent, tour, logs) |
| F10.2 | P10 → E2 | Agent dashboard KPIs (listing stats, tour stats, commission stats) |
| F10.3 | D9,D21,D22,D26,D27,D29,D30… → P10 | Aggregated read queries across all data stores |
| **System Settings (P11)** | | |
| F11.1 | E1 → P11 | Add/delete amenity, specialization, or property type |
| F11.2 | P11 → D13 | Insert/delete amenities |
| F11.3 | P11 → D8 | Insert/delete specializations |
| F11.4 | P11 → D14 | Insert/delete property_types |
| **Public Browsing (P12)** | | |
| F12.1 | E3 → P12 | Search filters (city, type, price range, beds, baths, status) |
| F12.2 | P12 → E3 | Filtered property listing results with images |
| F12.3 | E3 → P12 | Property like action, page view |
| F12.4 | P12 → D9 | Increment ViewsCount/Likes on property record |
| F12.5 | P12 → E3 | Full property details, floor plans, amenities, agent info |
| F12.6 | P12 → E3 | Agent directory and individual agent profile pages |

---

## 7. Detailed Data Flow Descriptions

### 7.1 Authentication Flow (P1)

```
E1/E2 ──[username, password]──▶ login.php
                                    │
                                    ├──▶ D1 (accounts): SELECT by username, verify password_hash
                                    ├──▶ D2 (user_roles): JOIN to get role_name
                                    │
                                    │  [Agent only] Check D6: profile_completed? is_approved?
                                    │     - Not completed → redirect to agent_info_form.php
                                    │     - Not approved  → "pending approval" error
                                    │
                                    ▼
                              two_factor.php (display 2FA form)
                                    │
                                    ▼
                              send_2fa.php (AJAX POST)
                                    │
                                    ├──▶ D3: Rate limit check (5 codes/15min, 60s cooldown)
                                    ├──▶ D3: INSERT code_hash (bcrypt, expires in 5 min)
                                    ├──▶ E4: Send 6-digit code via email (PHPMailer/STARTTLS)
                                    │
                                    ▼
                              verify_2fa.php (AJAX POST)
                                    │
                                    ├──▶ D3: SELECT latest code, verify hash, check expiry
                                    ├──▶ D3: UPDATE consumed=1 on success
                                    ├──▶ D5: INSERT admin_logs (login) [admin only]
                                    │
                                    ▼
                              Session created: account_id, username, user_role, 2fa_verified_at
                                    │
                              Redirect to admin_dashboard.php or agent_dashboard.php
```

### 7.2 Property Lifecycle Flow (P3 → P4/P5)

```
CREATION:
  E2 (Agent) ──[property data, images]──▶ add_property_process.php
      │                                       │
      │                                       ├──▶ D9: INSERT property (approval_status='pending')
      │                                       ├──▶ D10: INSERT property_images
      │                                       ├──▶ D11: INSERT property_floor_images
      │                                       ├──▶ D12: INSERT property_amenities
      │                                       ├──▶ D15: INSERT rental_details (if For Rent)
      │                                       ├──▶ D17: INSERT property_log (CREATED)
      │                                       └──▶ D31: INSERT admin notification
      │
  E1 (Admin) ──[property data, images]──▶ save_property.php
                                              │
                                              └── Same as above but approval_status='approved' (live immediately)

APPROVAL:
  E1 (Admin) ──[approve/reject]──▶ view_property.php
      │
      ├──▶ D9: UPDATE approval_status='approved' or 'rejected'
      ├──▶ D16: INSERT price_history (Listed)
      ├──▶ D17: INSERT property_log (APPROVED/REJECTED)
      ├──▶ D18: INSERT status_log
      ├──▶ D32: INSERT agent notification (property_approved/rejected)
      └──▶ E4: Email notification to agent

SALE FLOW:
  E2/E1 ──[sale details, buyer info, docs]──▶ mark_as_sold_process.php
      │
      ├──▶ D19: INSERT sale_verifications (status='Pending')
      ├──▶ D20: INSERT sale_verification_documents
      ├──▶ D9: UPDATE property Status='Pending Sold'
      └──▶ D31: INSERT admin notification (sale verification submitted)
      │
  E1 ──[approve/reject]──▶ admin_property_sale_approvals.php → admin_finalize_sale.php
      │
      ├──▶ D19: UPDATE status='Approved'
      ├──▶ D21: INSERT finalized_sales
      ├──▶ D22: INSERT agent_commissions (status='calculated')
      ├──▶ D9: UPDATE Status='Sold', is_locked=1
      ├──▶ D16: INSERT price_history (Sold)
      ├──▶ D17: INSERT property_log (SOLD)
      └──▶ D32 + E4: Notify agent

RENTAL FLOW:
  E2/E1 ──[tenant info, lease terms, docs]──▶ mark_as_rented_process.php
      │
      ├──▶ D24: INSERT rental_verifications (status='Pending')
      ├──▶ D25: INSERT rental_verification_documents
      └──▶ D31: INSERT admin notification
      │
  E1 ──[approve + commission_rate]──▶ admin_finalize_rental.php
      │
      ├──▶ D24: UPDATE status='Approved'
      ├──▶ D26: INSERT finalized_rentals (lease_status='Active')
      ├──▶ D9: UPDATE Status='Rented', is_locked=1
      ├──▶ D16: INSERT price_history (Rented)
      ├──▶ D17: INSERT property_log (RENTED)
      └──▶ D32 + E4: Notify agent + tenant
```

### 7.3 Payment & Commission Flow (P6 → P7)

```
RENTAL PAYMENT:
  E2 (Agent) ──[amount, period, proof docs]──▶ record_rental_payment.php
      │
      ├──▶ D27: INSERT rental_payments (status='Pending')
      ├──▶ D28: INSERT rental_payment_documents
      └──▶ D31: INSERT admin notification (rental_payment)

  E1 (Admin) ──[confirm]──▶ admin_confirm_rental_payment.php
      │
      ├──▶ D27: UPDATE status='Confirmed', confirmed_by, confirmed_at
      ├──▶ D29: INSERT rental_commissions (commission = amount × rate / 100)
      ├──▶ D32: INSERT agent notification (rental_payment_confirmed)
      └──▶ E4: Email agent

  E1 (Admin) ──[reject + reason]──▶ admin_reject_rental_payment.php
      │
      ├──▶ D27: UPDATE status='Rejected', admin_notes
      ├──▶ D32: INSERT agent notification (rental_payment_rejected)
      └──▶ E4: Email agent

COMMISSION PAYOUT:
  E1 ──[method, reference, proof]──▶ process_commission_payment.php
      │
      ├──▶ D22/D29: UPDATE status='paid', payment_method, proof_path
      ├──▶ D23: INSERT commission_payment_logs (audit trail)
      ├──▶ D32: INSERT agent notification (commission_paid)
      └──▶ E4: Email agent
```

### 7.4 Tour Request Flow (P8)

```
  E3 (User) ──[name, email, phone, date, time, message]──▶ request_tour_process.php
      │
      ├──▶ D17: SELECT property_log (CREATED) → identify listing agent
      ├──▶ D30: INSERT tour_requests (status='Pending')
      ├──▶ D32: INSERT agent notification (tour_new)
      ├──▶ D31: INSERT admin notification (if admin-listed property)
      └──▶ E4: Email to agent + confirmation to user

  E1/E2 ──[accept]──▶ tour_request_accept.php
      │
      ├──▶ D30: Conflict detection (30-min buffer, private/public grouping)
      ├──▶ D30: UPDATE status='Confirmed', confirmed_at
      └──▶ E4: Confirmation email to user

  E1/E2 ──[reject/cancel/complete]──▶ tour_request_*.php
      │
      ├──▶ D30: UPDATE status='Rejected'/'Cancelled'/'Completed'
      └──▶ E4: Status email to user

  [Auto] Cron / On-page-load ──▶ tour_requests.php
      │
      ├──▶ D30: UPDATE status='Expired' (if tour datetime passed + still Pending)
      └──▶ E4: Expiry notice to user
```

---

## 8. Notes on Key Data Flows

### 8.1 Role-Based Data Isolation

| Data | Admin Access | Agent Access | Public Access |
|------|-------------|-------------|---------------|
| All properties | Full CRUD + approve/reject | Own properties only (via `property_log.account_id`) | Approved + non-locked only |
| Sale verifications | Review all | Submit own only | None |
| Rental verifications | Review all | Submit own only | None |
| Rental payments | Confirm/reject all | Record own only | None |
| Commissions | Process payouts | View own only | None |
| Tour requests | Manage admin-property tours | Manage own-property tours | Submit only |
| Notifications | Admin notification feed | Agent notification feed | None |
| Reports | Full access | Agent dashboard stats only | None |

### 8.2 Document File Storage Paths

| Document Type | Upload Path | Managed By |
|--------------|-------------|------------|
| Property featured photos | `uploads/prop_*.jpg` | P3 (Property Management) |
| Floor plan images | `uploads/floors/{property_id}/floor_{n}/` | P3 (Property Management) |
| Agent profile pictures | `uploads/agents/agent_*.jpg` | P2 (Agent Profile) |
| Admin profile pictures | `uploads/admins/admin_*.jpg` | Admin Profile |
| Sale verification docs | `sale_documents/{property_id}/` | P4 (Sale Management) |
| Rental verification docs | `rental_documents/{property_id}/` | P5 (Rental Management) |
| Rental payment proofs | `rental_payment_documents/{rental_id}/` | P6 (Payment Processing) |
| Commission payment proofs | `uploads/commission_proofs/{commission_id}/` | P7 (Commission Management) |

### 8.3 Notification Event Matrix

| Event Trigger | Admin Notification (D31) | Agent Notification (D32) | Email (E4) |
|--------------|--------------------------|--------------------------|------------|
| Agent profile submitted | Yes (request, high) | — | — |
| Agent approved/rejected | — | — | Yes (to agent) |
| Property submitted by agent | Yes (update, normal) | — | — |
| Property approved/rejected | — | Yes (property_approved/rejected) | Yes (to agent) |
| Sale verification submitted | Yes (update, high) | — | — |
| Sale approved | Yes (update, normal) | Yes (sale_approved) | Yes (to agent) |
| Sale rejected | — | Yes (sale_rejected) | Yes (to agent) |
| Commission calculated | Yes (update, normal) | — | — |
| Commission paid | Yes (update, normal) | Yes (commission_paid) | Yes (to agent) |
| Rental verification submitted | Yes (update, high) | — | — |
| Rental approved | Yes (update, normal) | Yes (rental_approved) | Yes (to agent + tenant) |
| Rental rejected | — | Yes (rental_rejected) | Yes (to agent) |
| Rental payment submitted | Yes (request, normal) | — | — |
| Rental payment confirmed | — | Yes (rental_payment_confirmed) | Yes (to agent) |
| Rental payment rejected | — | Yes (rental_payment_rejected) | Yes (to agent) |
| Lease expiring (30 days) | Yes (alert, high) | Yes (lease_expiring) | Yes (to agent) |
| Lease auto-expired | Yes (alert, high) | Yes (lease_expired) | — |
| Lease renewed | Yes (update, normal) | — | Yes (to agent + tenant) |
| Lease terminated | Yes (update, normal) | — | Yes (to agent + tenant) |
| New tour request | Yes (if admin property) | Yes (tour_new) | Yes (to agent + user confirmation) |
| Tour confirmed | — | — | Yes (to user) |
| Tour rejected/cancelled | — | — | Yes (to user) |
| Tour completed | — | Yes (tour_completed) | — |
| Tour auto-expired | — | — | Yes (to user) |

### 8.4 Key Database Relationships

```
user_roles (D2)
  └──< accounts (D1)
         ├──< admin_information (D4)
         ├──< admin_logs (D5)
         ├──< agent_information (D6) ──< agent_specializations (D7) >── specializations (D8)
         ├──< two_factor_codes (D3)
         └──< property_log (D17)

property (D9)
  ├──< property_images (D10)
  ├──< property_floor_images (D11)
  ├──< property_amenities (D12) >── amenities (D13)
  ├──< rental_details (D15)
  ├──< price_history (D16)
  ├──< property_log (D17)
  ├──< tour_requests (D30)
  ├──< sale_verifications (D19) ──< sale_verification_documents (D20)
  │     └──< finalized_sales (D21) ──< agent_commissions (D22) ──< commission_payment_logs (D23)
  └──< rental_verifications (D24) ──< rental_verification_documents (D25)
        └──< finalized_rentals (D26)
              └──< rental_payments (D27) ──< rental_payment_documents (D28)
                    └──< rental_commissions (D29)

notifications (D31) ── Admin-scoped (item_type: agent/property/tour/property_sale/property_rental/rental_payment)
agent_notifications (D32) ── Agent-scoped (notif_type: tour_new/property_approved/sale_approved/commission_paid/...)
status_log (D18) ── Cross-entity audit (item_type: agent/property)
```

### 8.5 Security Data Flows

| Security Mechanism | Data Flow |
|-------------------|-----------|
| **Password Hashing** | User password → `password_hash(PASSWORD_DEFAULT)` → D1.password_hash |
| **2FA Code Hashing** | 6-digit code → `password_hash()` → D3.code_hash; plain code → E4 (email only) |
| **CSRF Protection** | `bin2hex(random_bytes(32))` → session; validated via `hash_equals()` on every 2FA request |
| **Session Fixation Prevention** | `session_regenerate_id(true)` after 2FA verification |
| **Rate Limiting** | D3 tracks: 5 codes/15min, 60s cooldown, 15 failed attempts/15min lockout |
| **SQL Injection Prevention** | All queries use `mysqli::prepare()` with bound parameters |
| **File Upload Validation** | MIME type checked via `finfo_file()`, extension whitelisting, size limits |
| **Path Traversal Prevention** | `realpath()` validation on all file download endpoints |
| **Session Hardening** | HttpOnly cookies, SameSite=Lax, strict_mode, cookie-only session IDs |

### 8.6 AJAX/API Endpoints Summary

| Endpoint | Method | Auth | Purpose | Data Store(s) |
|----------|--------|------|---------|---------------|
| `send_2fa.php` | POST | Pending session | Generate & email 2FA code | D3 |
| `verify_2fa.php` | POST | Pending session | Validate 2FA code, establish session | D1, D3, D5 |
| `get_property_data.php` | GET | Admin | Fetch property + amenities for edit form | D9, D12, D13, D15 |
| `get_property_photos.php` | GET | Admin | Fetch property images | D10, D11 |
| `admin_settings_api.php` | POST | Admin | CRUD amenities/specializations/property types | D8, D13, D14 |
| `admin_notifications.php` | POST | Admin | Mark read, delete notifications | D31 |
| `admin_check_tour_conflict.php` | POST | Admin | Check scheduling conflicts | D30 |
| `agent_notifications_api.php` | POST | Agent | Mark read, delete notifications | D32 |
| `check_tour_conflict.php` | POST | Agent | Check tour scheduling conflicts | D30 |
| `increment_property_view.php` | POST | Public | Increment property view count | D9 |
| `like_property.php` | POST | Public | Toggle property like | D9 |
| `get_likes.php` | GET | Public | Get current like count | D9 |
| `request_tour_process.php` | POST | Public | Submit tour request | D30, D32, D31 |
| `user_pages/search_results.php?partial=grid` | GET | Public | AJAX property search/filter | D9, D10 |

---

> **End of DFD Blueprint Document**
>
> This document is generated from deep analysis of the actual project source code and database schema. All processes, data stores, data flows, and external entities accurately reflect the implemented system behavior as of March 2026.
