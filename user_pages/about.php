<?php
include '../connection.php';

// Fetch stats for about page
$properties_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'approved'");
$properties_count = $properties_count_result->fetch_assoc()['count'];

$agents_count_result = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1");
$agents_count = $agents_count_result->fetch_assoc()['count'];

$sold_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Sold' AND approval_status = 'approved'");
$sold_count = $sold_count_result->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        /* ============================
           SCROLL REVEAL ANIMATION SYSTEM
        ============================ */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        .reveal.reveal-left {
            transform: translateX(-50px);
        }

        .reveal.reveal-right {
            transform: translateX(50px);
        }

        .reveal.reveal-scale {
            transform: scale(0.92);
        }

        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0) translateX(0) scale(1);
        }

        /* Stagger delays for children */
        .stagger-children .reveal:nth-child(1) { transition-delay: 0s; }
        .stagger-children .reveal:nth-child(2) { transition-delay: 0.1s; }
        .stagger-children .reveal:nth-child(3) { transition-delay: 0.15s; }

        /* Hero entrance cascade */
        .about-hero-inner > * {
            opacity: 0;
            transform: translateY(30px);
            animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .about-hero-inner > *:nth-child(1) { animation-delay: 0.15s; }
        .about-hero-inner > *:nth-child(2) { animation-delay: 0.3s; }
        .about-hero-inner > *:nth-child(3) { animation-delay: 0.45s; }

        @keyframes heroFadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Subtle floating animation for hero background */
        @keyframes subtleFloat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        .about-hero::before {
            animation: subtleFloat 20s ease-in-out infinite;
        }

        /* ============================
           SECTION HELPERS (shared)
        ============================ */
        .section-badge {
            display: inline-block;
            padding: 6px 16px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--blue-light);
            margin-bottom: 16px;
        }

        .section-badge.warm {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.14) 100%);
            border: 1px solid rgba(212, 175, 55, 0.25);
            color: var(--gold);
        }

        .section-title {
            font-size: 2.75rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 16px;
        }

        .section-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-divider {
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-dark));
            margin: 20px auto 0;
            border-radius: 1px;
            opacity: 0.6;
        }

        .section-description {
            font-size: 1.1rem;
            color: var(--gray-300);
            max-width: 640px;
            margin: 0 auto;
            line-height: 1.75;
        }

        /* ============================
           BUTTONS
        ============================ */
        .btn-primary-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            padding: 16px 40px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25),
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary-gold::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4),
                        0 0 0 1px rgba(212, 175, 55, 0.4);
            color: var(--black);
        }

        .btn-primary-gold:hover::before { left: 100%; }

        .btn-outline-blue {
            background: transparent;
            color: var(--gray-200);
            border: 1px solid rgba(37, 99, 235, 0.4);
            padding: 14px 36px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-outline-blue:hover {
            color: var(--blue-light);
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.08);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2);
        }

        /* ============================
           HERO — About Page
        ============================ */
        .about-hero {
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 140px 20px 100px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 0 -1px 0 rgba(212, 175, 55, 0.1);
            overflow: hidden;
        }

        .about-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image:
                radial-gradient(ellipse at top right, rgba(212, 175, 55, 0.05) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(37, 99, 235, 0.04) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.84), rgba(10, 10, 10, 0.92)),
                /* IMAGE: A warm, inviting scene — perhaps a family on their porch, a cozy 
                   neighbourhood street at golden hour, or a welcoming front door with 
                   warm light spilling out. Should feel personal and hopeful. */
                url('../images/hero-bg.jpg');
            background-size: cover;
            background-position: center;
        }

        .about-hero-inner {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 820px;
            margin: 0 auto;
        }

        .about-hero-inner::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 600px; height: 400px;
            background: radial-gradient(ellipse, rgba(212, 175, 55, 0.06) 0%, transparent 70%);
            pointer-events: none;
            z-index: -1;
        }

        .about-hero-badge {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.14) 100%);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 2px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gold);
            margin-bottom: 28px;
        }

        .about-hero-title {
            font-size: 3.75rem;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.15;
            color: var(--white);
            text-shadow: 0 2px 20px rgba(0,0,0,0.4);
        }

        .about-hero-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
        }

        .about-hero-subtitle {
            font-size: 1.2rem;
            color: var(--gray-300);
            line-height: 1.85;
            max-width: 620px;
            margin: 0 auto;
        }

        /* ============================
           STORY SECTION — Who We Are
        ============================ */
        .story-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, var(--black) 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            position: relative;
        }

        .story-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 15% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 45%),
                radial-gradient(circle at 85% 50%, rgba(37, 99, 235, 0.03) 0%, transparent 45%);
            pointer-events: none;
        }

        .story-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .story-image-container {
            position: relative;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.15);
        }

        .story-image-container img {
            width: 100%;
            height: 440px;
            object-fit: cover;
            display: block;
            transition: transform 0.5s ease;
        }

        .story-image-container:hover img {
            transform: scale(1.03);
        }

        .story-image-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 40%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.7), transparent);
            pointer-events: none;
        }

        .story-text h2 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .story-text h2 .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .story-text p {
            font-size: 1.05rem;
            color: var(--gray-300);
            line-height: 1.85;
            margin-bottom: 20px;
        }

        /* ============================
           MISSION & VISION — Side by side
        ============================ */
        .mission-vision-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black) 0%, var(--black-light) 50%, var(--black) 100%);
            position: relative;
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
        }

        .mission-vision-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(37, 99, 235, 0.03) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.03) 0%, transparent 40%);
            pointer-events: none;
        }

        .mv-header {
            text-align: center;
            margin-bottom: 60px;
            position: relative;
            z-index: 1;
        }

        .mv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .mv-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.5) 0%, rgba(10, 10, 10, 0.7) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 6px;
            padding: 48px 36px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            text-align: center;
        }

        .mv-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.5;
        }

        .mv-card:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.3), 0 6px 16px rgba(37, 99, 235, 0.1);
            transform: translateY(-4px);
        }

        .mv-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25);
        }

        .mv-icon i {
            font-size: 26px;
            color: var(--black);
        }

        .mv-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 16px;
        }

        .mv-card p {
            font-size: 1rem;
            color: var(--gray-300);
            line-height: 1.8;
        }

        /* ============================
           WHO WE HELP — Platform Support
        ============================ */
        .support-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, #0d0d0d 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            position: relative;
        }

        .support-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 10% 20%, rgba(37, 99, 235, 0.04) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 40%);
            pointer-events: none;
        }

        .support-header {
            text-align: center;
            margin-bottom: 64px;
            position: relative;
            z-index: 1;
        }

        .support-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            max-width: 1100px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .support-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.5) 0%, rgba(10, 10, 10, 0.7) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 6px;
            padding: 44px 32px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .support-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .support-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 16px 44px rgba(0, 0, 0, 0.3), 0 6px 20px rgba(37, 99, 235, 0.12);
            transform: translateY(-6px);
        }

        .support-card:hover::before { opacity: 1; }

        .support-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.12) 0%, rgba(37, 99, 235, 0.08) 100%);
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--blue-light);
            transition: all 0.3s ease;
        }

        .support-card:hover .support-icon {
            border-color: rgba(37, 99, 235, 0.4);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.15);
        }

        .support-card h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .support-card p {
            font-size: 0.95rem;
            color: var(--gray-400);
            line-height: 1.7;
            margin-bottom: 0;
        }

        .support-list {
            list-style: none;
            padding: 0;
            margin: 16px 0 0 0;
            text-align: left;
        }

        .support-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
            font-size: 0.9rem;
            color: var(--gray-400);
        }

        .support-list li i {
            color: var(--gold);
            font-size: 0.85rem;
            margin-top: 3px;
            flex-shrink: 0;
        }

        /* ============================
           TRUST SECTION — Commitment
        ============================ */
        .trust-section {
            padding: 100px 20px;
            background: var(--black);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            position: relative;
        }

        .trust-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 50% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .trust-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .trust-text h2 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .trust-text h2 .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .trust-text p {
            font-size: 1.05rem;
            color: var(--gray-300);
            line-height: 1.85;
            margin-bottom: 24px;
        }

        .trust-promises {
            list-style: none;
            padding: 0;
            margin: 0 0 32px 0;
        }

        .trust-promises li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            font-size: 0.95rem;
            color: var(--gray-300);
        }

        .trust-promises li i {
            color: var(--gold);
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .trust-image-container {
            position: relative;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.15);
        }

        .trust-image-container img {
            width: 100%;
            height: 440px;
            object-fit: cover;
            display: block;
            transition: transform 0.5s ease;
        }

        .trust-image-container:hover img {
            transform: scale(1.03);
        }

        .trust-image-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 35%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.6), transparent);
            pointer-events: none;
        }

        /* ============================
           STATS — Small & Gentle
        ============================ */
        .about-stats-section {
            background: linear-gradient(180deg, var(--black) 0%, #0c0c0c 50%, var(--black) 100%);
            padding: 80px 20px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            position: relative;
        }

        .about-stats-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(37, 99, 235, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .about-stats-header {
            text-align: center;
            margin-bottom: 52px;
            position: relative;
            z-index: 1;
        }

        .about-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .about-stat-item {
            text-align: center;
            padding: 28px 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.03) 0%, rgba(212, 175, 55, 0.02) 100%);
            border-radius: 6px;
            border: 1px solid rgba(37, 99, 235, 0.1);
            transition: all 0.3s ease;
        }

        .about-stat-item:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.3), 0 6px 16px rgba(37, 99, 235, 0.12);
            transform: translateY(-4px);
        }

        .about-stat-number {
            font-size: 2.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            margin-bottom: 6px;
        }

        .about-stat-label {
            font-size: 0.9rem;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================
           WARM CTA — Closing section
        ============================ */
        .warmcta-section {
            padding: 100px 20px;
            background:
                radial-gradient(circle at 30% 50%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                linear-gradient(135deg, var(--black-light) 0%, var(--black) 100%);
            border-top: 1px solid rgba(37, 99, 235, 0.2);
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .warmcta-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg,
                transparent 0%,
                rgba(37, 99, 235, 0.4) 25%,
                rgba(212, 175, 55, 0.4) 75%,
                transparent 100%);
        }

        .warmcta-content {
            max-width: 720px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .warmcta-title {
            font-size: 2.75rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--white);
            line-height: 1.2;
        }

        .warmcta-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .warmcta-description {
            font-size: 1.15rem;
            color: var(--gray-300);
            margin-bottom: 40px;
            line-height: 1.75;
        }

        .warmcta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ============================
           FOOTER — Consistent with homepage
        ============================ */
        .footer {
            background: linear-gradient(180deg, #0d0d0d 0%, var(--black-light) 100%);
            color: var(--gray-400);
            padding: 60px 20px 30px;
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            position: relative;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 25% 30%, rgba(37, 99, 235, 0.02) 0%, transparent 50%),
                radial-gradient(circle at 75% 70%, rgba(212, 175, 55, 0.02) 0%, transparent 50%);
            pointer-events: none;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 48px;
            max-width: 1200px;
            margin: 0 auto 40px;
            position: relative;
            z-index: 1;
        }

        .footer-section h5 {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            font-weight: 700;
            font-size: 1.125rem;
        }

        .footer-section p {
            color: var(--gray-400);
            line-height: 1.8;
        }

        .footer-section ul { list-style: none; }
        .footer-section ul li { margin-bottom: 12px; }

        .footer-section a {
            color: var(--gray-400);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .footer-section a:hover { color: var(--blue-light); }

        .footer-bottom {
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            padding-top: 30px;
            text-align: center;
            color: var(--gray-600);
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .social-links {
            display: flex;
            gap: 16px;
        }

        .social-links a {
            width: 40px; height: 40px;
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-400);
            font-size: 18px;
            transition: all 0.3s ease;
            background: rgba(37, 99, 235, 0.03);
        }

        .social-links a:hover {
            border-color: var(--blue);
            color: var(--blue-light);
            background: rgba(37, 99, 235, 0.1);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2);
        }

        /* ============================
           RESPONSIVE
        ============================ */
        @media (max-width: 992px) {
            .story-grid,
            .trust-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .story-image-container { order: -1; }

            .mv-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .support-grid {
                grid-template-columns: 1fr;
                max-width: 520px;
            }
        }

        @media (max-width: 768px) {
            .about-hero {
                padding: 120px 20px 70px;
                min-height: 60vh;
            }

            .about-hero-title { font-size: 2.5rem; }
            .about-hero-subtitle { font-size: 1rem; }
            .section-title { font-size: 2rem; }
            .story-text h2,
            .trust-text h2 { font-size: 1.85rem; }
            .warmcta-title { font-size: 2rem; }

            .about-stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
                max-width: 360px;
            }

            .warmcta-buttons { flex-direction: column; }
            .warmcta-buttons a { width: 100%; justify-content: center; }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        @media (max-width: 576px) {
            .about-hero-title { font-size: 2rem; }
            .about-stat-number { font-size: 2.25rem; }
        }
    </style>
</head>
<body>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<main>

    <!-- ============================================================
         HERO — Heartfelt Introduction
    ============================================================ -->
    <section class="about-hero">
        <div class="container">
            <div class="about-hero-inner">

                <div class="about-hero-badge">About Us</div>

                <h1 class="about-hero-title">
                    More Than Just<br>Finding a <span class="gold-text">Home</span>
                </h1>

                <p class="about-hero-subtitle">
                    We believe every person deserves a place that feels right — somewhere safe, 
                    warm, and full of possibility. At HomeEstate Realty, we don't just help 
                    people find properties. We help them find where they belong.
                </p>

            </div>
        </div>
    </section>

    <!-- ============================================================
         OUR STORY — Who We Are
    ============================================================ -->
    <section class="story-section">
        <div class="container">
            <div class="story-grid">

                <div class="story-image-container reveal reveal-left" data-parallax>
                    <!-- IMAGE: A friendly, candid moment — could be a couple receiving keys, 
                         a family looking at a new home together, or a warm handshake between 
                         an agent and a happy client. Should feel genuine and relatable. -->
                    <img src="../images/hero-bg2.jpg" alt="Helping families find home">
                    <div class="story-image-overlay"></div>
                </div>

                <div class="story-text reveal reveal-right">
                    <div class="section-badge warm">Our Story</div>
                    <h2>We Started With a <span class="gold-text">Simple Belief</span></h2>
                    <p>
                        Finding a home shouldn't feel overwhelming or impersonal. 
                        It should feel like someone genuinely cares about where you end up — 
                        because where you live shapes how you live.
                    </p>
                    <p>
                        That belief is why HomeEstate Realty exists. We built this platform 
                        not as a business tool, but as a bridge — connecting people who are 
                        looking for a place to call their own with agents who truly want 
                        to help them get there.
                    </p>
                    <p>
                        Every listing on our platform represents more than square footage and 
                        price tags. It represents someone's future morning routine, their 
                        kids' first steps, their quiet evenings — a life waiting to unfold.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================
         MISSION & VISION
    ============================================================ -->
    <section class="mission-vision-section">
        <div class="container">
            <div class="mv-header reveal">
                <div class="section-badge">What Drives Us</div>
                <h2 class="section-title">Our Mission <span class="gold-text">&</span> Vision</h2>
                <div class="section-divider"></div>
            </div>

            <div class="mv-grid stagger-children">
                <div class="mv-card reveal reveal-scale">
                    <div class="mv-icon">
                        <i class="bi bi-heart-fill"></i>
                    </div>
                    <h3>Our Mission</h3>
                    <p>
                        To make the journey of finding a home feel personal, guided, and reassuring. 
                        We exist to connect people with places where they can build their lives, 
                        create memories, and feel truly at peace — supported every step of the way 
                        by people who care.
                    </p>
                </div>

                <div class="mv-card reveal reveal-scale">
                    <div class="mv-icon">
                        <i class="bi bi-eye-fill"></i>
                    </div>
                    <h3>Our Vision</h3>
                    <p>
                        A world where finding a home isn't stressful or uncertain, but a meaningful 
                        experience built on trust and genuine care. We envision long-term 
                        relationships — not transactions — where every person feels heard, 
                        respected, and confident in one of life's biggest decisions.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         WHO WE HELP — Buyers, Renters, Agents
    ============================================================ -->
    <section class="support-section">
        <div class="container">
            <div class="support-header reveal">
                <div class="section-badge">Who We're Here For</div>
                <h2 class="section-title">Everyone Deserves <span class="gold-text">Support</span></h2>
                <p class="section-description">
                    Whether you're searching for your first home, finding a place to rent, 
                    or helping others discover theirs — we're here to make it easier.
                </p>
                <div class="section-divider"></div>
            </div>

            <div class="support-grid stagger-children">
                <div class="support-card reveal">
                    <div class="support-icon">
                        <i class="bi bi-house-heart"></i>
                    </div>
                    <h3>Home Buyers</h3>
                    <p>
                        Buying a home is one of the most meaningful decisions you'll ever make. 
                        We help you explore properties and find the one that truly feels right.
                    </p>
                    <ul class="support-list">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Browse verified, up-to-date listings
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Schedule tours at your convenience
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Save and compare your favorite homes
                        </li>
                    </ul>
                </div>

                <div class="support-card reveal">
                    <div class="support-icon">
                        <i class="bi bi-key"></i>
                    </div>
                    <h3>Renters</h3>
                    <p>
                        Whether it's your first apartment or a temporary fresh start, 
                        finding the right rental should feel simple, not stressful.
                    </p>
                    <ul class="support-list">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Filter by location, price, and type
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Connect directly with property agents
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Move-in with confidence and clarity
                        </li>
                    </ul>
                </div>

                <div class="support-card reveal">
                    <div class="support-icon">
                        <i class="bi bi-person-heart"></i>
                    </div>
                    <h3>Real Estate Agents</h3>
                    <p>
                        We give agents the tools they need so they can focus on what matters most — 
                        helping people find the right home.
                    </p>
                    <ul class="support-list">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Manage listings with ease
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Handle tour requests seamlessly
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            Build trust with a verified profile
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         TRUST — Our Commitment
    ============================================================ -->
    <section class="trust-section">
        <div class="container">
            <div class="trust-grid">

                <div class="trust-text reveal reveal-left">
                    <div class="section-badge warm">Our Promise</div>
                    <h2>Built on <span class="gold-text">Trust</span>, Not Just Technology</h2>
                    <p>
                        We know that searching for a home can feel uncertain. That's why 
                        we've made it our priority to build a platform you can rely on — 
                        one that's as honest and straightforward as the people behind it.
                    </p>
                    <ul class="trust-promises">
                        <li>
                            <i class="bi bi-shield-check"></i>
                            <span><strong>Transparency First</strong> — Every listing is verified. 
                            No hidden fees, no misleading photos, no surprises.</span>
                        </li>
                        <li>
                            <i class="bi bi-hand-thumbs-up"></i>
                            <span><strong>Reliable Information</strong> — Accurate details, real photos, 
                            and up-to-date availability so you can make confident decisions.</span>
                        </li>
                        <li>
                            <i class="bi bi-people"></i>
                            <span><strong>People Over Profit</strong> — We vet every agent on our platform 
                            because your trust matters more than our growth.</span>
                        </li>
                        <li>
                            <i class="bi bi-chat-dots"></i>
                            <span><strong>Always Reachable</strong> — Have questions? Need help? We're here. 
                            No automated runaround — just real support from real people.</span>
                        </li>
                    </ul>
                </div>

                <div class="trust-image-container reveal reveal-right" data-parallax>
                    <!-- IMAGE: A warm, trust-building image — a handshake, a welcome sign, 
                         a front door with warm light, or a neighbourhood at dawn. 
                         Should feel safe and reassuring. -->
                    <img src="../images/bannerListing.jpg" alt="Our commitment to trust">
                    <div class="trust-image-overlay"></div>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================
         GENTLE STATS
    ============================================================ -->
    <section class="about-stats-section">
        <div class="container">
            <div class="about-stats-header reveal">
                <div class="section-badge">By the Numbers</div>
                <h2 class="section-title">Real People, <span class="gold-text">Real Results</span></h2>
                <div class="section-divider"></div>
            </div>
            <div class="about-stats-grid stagger-children">
                <div class="about-stat-item reveal">
                    <span class="about-stat-number"><?php echo number_format($properties_count); ?>+</span>
                    <span class="about-stat-label">Properties Listed</span>
                </div>
                <div class="about-stat-item reveal">
                    <span class="about-stat-number"><?php echo number_format($sold_count); ?>+</span>
                    <span class="about-stat-label">Families Settled</span>
                </div>
                <div class="about-stat-item reveal">
                    <span class="about-stat-number"><?php echo number_format($agents_count); ?>+</span>
                    <span class="about-stat-label">Trusted Agents</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         WARM CTA — Come Home
    ============================================================ -->
    <section class="warmcta-section">
        <div class="container">
            <div class="warmcta-content reveal reveal-scale">
                <h2 class="warmcta-title">Ready to Find Where<br>You <span class="gold-text">Belong</span>?</h2>
                <p class="warmcta-description">
                    Your next chapter is waiting. Whether you're buying, renting, or just starting 
                    to look — we'd love to help you find a place that feels like home.
                </p>
                <div class="warmcta-buttons">
                    <a href="index.php" class="btn-primary-gold">
                        <i class="bi bi-house-door"></i>
                        Browse Homes
                    </a>
                    <a href="agents.php" class="btn-outline-blue">
                        <i class="bi bi-chat-heart"></i>
                        Talk to an Agent
                    </a>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h5>HomeEstate Realty</h5>
                <p>Helping people find not just a house, but a place where they truly belong. Your comfort and happiness is what guides everything we do.</p>
            </div>
            <div class="footer-section">
                <h5>Quick Links</h5>
                <ul class="list-unstyled">
                    <li><a href="search_results.php">Browse Homes</a></li>
                    <li><a href="search_results.php?status=For+Sale">Homes for Sale</a></li>
                    <li><a href="search_results.php?status=For+Rent">Homes for Rent</a></li>
                    <li><a href="about.php">About Us</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h5>Support</h5>
                <ul class="list-unstyled">
                    <li><a href="agents.php">Find an Agent</a></li>
                    <li><a href="about.php">How It Works</a></li>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Contact Us</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h5>Connect With Us</h5>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> HomeEstate Realty. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function() {
    'use strict';

    /* =============================================
       1. SCROLL REVEAL — IntersectionObserver
    ============================================= */
    var revealElements = document.querySelectorAll('.reveal');

    var revealObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -40px 0px'
    });

    revealElements.forEach(function(el) {
        revealObserver.observe(el);
    });

    /* =============================================
       2. SUBTLE PARALLAX on marked images
    ============================================= */
    var parallaxElements = document.querySelectorAll('[data-parallax]');
    var parallaxTicking = false;

    function updateParallax() {
        var scrollY = window.scrollY;
        var viewH = window.innerHeight;

        parallaxElements.forEach(function(el) {
            var rect = el.getBoundingClientRect();
            if (rect.bottom > 0 && rect.top < viewH) {
                var center = rect.top + rect.height / 2;
                var offset = (center - viewH / 2) / viewH;
                var img = el.querySelector('img');
                if (img) {
                    img.style.transform = 'scale(1.06) translateY(' + (offset * -18) + 'px)';
                }
            }
        });
        parallaxTicking = false;
    }

    if (parallaxElements.length > 0) {
        window.addEventListener('scroll', function() {
            if (!parallaxTicking) {
                requestAnimationFrame(updateParallax);
                parallaxTicking = true;
            }
        }, { passive: true });
    }

    /* =============================================
       3. SMOOTH SCROLL for anchor links
    ============================================= */
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (href !== '#' && href.length > 1) {
                e.preventDefault();
                var target = document.querySelector(href);
                if (target) {
                    var navH = document.querySelector('.navbar') ? document.querySelector('.navbar').offsetHeight : 0;
                    window.scrollTo({
                        top: target.offsetTop - navH - 20,
                        behavior: 'smooth'
                    });
                }
            }
        });
    });

})();
</script>

</body>
</html>
