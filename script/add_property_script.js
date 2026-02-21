document.addEventListener('DOMContentLoaded', function() {
    // Form elements
    const form = document.getElementById('propertyForm');
    const progressFill = document.getElementById('formProgress');
    const progressText = document.getElementById('progressText');
    const submitBtn = document.getElementById('submitBtn');
    
    // Image upload elements
    const imageUploadSection = document.getElementById('imageUploadSection');
    const fileInput = document.getElementById('property_photos');
    const imagePreviewGrid = document.getElementById('imagePreviewGrid');
    let selectedFiles = [];

    // Configuration
    const config = {
        maxFiles: 10,
        maxSize: 5 * 1024 * 1024, // 5MB
        allowedTypes: ['image/jpeg', 'image/png', 'image/gif'],
        allowedExtensions: ['.jpg', '.jpeg', '.png', '.gif']
    };

    // Progress calculation and update
    function updateProgress() {
        const requiredFields = form.querySelectorAll('[required]');
        let filledFields = 0;
        
        // Check required fields
        requiredFields.forEach(field => {
            if (field.type === 'checkbox') {
                const checkboxGroup = form.querySelectorAll(`[name="${field.name}"]`);
                if (Array.from(checkboxGroup).some(cb => cb.checked)) {
                    filledFields++;
                }
            } else if (field.value.trim() !== '') {
                filledFields++;
            }
        });
        
        // Add weight for optional sections
        const optionalWeight = 0.3;
        const descriptionField = document.getElementById('ListingDescription');
        const amenityChecked = form.querySelectorAll('input[name="amenities[]"]:checked').length > 0;
        const imagesSelected = selectedFiles.length > 0;
        
        let optionalScore = 0;
        if (descriptionField && descriptionField.value.trim() !== '') optionalScore += optionalWeight;
        if (amenityChecked) optionalScore += optionalWeight;
        if (imagesSelected) optionalScore += optionalWeight;
        
        const totalRequired = requiredFields.length;
        const totalOptional = 0.9; // Max optional weight
        const progress = Math.min(100, ((filledFields / totalRequired) * 70) + (optionalScore / totalOptional * 30));
        
        // Update progress bar
        progressFill.style.width = progress + '%';
        
        // Update progress text
        if (progress < 30) {
            progressText.textContent = 'Getting started - fill in the required fields';
        } else if (progress < 70) {
            progressText.textContent = 'Making progress - ' + Math.round(progress) + '% complete';
        } else if (progress < 95) {
            progressText.textContent = 'Almost done - ' + Math.round(progress) + '% complete';
        } else {
            progressText.textContent = 'Ready to submit! All fields completed';
        }
        
        return progress >= 70; // Enable submit when 70% complete
    }

    // Add event listeners to all form inputs for progress tracking
    const allInputs = form.querySelectorAll('input, select, textarea');
    allInputs.forEach(input => {
        input.addEventListener('input', updateProgress);
        input.addEventListener('change', updateProgress);
    });

    // Image Upload Functionality
    
    // Drag and drop event handlers
    imageUploadSection.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        imageUploadSection.classList.add('dragover');
    });

    imageUploadSection.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!imageUploadSection.contains(e.relatedTarget)) {
            imageUploadSection.classList.remove('dragover');
        }
    });

    imageUploadSection.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        imageUploadSection.classList.remove('dragover');
        
        const files = Array.from(e.dataTransfer.files);
        handleFiles(files);
    });

    // File input change handler
    fileInput.addEventListener('change', function(e) {
        const files = Array.from(e.target.files);
        handleFiles(files);
    });

    // Handle file selection and validation
    function handleFiles(files) {
        const validFiles = [];
        
        // Filter and validate files
        files.forEach(file => {
            // Check if we've reached max files
            if (selectedFiles.length >= config.maxFiles) {
                showAlert(`Maximum ${config.maxFiles} images allowed. Skipping additional files.`, 'warning');
                return;
            }
            
            // Validate file type
            if (!config.allowedTypes.includes(file.type)) {
                showAlert(`File "${file.name}" is not a supported image type. Only JPG, PNG, and GIF are allowed.`, 'danger');
                return;
            }
            
            // Validate file size
            if (file.size > config.maxSize) {
                showAlert(`File "${file.name}" is too large. Maximum size is ${formatFileSize(config.maxSize)}.`, 'danger');
                return;
            }
            
            // Check for duplicates
            if (selectedFiles.some(existingFile => 
                existingFile.name === file.name && 
                existingFile.size === file.size && 
                existingFile.lastModified === file.lastModified)) {
                showAlert(`File "${file.name}" has already been selected.`, 'warning');
                return;
            }
            
            validFiles.push(file);
        });
        
        // Add valid files
        validFiles.forEach(file => {
            selectedFiles.push(file);
            createImagePreview(file, selectedFiles.length - 1);
        });
        
        updateFileInput();
        updateProgress();
        
        // Show success message if files were added
        if (validFiles.length > 0) {
            showAlert(`Successfully added ${validFiles.length} image${validFiles.length > 1 ? 's' : ''}.`, 'success');
        }
    }

    // Create image preview element
    function createImagePreview(file, index) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const previewItem = document.createElement('div');
            previewItem.className = 'image-preview-item';
            previewItem.dataset.index = index;
            
            previewItem.innerHTML = `
                <img src="${e.target.result}" alt="Preview of ${file.name}" class="preview-image">
                <div class="image-info">
                    <div class="fw-semibold" title="${file.name}">${truncateFileName(file.name, 20)}</div>
                    <div class="text-muted">${formatFileSize(file.size)}</div>
                </div>
                <button type="button" class="remove-image" onclick="removeImage(${index})" 
                        title="Remove image" aria-label="Remove ${file.name}">
                    <i class="bi bi-x"></i>
                </button>
            `;
            
            // Add fade-in animation
            previewItem.style.opacity = '0';
            previewItem.style.transform = 'scale(0.8)';
            imagePreviewGrid.appendChild(previewItem);
            
            // Trigger animation
            requestAnimationFrame(() => {
                previewItem.style.transition = 'all 0.3s ease';
                previewItem.style.opacity = '1';
                previewItem.style.transform = 'scale(1)';
            });
        };
        
        reader.onerror = function() {
            showAlert(`Error reading file "${file.name}". Please try again.`, 'danger');
        };
        
        reader.readAsDataURL(file);
    }

    // Remove image function (attached to window for onclick access)
    window.removeImage = function(index) {
        if (index < 0 || index >= selectedFiles.length) return;
        
        const fileName = selectedFiles[index].name;
        selectedFiles.splice(index, 1);
        
        // Re-render all previews with updated indices
        imagePreviewGrid.innerHTML = '';
        selectedFiles.forEach((file, newIndex) => {
            createImagePreview(file, newIndex);
        });
        
        updateFileInput();
        updateProgress();
        
        showAlert(`Removed "${fileName}" from selection.`, 'info');
    };

    // Update the actual file input with selected files
    function updateFileInput() {
        try {
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
        } catch (error) {
            console.warn('DataTransfer not supported, files will be handled on submit');
        }
    }

    // Utility Functions

    // Format file size for display
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Truncate filename for display
    function truncateFileName(fileName, maxLength) {
        if (fileName.length <= maxLength) return fileName;
        
        const extension = fileName.split('.').pop();
        const nameWithoutExt = fileName.substring(0, fileName.lastIndexOf('.'));
        const truncatedName = nameWithoutExt.substring(0, maxLength - extension.length - 4) + '...';
        
        return truncatedName + '.' + extension;
    }

    // Show alert messages
    function showAlert(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.style.cssText = 'position: fixed; top: 100px; right: 20px; z-index: 1050; max-width: 400px;';
        alertDiv.innerHTML = `
            <i class="bi bi-${getAlertIcon(type)}-fill me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.classList.remove('show');
                setTimeout(() => alertDiv.remove(), 150);
            }
        }, 5000);
    }

    // Get appropriate icon for alert type
    function getAlertIcon(type) {
        const icons = {
            'success': 'check-circle',
            'danger': 'exclamation-triangle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    // Form Validation Enhancement

    // Add validation to required fields
    const requiredInputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    requiredInputs.forEach(input => {
        // Validate on blur
        input.addEventListener('blur', function() {
            validateField(this);
        });

        // Clear validation on input
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && this.value.trim()) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
                hideFieldError(this);
            }
        });
    });

    // Validate individual field
    function validateField(field) {
        const isValid = field.checkValidity() && field.value.trim() !== '';
        
        if (isValid) {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            hideFieldError(field);
        } else {
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');
            showFieldError(field, getFieldErrorMessage(field));
        }
        
        return isValid;
    }

    // Show field-specific error
    function showFieldError(field, message) {
        hideFieldError(field); // Remove existing error first
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        
        field.parentNode.appendChild(errorDiv);
    }

    // Hide field error
    function hideFieldError(field) {
        const existingError = field.parentNode.querySelector('.invalid-feedback');
        if (existingError) {
            existingError.remove();
        }
    }

    // Get appropriate error message for field
    function getFieldErrorMessage(field) {
        if (field.validity.valueMissing) {
            return `${field.labels[0]?.textContent.replace('*', '').trim() || 'This field'} is required.`;
        }
        if (field.validity.typeMismatch) {
            return 'Please enter a valid value.';
        }
        if (field.validity.patternMismatch) {
            return field.title || 'Please match the required format.';
        }
        if (field.validity.rangeUnderflow) {
            return `Value must be at least ${field.min}.`;
        }
        if (field.validity.rangeOverflow) {
            return `Value must not exceed ${field.max}.`;
        }
        return 'Please enter a valid value.';
    }

    // Form Submission Handler
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Validate all required fields
        let isFormValid = true;
        requiredInputs.forEach(input => {
            if (!validateField(input)) {
                isFormValid = false;
            }
        });
        
        if (!isFormValid) {
            showAlert('Please fill in all required fields correctly.', 'danger');
            return;
        }
        
        // Show loading state
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Saving Property...';
        
        // Simulate processing time (remove this in production)
        setTimeout(() => {
            // Reset loading state
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Save Property';
            
            // Actually submit the form
            form.submit();
        }, 1500);
    });

    // Additional Form Enhancements

    // Price input formatting
    const priceInput = document.getElementById('ListingPrice');
    if (priceInput) {
        priceInput.addEventListener('input', function() {
            // Remove non-numeric characters except decimal point
            let value = this.value.replace(/[^\d.-]/g, '');
            
            // Ensure only one decimal point
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            
            // Update the input value
            this.value = value;
        });
    }

    // Auto-resize textarea
    const textarea = document.getElementById('ListingDescription');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 300) + 'px';
        });
        
        // Character counter
        const maxLength = 2000;
        const counter = document.createElement('div');
        counter.className = 'form-text text-end';
        counter.style.marginTop = '0.5rem';
        
        function updateCounter() {
            const remaining = maxLength - textarea.value.length;
            counter.textContent = `${textarea.value.length}/${maxLength} characters`;
            counter.style.color = remaining < 100 ? '#dc3545' : '#6c757d';
        }
        
        textarea.parentNode.appendChild(counter);
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitBtn.focus();
            submitBtn.style.transform = 'scale(1.05)';
            setTimeout(() => {
                submitBtn.style.transform = '';
            }, 150);
        }
        
        // Escape to clear current field focus
        if (e.key === 'Escape') {
            document.activeElement.blur();
        }
    });

    // Initialize the form
    function initializeForm() {
        // Set focus to first input
        const firstInput = form.querySelector('input, select, textarea');
        if (firstInput) {
            firstInput.focus();
        }
        
        // Initialize progress
        updateProgress();
        
        // Add CSS for validation states
        const style = document.createElement('style');
        style.textContent = `
            .form-control.is-valid, .form-select.is-valid {
                border-color: #198754;
                box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
            }
            .form-control.is-invalid, .form-select.is-invalid {
                border-color: #dc3545;
                box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
            }
        `;
        document.head.appendChild(style);
        
        console.log('Property form initialized successfully');
    }

    // Initialize everything
    initializeForm();
});