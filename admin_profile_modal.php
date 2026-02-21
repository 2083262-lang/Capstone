<!-- Admin Information Form Modal -->
<style>
    #adminProfileModal .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    #adminProfileModal .modal-header {
        background: linear-gradient(135deg, #161209 0%, #2c241a 100%);
        border: none;
        padding: 2rem 2.5rem;
        position: relative;
        overflow: hidden;
    }

    #adminProfileModal .modal-header::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(188, 158, 66, 0.15) 0%, transparent 70%);
        border-radius: 50%;
        transform: translate(50px, -50px);
    }

    #adminProfileModal .modal-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #fff;
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    #adminProfileModal .modal-title i {
        color: #bc9e42;
        font-size: 1.5rem;
    }

    #adminProfileModal .modal-body {
        padding: 2.5rem;
        background-color: #fafbfc;
    }

    #adminProfileModal .alert-info {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        border: none;
        border-left: 4px solid #2196f3;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        color: #1565c0;
        font-weight: 500;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(33, 150, 243, 0.1);
    }

    #adminProfileModal .alert-info i {
        font-size: 1.2rem;
        vertical-align: middle;
    }

    #adminProfileModal .form-label {
        font-weight: 600;
        color: #161209;
        font-size: 0.95rem;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    #adminProfileModal .form-label i {
        color: #bc9e42;
        font-size: 1rem;
    }

    #adminProfileModal .form-control,
    #adminProfileModal .form-select {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        background-color: #fff;
    }

    #adminProfileModal .form-control:focus,
    #adminProfileModal .form-select:focus {
        border-color: #bc9e42;
        box-shadow: 0 0 0 0.2rem rgba(188, 158, 66, 0.15);
        background-color: #fff;
    }

    #adminProfileModal .form-control:hover,
    #adminProfileModal .form-select:hover {
        border-color: #bc9e42;
    }

    #adminProfileModal textarea.form-control {
        resize: vertical;
        min-height: 120px;
    }

    #adminProfileModal .form-text {
        font-size: 0.85rem;
        color: #6c757d;
        margin-top: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    #adminProfileModal .form-text i {
        color: #bc9e42;
    }

    #adminProfileModal .invalid-feedback {
        font-size: 0.875rem;
        color: #dc3545;
        font-weight: 500;
        margin-top: 0.5rem;
    }

    #adminProfileModal .btn-submit {
        background: linear-gradient(135deg, #bc9e42 0%, #a08636 100%);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 1rem 2rem;
        font-weight: 600;
        font-size: 1.05rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        position: relative;
        overflow: hidden;
    }

    #adminProfileModal .btn-submit::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s ease;
    }

    #adminProfileModal .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(188, 158, 66, 0.4);
    }

    #adminProfileModal .btn-submit:hover::before {
        left: 100%;
    }

    #adminProfileModal .btn-submit:active {
        transform: translateY(0);
    }

    #adminProfileModal .btn-submit i {
        margin-left: 0.5rem;
        transition: transform 0.3s ease;
    }

    #adminProfileModal .btn-submit:hover i {
        transform: translateX(4px);
    }

    #adminProfileModal .form-section {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #e9ecef;
    }

    #adminProfileModal .section-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #161209;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #bc9e42;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    #adminProfileModal .section-title i {
        color: #bc9e42;
    }

    #adminProfileModal .required-asterisk {
        color: #dc3545;
        font-weight: 700;
    }

    /* File upload custom styling */
    #adminProfileModal input[type="file"] {
        padding: 0.65rem 1rem;
    }

    #adminProfileModal input[type="file"]::file-selector-button {
        background: linear-gradient(135deg, #bc9e42 0%, #a08636 100%);
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-weight: 600;
        margin-right: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    #adminProfileModal input[type="file"]::file-selector-button:hover {
        background: linear-gradient(135deg, #a08636 0%, #bc9e42 100%);
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(188, 158, 66, 0.3);
    }

    /* Animation for modal appearance */
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    #adminProfileModal.show .modal-dialog {
        animation: slideDown 0.4s ease-out;
    }

    /* Loading state for submit button */
    #adminProfileModal .btn-submit.loading {
        pointer-events: none;
        opacity: 0.7;
    }

    #adminProfileModal .btn-submit.loading::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        top: 50%;
        left: 50%;
        margin-left: -8px;
        margin-top: -8px;
        border: 2px solid #fff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 0.6s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        #adminProfileModal .modal-body {
            padding: 1.5rem;
        }

        #adminProfileModal .modal-header {
            padding: 1.5rem;
        }

        #adminProfileModal .modal-title {
            font-size: 1.4rem;
        }

        #adminProfileModal .form-section {
            padding: 1rem;
        }
    }
</style>

<div class="modal fade" id="adminProfileModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="adminProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adminProfileModalLabel">
                    <i class="fas fa-user-shield"></i>
                    Complete Your Admin Profile
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i> 
                    <span>Please complete your admin profile before continuing to access the dashboard.</span>
                </div>
                
                <form id="adminInfoForm" action="save_admin_info.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                    
                    <!-- Professional Information Section -->
                    <div class="form-section">
                        <h6 class="section-title">
                            <i class="fas fa-briefcase"></i>
                            Professional Information
                        </h6>
                        
                        <!-- License Number Field -->
                        <div class="mb-3">
                            <label for="license_number" class="form-label">
                                <i class="fas fa-id-card"></i>
                                License Number <span class="required-asterisk">*</span>
                            </label>
                            <input type="text" class="form-control" id="license_number" name="license_number" 
                                   placeholder="Enter your license number" required>
                            <div class="invalid-feedback">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                Please provide a valid license number.
                            </div>
                        </div>
                        
                        <!-- Specialization Field -->
                        <div class="mb-3">
                            <label for="specialization" class="form-label">
                                <i class="fas fa-certificate"></i>
                                Specialization <span class="required-asterisk">*</span>
                            </label>
                            <input type="text" class="form-control" id="specialization" name="specialization" 
                                   placeholder="e.g., Real Estate Administration, Property Management" required>
                            <div class="invalid-feedback">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                Please provide your specialization.
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Years Experience Field -->
                            <div class="col-md-6 mb-3">
                                <label for="years_experience" class="form-label">
                                    <i class="fas fa-chart-line"></i>
                                    Years of Experience <span class="required-asterisk">*</span>
                                </label>
                                <input type="number" class="form-control" id="years_experience" name="years_experience" 
                                       min="0" max="50" placeholder="0" required>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle me-1"></i>
                                    Please provide your years of experience.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h6 class="section-title">
                            <i class="fas fa-user"></i>
                            Personal Information
                        </h6>
                        
                        <!-- Bio Field -->
                        <div class="mb-3">
                            <label for="bio" class="form-label">
                                <i class="fas fa-align-left"></i>
                                Professional Bio <span class="required-asterisk">*</span>
                            </label>
                            <textarea class="form-control" id="bio" name="bio" rows="5" 
                                      placeholder="Tell us about your professional background, expertise, and responsibilities..." required></textarea>
                            <div class="form-text">
                                <i class="fas fa-lightbulb"></i>
                                Provide a brief professional summary (minimum 50 characters)
                            </div>
                            <div class="invalid-feedback">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                Please provide your professional bio.
                            </div>
                        </div>
                        
                        <!-- Profile Picture Field -->
                        <div class="mb-0">
                            <label for="profile_picture" class="form-label">
                                <i class="fas fa-camera"></i>
                                Profile Picture
                            </label>
                            <input type="file" class="form-control" id="profile_picture" name="profile_picture" 
                                   accept="image/jpeg,image/png,image/gif">
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i>
                                Maximum file size: 5MB. Supported formats: JPG, PNG, GIF.
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-submit">
                            Save Admin Information
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>