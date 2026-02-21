# Real Estate Capstone System

A PHP/MySQL web application for managing real estate listings with Admin and Agent dashboards and a public property catalogue. Supports both properties For Sale and For Rent, with rental-specific workflows, image uploads, agent tour requests, and email notifications via PHPMailer.

## Key Features

- Admin and Agent roles with separate dashboards
- Add, update, approve/reject properties
- Rental workflow when Status = "For Rent"
  - Monthly Rent, Security Deposit, Lease Term, Furnishing, Available From
  - Frontend + backend validation (see below)
- Property images upload with preview and reordering
- Amenities checklist
- Property logs and status logs for auditing
- Email notifications via PHPMailer (SMTP)
- Public pages for browsing and viewing property details

## Project Structure (high level)

- Root PHP pages (admin actions, auth, listings): `*.php`
- Agent pages: `agent_pages/`
- User/public pages: `user_pages/`
- Client scripts: `script/`
- Styles: `css/`
- Images and uploads: `images/`, `uploads/`
- Email config + library: `config/`, `PHPMailer/`
- Misc UI fragments: `modals/`

## Prerequisites

- Windows + XAMPP (Apache + MySQL)
- PHP 8.x recommended
- MySQL/MariaDB
- Composer not required for PHPMailer (library bundled)

## Database

- Default connection (see `connection.php`):
  - Host: `localhost`
  - User: `root`
  - Password: `""` (empty)
  - Database: `realestatesystem`
- Ensure the schema includes at least these tables:
  - `property`, `property_images`, `amenities`, `property_amenities`
  - `property_log`, `status_log`
  - `rental_details` (for rentals)
- If your DB names/credentials differ, update `connection.php`.

## Configuration

- App DB: `connection.php`
- Mail (SMTP): `config/mail_config.php`
  - Update the SMTP username, password, and from fields.
  - Set `MAIL_DEBUG_ENABLED` to `false` in production.
  - The app writes mail debug logs to `logs/mail.log` when enabled.

## Running Locally (XAMPP)

1. Place this folder under `xampp/htdocs/` (e.g., `d:\xampp\htdocs\capstoneSystem`).
2. Start Apache and MySQL in XAMPP Control Panel.
3. Create the database `realestatesystem` and import your schema.
4. Visit `http://localhost/capstoneSystem/` in your browser.

## Uploads & File Storage

- Property images are stored under `uploads/`.
- Make sure the web server can write to this folder.
- Frontend guidance: up to 10 images, ~5MB each, JPG/PNG/GIF.

## Roles and Access

- The app distinguishes `admin` and `agent` (via `$_SESSION['user_role']`).
- Use `register.php` to create an account, or provision directly in the DB.
- Update roles in your user/account table as needed.

## Add Property: Sale vs Rent

- Status = "For Sale":
  - "Listing Price" is the sale price.
  - Square Footage and Lot Size are required.
- Status = "For Rent":
  - "Listing Price" becomes Monthly Rent.
  - Shows rental fields: Security Deposit, Lease Term, Furnishing, Available From.
  - Square Footage and Lot Size become optional (clearly indicated in the UI).
  - Rental details are saved into `rental_details`.

## Validation Rules (important)

Global
- ZIP: exactly 4 digits (PH postal code).
- At least one property photo is required.
- Most form fields are required (except Amenities and a few others noted below).

Rentals (Status = For Rent)
- Monthly Rent: must be a positive number (uses Listing Price).
- Security Deposit: numeric, >= 0, and cannot exceed 12 months of rent.
- Lease Term (months): must be one of 6, 12, 18, or 24.
- Furnishing: one of `Unfurnished`, `Semi-Furnished`, `Fully Furnished`.
- Available From: required and cannot be in the past.
- Square Footage and Lot Size: Optional (UI shows warning/orange styling when optional).

## Email (PHPMailer)

- Config file: `config/mail_config.php`.
- Uses SMTP (default is Gmail STARTTLS on 587). Replace with your credentials.
- Keep credentials out of version control in production.

## Auditing and Logs

- `property_log`: captures key property lifecycle events (e.g., CREATED, SOLD).
- `status_log`: captures status changes and admin actions.

## Troubleshooting

- Form shows "Please enter a valid value" on optional fields:
  - Ensure you selected Status = "For Rent"—the UI removes `required` and shows optional styling.
- Image upload fails:
  - Verify `uploads/` exists and is writable by the web server.
  - Check PHP `upload_max_filesize` and `post_max_size` in `php.ini`.
- Email not sending:
  - Verify SMTP credentials in `config/mail_config.php`.
  - Temporarily set `MAIL_DEBUG_ENABLED` to `true` and check `logs/mail.log`.
- DB connection errors:
  - Confirm credentials and database name in `connection.php`.

## Security Notes

- Change SMTP credentials and disable mail debug in production.
- Consider server-side validation of image mimetypes and size (recommended hardening).
- Limit file extensions and scan uploads as needed.
- Keep `config/` secrets out of version control in production environments.

## Contributing / Next Steps

- Add server-side MIME/type and per-file size validation for image uploads.
- Add rental-specific filters to search/browse UI.
- Make deposit cap configurable.
- Add automated tests for key flows (save property, rental validation, uploads).
