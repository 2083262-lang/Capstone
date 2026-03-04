<?php require_once __DIR__ . '/config/paths.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile Modal Preview</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
</head>
<body>
    <div class="container mt-5">
        <h1>Admin Profile Modal Preview</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adminProfileModal">
            Open Admin Profile Modal
        </button>
    </div>

    <?php include 'admin_profile_modal.php'; ?>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        const adminInfoForm = document.getElementById('adminInfoForm');
        if (adminInfoForm) {
            const bioField = document.getElementById('bio');
            if (bioField) {
                bioField.addEventListener('input', function() {
                    if (this.value.length < 50) {
                        this.setCustomValidity('Bio must be at least 50 characters long');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            adminInfoForm.addEventListener('submit', function(event) {
                event.preventDefault();
                event.stopPropagation();
                
                if (!this.checkValidity()) {
                    this.classList.add('was-validated');
                    return;
                }
                
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                
                // Simulate AJAX call
                setTimeout(() => {
                    alert('Form would be submitted via AJAX');
                    submitBtn.classList.remove('loading');
                    submitBtn.disabled = false;
                }, 2000);
                
                this.classList.add('was-validated');
            });
        }
    </script>
</body>
</html>