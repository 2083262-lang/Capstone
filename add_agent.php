<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Initialize message variable
$message_type = '';
$message_text = '';

// Check if there's a message from the save_agent.php script
if (isset($_SESSION['message'])) {
    $message_type = $_SESSION['message']['type'];
    $message_text = $_SESSION['message']['text'];
    unset($_SESSION['message']); // Clear the message after displaying it
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Agent - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/admin_layout.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f4f4;
            margin: 0;
        }
        /* Sidebar */
        .sidebar {
            background-color: #161209;
            color: #fff;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 290px;
            overflow-y: auto;
            padding-top: 30px;
            display: flex;
            flex-direction: column;
        }
        /* Logo area */
        .sidebar-logo {
            text-align: center;
            padding: 20px;
            margin-bottom: 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-logo img {
            max-width: 160px;
            height: auto;
        }
        .sidebar a {
            display: block;
            color: #f8f4f4;
            padding: 15px 30px;
            text-decoration: none;
            font-weight: 500;
            font-size: 1.1rem;
        }
        .sidebar a:hover {
            background-color: #bc9e42;
            color: #161209;
        }
        .sidebar .active {
            background-color: #bc9e42;
            color: #161209;
        }
        .sidebar a.text-danger {
            margin-top: auto;
            padding-top: 20px;
            padding-bottom: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        /* Navbar */
        .navbar-custom {
            background-color: #fff;
            border-bottom: 2px solid #e6e6e6;
            height: 70px;
            margin-left: 290px;
            padding: 0 20px;
            z-index: 999;
            position: sticky;
            top: 0;
        }
        .navbar-custom .nav-item .nav-link {
            color: #161209;
        }
        .navbar-custom .nav-item .nav-link:hover {
            color: #bc9e42;
        }
        
        /* Main Content */
        .content {
            margin-left: 290px;
            padding: 20px;
        }
        .logout-custom {
            color: #bc9e42;
        }

        /* Form Specific Styles */
        .form-container {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            max-width: none;
            width: 100%;
            margin: 0;
        }
        .form-container h4 {
            font-weight: 700;
            color: #343a40;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 15px;
            border: 1px solid #ced4da;
        }
        .form-control:focus, .form-select:focus {
            border-color: #bc9e42;
            box-shadow: 0 0 0 0.25rem rgba(188, 158, 66, 0.25);
        }
        .btn-submit {
            background-color: #bc9e42;
            color: #fff;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-submit:hover {
            background-color: #a38736;
            transform: translateY(-1px);
        }
        .alert {
            border-radius: 8px;
            font-size: 0.95rem;
            padding: 15px 20px;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">
        <img src="https://via.placeholder.com/160x60?text=LOGO" alt="Company Logo">
    </div>
    <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
    <a href="property.php"><i class="fas fa-building me-2"></i> Properties</a>
    <a href="#"><i class="fas fa-users me-2"></i> Clients</a>
    <a href="agent.php" class="active"><i class="fas fa-user-tie me-2"></i> Agents</a>
    <a href="#"><i class="fas fa-file-contract me-2"></i> Contracts</a>
    <a href="#"><i class="fas fa-chart-line me-2"></i> Reports</a>
    <a href="#"><i class="fas fa-cog me-2"></i> Settings</a>
    <a href="#" class="text-danger" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
</div>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <span class="fw-bold fs-5">Real Estate Admin</span>
        <ul class="navbar-nav ms-auto d-flex flex-row align-items-center">
            <li class="nav-item me-3">
                <a class="nav-link" href="#"><i class="fas fa-bell fa-lg"></i></a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="https://via.placeholder.com/40" class="rounded-circle me-2" alt="Profile">
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="logout-custom dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<div class="content">
    <div class="form-container">
        <h4 class="mb-4"><i class="bi bi-person"></i> Agent Information</h4>

        <?php if ($message_text): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message_text; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="save_agent.php" method="POST" enctype="multipart/form-data" class="row g-3">

            <div class="col-md-6">
                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" id="first_name" name="first_name" class="form-control" required maxlength="100">
            </div>
            <div class="col-md-6">
                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" id="last_name" name="last_name" class="form-control" required maxlength="100">
            </div>
            
            <div class="col-md-6">
                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" id="email" name="email" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-6">
                <label for="phone_number" class="form-label">Phone Number</label>
                <input type="tel" id="phone_number" name="phone_number" class="form-control" maxlength="20">
            </div>

            <div class="col-md-6">
                <label for="license_number" class="form-label">License Number <span class="text-danger">*</span></label>
                <input type="text" id="license_number" name="license_number" class="form-control" required maxlength="100">
            </div>
            <div class="col-md-6">
                <label for="specialization" class="form-label">Specialization</label>
                <input type="text" id="specialization" name="specialization" class="form-control" maxlength="255">
            </div>

            <div class="col-md-6">
                <label for="years_experience" class="form-label">Years of Experience</label>
                <input type="number" id="years_experience" name="years_experience" class="form-control" min="0" value="0">
            </div>
            

            <div class="col-md-12">
                <label for="bio" class="form-label">Biography</label>
                <textarea id="bio" name="bio" class="form-control" rows="4"></textarea>
            </div>

            <div class="col-md-12">
                <label for="profile_picture" class="form-label">Profile Picture</label>
                <input type="file" id="profile_picture" name="profile_picture" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <div class="form-text">Upload a profile picture (Max 5MB). Allowed types: JPG, PNG, GIF, WEBP.</div>
            </div>

            <div class="col-12 text-end mt-4">
                <button type="submit" class="btn btn-submit">Save Agent</button>
            </div>
        </form>
    </div>
</div>

<!-- Modals -->
 <?php include 'logout_modal.php'; ?>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>