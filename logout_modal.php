<style>
    /* Custom styles for a modern, professional look */
    .icon-wrapper {
        width: 60px; /* Reduced size for better visual balance */
        height: 60px;
        margin: 0 auto 1.5rem; /* Added bottom margin */
        border-radius: 50%;
        background-color: #fcebeb; /* Lighter, more subtle red background */
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .icon-wrapper .fa-sign-out-alt {
        color: #dc3545; /* Ensures the icon color is a consistent red */
        font-size: 2.2rem; /* Adjusted icon size */
    }

    /* Modern button styling */
    .btn-custom-cancel {
        border-color: #dee2e6; /* subtle border color */
        color: #495057; /* Darker text for readability */
        background-color: #f8f9fa; /* Light gray background */
        transition: all 0.2s ease-in-out;
    }
    
    .btn-custom-cancel:hover {
        color: #f8f4f4;
        background-color: #040404;
        border-color: #dae0e5;
    }

    .btn-custom-logout {
        background-color: #bc9e42; /* Standard danger color */
        border-color: #f8f4f4;
        transition: all 0.2s ease-in-out;
    }

    .btn-custom-logout:hover {
        background-color: #040404;
        border-color: #f8f4f4;
    }
</style>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-sm">
            <div class="modal-body text-center p-4">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <h4 class="fw-bold mb-2 text-dark mt-4" id="logoutModalLabel">You're About to Log Out</h4>
                <p class="text-muted mb-4">
                    Are you sure you want to end your current session? You'll need to log in again to access your account.
                </p>
            </div>

            <div class="modal-footer border-top-0 d-flex justify-content-center p-3 mb-3">
                <button type="button" class="btn btn-custom-cancel rounded-pill px-4 me-2" data-bs-dismiss="modal">
                    Cancel
                </button>
                <a href="logout.php" class="btn btn-custom-logout text-white rounded-pill px-4">
                    Log Out Anyway
                </a>
            </div>
        </div>
    </div>
</div>