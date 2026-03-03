<?php
session_start();
include 'connection.php'; // Your database connection

// Check if the user is logged in and is either an admin or an agent
if (!isset($_SESSION['account_id']) || !in_array($_SESSION['user_role'], ['admin', 'agent'])) {
    header("Location: login.php");
    exit();
}

// Fetch all amenities for the checklist
$amenities_result = $conn->query("SELECT * FROM amenities ORDER BY amenity_name");

// Fetch all property types for the dropdown
$property_types_result = $conn->query("SELECT * FROM property_types ORDER BY type_name");

// Check for session messages
$message_type = $_SESSION['message']['type'] ?? '';
$message_text = $_SESSION['message']['text'] ?? '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Property - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* ================================================
           ADD PROPERTY PAGE
           Structure matches property.php / admin_dashboard.php:
           - Simple :root for core vars only
           - Hardcoded sidebar/content layout (290px)
           - No wildcard resets
           - Page-specific vars scoped to .admin-content
           ================================================ */

        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: #212529;
        }

        .admin-sidebar {
            background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%);
            color: #fff;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 290px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .admin-content {
            margin-left: 290px;
            padding: 2rem;
            min-height: 100vh;
            max-width: 1800px;
        }

        @media (max-width: 1200px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }

        /* ===== PAGE-SPECIFIC VARIABLES (scoped to admin-content) ===== */
        .admin-content {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
        }

        /* ===== PAGE HEADER (matches property.php) ===== */
        .page-header {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .page-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .page-header-inner {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.25rem;
        }

        .page-header .subtitle {
            color: var(--text-secondary, #64748b);
            font-size: 0.95rem;
            margin: 0;
        }

        /* ===== FORM PROGRESS (redesigned to match theme) ===== */
        .form-progress {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .form-progress::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .progress-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold));
            width: 0%;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary, #64748b);
            text-align: center;
        }

        /* ===== FORM SECTIONS (matches property.php card style) ===== */
        .form-section {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .form-section:hover::before { opacity: 1; }

        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .section-title i {
            color: var(--gold-dark);
            font-size: 1.125rem;
        }

        /* ===== FORM CONTROLS ===== */
        .form-label {
            font-weight: 600;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            display: block;
        }

        .form-label .required {
            color: #dc2626;
            margin-left: 0.25rem;
        }

        .form-label .optional {
            color: #d97706;
            font-weight: 500;
            margin-left: 0.25rem;
            font-size: 0.8125rem;
        }

        .form-control, .form-select {
            height: 42px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            padding: 0.5rem 0.875rem;
            font-size: 0.9375rem;
            background-color: #fff;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
            outline: none;
        }

        .form-control:hover, .form-select:hover {
            border-color: rgba(37, 99, 235, 0.3);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .form-control.optional-field {
            background-color: #fffbf0;
        }

        /* Input Groups */
        .input-group {
            position: relative;
        }

        /* Textarea */
        textarea.form-control {
            resize: vertical;
            min-height: 120px;
            height: auto;
            border-radius: 4px !important;
            padding: 0.75rem 0.875rem;
        }

        /* ===== CUSTOM PROPERTY INPUT GROUP STYLES - ISOLATED ===== */
        .property-input-group {
            position: relative;
            display: block;
        }

        .property-input-group .property-form-input {
            padding-left: 2.5rem !important;
            padding-right: calc(1.5em + 0.75rem) !important;
            border-radius: 4px !important;
            height: 42px !important;
            display: block;
            width: 100%;
        }

        .property-input-group .property-input-icon {
            position: absolute !important;
            left: 0.875rem !important;
            top: 11px !important;
            display: inline-block !important;
            color: var(--text-secondary, #64748b) !important;
            pointer-events: none !important;
            z-index: 100 !important;
            line-height: 1 !important;
            font-size: 1rem !important;
        }

        .property-input-group .property-form-input:invalid,
        .property-input-group .property-form-input.is-invalid,
        .was-validated .property-input-group .property-form-input:invalid {
            padding-left: 2.5rem !important;
            padding-right: calc(1.5em + 0.75rem) !important;
            border-radius: 4px !important;
            height: 42px !important;
            background-position: right calc(0.375em + 0.1875rem) center !important;
        }

        .was-validated .property-input-group .property-input-icon,
        .property-input-group .property-form-input:invalid ~ .property-input-icon,
        .property-input-group .property-form-input.is-invalid ~ .property-input-icon {
            top: 11px !important;
            left: 0.875rem !important;
            position: absolute !important;
        }

        .property-input-group .input-group-icon {
            position: absolute !important;
            left: 0.875rem !important;
            top: 11px !important;
            display: inline-block !important;
            color: var(--text-secondary, #64748b) !important;
            pointer-events: none !important;
            z-index: 100 !important;
            line-height: 1 !important;
        }

        /* Reset padding for inputs NOT in our custom group */
        .form-control:not(.property-form-input) {
            padding-left: 0.875rem;
        }

        /* Row spacing consistency */
        .form-section .row {
            margin-left: -0.5rem;
            margin-right: -0.5rem;
        }

        .form-section .row > [class*='col'] {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .form-section .g-3 {
            --bs-gutter-x: 1rem;
            --bs-gutter-y: 1rem;
        }

        /* Custom Property Form Inputs */
        .property-form-input {
            border-radius: 4px !important;
        }

        .property-form-select {
            border-radius: 4px !important;
        }

        .property-input-group .form-control {
            border-radius: 4px !important;
        }

        .property-input-group .input-group-text {
            border-radius: 4px !important;
        }

        .property-form-textarea {
            border-radius: 4px !important;
        }

        /* ===== AMENITIES SECTION ===== */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .amenities-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 0.5rem;
            margin-top: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 1rem;
            background-color: #fff;
        }

        .amenities-search {
            position: relative;
            margin-bottom: 1rem;
        }

        .amenities-search .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary, #64748b);
        }

        .amenities-search input {
            padding-left: 2.5rem;
            border-radius: 4px !important;
        }

        .form-check {
            background: #f8fafc;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            margin-bottom: 0;
            display: flex;
            align-items: center;
        }

        .form-check:hover {
            background: rgba(212, 175, 55, 0.04);
            border-color: rgba(212, 175, 55, 0.3);
        }

        .form-check-input {
            border-radius: 3px !important;
            margin-top: 0;
            margin-right: 0.5rem;
        }

        .form-check-input:checked {
            background-color: var(--gold-dark);
            border-color: var(--gold-dark);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.2);
        }

        .form-check-label {
            font-weight: 500;
            color: var(--text-primary, #0f172a);
            cursor: pointer;
            font-size: 0.9375rem;
        }

        /* ===== IMAGE UPLOAD SECTION ===== */
        .image-upload-section {
            border: 2px dashed #e2e8f0;
            border-radius: 4px;
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            transition: all 0.2s ease;
            position: relative;
        }

        .image-upload-section:hover {
            border-color: rgba(212, 175, 55, 0.5);
            background: rgba(212, 175, 55, 0.02);
        }

        .image-upload-section.dragover {
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.05);
        }

        .upload-icon {
            font-size: 2.5rem;
            color: var(--gold-dark);
            margin-bottom: 1rem;
        }

        .upload-text {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.375rem;
        }

        .upload-subtext {
            color: var(--text-secondary, #64748b);
            font-size: 0.85rem;
        }

        .file-input-wrapper {
            margin-top: 1rem;
        }

        .file-input {
            position: absolute;
            left: -9999px;
        }

        .file-input-button {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: #fff;
            border: none;
            padding: 0.6rem 1.25rem;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
            position: relative;
            overflow: hidden;
        }

        .file-input-button::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }

        .file-input-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.35);
        }

        .file-input-button:hover::before { left: 100%; }

        /* Image Preview Grid */
        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .image-preview-item {
            position: relative;
            background: #fff;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }

        .preview-image {
            width: 100%;
            height: 110px;
            object-fit: cover;
        }

        .image-info {
            padding: 0.625rem;
            font-size: 0.75rem;
            color: var(--text-secondary, #64748b);
            border-top: 1px solid #e2e8f0;
        }

        .remove-image {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(220, 38, 38, 0.9);
            color: #fff;
            border: none;
            border-radius: 4px;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .remove-image:hover {
            background: #dc2626;
        }

        /* ===== FLOOR UPLOAD SECTIONS ===== */
        .floor-upload-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }

        .floor-upload-card.error {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .floor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .floor-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .floor-title i {
            color: var(--gold-dark);
        }

        .floor-badge {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 2px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .floor-upload-area {
            border: 2px dashed #e2e8f0;
            border-radius: 4px;
            padding: 1.5rem;
            text-align: center;
            background: white;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .floor-upload-area:hover {
            border-color: rgba(212, 175, 55, 0.5);
            background: rgba(212, 175, 55, 0.02);
        }

        .floor-upload-area.has-files {
            border-style: solid;
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.03);
        }

        .floor-upload-icon {
            font-size: 1.75rem;
            color: var(--gold-dark);
            margin-bottom: 0.625rem;
        }

        .floor-upload-text {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.25rem;
        }

        .floor-upload-subtext {
            font-size: 0.8125rem;
            color: var(--text-secondary, #64748b);
        }

        .floor-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .floor-preview-item {
            position: relative;
            background: white;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }

        .floor-preview-image {
            width: 100%;
            height: 90px;
            object-fit: cover;
        }

        .floor-image-info {
            padding: 0.5rem;
            font-size: 0.7rem;
            color: var(--text-secondary, #64748b);
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .remove-floor-image {
            position: absolute;
            top: 0.375rem;
            right: 0.375rem;
            background: rgba(220, 38, 38, 0.9);
            color: white;
            border: none;
            border-radius: 4px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.75rem;
        }

        .remove-floor-image:hover {
            background: #dc2626;
        }

        /* ===== SUBMIT SECTION ===== */
        .submit-section {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.5rem 2rem;
            text-align: center;
            margin-top: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .submit-section::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: #fff;
            border: none;
            padding: 0.7rem 2rem;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.35);
            color: #fff;
        }

        .btn-submit:hover::before { left: 100%; }

        .btn-cancel {
            background: var(--card-bg);
            color: var(--text-secondary, #64748b);
            border: 1px solid #e2e8f0;
            padding: 0.7rem 1.75rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-right: 1rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-cancel:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: rgba(37, 99, 235, 0.03);
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            pointer-events: none;
        }
        .app-toast {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            background: #ffffff;
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            min-width: 300px;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06);
            pointer-events: all;
            position: relative;
            overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast.toast-warning::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .toast-success .app-toast-icon,
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .app-toast-body      { flex: 1; min-width: 0; }
        .app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg       { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close {
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 0.8rem;
            padding: 0; line-height: 1;
            flex-shrink: 0;
            transition: color .2s;
        }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            border-radius: 0 0 0 12px;
        }
        .toast-success .app-toast-progress,
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .form-section {
                padding: 1.5rem;
            }

            .amenities-grid {
                grid-template-columns: 1fr;
            }

            .image-preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .page-header {
                padding: 1.5rem;
            }
        }

        /* ===== UTILITIES ===== */
        .form-text {
            font-size: 0.8125rem;
            color: var(--text-secondary, #64748b);
            margin-top: 0.375rem;
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
           Matches: add_property.php
           ================================================================ */
        @keyframes sk-shimmer { 0% { background-position: -800px 0; } 100% { background-position: 800px 0; } }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 800px 100%;
            animation: sk-shimmer 1.4s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        .sk-page-header { background:#fff; border-radius:4px; padding:1.25rem 1.75rem; margin-bottom:1.5rem; border:1px solid rgba(37,99,235,0.08); }
        .sk-form-progress { background:#fff; border-radius:4px; padding:1rem 1.75rem; margin-bottom:1.5rem; border:1px solid rgba(37,99,235,0.08); }
        .sk-form-section { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:1.5rem 1.75rem; margin-bottom:1.25rem; }
        .sk-form-section-title { margin-bottom:1rem; }
        .sk-form-row { display:grid; gap:1rem; margin-bottom:0.75rem; }
        .sk-form-row-5 { grid-template-columns: repeat(5, 1fr); }
        .sk-form-row-4 { grid-template-columns: repeat(4, 1fr); }
        .sk-form-row-2 { grid-template-columns: repeat(2, 1fr); }
        .sk-form-field { display:flex; flex-direction:column; gap:0.4rem; }
        .sk-form-input { height: 40px; border-radius:4px; }
        .sk-form-textarea { height: 100px; border-radius:4px; }
        .sk-form-chips { display:grid; grid-template-columns:repeat(6, 1fr); gap:0.5rem; margin-top:0.5rem; }
        .sk-upload-area { height:130px; border-radius:6px; border:2px dashed #e2e8f0; display:flex; align-items:center; justify-content:center; }
        .sk-line { display:block; border-radius:4px; }
        @media (max-width:992px) { .sk-form-row-5 { grid-template-columns:repeat(3,1fr); } .sk-form-row-4 { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:768px) { .sk-form-row-5, .sk-form-row-4, .sk-form-row-2 { grid-template-columns:1fr; } .sk-form-chips { grid-template-columns:repeat(3,1fr); } }
    </style>
</head>
<body>

    <!-- Include Sidebar Component -->
    <?php 
        // Ensure the Properties menu is highlighted when adding a property
        $active_page = 'property.php';
        include 'admin_sidebar.php'; 
    ?>
    
    <!-- Include Navbar Component -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="admin-content">

        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ══════════════════════════════════════════════════════════
             SKELETON SCREEN — visible on first paint
        ══════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Page Header -->
            <div class="sk-page-header">
                <div class="sk-line sk-shimmer" style="width:190px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-line sk-shimmer" style="width:320px;height:13px;"></div>
            </div>

            <!-- Form Progress Bar -->
            <div class="sk-form-progress">
                <div class="sk-shimmer" style="width:100%;height:8px;border-radius:4px;margin-bottom:0.5rem;"></div>
                <div class="sk-line sk-shimmer" style="width:260px;height:12px;"></div>
            </div>

            <!-- Form Section 1: Basic Information -->
            <div class="sk-form-section">
                <div class="sk-form-section-title">
                    <div class="sk-line sk-shimmer" style="width:160px;height:18px;"></div>
                </div>
                <div class="sk-form-row sk-form-row-5">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="sk-form-field">
                        <div class="sk-line sk-shimmer" style="width:80px;height:12px;"></div>
                        <div class="sk-form-input sk-shimmer"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="sk-form-row sk-form-row-4" style="margin-top:0.75rem;">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="sk-form-field">
                        <div class="sk-line sk-shimmer" style="width:70px;height:12px;"></div>
                        <div class="sk-form-input sk-shimmer"></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Form Section 2: Property Details -->
            <div class="sk-form-section">
                <div class="sk-form-section-title">
                    <div class="sk-line sk-shimmer" style="width:150px;height:18px;"></div>
                </div>
                <div class="sk-form-row sk-form-row-4">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="sk-form-field">
                        <div class="sk-line sk-shimmer" style="width:75px;height:12px;"></div>
                        <div class="sk-form-input sk-shimmer"></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Form Section 3: Description + MLS -->
            <div class="sk-form-section">
                <div class="sk-form-section-title">
                    <div class="sk-line sk-shimmer" style="width:180px;height:18px;"></div>
                </div>
                <div class="sk-form-row sk-form-row-2" style="margin-bottom:0.75rem;">
                    <div class="sk-form-field">
                        <div class="sk-line sk-shimmer" style="width:90px;height:12px;"></div>
                        <div class="sk-form-input sk-shimmer"></div>
                    </div>
                    <div class="sk-form-field">
                        <div class="sk-line sk-shimmer" style="width:90px;height:12px;"></div>
                        <div class="sk-form-input sk-shimmer"></div>
                    </div>
                </div>
                <div class="sk-form-field">
                    <div class="sk-line sk-shimmer" style="width:110px;height:12px;margin-bottom:0.4rem;"></div>
                    <div class="sk-form-textarea sk-shimmer"></div>
                </div>
            </div>

            <!-- Form Section 4: Amenities -->
            <div class="sk-form-section">
                <div class="sk-form-section-title" style="margin-bottom:0.75rem;">
                    <div class="sk-line sk-shimmer" style="width:170px;height:18px;"></div>
                </div>
                <div class="sk-shimmer" style="height:38px;border-radius:4px;margin-bottom:0.75rem;"></div>
                <div class="sk-form-chips">
                    <?php for ($i = 0; $i < 12; $i++): ?>
                    <div class="sk-shimmer" style="height:32px;border-radius:4px;"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Form Section 5: Property Images -->
            <div class="sk-form-section">
                <div class="sk-form-section-title" style="margin-bottom:0.75rem;">
                    <div class="sk-line sk-shimmer" style="width:160px;height:18px;"></div>
                </div>
                <div class="sk-upload-area">
                    <div style="text-align:center;">
                        <div class="sk-shimmer" style="width:40px;height:40px;border-radius:50%;margin:0 auto 0.5rem;"></div>
                        <div class="sk-line sk-shimmer" style="width:150px;height:13px;margin:0 auto;"></div>
                    </div>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="sk-form-section" style="display:flex;gap:1rem;padding:1.25rem 1.75rem;">
                <div class="sk-shimmer" style="width:160px;height:42px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:100px;height:42px;border-radius:4px;"></div>
            </div>

        </div><!-- /#sk-screen -->

        <div id="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="bi bi-plus-circle me-2" style="color: var(--gold);"></i>Add New Property</h1>
                    <p class="subtitle">Fill in the details below to create a new property listing</p>
                </div>
            </div>
        </div>
    
        <!-- Form Progress Indicator -->
        <div class="form-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="formProgress"></div>
            </div>
            <div class="progress-text" id="progressText">Complete the form below to add a new property</div>
        </div>

        <?php if ($message_text): ?>
        <script>
        document.addEventListener('skeleton:hydrated', function() {
            <?php
                $toast_type = ($message_type === 'success') ? 'success' : 'error';
                $toast_title = ($message_type === 'success') ? 'Success' : 'Error';
            ?>
            showToast('<?= $toast_type ?>', '<?= $toast_title ?>', '<?= addslashes(htmlspecialchars($message_text)) ?>', 6000);
        });
        </script>
        <?php endif; ?>

        <!-- Form -->
        <form action="save_property.php" method="POST" enctype="multipart/form-data" id="propertyForm">
                    
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-house"></i>
                            Basic Information
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="StreetAddress" class="form-label">
                                    Street Address <span class="required">*</span>
                                </label>
                                <div class="property-input-group">
                                    <i class="bi bi-geo-alt property-input-icon"></i>
                                    <input type="text" id="StreetAddress" name="StreetAddress" class="form-control property-form-input" 
                                           placeholder="Enter street address" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="City" class="form-label">
                                    City <span class="required">*</span>
                                </label>
                                <input type="text" id="City" name="City" class="form-control property-form-input" 
                                       placeholder="City name" required>
                            </div>
                            <div class="col-md-2">
                                <label for="Province" class="form-label">
                                    Province <span class="required">*</span>
                                </label>
                                <input type="text" id="Province" name="Province" class="form-control property-form-input" 
                                       placeholder="e.g., Cebu" 
                                       title="Enter your province" required>
                            </div>
                            <div class="col-md-1">
                                <label for="ZIP" class="form-label">
                                    ZIP <span class="required">*</span>
                                </label>
                    <input type="text" id="ZIP" name="ZIP" class="form-control property-form-input" 
                        placeholder="ZIP code" pattern="\d{4}" maxlength="4" inputmode="numeric" title="Enter a 4-digit PH postal code" required>
                            </div>
                            <div class="col-md-6">
                                <label for="Barangay" class="form-label">Barangay <span class="optional">(Optional)</span></label>
                                <input type="text" id="Barangay" name="Barangay" class="form-control property-form-input" 
                                       placeholder="e.g., Brgy. San Jose">
                            </div>
                            <div class="col-md-3">
                                <label for="PropertyType" class="form-label">
                                    Property Type <span class="required">*</span>
                                </label>
                                <select id="PropertyType" name="PropertyType" class="form-select property-form-select" required>
                                    <option value="">Select Property Type</option>
                                    <?php while ($pt = $property_types_result->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($pt['type_name']); ?>"><?php echo htmlspecialchars($pt['type_name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="Status" class="form-label">
                                    Status <span class="required">*</span>
                                </label>
                                <select id="Status" name="Status" class="form-select property-form-select" required>
                                    <option value="">Select Status</option>
                                    <option value="For Sale">For Sale</option>
                                    <option value="For Rent">For Rent</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Property Details Section -->
                    <div class="form-section" id="propertyDetailsSection">
                        <h3 class="section-title">
                            <i class="bi bi-rulers"></i>
                            Property Details
                        </h3>

                        <!-- First row: five compact inputs (now equally expanded to maximize width) -->
                        <div class="row g-2 align-items-start">
                            <div class="col">
                                <div class="form-group">
                                    <label for="YearBuilt" class="form-label">Year Built <span class="optional">(Optional)</span></label>
                                    <input type="number" id="YearBuilt" name="YearBuilt" class="form-control property-form-input" min="1800" max="<?php echo date("Y") + 5; ?>" placeholder="e.g., 2020">
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="NumberOfFloors" class="form-label">Floors <span class="optional">(Optional)</span></label>
                                    <input type="number" id="NumberOfFloors" name="NumberOfFloors" class="form-control property-form-input" min="1" max="10" placeholder="1-10" value="1">
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="Bedrooms" class="form-label">Bedrooms <span class="optional">(Optional)</span></label>
                                    <input type="number" id="Bedrooms" name="Bedrooms" class="form-control property-form-input" min="0" placeholder="e.g., 3">
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="Bathrooms" class="form-label">Bathrooms <span class="optional">(Optional)</span></label>
                                    <input type="number" step="0.5" id="Bathrooms" name="Bathrooms" class="form-control property-form-input" min="0" placeholder="e.g., 2.5">
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="ListingDate" class="form-label">Listing Date <span class="required">*</span></label>
                                    <input type="date" id="ListingDate" name="ListingDate" class="form-control property-form-input" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Second row: four remaining wider inputs -->
                        <div class="row g-3 align-items-start mt-2">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="SquareFootage" class="form-label" id="SquareFootageLabel">Square Footage (ft²) <span class="required">*</span></label>
                                    <div class="property-input-group">
                                        <i class="bi bi-arrows-fullscreen property-input-icon"></i>
                                        <input type="number" id="SquareFootage" name="SquareFootage" class="form-control property-form-input" min="1" placeholder="e.g., 2500" required>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="LotSize" class="form-label" id="LotSizeLabel">Lot Size (acres) <span class="optional">(Optional)</span></label>
                                    <input type="number" step="0.01" id="LotSize" name="LotSize" class="form-control property-form-input" min="0" placeholder="e.g., 0.25">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ParkingType" class="form-label">Parking Type <span class="optional">(Optional)</span></label>
                                    <input type="text" id="ParkingType" name="ParkingType" class="form-control property-form-input" placeholder="e.g., Garage, Driveway">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="ListingPrice" class="form-label" id="priceLabel">Listing Price <span class="required">*</span></label>
                                    <div class="property-input-group">
                                        <!-- Peso Icon (Text only, no Bootstrap Icon class) -->
                                        <span class="property-input-icon fw-bold" style="font-size: 1.1rem;">₱</span>
                                        <input type="number" step="0.01" id="ListingPrice" name="ListingPrice" class="form-control property-form-input" min="0.01" placeholder="e.g., 500,000" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Rental Details Section -->
                    <div class="form-section d-none" id="rentalDetailsSection">
                        <h3 class="section-title">
                            <i class="bi bi-key"></i>
                            Rental Details
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="SecurityDeposit" class="form-label">Security Deposit</label>
                                <div class="property-input-group">
                                    <i class="bi bi-shield-lock property-input-icon"></i>
                                    <input type="number" step="0.01" min="0" id="SecurityDeposit" name="SecurityDeposit" class="form-control property-form-input" placeholder="e.g., 50000">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="LeaseTermMonths" class="form-label">Lease Term (months)</label>
                                <select id="LeaseTermMonths" name="LeaseTermMonths" class="form-select property-form-select">
                                    <option value="">Select Lease Term</option>
                                    <option value="6">6 months</option>
                                    <option value="12">12 months</option>
                                    <option value="18">18 months</option>
                                    <option value="24">24 months</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="Furnishing" class="form-label">Furnishing</label>
                                <select id="Furnishing" name="Furnishing" class="form-select property-form-select">
                                    <option value="">Select Furnishing</option>
                                    <option value="Unfurnished">Unfurnished</option>
                                    <option value="Semi-Furnished">Semi-Furnished</option>
                                    <option value="Fully Furnished">Fully Furnished</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="AvailableFrom" class="form-label">Available From</label>
                                <input type="date" id="AvailableFrom" name="AvailableFrom" class="form-control property-form-input" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- MLS Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-database"></i>
                            MLS Information
                        </h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="Source" class="form-label">Source (MLS Name) <span class="required">*</span></label>
                                <input type="text" id="Source" name="Source" class="form-control property-form-input" 
                                       placeholder="e.g., Regional MLS" required>
                            </div>
                            <div class="col-md-6">
                                <label for="MLSNumber" class="form-label">MLS Number <span class="required">*</span></label>
                                <input type="text" id="MLSNumber" name="MLSNumber" class="form-control property-form-input" 
                                       placeholder="e.g., MLS123456" required>
                            </div>
                        </div>
                    </div>

                    <!-- Description Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-file-text"></i>
                            Property Description
                        </h3>
                        <div class="row">
                            <div class="col-12">
                                <label for="ListingDescription" class="form-label">Description <span class="required">*</span></label>
                                <textarea id="ListingDescription" name="ListingDescription" class="form-control property-form-textarea" 
                                          rows="6" placeholder="Describe the property features, location benefits, and unique selling points..." required></textarea>
                                <div class="form-text">Provide a detailed description to attract potential buyers or renters.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Amenities Section -->
                    <div class="form-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="section-title mb-0">
                                <i class="bi bi-star"></i>
                                Amenities & Features
                            </h3>
                            <div class="text-muted small">
                                <span id="selectedCount">0</span> selected
                            </div>
                        </div>

                        <?php if ($amenities_result && $amenities_result->num_rows > 0) : ?>
                            <!-- Search Filter -->
                            <div class="amenities-search position-relative">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" id="amenitySearch" class="form-control property-form-input" placeholder="Search amenities...">
                            </div>

                            <div class="amenities-container">
                                <div class="amenities-grid" id="amenitiesList">
                                    <?php while ($amenity = $amenities_result->fetch_assoc()) : ?>
                                        <div class="form-check amenity-item" data-name="<?php echo strtolower(htmlspecialchars($amenity['amenity_name'])); ?>">
                                            <input class="form-check-input amenity-checkbox" type="checkbox" name="amenities[]" 
                                                   value="<?php echo htmlspecialchars($amenity['amenity_id']); ?>" 
                                                   id="amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                            <label class="form-check-label w-100" for="amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                                <?php echo htmlspecialchars($amenity['amenity_name']); ?>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                <div id="noResults" class="text-center py-4 text-muted d-none">
                                    <i class="bi bi-emoji-frown mb-2" style="font-size: 1.5rem;"></i>
                                    <p class="mb-0">No amenities found matching your search.</p>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="text-center py-4">
                                <i class="bi bi-info-circle text-muted" style="font-size: 2rem;"></i>
                                <p class="text-muted mt-2">No amenities found. Please add some in the admin panel.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Featured Images Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-images"></i>
                            Featured Property Photos
                        </h3>
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload general property images (exterior, interior, backyard, frontyard, etc.)
                        </p>
                        <div class="image-upload-section" id="imageUploadSection">
                            <i class="bi bi-cloud-upload upload-icon"></i>
                            <div class="upload-text">Upload Featured Images</div>
                            <div class="upload-subtext">Drag and drop images here, or click to select files</div>
                            <div class="upload-subtext">Maximum 20 images, 25MB per file • JPG, PNG, GIF supported</div>
                            
                            <div class="file-input-wrapper">
                                        <input type="file" id="property_photos" name="property_photos[]" class="file-input" 
                                               accept="image/jpeg,image/png,image/gif" multiple required>
                                        <button type="button" class="file-input-button" onclick="document.getElementById('property_photos').click()">
                                            <i class="bi bi-upload me-2"></i>Choose Images
                                        </button>
                                    </div>
                        </div>
                        
                        <!-- Image Preview Grid -->
                        <div class="image-preview-grid" id="imagePreviewGrid"></div>
                    </div>

                    <!-- Floor-by-Floor Images Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="bi bi-layers"></i>
                            Floor Images
                        </h3>
                        <p class="text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload images for each floor of the property. The number of floor upload sections is based on the "Number of Floors" field above.
                        </p>
                        
                        <!-- Dynamic Floor Upload Containers -->
                        <div id="floorImagesContainer"></div>
                        
                        <div class="text-center mt-3" id="noFloorsMessage">
                            <i class="bi bi-building text-muted" style="font-size: 2rem;"></i>
                            <p class="text-muted mt-2">Set the number of floors above to enable floor-specific image uploads.</p>
                        </div>
                    </div>

                    <!-- Submit Section -->
                    <div class="submit-section">
                        <a href="property.php" class="btn-cancel">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="bi bi-check-circle me-2"></i>Save Property
                        </button>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Please review all information before submitting
                            </small>
                        </div>
                    </div>
                </form>
        </div><!-- /#page-content -->
    </div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="script/add_property_script.js"></script>
<script>
// ===== TOAST NOTIFICATION SYSTEM =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    const container = document.getElementById('toastContainer');
    const icons = {
        success: 'bi-check-circle-fill',
        error:   'bi-x-circle-fill',
        info:    'bi-info-circle-fill',
        warning: 'bi-exclamation-triangle-fill'
    };
    const toast = document.createElement('div');
    toast.className = `app-toast toast-${type}`;
    toast.innerHTML = `
        <div class="app-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
        <div class="app-toast-body">
            <div class="app-toast-title">${title}</div>
            <div class="app-toast-msg">${message}</div>
        </div>
        <button class="app-toast-close" onclick="dismissToast(this.closest('.app-toast'))">&times;</button>
        <div class="app-toast-progress" style="animation: toast-progress ${duration}ms linear forwards;"></div>
    `;
    container.appendChild(toast);
    const timer = setTimeout(() => dismissToast(toast), duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(() => toast.remove(), 320);
}

document.addEventListener('DOMContentLoaded', function(){
    const form = document.getElementById('propertyForm');
    const fileInput = document.getElementById('property_photos');
    const statusSelect = document.getElementById('Status');
    const rentalSection = document.getElementById('rentalDetailsSection');
    const priceLabel = document.getElementById('priceLabel');
    const priceInput = document.getElementById('ListingPrice');
    const squareInput = document.getElementById('SquareFootage');
    const lotInput = document.getElementById('LotSize');
    const squareLabel = document.getElementById('SquareFootageLabel');
    const lotLabel = document.getElementById('LotSizeLabel');
    const rentalRequiredFields = [
        document.getElementById('SecurityDeposit'),
        document.getElementById('LeaseTermMonths'),
        document.getElementById('Furnishing'),
        document.getElementById('AvailableFrom')
    ];

    // Floor Images Management
    const numberOfFloorsInput = document.getElementById('NumberOfFloors');
    const floorImagesContainer = document.getElementById('floorImagesContainer');
    const noFloorsMessage = document.getElementById('noFloorsMessage');
    let floorFileInputs = {}; // Store file inputs for each floor

    // Generate floor upload sections dynamically
    function generateFloorUploadSections(floorCount) {
        floorImagesContainer.innerHTML = '';
        floorFileInputs = {};

        if (floorCount < 1) {
            noFloorsMessage.style.display = 'block';
            return;
        }

        noFloorsMessage.style.display = 'none';

        for (let i = 1; i <= floorCount; i++) {
            const floorCard = document.createElement('div');
            floorCard.className = 'floor-upload-card';
            floorCard.innerHTML = `
                <div class="floor-header">
                    <div class="floor-title">
                        <i class="bi bi-building"></i>
                        ${getFloorLabel(i)}
                    </div>
                    <div class="floor-badge">Floor ${i}</div>
                </div>
                
                <div class="floor-upload-area" id="floorUploadArea_${i}" onclick="document.getElementById('floor_images_${i}').click()">
                    <i class="bi bi-cloud-arrow-up floor-upload-icon"></i>
                    <div class="floor-upload-text">Click to upload images for ${getFloorLabel(i)}</div>
                    <div class="floor-upload-subtext">Max 10 images per floor • JPG, PNG, GIF (25MB each)</div>
                </div>
                
                <input type="file" 
                       id="floor_images_${i}" 
                       name="floor_images_${i}[]" 
                       class="file-input" 
                       accept="image/jpeg,image/png,image/gif" 
                       multiple 
                       style="display: none;">
                
                <div class="floor-preview-grid" id="floorPreviewGrid_${i}"></div>
            `;
            
            floorImagesContainer.appendChild(floorCard);

            // Setup file input handler for this floor
            const floorInput = document.getElementById(`floor_images_${i}`);
            floorFileInputs[i] = floorInput;
            
            floorInput.addEventListener('change', function(e) {
                handleFloorImageUpload(i, e.target.files);
            });
        }
    }

    function getFloorLabel(floorNumber) {
        const labels = {
            1: 'First Floor',
            2: 'Second Floor',
            3: 'Third Floor',
            4: 'Fourth Floor',
            5: 'Fifth Floor',
            6: 'Sixth Floor',
            7: 'Seventh Floor',
            8: 'Eighth Floor',
            9: 'Ninth Floor',
            10: 'Tenth Floor'
        };
        return labels[floorNumber] || `Floor ${floorNumber}`;
    }

    function handleFloorImageUpload(floorNumber, files) {
        const uploadArea = document.getElementById(`floorUploadArea_${floorNumber}`);
        const previewGrid = document.getElementById(`floorPreviewGrid_${floorNumber}`);
        
        if (!files || files.length === 0) return;

        // Limit to 10 files
        if (files.length > 10) {
            alert(`Maximum 10 images allowed per floor. Only the first 10 will be used.`);
        }

        uploadArea.classList.add('has-files');
        previewGrid.innerHTML = '';

        const filesToProcess = Array.from(files).slice(0, 10);

        filesToProcess.forEach((file, index) => {
            // Validate file size (25MB)
            if (file.size > 25 * 1024 * 1024) {
                alert(`File ${file.name} exceeds 25MB limit.`);
                return;
            }

            // Validate file type
            if (!file.type.match('image/(jpeg|png|gif)')) {
                alert(`File ${file.name} is not a supported image format.`);
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const previewItem = document.createElement('div');
                previewItem.className = 'floor-preview-item';
                previewItem.innerHTML = `
                    <img src="${e.target.result}" alt="Floor ${floorNumber} - Image ${index + 1}" class="floor-preview-image">
                    <div class="floor-image-info">${file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name}</div>
                    <button type="button" class="remove-floor-image" onclick="removeFloorImage(${floorNumber}, ${index})" title="Remove image">
                        <i class="bi bi-x"></i>
                    </button>
                `;
                previewGrid.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        });
    }

    // Make removeFloorImage globally accessible
    window.removeFloorImage = function(floorNumber, imageIndex) {
        const floorInput = floorFileInputs[floorNumber];
        if (!floorInput) return;

        // Create a new FileList without the removed file
        const dt = new DataTransfer();
        const files = Array.from(floorInput.files);
        
        files.forEach((file, idx) => {
            if (idx !== imageIndex) {
                dt.items.add(file);
            }
        });

        floorInput.files = dt.files;

        // Re-render previews
        handleFloorImageUpload(floorNumber, floorInput.files);

        // If no files left, remove has-files class
        if (dt.files.length === 0) {
            const uploadArea = document.getElementById(`floorUploadArea_${floorNumber}`);
            uploadArea.classList.remove('has-files');
        }
    };

    // Listen to floor count changes
    if (numberOfFloorsInput) {
        numberOfFloorsInput.addEventListener('input', function() {
            const floorCount = parseInt(this.value) || 0;
            if (floorCount >= 1 && floorCount <= 10) {
                generateFloorUploadSections(floorCount);
            } else if (floorCount > 10) {
                this.value = 10;
                generateFloorUploadSections(10);
            } else {
                generateFloorUploadSections(0);
            }
        });

        // Initialize with default value (1 floor)
        const initialFloors = parseInt(numberOfFloorsInput.value) || 1;
        generateFloorUploadSections(initialFloors);
    }

    form.addEventListener('submit', function(e){
        // remove previous inline errors if present
        const prevPhoto = document.getElementById('photoError');
        if (prevPhoto) prevPhoto.remove();
        const prevRental = document.getElementById('rentalError');
        if (prevRental) prevRental.remove();

        let hasFile = false;
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            // ensure at least one file has a size > 0
            for (let i = 0; i < fileInput.files.length; i++) {
                if (fileInput.files[i].size > 0) { hasFile = true; break; }
            }
        }

        if (!hasFile) {
            e.preventDefault();
            const alertDiv = document.createElement('div');
            alertDiv.id = 'photoError';
            alertDiv.className = 'alert alert-danger';
            alertDiv.textContent = 'Please upload at least one featured property photo.';
            // insert the alert above the form
            form.parentNode.insertBefore(alertDiv, form);
            alertDiv.scrollIntoView({behavior: 'smooth', block: 'center'});
        }

        // Additional client-side validation for rentals
        const isForRent = statusSelect && statusSelect.value === 'For Rent';
        if (isForRent) {
            const errors = [];
            const dep = document.getElementById('SecurityDeposit');
            const lease = document.getElementById('LeaseTermMonths');
            const furn = document.getElementById('Furnishing');
            const avail = document.getElementById('AvailableFrom');

            const depositVal = dep && dep.value !== '' ? parseFloat(dep.value) : NaN;
            if (isNaN(depositVal) || depositVal < 0) {
                errors.push('Security Deposit must be 0 or more.');
            }

            // Validate monthly rent (ListingPrice) exists and is positive
            const listingPriceEl = document.getElementById('ListingPrice');
            const listingPriceVal = listingPriceEl && listingPriceEl.value !== '' ? parseFloat(listingPriceEl.value) : NaN;
            if (isNaN(listingPriceVal) || listingPriceVal <= 0) {
                errors.push('Monthly Rent must be a positive number.');
            }

            // Enforce deposit cap: cannot exceed 12 months of rent
            if (!isNaN(depositVal) && !isNaN(listingPriceVal) && listingPriceVal > 0) {
                const maxDeposit = listingPriceVal * 12;
                if (depositVal > maxDeposit) {
                    errors.push('Security Deposit cannot exceed 12 months of rent (₱' + maxDeposit.toFixed(2) + ').');
                }
            }

            const allowedLease = ['6','12','18','24'];
            if (!lease || !allowedLease.includes((lease.value || '').trim())) {
                errors.push('Lease Term must be one of: 6, 12, 18, or 24 months.');
            }

            const allowedFurn = ['Unfurnished','Semi-Furnished','Fully Furnished'];
            if (!furn || !allowedFurn.includes((furn.value || '').trim())) {
                errors.push('Furnishing must be selected.');
            }

            if (!avail || !avail.value) {
                errors.push('Available From date is required.');
            } else {
                const today = new Date();
                today.setHours(0,0,0,0);
                const avDate = new Date(avail.value + 'T00:00:00');
                if (isNaN(avDate.getTime())) {
                    errors.push('Available From date is invalid.');
                } else if (avDate < today) {
                    errors.push('Available From date cannot be in the past.');
                }
            }

            if (errors.length) {
                e.preventDefault();
                const alertDiv = document.createElement('div');
                alertDiv.id = 'rentalError';
                alertDiv.className = 'alert alert-danger';
                alertDiv.innerHTML = errors.map(x => `• ${x}`).join('<br>');
                form.parentNode.insertBefore(alertDiv, form);
                alertDiv.scrollIntoView({behavior: 'smooth', block: 'center'});
            }

            // Require at least one image per floor
            const floorCount = parseInt(numberOfFloorsInput && numberOfFloorsInput.value ? numberOfFloorsInput.value : '0') || 0;
            if (floorCount > 0) {
                const missingFloors = [];
                let firstErrorCard = null;
                for (let i = 1; i <= floorCount; i++) {
                    const input = document.getElementById(`floor_images_${i}`);
                    const uploadArea = document.getElementById(`floorUploadArea_${i}`);
                    const card = uploadArea ? uploadArea.closest('.floor-upload-card') : null;
                    // Clear previous error state
                    if (card) card.classList.remove('error');

                    const hasFiles = input && input.files && input.files.length > 0 && Array.from(input.files).some(f => f.size > 0);
                    if (!hasFiles) {
                        missingFloors.push(i);
                        if (card) {
                            card.classList.add('error');
                            if (!firstErrorCard) firstErrorCard = card;
                        }
                    }
                }

                if (missingFloors.length > 0) {
                    e.preventDefault();
                    // Remove existing floor error if any
                    const prevFloorErr = document.getElementById('floorImagesError');
                    if (prevFloorErr) prevFloorErr.remove();
                    const alertDiv = document.createElement('div');
                    alertDiv.id = 'floorImagesError';
                    alertDiv.className = 'alert alert-danger';
                    const missingLabel = missingFloors.map(n => `Floor ${n}`).join(', ');
                    alertDiv.innerHTML = `<strong>Floor images required:</strong> Please upload at least one image for each floor. Missing: ${missingLabel}.`;
                    // Insert error just above the submit section (after floor images section)
                    const floorSection = document.getElementById('floorImagesContainer');
                    if (floorSection && floorSection.parentNode) {
                        floorSection.parentNode.insertBefore(alertDiv, floorSection.nextSibling);
                    } else {
                        form.parentNode.insertBefore(alertDiv, form);
                    }
                    (firstErrorCard || alertDiv).scrollIntoView({behavior: 'smooth', block: 'center'});
                    return;
                }
            }
        }
    });

    function toggleRentalFields() {
        const isForRent = statusSelect && statusSelect.value === 'For Rent';
        if (isForRent) {
            rentalSection.classList.remove('d-none');
            // Switch label to Monthly Rent
            if (priceLabel) priceLabel.innerHTML = 'Monthly Rent <span class="required">*</span>';
            if (priceInput) priceInput.placeholder = 'e.g., 25000';
            // add required to rental fields
            rentalRequiredFields.forEach(el => { if (el) el.setAttribute('required', 'required'); });

            // SquareFootage stays required; LotSize is optional for rentals
            if (squareLabel) squareLabel.innerHTML = 'Square Footage (ft²) <span class="required">*</span>';
            if (lotLabel) lotLabel.innerHTML = 'Lot Size (acres) <span class="optional">(Optional)</span>';
        } else {
            rentalSection.classList.add('d-none');
            if (priceLabel) priceLabel.innerHTML = 'Listing Price <span class="required">*</span>';
            if (priceInput) priceInput.placeholder = 'e.g., 500000';
            rentalRequiredFields.forEach(el => { if (el) el.removeAttribute('required'); });

            // SquareFootage stays required; LotSize optional
            if (squareLabel) squareLabel.innerHTML = 'Square Footage (ft²) <span class="required">*</span>';
            if (lotLabel) lotLabel.innerHTML = 'Lot Size (acres) <span class="optional">(Optional)</span>';
        }
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleRentalFields);
        // initialize on load (handles preselected value)
        toggleRentalFields();
    }

    // Amenities Search & Filter Logic
    const amenitySearch = document.getElementById('amenitySearch');
    const amenitiesList = document.getElementById('amenitiesList');
    const selectedCountSpan = document.getElementById('selectedCount');
    const noResultsMsg = document.getElementById('noResults');

    if (amenitySearch && amenitiesList) {
        const amenityItems = amenitiesList.querySelectorAll('.amenity-item');
        const checkboxes = amenitiesList.querySelectorAll('.amenity-checkbox');

        // Filter function
        amenitySearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            let hasVisibleItems = false;
            
            // Loop through all items and hide/show based on search
            const items = amenitiesList.querySelectorAll('.amenity-item');
            
            items.forEach(item => {
                const name = item.getAttribute('data-name');
                // Check if the amenity name STARTS with the search term
                if (name.startsWith(searchTerm)) {
                    item.style.display = 'flex'; // Restore flex display
                    hasVisibleItems = true;
                } else {
                    item.style.display = 'none';
                }
            });

            if (noResultsMsg) {
                if (hasVisibleItems) {
                    noResultsMsg.classList.add('d-none');
                } else {
                    noResultsMsg.classList.remove('d-none');
                }
            }
        });

        // Update count function
        function updateSelectedCount() {
            const count = Array.from(checkboxes).filter(cb => cb.checked).length;
            if (selectedCountSpan) selectedCountSpan.textContent = count;
        }

        // Add listeners to checkboxes
        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // Make the whole item clickable
        amenityItems.forEach(item => {
            item.addEventListener('click', function(e) {
                // If the click is on the input or label, let them handle it
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
                
                const checkbox = this.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    updateSelectedCount();
                }
            });
        });
    }
});
</script>

    <!-- ══════════════════════════════════════════════════════════
         SKELETON HYDRATION SCRIPT
         Waits for window 'load' (fonts + CSS ready) then
         cross-fades skeleton out and real content in.
    ══════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';
        var MIN_SKELETON_MS = 400;
        var skeletonStart   = Date.now();
        var hydrated        = false;
        function hydrate() {
            if (hydrated) return; hydrated = true;
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!sk || !pc) return;
            sk.style.transition = 'opacity 0.35s ease'; sk.style.opacity = '0';
            setTimeout(function () { sk.style.display = 'none'; }, 360);
            pc.style.opacity = '0'; pc.style.display = 'block';
            requestAnimationFrame(function () { pc.style.transition = 'opacity 0.4s ease'; pc.style.opacity = '1'; });
            setTimeout(function () { document.dispatchEvent(new CustomEvent('skeleton:hydrated')); }, 520);
        }
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = MIN_SKELETON_MS - elapsed;
            if (remaining <= 0) { hydrate(); } else { setTimeout(hydrate, remaining); }
        }
        window.addEventListener('load', scheduleHydration);
    }());
    </script>
</body>
</html>