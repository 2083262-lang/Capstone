# HomeEstate Realty — User Manual

**Document Type:** Capstone Project User Manual  
**System Name:** HomeEstate Realty — Real Estate Property Management System  
**Version:** 1.0  
**Date:** April 2026  
**Prepared for:** Capstone Documentation  

---

## Table of Contents

1. [Introduction](#1-introduction)  
   1.1 [Overview of the System](#11-overview-of-the-system)  
   1.2 [Purpose of the Manual](#12-purpose-of-the-manual)  
   1.3 [Scope and Intended Users](#13-scope-and-intended-users)  
2. [System Overview](#2-system-overview)  
   2.1 [General Description](#21-general-description)  
   2.2 [System Modules](#22-system-modules)  
3. [System Requirements](#3-system-requirements)  
   3.1 [Hardware Requirements](#31-hardware-requirements)  
   3.2 [Software Requirements](#32-software-requirements)  
4. [User Roles and Access Levels](#4-user-roles-and-access-levels)  
   4.1 [Administrator](#41-administrator)  
   4.2 [Real Estate Agent](#42-real-estate-agent)  
   4.3 [Public User / Client](#43-public-user--client)  
5. [Getting Started](#5-getting-started)  
   5.1 [Accessing the System](#51-accessing-the-system)  
   5.2 [Agent Registration](#52-agent-registration)  
   5.3 [Logging In](#53-logging-in)  
   5.4 [Two-Factor Authentication (2FA)](#54-two-factor-authentication-2fa)  
   5.5 [Logging Out](#55-logging-out)  
6. [Administrator Guide](#6-administrator-guide)  
   6.1 [Admin Dashboard](#61-admin-dashboard)  
   6.2 [Navigation and Layout](#62-navigation-and-layout)  
   6.3 [Property Management](#63-property-management)  
   6.4 [Agent Management](#64-agent-management)  
   6.5 [Tour Request Management](#65-tour-request-management)  
   6.6 [Sale Approvals](#66-sale-approvals)  
   6.7 [Rental Approvals](#67-rental-approvals)  
   6.8 [Rental Payment Management](#68-rental-payment-management)  
   6.9 [Lease Management](#69-lease-management)  
   6.10 [Commission Processing](#610-commission-processing)  
   6.11 [Reports](#611-reports)  
   6.12 [Notifications](#612-notifications)  
   6.13 [System Settings](#613-system-settings)  
   6.14 [Admin Profile](#614-admin-profile)  
7. [Real Estate Agent Guide](#7-real-estate-agent-guide)  
   7.1 [Agent Dashboard](#71-agent-dashboard)  
   7.2 [Completing Your Agent Profile](#72-completing-your-agent-profile)  
   7.3 [Property Management](#73-property-management)  
   7.4 [Tour Request Management](#74-tour-request-management)  
   7.5 [Marking a Property as Sold](#75-marking-a-property-as-sold)  
   7.6 [Marking a Property as Rented](#76-marking-a-property-as-rented)  
   7.7 [Rental Payment Recording](#77-rental-payment-recording)  
   7.8 [Lease Management](#78-lease-management)  
   7.9 [Commission Tracking](#79-commission-tracking)  
   7.10 [Agent Notifications](#710-agent-notifications)  
8. [Public User / Client Guide](#8-public-user--client-guide)  
   8.1 [Home Page](#81-home-page)  
   8.2 [Browsing Properties](#82-browsing-properties)  
   8.3 [Viewing Property Details](#83-viewing-property-details)  
   8.4 [Requesting a Property Tour](#84-requesting-a-property-tour)  
   8.5 [Browsing Agents](#85-browsing-agents)  
   8.6 [Viewing an Agent Profile](#86-viewing-an-agent-profile)  
   8.7 [Liking a Property](#87-liking-a-property)  
   8.8 [About Page](#88-about-page)  
9. [Screens and Features Explanation](#9-screens-and-features-explanation)  
   9.1 [Common Interface Elements](#91-common-interface-elements)  
   9.2 [Status Badges and Indicators](#92-status-badges-and-indicators)  
   9.3 [Notification System](#93-notification-system)  
   9.4 [File Upload Components](#94-file-upload-components)  
   9.5 [Email Notifications](#95-email-notifications)  
10. [Error Handling and Troubleshooting](#10-error-handling-and-troubleshooting)  
    10.1 [Login Issues](#101-login-issues)  
    10.2 [Two-Factor Authentication Issues](#102-two-factor-authentication-issues)  
    10.3 [Session Timeout](#103-session-timeout)  
    10.4 [File Upload Errors](#104-file-upload-errors)  
    10.5 [Form Validation Errors](#105-form-validation-errors)  
    10.6 [Tour Request Conflicts](#106-tour-request-conflicts)  
    10.7 [General Troubleshooting](#107-general-troubleshooting)  
11. [Best Practices and Usage Tips](#11-best-practices-and-usage-tips)  
    11.1 [For Administrators](#111-for-administrators)  
    11.2 [For Agents](#112-for-agents)  
    11.3 [For Public Users](#113-for-public-users)  
    11.4 [Security Recommendations](#114-security-recommendations)  
12. [Glossary of Terms](#12-glossary-of-terms)  

---

## 1. Introduction

### 1.1 Overview of the System

HomeEstate Realty is a web-based real estate property management system designed to streamline the processes of listing, marketing, selling, and renting real estate properties. The system serves as a centralized platform that connects property administrators, licensed real estate agents, and prospective clients (buyers and tenants) in a unified digital environment.

The platform supports the complete property lifecycle — from initial listing creation by agents or administrators, through administrative approval, to the finalization of sales and rental agreements. It incorporates comprehensive features including property search and filtering, image gallery management, tour scheduling, rental payment tracking, agent commission processing, and automated email notifications.

HomeEstate Realty is developed using PHP and MySQL, and is deployed on an Apache web server environment (XAMPP). The system implements session-based authentication with two-factor authentication (2FA) via email-delivered one-time passwords (OTP), ensuring secure access for authorized personnel.

### 1.2 Purpose of the Manual

This User Manual serves as a comprehensive reference guide for all users of the HomeEstate Realty system. Its primary objectives are:

- To provide clear, step-by-step instructions for performing all system operations.
- To describe the functionality of each module, page, and interface component.
- To assist new users in understanding system workflows and navigation.
- To serve as a troubleshooting reference for common issues encountered during system use.
- To document the system in a format suitable for inclusion in capstone project documentation.

### 1.3 Scope and Intended Users

This manual covers all features and functionalities of the HomeEstate Realty system as deployed in version 1.0. The document is intended for three categories of users:

| User Role | Description |
|-----------|-------------|
| **Administrator** | The system administrator who manages the entire platform, including property approvals, agent management, sale and rental finalization, commission processing, and system configuration. |
| **Real Estate Agent** | Licensed real estate professionals who list properties, manage tours, process sales and rentals, record payments, and track commissions. |
| **Public User / Client** | Prospective buyers, tenants, or general visitors who browse property listings, view agent profiles, and request property tours without requiring authentication. |

---

## 2. System Overview

### 2.1 General Description

HomeEstate Realty operates as a three-tier web application consisting of a public-facing portal for property browsing, an agent portal for property management, and an administrative portal for oversight and approvals. The system enforces a structured approval workflow ensuring that all property listings, sales, and rental transactions are verified by the administrator before finalization.

Key architectural characteristics include:

- **Role-Based Access Control (RBAC):** Three distinct user roles (Admin, Agent, Public) with granular permission enforcement.
- **Approval-Based Workflow:** Properties submitted by agents require administrative approval before public visibility. Sale and rental transactions similarly require administrative review and finalization.
- **Two-Factor Authentication (2FA):** Mandatory for admin and agent logins to enhance security.
- **Email Notification System:** Automated notifications via PHPMailer for account events, approval statuses, tour confirmations, payment updates, and lease expiry alerts.
- **Session Security:** 30-minute inactivity timeout, secure cookie flags, session regeneration after authentication, and IP/User-Agent logging.

### 2.2 System Modules

The HomeEstate Realty system is organized into the following functional modules:

| Module | Description |
|--------|-------------|
| **Authentication Module** | Handles user login, registration, two-factor authentication, session management, and logout. |
| **Property Management Module** | Supports property listing creation, editing, approval, image and floor plan management, and status tracking (For Sale, For Rent, Sold, Rented). |
| **Agent Management Module** | Manages agent registration, profile completion, administrative approval/rejection, and agent specialization tracking. |
| **Tour Request Module** | Enables public users to schedule property tours; agents and administrators to accept, reject, complete, or cancel tour requests with conflict detection. |
| **Sales Processing Module** | Handles agent submission of sale verifications, administrative approval, finalization of sales, and recording of sale details. |
| **Rental Processing Module** | Manages rental verification submissions, administrative approval, lease creation, lease renewal, and lease termination. |
| **Rental Payment Module** | Tracks monthly rental payments submitted by agents with supporting documents, administrative confirmation/rejection, and commission calculation. |
| **Commission Module** | Calculates and tracks agent commissions from finalized sales and confirmed rental payments, supports payment processing with proof of payment uploads. |
| **Notification Module** | Dual notification system — admin-facing notifications for platform events and agent-facing notifications for property, tour, and commission updates. |
| **Reporting Module** | Comprehensive reporting dashboard with property, sales, rental, agent performance, and tour request reports with export capabilities. |
| **System Settings Module** | Administrative configuration of property types, amenities, and agent specializations. |

---

## 3. System Requirements

### 3.1 Hardware Requirements

The following minimum hardware specifications are recommended for users accessing the HomeEstate Realty system:

**For End Users (Client Access):**

| Component | Minimum Requirement |
|-----------|-------------------|
| Processor | Intel Core i3 or equivalent |
| RAM | 4 GB |
| Storage | 500 MB free disk space (for browser cache) |
| Display | 1366 × 768 resolution or higher |
| Network | Stable internet connection (minimum 5 Mbps) |

**For Server Deployment:**

| Component | Minimum Requirement |
|-----------|-------------------|
| Processor | Intel Core i5 or equivalent |
| RAM | 8 GB |
| Storage | 50 GB (for application files, uploads, and database) |
| Network | Dedicated IP address with stable broadband connection |

### 3.2 Software Requirements

**For End Users:**

| Software | Requirement |
|----------|-------------|
| Web Browser | Google Chrome (v100+), Mozilla Firefox (v100+), Microsoft Edge (v100+), or Safari (v15+) |
| JavaScript | Enabled |
| Cookies | Enabled |

**For Server Environment:**

| Software | Requirement |
|----------|-------------|
| Operating System | Windows 10/11 or Linux (Ubuntu 20.04+) |
| Web Server | Apache 2.4+ (XAMPP recommended for development) |
| PHP | Version 8.2 or higher |
| Database | MariaDB 10.4+ or MySQL 8.0+ |
| Mail Server | SMTP-compatible email service (Gmail, Outlook, or dedicated SMTP) |
| Additional Libraries | PHPMailer (included in system) |

---

## 4. User Roles and Access Levels

### 4.1 Administrator

The Administrator is the highest-level system user with full control over all platform operations. Administrator access is granted through the `accounts` table with `role_id = 1`.

**Permissions and Capabilities:**

- View and manage all property listings across all statuses
- Approve or reject agent-submitted property listings
- Approve or reject registered agents
- Add new properties and agents directly
- Manage tour requests for admin-listed properties
- Review, approve, or reject sale verifications
- Review, approve, or reject rental verifications
- Confirm or reject rental payment submissions
- Process and record commission payments to agents
- Manage active leases (view payment history)
- Access comprehensive reporting dashboard
- Configure system settings (property types, amenities, specializations)
- View and manage platform notifications
- View admin profile and account information

### 4.2 Real Estate Agent

Agents are licensed real estate professionals who register through the system and, upon administrative approval, gain access to the agent portal. Agent access is granted through the `accounts` table with `role_id = 2`.

**Permissions and Capabilities:**

- Complete and maintain agent profile (license, specializations, bio, photo)
- Create, edit, and manage property listings (subject to admin approval)
- Upload, reorder, and delete property images and floor plans
- Respond to tour requests (accept, reject, complete, cancel)
- Submit sale verifications for completed property sales
- Submit rental verifications for rented properties
- Record and submit monthly rental payments with supporting documents
- Manage active leases (renew or terminate)
- View earned commissions and download payment proof
- Receive and manage notifications

### 4.3 Public User / Client

Public users are visitors to the HomeEstate Realty platform who access the system without authentication. No registration is required for browsing.

**Permissions and Capabilities:**

- Browse the home page with featured properties and statistics
- Search and filter properties by city, type, price range, bedrooms, bathrooms, and other criteria
- View detailed property information, image galleries, floor plans, and amenities
- Request property tours by submitting a tour request form
- Browse and view profiles of approved agents
- Like properties (tracked via browser session/local storage)
- View the About page for company information

---

## 5. Getting Started

### 5.1 Accessing the System

To access the HomeEstate Realty system, open a supported web browser and navigate to the system URL. The default local development URL is:

```
http://localhost/capstoneSystem/user_pages/index.php
```

- **Public users** are immediately presented with the home page and do not require login.
- **Administrators** and **agents** must navigate to the login page to authenticate.

The login page is accessible at:

```
http://localhost/capstoneSystem/login.php
```

### 5.2 Agent Registration

New real estate agents can create an account through the self-service registration process.

**Step-by-Step Procedure:**

1. Navigate to the login page (`login.php`).
2. Click the **"Register as an agent"** link located below the login form.
3. The registration form is displayed. Complete all required fields:
   - **First Name** — Enter your legal first name.
   - **Middle Name** — (Optional) Enter your middle name.
   - **Last Name** — Enter your legal last name.
   - **Email Address** — Enter a valid email address. This will be used for two-factor authentication and system notifications.
   - **Phone Number** — Enter your Philippine mobile number in the format `09XXXXXXXXX`. The system normalizes it to `+639XXXXXXXXX`.
   - **Username** — Choose a unique username. The system validates uniqueness upon submission.
   - **Password** — Create a password with a minimum of 8 characters containing both letters and numbers.
   - **Confirm Password** — Re-enter the password to confirm.
4. Click the **"Register"** button to submit the form.
5. Upon successful registration, you are redirected to the login page with a confirmation message: *"Registration successful! Complete your profile after logging in."*
6. **Important:** After registration, you must log in and complete your agent profile before your account can be approved by the administrator.

### 5.3 Logging In

Administrators and agents use the same login page to access the system.

**Step-by-Step Procedure:**

1. Navigate to `login.php`.
2. Enter your **Username** in the username field.
3. Enter your **Password** in the password field. Use the eye icon to toggle password visibility if needed.
4. Click the **"Login"** button.
5. The system verifies your credentials:
   - If the credentials are correct, you are redirected to the Two-Factor Authentication (2FA) page.
   - If the credentials are incorrect, an error message is displayed: *"Invalid username or password."*
   - If the agent account has not completed profile setup, a message is displayed: *"Please complete your profile first."*
   - If the agent account is pending approval, a message is displayed: *"Your account is pending admin approval."*

### 5.4 Two-Factor Authentication (2FA)

After successful credential verification, the system requires a one-time password (OTP) for additional security.

**Step-by-Step Procedure:**

1. Upon successful login, you are redirected to the 2FA page (`two_factor.php`).
2. The system automatically sends a 6-digit OTP code to the email address associated with your account.
3. The page displays a masked version of your email (e.g., `k***@***.com`) and a code input field.
4. Open your email inbox and locate the OTP email from HomeEstate Realty.
5. Enter the 6-digit code in the input field.
6. Click the **"Verify"** button.
7. Upon successful verification:
   - **Administrators** are redirected to the Admin Dashboard (`admin_dashboard.php`).
   - **Agents** are redirected to the Agent Dashboard (`agent_pages/agent_dashboard.php`).

**Important Notes:**

- The OTP code expires after **10 minutes**. If the code expires, click **"Resend Code"** to receive a new OTP.
- You are allowed a maximum of **3 attempts** per OTP code. After exceeding attempts, request a new code.
- A countdown timer displays the remaining time before you may request a resend.
- If the pending login session exceeds 10 minutes of inactivity, you are redirected to the login page.

### 5.5 Logging Out

**For Administrators:**

1. Click your profile name or avatar in the top-right corner of the navigation bar.
2. Click **"Logout"** from the dropdown menu.
3. A confirmation modal appears asking: *"Are you sure you want to sign out?"*
4. Click **"Confirm"** to log out. You are redirected to the login page.

**For Agents:**

1. Click your profile name or avatar in the top navigation bar.
2. Click **"Logout"** from the dropdown.
3. Confirm the logout action in the modal dialog.
4. You are redirected to the login page.

**Automatic Session Timeout:**

- The system automatically logs out users after **30 minutes** of inactivity.
- A notification is displayed upon next access: *"Session expired due to inactivity. Please log in again."*
- Background notification polling does not reset the inactivity timer.

---

## 6. Administrator Guide

### 6.1 Admin Dashboard

The Admin Dashboard serves as the primary landing page after administrator login. It provides a comprehensive overview of platform activity and key performance metrics.

**Accessing the Dashboard:**  
Navigate to `admin_dashboard.php` or click **"Dashboard"** in the sidebar navigation.

**Dashboard Sections:**

1. **Welcome Banner** — Displays a personalized greeting: *"Welcome Back, [First Name]."* A profile completion alert appears if the admin profile is incomplete.

2. **Key Performance Indicator (KPI) Cards** — A grid of statistics cards displaying:
   - Total Properties, Approved, Pending, and Rejected counts
   - Sold Properties, Pending Sold, For Rent, and For Sale counts
   - Total Agents, Approved Agents, and Pending Agents
   - Tour Request counts by status (Pending, Confirmed, Completed, Cancelled)
   - Financial Metrics: Total Property Value, Average Property Value, Total Sold Value

3. **Property Highlights** — Cards showing the highest-priced property, the most-viewed property, and aggregate totals for views and likes.

4. **Dashboard Charts** (4 charts):
   - **30-Day Listings Trend** — Line chart showing new listings over the past 30 days.
   - **30-Day Tour Requests Trend** — Line chart for tour requests on admin-listed properties.
   - **Property Type Distribution** — Pie/doughnut chart of property types across all listings.
   - **Property Status Distribution** — Chart showing the proportion of For Sale, For Rent, Sold, and Pending Sold properties.

5. **Recent Activity Timeline** — Displays the 8 most recent platform actions, including agent approvals, property creations, and listing updates with timestamps.

6. **Pending Approvals Preview** — Cards listing up to 5 items each for:
   - Pending Sale Verifications
   - Pending Agent Approvals
   - Pending Property Listings

7. **Top Agents by Sales** — A ranked card showing the top 5 agents by number of completed sales and total sales revenue.

8. **Upcoming Tours** — Lists the next 6 confirmed tour appointments with date, time, visitor name, property address, and assigned agent.

9. **Rental Metrics** — Summary indicators for Pending Rentals, Active Leases, Pending Rental Payments, and Total Rental Revenue.

### 6.2 Navigation and Layout

The administrator interface consists of three navigation components:

**Sidebar Navigation (Left Panel):**

The sidebar is fixed on the left side of the screen (290px width) with a dark gradient background. It contains the following menu items:

| Menu Item | Destination | Icon |
|-----------|-------------|------|
| Dashboard | `admin_dashboard.php` | Dashboard monitor |
| Properties | `property.php` | Apartment |
| Agents | `agent.php` | Employees |
| Tour Requests | `tour_requests.php` | Map marker |
| Sale Approvals | `admin_property_sale_approvals.php` | Sold house |
| Rental Approvals | `admin_rental_approvals.php` | House with checkmark |
| Rental Payments | `admin_rental_payments.php` | Cash stack |
| Notifications | `admin_notifications.php` | Bell |
| Reports | `reports.php` | Chart/pie |

The active page is highlighted with a gold background accent. A logout button is positioned at the bottom of the sidebar.

**Top Navigation Bar:**

A sticky horizontal bar at the top of each page containing:
- **Left:** HomeEstate Realty branding/logo.
- **Right:** Notification bell icon with unread count badge, admin profile dropdown with links to Profile, Settings, and Logout.

**Notification Bell Dropdown:**

Clicking the bell icon displays the 5 most recent notifications, prioritized by unread status. Each notification links to the relevant review page. A **"View All Notifications"** link directs to the full Notifications Center.

### 6.3 Property Management

**Accessing Property Management:**  
Click **"Properties"** in the sidebar or navigate to `property.php`.

**Page Layout:**

The Property Management page organizes all property listings into tabbed categories:

| Tab | Description |
|-----|-------------|
| Pending Properties | Properties awaiting admin approval |
| Approved Properties | Properties that are live and visible to the public |
| Rejected Properties | Properties that were rejected during review |
| Pending Sold | Properties with submitted sale verifications awaiting finalization |
| Sold Properties | Properties with finalized sale records |
| Rented Properties | Properties with active rental agreements |

**Filter Options:**

A collapsible filter drawer provides:
- **Property Type** — Dropdown to filter by property type (House, Condominium, Townhouse, etc.)
- **Posted By** — Filter by the agent or admin who created the listing.
- **Price Range** — Minimum and maximum price inputs.

Click **"Apply Filters"** to update results or **"Clear Filters"** to reset.

**Property Cards:**

Each property is displayed as a card containing:
- Property thumbnail image (first image by sort order)
- Property type badge
- Street address and city
- Listing price (formatted as Philippine Peso)
- Bed, bath, and square footage details
- Name of the poster (admin or agent)
- Approval status badge

**Approving a Property:**

1. Navigate to the **Pending Properties** tab.
2. Locate the property to review.
3. Click **"View Details"** to review the property information, images, and amenities.
4. Click the **"Approve"** button.
5. The property status changes to "Approved" and becomes visible to public users.
6. The posting agent receives an email and in-system notification confirming the approval.

**Rejecting a Property:**

1. Navigate to the **Pending Properties** tab.
2. Click the **"Reject"** button on the target property card.
3. A modal dialog appears requesting a rejection reason.
4. Enter the reason for rejection in the text field.
5. Click **"Confirm Rejection."**
6. The property is moved to the Rejected Properties tab, and the agent is notified.

**Adding a New Property (Admin Direct Listing):**

1. Click the **"Add New Property"** button at the top of the page.
2. Complete the property form with the following sections:

   **Basic Information:**
   - Street Address (max 255 characters)
   - City
   - Barangay
   - Province
   - ZIP Code (4-digit Philippine format)

   **Property Details:**
   - Property Type (dropdown: House, Condo, Townhouse, Multi-Family, Lot, etc.)
   - Year Built
   - Square Footage and Lot Size
   - Number of Bedrooms and Bathrooms
   - Parking Type (Covered, Uncovered, None)

   **Listing Configuration:**
   - Status (For Sale or For Rent)
   - Listing Price (currency format)
   - Listing Date (defaults to today)

   **Rental-Specific Fields** (displayed only when Status is "For Rent"):
   - Monthly Rent
   - Security Deposit
   - Lease Term (months)
   - Furnishing (Furnished, Unfurnished, Partially Furnished)
   - Available From date

   **Amenities:**
   - Select applicable amenities from a checklist (Swimming Pool, Garage, Fenced Yard, Air Conditioning, Fireplace, etc.)

   **Media Upload:**
   - Property Images: Upload up to multiple images (max 25 MB each, JPEG/PNG/GIF). Images can be drag-and-drop reordered.
   - Floor Plans: Upload floor plan images organized by floor number.

   **Description:**
   - Property description text (max 2,000 characters)

3. Click **"Save Property"** to submit. Admin-created properties are visible to the public immediately.

### 6.4 Agent Management

**Accessing Agent Management:**  
Click **"Agents"** in the sidebar or navigate to `agent.php`.

**Page Layout:**

Agent records are organized into the following tabs:

| Tab | Description |
|-----|-------------|
| Pending Approval | Agents who completed their profile and await admin review |
| Approved Agents | Active agents with full system access |
| Needs Profile Completion | Agents who registered but have not completed their profile |
| Rejected Agents | Agents whose applications were rejected |

**Filter Options:**
- Specialization filter (multi-select)
- License number search
- Status filter

**Agent Cards:**

Each agent card displays:
- Profile picture (or default avatar)
- Full name, email, phone number
- License number
- Specializations (as tags)
- Years of experience
- Registration date
- Approval status badge

**Approving an Agent:**

1. Navigate to the **Pending Approval** tab.
2. Click **"View Profile"** on the agent card to review the agent's details.
3. The Agent Review page (`review_agent_details.php`) displays:
   - Full profile information (name, license, specializations, biography, experience)
   - Profile picture
   - Contact information
4. Click **"Approve"** to activate the agent's account.
5. The agent receives an approval notification via email and an in-system notification confirming their active status.

**Rejecting an Agent:**

1. From the Pending Approval tab, click the **"Reject"** button on the agent card.
2. A modal dialog appears requesting a rejection reason.
3. Enter the reason and click **"Confirm Rejection."**
4. The agent is notified via email with the rejection reason.

**Adding an Agent (Admin-Created):**

1. Click the **"Add New Agent"** button.
2. Complete the agent creation form:
   - **Account Information:** First Name, Middle Name (optional), Last Name, Email, Phone Number, Username, Password, Confirm Password.
   - **Professional Information:** License Number (5–30 characters, alphanumeric), Years of Experience (0–99).
   - **Specializations:** Select at least one from the checklist.
   - **Biography:** Agent's professional bio (max 2,000 characters).
   - **Profile Picture:** Upload an image (max 2 MB, JPEG/PNG/GIF/WEBP).
3. Click **"Create Agent Account"** to submit.

### 6.5 Tour Request Management

**Accessing Tour Requests:**  
Click **"Tour Requests"** in the sidebar or navigate to `tour_requests.php`.

This page manages tour requests for properties listed by the administrator. Tour requests for agent-listed properties are managed by the respective agents.

**Page Layout:**

Tour requests are organized by status tabs:

| Tab | Description |
|-----|-------------|
| All | All tour requests regardless of status |
| Pending | New requests awaiting response |
| Confirmed | Accepted tours scheduled for a future date |
| Completed | Tours that have been conducted |
| Cancelled | Tours cancelled by the admin |
| Rejected | Tours rejected by the admin |
| Expired | Tours that were not responded to before the scheduled date |

**Property Filter:** A dropdown filter allows narrowing results to a specific admin-listed property.

**Tour Request Cards:**

Each tour request card displays:
- Visitor's name, email, phone, and message
- Tour date and time
- Tour type badge (Public or Private)
- Property address with link to details
- Current status badge with timestamps

**Accepting a Tour Request:**

1. Navigate to the **Pending** tab.
2. Click the **"Accept"** button on the tour request card.
3. The system performs an automatic conflict check:
   - Checks for exact time conflicts with other confirmed tours on the same property.
   - Enforces a 30-minute travel buffer between tours at different physical locations.
4. If no conflicts are detected, the tour is confirmed, and the visitor receives a confirmation email.
5. If a conflict is detected, the admin is warned and may proceed with caution or reject the request.

**Completing a Tour:**

1. Navigate to the **Confirmed** tab.
2. After the tour has been conducted, click the **"Complete"** button.
3. The tour status changes to "Completed" with a timestamp.

**Rejecting or Cancelling a Tour:**

1. Click the **"Reject"** or **"Cancel"** button on the respective tour card.
2. Enter a reason in the modal dialog that appears.
3. Click **"Confirm."** The visitor receives an email notification with the reason.

**Auto-Expiration:**

Tour requests that remain in "Pending" status past the scheduled tour date and time are automatically marked as "Expired" when the page loads. The visitor receives an expiration notification email.

### 6.6 Sale Approvals

**Accessing Sale Approvals:**  
Click **"Sale Approvals"** in the sidebar or navigate to `admin_property_sale_approvals.php`.

**Page Layout:**

Sale verifications submitted by agents are organized by status:

| Tab | Description |
|-----|-------------|
| All | All sale verification records |
| Pending | Verifications awaiting admin review |
| Approved | Approved and finalized sales |
| Rejected | Rejected sale submissions |

**KPI Cards:** Display the count of pending sales and total pending sale value.

**Sale Verification Cards:**

Each card displays:
- Property image thumbnail and address
- Agent name and contact
- Sale price (formatted)
- Buyer name and email
- Sale date
- Verification status badge
- Submission timestamp
- Supporting documents (with download links)

**Approving a Sale:**

1. Navigate to the **Pending** tab.
2. Review the sale verification details and supporting documents.
3. Click the **"Approve"** button.
4. Confirm the action in the modal dialog.
5. The system executes the following operations within a database transaction:
   - Updates the verification status to "Approved."
   - Updates the property status to "Sold" and locks it from further modifications.
   - Creates a finalized sale record with buyer and price details.
   - Creates a price history entry recording the sold price.
   - Sends email notifications to the agent and buyer (if email provided).
   - Creates an agent notification confirming the sale approval.
6. **Commission creation occurs separately** — see Section 6.10.

**Rejecting a Sale:**

1. Click the **"Reject"** button on the pending verification.
2. Enter a rejection reason in the modal dialog.
3. Click **"Confirm Rejection."** The property status reverts to its pre-sale state, and the agent is notified.

### 6.7 Rental Approvals

**Accessing Rental Approvals:**  
Click **"Rental Approvals"** in the sidebar or navigate to `admin_rental_approvals.php`.

**Page Layout:**

Rental verifications are organized by status tabs (All, Pending, Approved, Rejected).

**Rental Verification Cards:**

Each card displays:
- Property image and address
- Agent information
- Monthly rent and security deposit
- Lease term (months)
- Tenant name and contact details
- Verification status badge
- Supporting documents with download links

**Approving a Rental (Finalizing a Lease):**

1. Navigate to the **Pending** tab.
2. Review the rental verification details and attached documents.
3. Click the **"Approve"** button.
4. In the approval modal:
   - Review the lease details (start date, term, monthly rent).
   - Enter the **Commission Rate** (percentage per confirmed rental payment). This field determines the agent's commission on each confirmed monthly payment.
   - Optionally, enter admin notes.
5. Click **"Finalize Rental."**
6. The system:
   - Updates the verification status to "Approved."
   - Creates a finalized rental record with lease dates, tenant information, and commission rate.
   - The lease end date is automatically calculated as: `lease_start_date + lease_term_months`.
   - Sets the lease status to "Active."
   - Sends notification emails to the agent and tenant.
   - Creates an agent notification confirming the rental approval.

**Rejecting a Rental:**

1. Click **"Reject"** and enter a rejection reason.
2. The agent is notified, and the property becomes available for new rental submissions.

### 6.8 Rental Payment Management

**Accessing Rental Payments:**  
Click **"Rental Payments"** in the sidebar or navigate to `admin_rental_payments.php`.

**Page Layout:**

The page is divided into two main sections:

**Section 1 — Active Rental Leases:**
- KPI cards for Active Leases count, Pending Payments count, and Total Confirmed Revenue.
- A paginated grid of lease cards, each showing the property address, tenant name, lease dates, monthly rent, agent name, lease status, and payment/revenue summaries.
- Click **"View Payment History"** to access detailed lease management.

**Section 2 — Recent Payment Submissions:**
- Tabs for All, Pending, Confirmed, and Rejected payments.
- Each payment card displays the property address, tenant name, payment amount, payment period (start to end date), submission date, agent name, commission information, and supporting document count.

**Confirming a Rental Payment:**

1. Navigate to the **Pending** tab in the payment submissions section.
2. Review the payment details, including the amount, payment period, and supporting documents.
3. Click the **"Confirm"** button.
4. The system:
   - Updates the payment status to "Confirmed."
   - If the lease has a commission rate greater than 0%, a rental commission record is automatically created.
   - Commission amount = `payment_amount × (commission_rate / 100)`.
   - The agent receives a notification confirming the payment.

**Rejecting a Rental Payment:**

1. Click the **"Reject"** button.
2. Enter admin notes or rejection reason in the modal (optional).
3. Click **"Confirm Rejection."** The agent is notified.

### 6.9 Lease Management

**Accessing Lease Management:**  
From the Rental Payments page, click **"View Payment History"** on a specific lease card. This navigates to `admin_lease_management.php`.

**Page Layout:**

1. **Hero Section** — Displays the property image as a background with the property address, monthly rent, and lease status overlaid.

2. **Lease Details Card:**
   - Tenant name, email, and phone
   - Lease start and end dates
   - Lease term (months)
   - Commission rate (%)
   - Lease status badge (Active, Renewed, Terminated, Expired)
   - Agent information (name, profile picture, email)

3. **Payment History:**
   - KPI statistics: Confirmed Payments Count, Pending Payments Count, Rejected Payments Count, Total Revenue, Total Commission Earned.
   - A table of all payment records with columns for period dates, payment status, payment amount, commission earned, and commission status.

### 6.10 Commission Processing

Commission records are created when the administrator approves a sale verification or when a rental payment is confirmed with a commission rate.

**For Sale Commissions:**

1. After approving a sale verification (Section 6.6), navigate to the finalized sale record.
2. The commission details are displayed:
   - Commission percentage, commission amount, agent name, sale price, and current status.
   - Commission status follows the lifecycle: **Pending → Calculated → Processing → Paid** (or Cancelled at any point).
3. To process a commission payment:
   - Click the **"Process Payment"** button.
   - Enter the following details:
     - **Payment Method:** Bank Transfer, GCash, Maya, Cash, Check, or Other.
     - **Payment Reference:** Reference number or transaction ID.
     - **Payment Notes:** Any relevant notes about the payment.
     - **Payment Proof:** Upload a proof of payment file (max 5 MB, formats: JPG, PNG, WEBP, GIF, PDF).
   - Click **"Confirm Payment."**
4. The commission status is updated, and the agent receives a notification with the payment reference and amount.

**For Rental Commissions:**

Rental commissions are automatically generated when a rental payment is confirmed (Section 6.8). The commission amount is calculated using the commission rate set during lease finalization.

### 6.11 Reports

**Accessing Reports:**  
Click **"Reports"** in the sidebar or navigate to `reports.php`.

The Reports Dashboard provides comprehensive analytics organized into tabbed report categories:

**1. Property Report:**
- Columns: Property ID, Address, City, Province, Type, Price, Status, Approval Status, Posted By (name and role), Bedrooms, Bathrooms, Square Footage, Listing Date, Views, Likes.
- Export options: CSV and Print.

**2. Sales Report:**
- Columns: Sale ID, Property (address and type), Final Sale Price, Buyer Name, Agent Name, Sale Date, Commission Amount, Commission Percentage, Commission Status, Finalized By, Finalized Date.
- Summary row with totals for sale prices and commissions.

**3. Rental Report:**
- Columns: Rental ID, Property Address, Tenant Name, Monthly Rent, Lease Start Date, Lease End Date, Lease Status, Agent Name, Confirmed Payments Count, Total Collected Revenue, Commission Rate, Total Commission.

**4. Agent Performance Report:**
- Columns: Agent Name, Specializations, Total Active Listings, Total Completed Sales, Total Sales Revenue, Total Commissions Earned, Total Tours, Completed Tours, Approval Status.

**5. Tour Requests Report:**
- Columns: Tour ID, Visitor Name, Property Address, Tour Date/Time, Tour Type, Status, Agent/Admin Name, Decision Reason (if applicable).

**Global Report Features:**
- Date range filter applicable to all report tabs.
- Status filters specific to each report type.
- Text search by property name, agent name, or other relevant fields.
- Pagination for large datasets.

### 6.12 Notifications

**Accessing Notifications:**  
Click **"Notifications"** in the sidebar or click the **"View All Notifications"** link in the navbar bell dropdown. This navigates to `admin_notifications.php`.

**Page Layout:**

A priority filter allows filtering by Urgent, High, Normal, or Low priorities.

Notifications are categorized into tabs:

| Tab | Description |
|-----|-------------|
| All | All notifications, sorted by unread first, then by date |
| Requests | Notifications categorized as requests (e.g., new agent submissions, tour requests) |
| Updates | Notifications for status changes (e.g., sale approved, commission paid) |
| Alerts | System alerts requiring attention |
| System | Automated system-generated notifications |

**Notification Cards:**

Each notification displays:
- Icon based on the notification type (person badge for agents, calendar for tours, building for properties, cash stack for sales)
- Title and message text
- Category and priority badges (color-coded)
- Relative timestamp (e.g., "5 minutes ago")
- Read/unread indicator

**Available Actions:**
- **Mark as Read** — Click on a notification or use the action button.
- **Delete** — Remove a notification from the list.
- **Navigate** — Click to go to the related item (e.g., agent review, sale approval page).
- **Mark All as Read** — Bulk action button to mark all notifications as read.
- **Delete All Read** — Remove all previously read notifications.

### 6.13 System Settings

**Accessing System Settings:**  
Click **"Settings"** from the profile dropdown in the top navigation bar. This navigates to `admin_settings.php`.

**Page Layout:**

Overview cards display the total counts for amenities, specializations, and property types.

Three tabbed sections allow management of system lookup values:

**1. Property Types Tab:**
- Add a new property type by entering the type name (e.g., "Condominium," "Townhouse") and clicking **"Add Type."**
- Existing types are listed with a **"Delete"** button for each entry.

**2. Amenities Tab:**
- Add a new amenity by entering the name (e.g., "Swimming Pool," "Gym") and clicking **"Add Amenity."**
- Existing amenities are displayed as tags/chips with individual delete buttons.

**3. Specializations Tab:**
- Add a new specialization (e.g., "Residential," "Commercial," "Relocation") and click **"Add Specialization."**
- Existing specializations are listed with delete options.

All operations use AJAX requests for real-time updates without page refresh.

### 6.14 Admin Profile

**Accessing Admin Profile:**  
Click **"Profile"** from the profile dropdown in the top navigation bar. This navigates to `admin_profile.php`.

**Profile Page:**

- **Profile Picture** — Displayed as a circular image. Click **"Change Picture"** to upload a new photo.
- **Account Information** — First Name, Middle Name, Last Name, Email, Phone Number, Username (read-only fields).
- **Professional Information (Editable):**
  - License Number
  - Specialization
  - Years of Experience
  - Biography
- Click **"Save Changes"** to update the profile.

---

## 7. Real Estate Agent Guide

### 7.1 Agent Dashboard

After successful login and 2FA verification, agents are directed to the Agent Dashboard (`agent_pages/agent_dashboard.php`).

**Dashboard Sections:**

1. **Statistics Grid** — Displays key metrics:
   - Total Listings (active property count)
   - Pending/Confirmed/Completed Tour Requests
   - Total Commission Earned
   - Total Sales Completed

2. **Upcoming Confirmed Tours** — Lists up to 5 upcoming confirmed tours with visitor name, property address, date/time, and tour type.

3. **Pending Tour Requests** — Displays new tour requests requiring attention with quick-action buttons.

4. **Recent Properties** — Shows the agent's 4 most recently created properties with status badges and quick links to detail views.

5. **Top Performing Properties** — Highlights the agent's 3 most-viewed or most-liked properties.

6. **Quick Action Buttons:**
   - Upload New Property
   - Manage Tour Requests
   - View Commissions
   - View Notifications

### 7.2 Completing Your Agent Profile

After initial registration, agents must complete their professional profile before their account can be approved.

**Step-by-Step Procedure:**

1. Log in to the system and navigate to the Agent Profile page by clicking your name in the navigation bar and selecting **"Profile"** (or navigate to `agent_pages/agent_profile.php`).
2. Complete the following fields:
   - **Profile Picture** — Upload a professional headshot (max 2 MB, JPEG/PNG/GIF/WEBP). Click the upload area and select a file.
   - **License Number** — Enter your real estate license number (5–30 characters, alphanumeric with spaces, dashes, or slashes allowed).
   - **Years of Experience** — Enter a number between 0 and 99.
   - **Specializations** — Select at least one area of expertise from the checklist (e.g., Residential, Commercial, First-Time Buyers, Investment Properties, Rentals, Land, Relocation).
   - **Biography** — Write a professional bio of up to 2,000 characters describing your experience and expertise.
3. Click **"Save Changes"** to submit your profile.
4. Your profile status changes to "Completed," and the administrator is notified of your submission for review.
5. **Wait for administrative approval.** You will receive an email and in-system notification once your profile is reviewed.

### 7.3 Property Management

**Accessing Property Management:**  
Click **"Properties"** in the navigation bar or navigate to `agent_pages/agent_property.php`.

**Page Layout:**

The Agent Property page displays your personal property portfolio organized by status:
- **Approved** — Properties visible to the public.
- **Pending** — Properties awaiting admin approval (displayed with grayed styling).
- **Rejected** — Properties rejected by admin with the rejection reason displayed.
- **Pending Sold** — Properties with submitted but unfinalized sale verifications.
- **Sold** — Properties with completed sales.
- **Rented** — Properties with active rental agreements.

A portfolio header shows total property value, total views, and total likes.

**Adding a New Property:**

1. Click the **"Add New Property"** button.
2. Complete the property form:

   **Address Information:**
   - Street Address, City, Barangay, Province, ZIP Code (4-digit PH format)

   **Property Details:**
   - Property Type (select from dropdown)
   - Year Built, Square Footage, Lot Size
   - Bedrooms, Bathrooms
   - Parking Type

   **Listing Information:**
   - Listing Status: For Sale or For Rent
   - Listing Price

   **Rental Details** (if "For Rent"):
   - Monthly Rent, Security Deposit, Lease Term, Furnishing, Available From

   **Amenities:**
   - Select all applicable amenities from the checklist.

   **Property Images:**
   - Upload images via the drag-and-drop area (max 25 MB per image, JPEG/PNG/GIF).
   - Multiple images can be uploaded.
   - After upload, images appear as thumbnails. Drag and drop to rearrange the display order.
   - The first image (by sort order) serves as the primary listing photo.

   **Floor Plans:**
   - Upload floor plan images organized by floor number.

3. Click **"Submit Property"** to submit the listing.
4. The property enters "Pending" status and awaits administrative approval.
5. You receive a notification when the admin approves or rejects the listing.

**Editing a Property:**

1. From your property list, click on the property card to open its detail view (`agent_pages/view_agent_property.php`).
2. Click the **"Edit"** button.
3. Modify the desired fields and click **"Save Changes."**
4. Revised properties may require re-approval depending on the extent of changes.

**Managing Property Images:**

1. Open the property detail view.
2. **Upload:** Click the upload area or drag and drop a new image. The system displays a thumbnail preview upon successful upload.
3. **Reorder:** Drag and drop image thumbnails to change the display order. Changes are saved automatically via AJAX.
4. **Delete:** Hover over an image thumbnail and click the delete icon. Confirm the deletion.
5. **Set as Primary:** The first image in the sort order serves as the primary image displayed on property cards.

**Managing Floor Plans:**

1. In the property detail view, scroll to the Floor Plans section.
2. Click **"Upload Floor Plan"** and specify the floor number.
3. Select the floor plan image file to upload.
4. To remove a floor plan, click the delete button next to the plan.

**Price History:**

The property detail view displays a Price History table showing all price events (Listed, Price Change, Sold, Rented) with dates, event types, and price values.

### 7.4 Tour Request Management

**Accessing Tour Requests:**  
Click **"Tours"** in the navigation bar or navigate to `agent_pages/agent_tour_requests.php`.

**Page Layout:**

- **Header Statistics** — Total, Pending, Confirmed, Completed, Cancelled, Rejected, and Expired counts.
- **Status Filter Tabs** — Filter tour requests by status.
- **Property Filter Dropdown** — Narrow results by a specific property.

**Tour Request Cards:**

Each card displays:
- Visitor name, email, phone number, and message
- Property address (linked to property detail)
- Tour date, time, and type (Public/Private)
- Status badge with timestamps

**Accepting a Tour Request:**

1. Navigate to the **Pending** tab.
2. Click the **"Accept"** button on the tour request card.
3. The system performs a conflict check:
   - Verifies no other confirmed tour is scheduled at the same time for the same property.
   - Checks a 30-minute buffer for tours on different properties to account for travel time.
4. If no conflicts are found, the tour is confirmed, and the visitor receives a confirmation email.
5. A notification is created in your notification center.

**Completing a Tour:**

1. After conducting the tour, navigate to the **Confirmed** tab.
2. Click the **"Complete"** button on the tour card.
3. The tour status updates to "Completed" with a completion timestamp.

**Rejecting or Cancelling a Tour:**

1. Click **"Reject"** or **"Cancel"** on the tour card.
2. Enter a reason in the modal dialog.
3. Click **"Confirm."** The visitor is notified via email.

**Auto-Expiration:**

Tours in "Pending" status that pass the scheduled date are automatically expired when you access the Tour Requests page. Expired tour visitors receive email notifications.

### 7.5 Marking a Property as Sold

When a property sale is completed, agents submit a sale verification for administrative review.

**Step-by-Step Procedure:**

1. Navigate to the property detail view for the sold property (`agent_pages/view_agent_property.php`).
2. Click the **"Mark as Sold"** button.
3. Complete the Sale Verification form:
   - **Sale Price** — Enter the final sale price (must be greater than 0).
   - **Sale Date** — Enter the date the sale was completed.
   - **Buyer Name** — Enter the buyer's full name.
   - **Buyer Email** — (Optional but recommended) Enter the buyer's email address.
   - **Additional Notes** — Any relevant notes about the transaction.
   - **Supporting Documents** — Upload verification documents (contracts, receipts, etc.). Max 5 MB per file; accepted formats: PDF, DOCX, XLSX, JPG, PNG.
4. Click **"Submit Sale Verification."**
5. The property status changes to "Pending Sold."
6. The administrator receives a notification to review the sale verification.
7. You receive a notification once the sale is approved or rejected.

### 7.6 Marking a Property as Rented

When a property is rented to a tenant, agents submit a rental verification for administrative review.

**Step-by-Step Procedure:**

1. Navigate to the property detail view for the rented property.
2. Click the **"Mark as Rented"** button.
3. Complete the Rental Verification form:
   - **Monthly Rent** — Enter the agreed monthly rental amount (must be greater than 0).
   - **Security Deposit** — Enter the security deposit amount.
   - **Lease Start Date** — Enter the lease commencement date.
   - **Lease Term** — Enter the lease duration in months (1–360).
   - **Tenant Name** — Enter the tenant's full name (required).
   - **Tenant Email** — (Optional but recommended) Enter the tenant's email.
   - **Tenant Phone** — (Optional) Enter the tenant's phone number.
   - **Additional Notes** — Any relevant notes about the rental agreement.
   - **Supporting Documents** — Upload lease agreements and other supporting documents (max 5 MB per file; PDF, DOCX, XLSX, JPG, PNG).
4. Click **"Submit Rental Verification."**
5. The administrator receives a notification to review and finalize the rental.
6. Once approved, an active lease is created, and you receive a confirmation notification.

### 7.7 Rental Payment Recording

For properties with active leases, agents submit monthly rental payment records.

**Accessing Rental Payments:**  
Click **"Rental Payments"** in the navigation bar or navigate to `agent_pages/agent_rental_payments.php`.

**Page Layout:**

- **KPI Cards** — Pending count, Confirmed count, Rejected count, Total Confirmed Revenue, and Commission Earned.
- **Active Rentals Bar** — Quick links to your active/renewed leases.
- **Payment Cards Grid** — Displays all submitted payments with property details, amounts, periods, status badges, and commission information.

**Recording a New Payment:**

1. Click the **"Record Payment"** button.
2. In the modal form:
   - **Select Rental** — Choose the active lease from the dropdown.
   - **Payment Amount** — Enter the payment amount received.
   - **Payment Date** — Enter the date the payment was received.
   - **Period Start** — The start date of the rental period covered by this payment.
   - **Period End** — The end date of the rental period covered.
   - **Upload Receipt** — (Optional) Upload a payment receipt or proof (max 5 MB; PDF or image formats).
3. Click **"Submit Payment."**
4. The payment enters "Pending" status and awaits admin confirmation.
5. Once confirmed by the admin, a commission is automatically calculated if the lease has a commission rate greater than 0%.

### 7.8 Lease Management

Agents can manage active leases for their properties.

**Renewing a Lease:**

1. From the Rental Payments page, locate the lease card.
2. Click the **"Renew Lease"** button.
3. The system extends the lease end date by the original lease term (in months).
4. The lease status changes to "Renewed" with a renewal timestamp.
5. A confirmation message displays the new end date.

**Terminating a Lease:**

1. Click the **"Terminate Lease"** button.
2. A modal dialog appears requesting a termination reason (required).
3. Enter the reason and click **"Confirm Termination."**
4. The lease status changes to "Terminated" with a timestamp and the recorded reason.
5. The corresponding property becomes available for new listings or rentals.

### 7.9 Commission Tracking

**Accessing Commission Tracking:**  
Click **"Commissions"** in the navigation bar or navigate to `agent_pages/agent_commissions.php`.

**Page Layout:**

- **Summary Cards** — Total Earnings, Paid Amount, Pending Amount, Calculated Amount, Average Commission Rate, Cancelled Count.
- **Monthly Chart** — A 12-month chart showing earned versus paid commissions.

**Sales Commissions Table:**

Displays all commissions earned from finalized sales:
- Sale Date, Property Address, Buyer Name, Sale Price, Commission Percentage, Commission Amount, Status (color-coded), Paid Date, Payment Method.

**Rental Commissions Table:**

Displays all commissions earned from confirmed rental payments:
- Similar layout for rental-based commissions.

**Commission Status Lifecycle:**

| Status | Description |
|--------|-------------|
| Pending | Commission record created; awaiting calculation |
| Calculated | Commission amount has been computed |
| Processing | Payment is being processed by the administrator |
| Paid | Commission has been paid to the agent |
| Cancelled | Commission has been voided |

**Downloading Payment Proof:**

When a commission status is "Paid," a **"Download Proof"** button is available. Click it to download the payment proof file (receipt, bank transfer confirmation, etc.) uploaded by the administrator.

### 7.10 Agent Notifications

**Accessing Notifications:**  
Click the **bell icon** in the navigation bar to view recent notifications in a dropdown, or navigate to `agent_pages/agent_notifications.php` for the full Notification Center.

**Notification Types:**

| Type | Trigger |
|------|---------|
| New Tour Request | A visitor requests a tour on one of your properties |
| Tour Cancelled/Completed | Tour status change |
| Property Approved | Admin approves your property listing |
| Property Rejected | Admin rejects your property listing |
| Sale Approved | Admin approves your sale verification |
| Sale Rejected | Admin rejects your sale verification |
| Commission Paid | Admin processes your commission payment |
| Rental Approved | Admin finalizes your rental verification |
| Rental Rejected | Admin rejects your rental verification |
| Rental Payment Confirmed | Admin confirms a recorded rental payment |
| Rental Payment Rejected | Admin rejects a recorded rental payment |
| Rental Commission Paid | A rental commission is paid |
| Lease Expiring | A lease is nearing its end date (within 30 days) |
| Lease Expired | A lease has passed its end date |

**Notification Center Layout:**

- **Summary Bar** — Total notifications and unread count.
- **Filter Tabs** — All, Unread, Read; and by category: Tours, Properties, Sales, Rentals.
- **Notification Cards** — Displayed in reverse chronological order with unread indicators, icons, titles, messages, relative timestamps, and category badges.
- **Actions:** Mark as Read/Unread, Delete, View Details (navigates to the relevant page).
- **Bulk Actions:** Mark All as Read, Delete All Read.

The notification bell in the navigation bar displays an unread count badge that updates automatically in the background.

---

## 8. Public User / Client Guide

### 8.1 Home Page

The home page is the primary landing page of the HomeEstate Realty system, accessible at `user_pages/index.php`.

**Page Sections:**

1. **Hero Section** — A full-width banner with the heading *"Find Your Dream Home"* and an animated call-to-action button leading to the property search page. The background features a gradient overlay on a real estate image.

2. **Search Bar** — A sticky search bar with quick filters:
   - City dropdown
   - Property Type dropdown
   - Price Range inputs
   - Bedrooms and Bathrooms selectors
   - **"Search"** button redirects to the search results page with the selected filters applied.

3. **Statistics Bar** — Displays live counts from the database:
   - Properties For Sale
   - Properties For Rent
   - Properties Sold
   - Approved Agents

4. **Featured Properties** — A carousel or grid displaying the 6 most recently approved property listings. Each property card includes:
   - Property image
   - Address and city
   - Bedrooms, bathrooms count
   - Listing price
   - Like button
   - **"View Details"** call-to-action button

5. **Why Choose Us** — Four feature highlight cards:
   - Wide Selection of Properties
   - Trusted Licensed Agents
   - Easy and Transparent Process
   - Secure Transactions

6. **Browse by City** — A grid of popular cities with property counts. Clicking a city navigates to the search results filtered by that city.

7. **Browse by Type** — A carousel of property types (House, Condo, Lot, Commercial) with counts. Clicking a type navigates to the filtered search results.

### 8.2 Browsing Properties

**Accessing the Property Search:**  
Click **"Browse Properties"** in the navigation bar or use the home page search bar. This navigates to `user_pages/search_results.php`.

**Page Layout:**

- **Left Sidebar (Filter Panel, ~25% width):**
  - **City** — Dropdown to filter by city.
  - **Property Type** — Multi-select for types (House, Condo, Townhouse, etc.).
  - **Status** — Radio buttons for "For Sale" or "For Rent."
  - **Price Range** — Minimum and maximum price input fields.
  - **Bedrooms** — Slider or input.
  - **Bathrooms** — Slider or input.
  - **Category** — Dropdown with options: All, Most Viewed, Most Liked, Most Bedrooms.
  - **Agent Filter** — (Optional) Filter by posted agent.
  - **Sort By** — Dropdown: Date (Newest), Price (Low to High), Price (High to Low), Most Views, Most Likes.
  - **"Apply Filters"** and **"Clear Filters"** buttons.

- **Main Area (~75% width):**
  - Search bar for text-based keyword search.
  - Active filter badges showing applied filters.
  - Results count and pagination information.
  - View toggle: Grid View or List View.

**Property Grid (24 properties per page, 3–4 columns):**

Each property card displays:
- Thumbnail image (lazy-loaded for performance)
- Status badge (For Sale / For Rent)
- Likes count
- Address and city
- Property type, bedrooms, bathrooms, square footage
- Listing price (formatted as Philippine Peso)
- View count

**AJAX-Driven Updates:**

Filters, pagination, and sorting updates use AJAX requests for smooth, seamless transitions without full page reloads. Skeleton loading placeholders appear during data retrieval.

**Pagination:**

Navigation controls at the bottom of the results: Previous, numbered page links, and Next buttons. The system displays 24 properties per page.

### 8.3 Viewing Property Details

**Accessing a Property Detail Page:**  
Click on any property card or the **"View Details"** button from the search results, home page, or agent profile. This navigates to `user_pages/property_details.php?id=[property_ID]`.

**Page Sections:**

1. **Header** — Full property address, status badge (For Sale / For Rent), and main listing price.

2. **Image Gallery** — A large carousel with all property images, scrollable thumbnails for navigation, fullscreen/expand option, and image counter.

3. **Quick Facts Row** — Bedrooms, Bathrooms, Square Footage, Lot Size, Year Built, Parking Type.

4. **Amenities Grid** — A list of all property amenities displayed as checkmark badges (e.g., ✓ Swimming Pool, ✓ Garage, ✓ Air Conditioning).

5. **Description** — Full listing description text, property type, MLS information, and date listed.

6. **Floor Plans** — (If available) Expandable floor plan images grouped by floor number.

7. **Price and Status Details:**
   - For Sale: Listing price with price-per-square-foot calculation.
   - For Rent: Monthly rent, security deposit, lease term, and furnishing details.

8. **Agent/Contact Card (Sidebar):**
   - Agent profile picture, name, specializations, license number, and years of experience.
   - Email (mailto link) and Phone (tel link) for direct contact.
   - **"View Agent Profile"** link.

9. **Tour Request Form (Sticky Sidebar)** — See Section 8.4.

10. **Like and Share:**
    - Heart icon to like/unlike the property (tracked via local browser storage).
    - Share buttons for copying the link, Facebook, and Twitter.

11. **Similar Properties** — A "You Might Also Like" carousel displaying 3–4 properties of the same type, similar price range, or in the same city.

### 8.4 Requesting a Property Tour

Visitors can schedule a tour to view a property in person without requiring a system account.

**Step-by-Step Procedure:**

1. Navigate to the property detail page for the desired property.
2. Locate the **"Schedule a Tour"** form in the sidebar.
3. Complete the following fields:
   - **Full Name** — Enter your full name (required).
   - **Email Address** — Enter a valid email address (required). Tour confirmations and updates are sent to this address.
   - **Phone Number** — (Optional) Enter a contact phone number.
   - **Preferred Tour Date** — Use the date picker to select a future date.
   - **Preferred Tour Time** — Use the time picker to select a preferred time.
   - **Tour Type** — Select either:
     - **Public** — An open-house style tour that may include other visitors.
     - **Private** — A one-on-one tour exclusive to you.
   - **Message** — (Optional) Enter any additional message or special requests.
4. Click **"Submit Tour Request."**
5. The system validates the form fields and submits the request via AJAX:
   - If successful, a confirmation message is displayed: *"Your tour request has been submitted successfully!"*
   - If validation errors occur, error messages are displayed below the respective fields.
6. An email confirmation is sent to the email address you provided.
7. The property's agent (or administrator, if admin-listed) receives a notification about your tour request.
8. You will receive an email when the agent accepts, rejects, or the request expires.

### 8.5 Browsing Agents

**Accessing the Agent Directory:**  
Click **"Find Agents"** in the navigation bar or navigate to `user_pages/agents.php`.

**Page Layout:**

- **Hero Section** — Heading: *"Trusted Real Estate Professionals"* with the count of licensed agents.

- **Filter Sidebar:**
  - Search box for agent name or license number.
  - Location dropdown.
  - Specialization multi-select filter.
  - **"Apply Filters"** and **"Clear Filters"** buttons.

- **Agent Grid (12 agents per page, 3 columns):**

Each agent card displays:
- Profile picture (large)
- Full name
- License number
- Specializations (displayed as pill badges)
- Years of experience
- Bio excerpt (truncated)
- Statistics: Active Listings, Sales Completed, Tours Completed
- **"View Profile"** and **"Contact"** buttons

- **Pagination** — Standard Previous / Page Numbers / Next controls.

### 8.6 Viewing an Agent Profile

**Accessing an Agent Profile:**  
Click **"View Profile"** on an agent card in the agent directory, or navigate to `user_pages/agent_profile.php?id=[account_id]`.

**Page Sections:**

1. **Header Card** — Large profile picture, agent's full name, license number, years of experience, specializations (pill badges), member since date, contact details (email and phone as clickable links), and professional bio.

2. **Statistics Row** — Total Active Listings, For Sale, For Rent, Completed Sales, Completed Tours.

3. **Featured Listings** — A grid of the agent's approved and active property listings displayed in the standard property card format.

4. **Similar Agents** — A "Meet Other Professionals" carousel showing 3–4 other approved agents.

5. **Action Buttons:**
   - **"View All Listings"** — Navigates to search results filtered by this agent.
   - **"Contact Agent"** — Email link.
   - **"Call Agent"** — Phone link.

### 8.7 Liking a Property

Visitors can express interest in a property by liking it.

**How to Like a Property:**

1. On any property card (home page, search results, or property detail page), click the **heart icon (❤)**.
2. The like count increments, and the heart icon fills to indicate the liked state.
3. Click the heart icon again to unlike the property. The like count decrements.
4. Like tracking is managed via browser session and local storage. Clearing browser data resets liked status.

### 8.8 About Page

**Accessing the About Page:**  
Click **"About Us"** in the navigation bar or navigate to `user_pages/about.php`.

**Page Sections:**

1. **Hero Section** — "About HomeEstate Realty" heading with the tagline *"Your Trusted Partner in Real Estate."*
2. **Company Overview** — Mission and vision statements, founding principles, and core values.
3. **Statistics Section** — Animated counters showing live database counts: Properties For Sale, For Rent, Sold, and Licensed Agents.
4. **Mission/Vision** — Detailed paragraphs describing the company's goals and long-term direction.
5. **Why Choose Us** — Feature highlight cards (Wide Selection, Expert Agents, Transparent Process, Secure Transactions).
6. **Call-to-Action** — *"Ready to Get Started?"* with buttons linking to Browse Properties and Find Agents.

---

## 9. Screens and Features Explanation

### 9.1 Common Interface Elements

**Cards:** The system uses card-based layouts throughout all portals. Property cards, agent cards, tour request cards, and notification cards follow consistent design patterns with images, titles, metadata, status badges, and action buttons.

**Modals:** Interactive modal dialogs are used for confirmation prompts (approve, reject, delete), form submissions (tour requests, payment recording), and detail views. Modals overlay the current page without navigation.

**Tabs:** Tabbed navigation organizes data by status categories (Pending, Approved, Rejected, etc.). Each tab displays a count badge indicating the number of items in that category.

**Toast Notifications:** Brief success or error messages that appear at the top or bottom of the screen for 3–5 seconds following an action (e.g., "Payment confirmed successfully").

**Breadcrumbs:** Some pages display breadcrumb trails (e.g., Dashboard > Properties > Add New Property) for navigation context.

### 9.2 Status Badges and Indicators

The system uses color-coded status badges throughout the interface:

| Badge Color | Status Examples |
|-------------|----------------|
| **Green** | Approved, Confirmed, Active, Paid, Completed |
| **Yellow/Orange** | Pending, Pending Sold, Calculated, Processing |
| **Red** | Rejected, Cancelled, Terminated, Expired |
| **Blue** | For Sale, For Rent, Renewed |
| **Gray** | Inactive, Needs Profile Completion |

### 9.3 Notification System

The HomeEstate Realty system implements a dual notification system:

1. **Admin Notifications** (`notifications` table) — Centralized alerts for the administrator covering agent submissions, property events, sale/rental verifications, tour requests, and commission updates. Notifications include priority levels (low, normal, high, urgent) and categories (request, update, alert, system).

2. **Agent Notifications** (`agent_notifications` table) — Agent-specific alerts for tour requests, property approvals, sale/rental approvals, commission payments, and lease events. Notifications include a type identifier for categorization.

Both systems support mark-as-read functionality, unread count badges, and navigation links to related items.

### 9.4 File Upload Components

The system supports multiple file upload scenarios:

| Upload Type | Max Size | Accepted Formats | Location |
|-------------|----------|-------------------|----------|
| Property Images | 25 MB per file | JPEG, PNG, GIF | `/uploads/` |
| Floor Plan Images | 25 MB per file | JPEG, PNG, GIF | `/uploads/` |
| Profile Pictures | 2 MB | JPEG, PNG, GIF, WEBP | `/uploads/agents/` or `/uploads/admins/` |
| Sale Verification Documents | 5 MB per file | PDF, DOCX, XLSX, JPG, PNG | `/sale_documents/` |
| Rental Verification Documents | 5 MB per file | PDF, DOCX, XLSX, JPG, PNG | `/rental_documents/` |
| Rental Payment Receipts | 5 MB per file | PDF, JPG, PNG | `/rental_payment_documents/` |
| Commission Payment Proof | 5 MB | JPG, PNG, WEBP, GIF, PDF | `/uploads/commission_proofs/` |

All uploads undergo server-side MIME type verification using `finfo_open(FILEINFO_MIME_TYPE)` to prevent malicious file uploads.

### 9.5 Email Notifications

The system sends automated email notifications for the following events:

| Event | Recipients | Content |
|-------|-----------|---------|
| Agent Registration | Agent | Welcome and next steps |
| Agent Profile Approved | Agent | Approval confirmation |
| Agent Profile Rejected | Agent | Rejection with reason |
| Property Approved | Agent | Listing is now live |
| Property Rejected | Agent | Rejection with reason |
| Tour Request Submitted | Agent/Admin | Tour details |
| Tour Confirmed | Visitor | Confirmation with date/time |
| Tour Rejected/Cancelled | Visitor | Notification with reason |
| Tour Expired | Visitor | Expiration notification |
| Sale Approved | Agent, Buyer | Sale confirmation |
| Sale Rejected | Agent | Rejection with reason |
| Rental Approved | Agent, Tenant | Lease confirmation |
| Rental Rejected | Agent | Rejection with reason |
| Rental Payment Confirmed | Agent | Payment confirmation |
| Commission Paid | Agent | Payment details and reference |
| Lease Expiring (30 days) | Agent | Advance warning |
| Lease Expired | Agent | Expiration alert |
| 2FA OTP Code | User | 6-digit verification code |

Emails are sent via SMTP using PHPMailer with STARTTLS encryption on port 587. Email templates include branded headers, structured content blocks, and footer information.

---

## 10. Error Handling and Troubleshooting

### 10.1 Login Issues

| Issue | Cause | Resolution |
|-------|-------|------------|
| "Invalid username or password" | Incorrect credentials entered | Verify the username and password. Ensure Caps Lock is off. Passwords are case-sensitive. |
| "Please complete your profile first" | Agent profile has not been filled out | Log in and immediately navigate to the Profile page to complete all required fields. |
| "Your account is pending admin approval" | Agent profile was submitted but not yet reviewed by admin | Wait for the administrator to review your profile. You will receive an email upon approval. |
| "Your account has been deactivated" | Account disabled by admin | Contact the administrator to request reactivation. |
| Page redirects to login without message | Session expired | The 30-minute inactivity timeout was triggered. Log in again. |

### 10.2 Two-Factor Authentication Issues

| Issue | Cause | Resolution |
|-------|-------|------------|
| "Invalid code" | Incorrect OTP entered | Double-check the code from your email. Ensure you are using the most recent OTP. |
| "Code expired" | OTP exceeded the 10-minute validity window | Click "Resend Code" to request a new OTP and enter it within 10 minutes. |
| "Too many attempts" | Exceeded 3 attempts for a single code | Click "Resend Code" to generate a new OTP. |
| OTP email not received | Email delivery delay or spam filtering | Check your spam/junk folder. Verify the email address on your account is correct. Wait 1–2 minutes and try "Resend Code." |
| Redirected back to login page | Pending login session expired (10 minutes) | Start the login process again from the login page. |

### 10.3 Session Timeout

| Issue | Cause | Resolution |
|-------|-------|------------|
| "Session expired due to inactivity" message on login page | No activity for 30 minutes | Log in again. Any unsaved form data is lost. |
| Frequent timeouts during form completion | Long forms taking more than 30 minutes | Complete forms promptly. If entering large amounts of data, save periodically. |

### 10.4 File Upload Errors

| Issue | Cause | Resolution |
|-------|-------|------------|
| "File too large" | File exceeds the maximum size limit | Compress or resize the file. Property images: max 25 MB. Documents: max 5 MB. Profile pictures: max 2 MB. |
| "Invalid file type" | File format not accepted | Convert the file to an accepted format (JPEG, PNG, GIF for images; PDF, DOCX for documents). |
| Upload appears stuck or fails silently | Network interruption or server timeout | Refresh the page and retry the upload. Ensure a stable internet connection. |
| "Failed to upload image" | Server storage or permissions issue | Contact the system administrator. |

### 10.5 Form Validation Errors

| Issue | Cause | Resolution |
|-------|-------|------------|
| "ZIP code must be exactly 4 digits" | Non-numeric or incorrect length | Enter a valid Philippine ZIP code (4 digits, numeric only). |
| "Password must contain at least 8 characters with letters and numbers" | Weak password | Include both alphabetic characters and numbers, ensuring at least 8 characters total. |
| "Phone number format invalid" | Phone number not in expected format | Enter a Philippine mobile number: `09XXXXXXXXX` (11 digits starting with 09). |
| Required field highlighted in red | Mandatory field left empty | Fill in all fields marked with a red asterisk (*). |
| "Username already taken" | Another account uses the chosen username | Choose a different username. |

### 10.6 Tour Request Conflicts

| Issue | Cause | Resolution |
|-------|-------|------------|
| "Time conflict detected" when accepting a tour | Another confirmed tour is scheduled at the same time or within 30 minutes | Check the conflicting tour details. Either reschedule or reject the conflicting request. |
| Tour auto-expired | Tour date passed without a response | The system automatically expires unresponded tours. Monitor pending requests regularly. |

### 10.7 General Troubleshooting

| Issue | Resolution |
|-------|------------|
| Page not loading or displaying errors | Clear browser cache, disable browser extensions, and try again. Ensure JavaScript and cookies are enabled. |
| Data not updating after an action | Refresh the page. Some features use AJAX; ensure the action completed (look for a success toast or confirmation). |
| Broken images or missing photos | The image file may have been deleted from the server. Contact the system administrator. |
| Incorrect financial data displayed | Verify source records (sale verifications, rental payments). Financial data is derived from approved records only. |
| Email notifications not arriving | Check spam/junk folders. Verify the SMTP configuration with the system administrator. Ensure the recipient email is valid. |

---

## 11. Best Practices and Usage Tips

### 11.1 For Administrators

1. **Review pending items daily.** Check the dashboard for pending property approvals, agent registrations, sale/rental verifications, and tour requests. Timely reviews keep the platform responsive.

2. **Set commission rates carefully during rental finalization.** The commission rate entered during rental approval is applied to all confirmed payments for the duration of the lease.

3. **Always provide clear reasons when rejecting items.** Rejection reasons are sent to agents via email and help them understand what needs to be corrected.

4. **Monitor the Reports dashboard regularly.** Use reports to track platform performance, agent productivity, and revenue trends. Export reports periodically for record-keeping.

5. **Keep system settings updated.** Add new property types, amenities, and specializations as the market evolves to keep listings comprehensive.

6. **Process commission payments promptly.** Agents rely on commission tracking for their income. Upload payment proof when marking commissions as paid.

7. **Review tour requests for admin-listed properties daily.** Respond to pending tour requests promptly before they auto-expire.

### 11.2 For Agents

1. **Complete your profile immediately after registration.** A complete profile accelerates the approval process and presents a professional image to potential clients.

2. **Upload high-quality property images.** Use well-lit, high-resolution photographs. The first image in the display order serves as the primary thumbnail — ensure it is the most visually appealing.

3. **Write detailed property descriptions.** Include information about the neighborhood, nearby amenities, transportation access, and unique features. Detailed descriptions improve search visibility and client interest.

4. **Set accurate property information.** Ensure bedrooms, bathrooms, square footage, and pricing data are correct. Inaccurate data can mislead clients and delay transactions.

5. **Respond to tour requests promptly.** Pending tours that are not addressed will auto-expire, potentially losing client interest. Aim to respond within 24 hours.

6. **Submit sale and rental verifications with complete documentation.** Include all supporting documents (contracts, receipts) to expedite administrative review.

7. **Record rental payments regularly.** Submit monthly payment records promptly with receipts to maintain accurate financial records and commission tracking.

8. **Monitor notifications.** The notification badge in the navigation bar indicates unread alerts. Regular monitoring ensures you do not miss important updates.

### 11.3 For Public Users

1. **Use filters to narrow your search.** The property search page offers extensive filtering options — city, type, price range, bedrooms, and bathrooms. Using filters saves time and provides more relevant results.

2. **Sort results strategically.** Use "Newest" to see recently listed properties, "Most Views" for popular listings, or price sorting to fit your budget.

3. **Provide accurate contact information in tour requests.** Tour confirmations and updates are sent to the email you provide. An incorrect email means you will not receive important notifications.

4. **Schedule tours in advance.** Select a future date and time that allows the agent adequate time to respond. Same-day tours may not always be accommodated.

5. **Include a message with your tour request.** Providing context (e.g., "First-time buyer looking for a family home") helps the agent prepare for your visit.

6. **Browse agent profiles before requesting tours.** Check the agent's specializations, experience, and active listings to find a good match for your needs.

7. **Like properties you're interested in.** Liking helps you keep track of properties you want to revisit. Note that likes are stored in your browser and will be cleared if you clear browser data.

### 11.4 Security Recommendations

1. **Use strong, unique passwords.** Passwords should be at least 8 characters containing both letters and numbers. Avoid using personal information.

2. **Do not share your OTP codes.** Two-factor authentication codes are for your use only. HomeEstate Realty staff will never ask for your OTP.

3. **Log out after each session.** Especially when using shared or public computers, always use the logout function rather than simply closing the browser.

4. **Be cautious with links in emails.** Verify that notification emails originate from the official HomeEstate Realty system before clicking links.

5. **Update your contact information promptly.** Keep your email address and phone number current to ensure you receive security codes and system notifications.

---

## 12. Glossary of Terms

| Term | Definition |
|------|-----------|
| **2FA (Two-Factor Authentication)** | A security method requiring two forms of verification (password and OTP) to access the system. |
| **Agent** | A licensed real estate professional registered on the platform to list and manage properties. |
| **Amenities** | Features and facilities associated with a property (e.g., swimming pool, garage, gym). |
| **Approval Status** | The administrative review state of a listing or agent: Pending, Approved, or Rejected. |
| **Commission** | A percentage-based payment earned by an agent upon the completion of a sale or confirmed rental payment. |
| **Commission Rate** | The percentage applied to a payment amount to calculate the agent's commission. |
| **Finalized Rental** | A rental agreement that has been approved by the administrator, creating an active lease record. |
| **Finalized Sale** | A property sale that has been approved and recorded by the administrator. |
| **Floor Plan** | A diagram or image showing the layout of a property's floors. |
| **Lease** | A contractual agreement between a property owner and a tenant for a specified duration and rental amount. |
| **Lease Status** | The current state of a lease: Active, Renewed, Terminated, or Expired. |
| **Listing Price** | The asking price set for a property either for sale or rent. |
| **OTP (One-Time Password)** | A 6-digit code sent via email for two-factor authentication. |
| **Pending Sold** | A status indicating a sale verification has been submitted but not yet finalized by the administrator. |
| **Property Status** | The current state of a property: For Sale, For Rent, Sold, Pending Sold, or Rented. |
| **Rental Verification** | Documentation and details submitted by an agent to verify a completed rental transaction. |
| **Sale Verification** | Documentation and details submitted by an agent to verify a completed property sale. |
| **Session Timeout** | Automatic logout triggered after 30 minutes of inactivity. |
| **Specialization** | An agent's area of professional expertise (e.g., Residential, Commercial, Rentals, Investment Properties). |
| **Tour Request** | A request from a prospective client to visit and inspect a property at a specific date and time. |
| **Tour Type** | The nature of the tour: Public (open house, may include other visitors) or Private (exclusive one-on-one). |
| **Verification Documents** | Supporting files (contracts, receipts, etc.) uploaded with sale or rental verifications. |

---

**End of User Manual**

*HomeEstate Realty — Real Estate Property Management System*  
*Version 1.0 — April 2026*  
*Prepared for Capstone Project Documentation*
