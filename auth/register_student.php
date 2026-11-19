<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'];
    if ($role === 'admin') {
        header("Location: ../admin_dashboard.php");
    } elseif ($role === 'instructor') {
        header("Location: ../instructor_dashboard.php");
    } else {
        header("Location: ../student_dashboard.php");
    }
    exit;
}

// Fetch programs and departments for dropdowns
try {
    $programs_stmt = $pdo->query("SELECT id, name, department_id FROM programs WHERE status = 'active' ORDER BY name");
    $programs = $programs_stmt->fetchAll();

    $departments_stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $departments_stmt->fetchAll();
} catch (Exception $e) {
    $programs = [];
    $departments = [];
}
?>
<?php
$pageTitle = 'Student Registration - LearnAlytics';
$extraCss = ['assets/css/pages/register_student.css'];
require_once __DIR__ . '/../includes/header.php';
?>
    <div class="registration-container">
        <div class="registration-card">
            <!-- Header -->
            <div class="registration-header">
                <h1><i class="fas fa-user-graduate"></i> Student Registration</h1>
                <p>Join LearnAlytics and start your learning journey today</p>
            </div>

            <!-- Body -->
            <div class="registration-body">
                <!-- Alerts -->
                <div id="successAlert" class="alert-custom alert-success-custom">
                    <i class="fas fa-check-circle"></i> <strong>Success!</strong> <span id="successMessage"></span>
                </div>

                <div id="errorAlert" class="alert-custom alert-error-custom">
                    <i class="fas fa-exclamation-circle"></i> <strong>Error!</strong> <span id="errorMessage"></span>
                </div>

                <div id="warningAlert" class="alert-custom alert-warning-custom">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Warning!</strong> <span id="warningMessage"></span>
                </div>

                <!-- Success Message -->
                <div id="successMessageContainer" class="success-message-container">
                    <div class="success-icon success-animation">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2>Registration Successful!</h2>
                    <p>Your student account has been created successfully.</p>
                    <div class="success-details" id="successDetails"></div>
                    <p style="color: #999; font-size: 12px; margin-top: 15px;">You can now login with your credentials</p>
                    <a href="login.php" class="btn-primary-custom">
                        <i class="fas fa-sign-in-alt"></i> Go to Login
                    </a>
                </div>

                <!-- Registration Form -->
                <form id="registrationForm" class="registration-form">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-user"></i> Personal Information
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="studentId">
                                    Student ID <span class="required-indicator">*</span>
                                </label>
                                <input type="text" id="studentId" name="student_id" class="form-control" 
                                       placeholder="e.g., STU001234" required maxlength="50" autocomplete="off">
                                <div class="field-validation-status" id="studentIdStatus"></div>
                            </div>

                            <div class="form-group">
                                <label for="firstName">
                                    First Name <span class="required-indicator">*</span>
                                </label>
                                <input type="text" id="firstName" name="first_name" class="form-control" 
                                       placeholder="Enter your first name" required maxlength="100">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="lastName">
                                    Last Name <span class="required-indicator">*</span>
                                </label>
                                <input type="text" id="lastName" name="last_name" class="form-control" 
                                       placeholder="Enter your last name" required maxlength="100">
                            </div>

                            <div class="form-group">
                                <label for="middleInitial">
                                    Middle Initial
                                </label>
                                <input type="text" id="middleInitial" name="middle_initial" class="form-control" 
                                       placeholder="E.g., M" maxlength="5">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="dateOfBirth">
                                    Date of Birth <span class="required-indicator">*</span>
                                </label>
                                <input type="date" id="dateOfBirth" name="date_of_birth" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="gender">
                                    Gender <span class="required-indicator">*</span>
                                </label>
                                <select id="gender" name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-envelope"></i> Contact Information
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">
                                    Email Address <span class="required-indicator">*</span>
                                </label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       placeholder="Enter your email address" required autocomplete="off">
                                <div class="field-validation-status" id="emailStatus"></div>
                            </div>

                            <div class="form-group">
                                <label for="contactNumber">
                                    Contact Number <span class="required-indicator">*</span>
                                </label>
                                <input type="tel" id="contactNumber" name="contact_number" class="form-control" 
                                       placeholder="e.g., +1-555-0123" required maxlength="20">
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-book"></i> Academic Information
                        </div>

                        <div class="form-group">
                                <label for="department">
                                    Department <span class="required-indicator">*</span>
                                </label>
                                <select id="department" name="department" class="form-select" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept['id']); ?>">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="program">
                                    Program <span class="required-indicator">*</span>
                                </label>
                                <select id="program" name="program" class="form-select" required disabled>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo htmlspecialchars($program['id']); ?>" data-department-id="<?php echo htmlspecialchars($program['department_id']); ?>">
                                            <?php echo htmlspecialchars($program['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="yearLevel">
                                    Year Level <span class="required-indicator">*</span>
                                </label>
                                <select id="yearLevel" name="year_level" class="form-select" required>
                                    <option value="">Select Year Level</option>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Security Information Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-lock"></i> Security Information
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">
                                    Password <span class="required-indicator">*</span>
                                </label>
                                <div class="password-field-wrapper">
                                    <input type="password" id="password" name="password" class="form-control" 
                                           placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
                                    <button type="button" class="password-toggle" id="passwordToggle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-text text-muted">Use 8 or more characters. Mixed case and symbols are optional.</small>
                            </div>

                            <div class="form-group">
                                <label for="confirmPassword">
                                    Confirm Password <span class="required-indicator">*</span>
                                </label>
                                <div class="password-field-wrapper">
                                    <input type="password" id="confirmPassword" name="confirm_password" class="form-control" 
                                           placeholder="Confirm your password" required autocomplete="new-password">
                                    <button type="button" class="password-toggle" id="confirmPasswordToggle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="captchaAnswer">
                                Security Check <span class="required-indicator">*</span>
                            </label>
                            <div class="captcha-challenge">
                                <img id="captchaImage" src="captcha.php" alt="CAPTCHA challenge">
                                <button type="button" class="btn-secondary-custom captcha-refresh" id="captchaRefresh" aria-label="Get a new CAPTCHA">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                            <input type="text" id="captchaAnswer" name="captcha_answer" class="form-control"
                                   placeholder="Enter the characters shown" required maxlength="6"
                                   autocomplete="off" autocapitalize="characters" spellcheck="false">
                            <small class="form-text text-muted">Enter the 6 characters shown above. The check expires after 10 minutes.</small>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="button-group">
                        <button type="reset" class="btn-secondary-custom">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                        <button type="submit" class="btn-primary-custom" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                    </div>

                    <!-- Login Link -->
                    <div class="login-link">
                        Already have an account? <a href="login.php">Login here</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================================
        // Form Validation and Real-Time Checks
        // ============================================================

        const form = document.getElementById('registrationForm');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const studentIdInput = document.getElementById('studentId');
        const emailInput = document.getElementById('email');
        const submitBtn = document.getElementById('submitBtn');
        const captchaImage = document.getElementById('captchaImage');
        const captchaAnswer = document.getElementById('captchaAnswer');
        const captchaRefresh = document.getElementById('captchaRefresh');
        const departmentSelect = document.getElementById('department');
        const programSelect = document.getElementById('program');
        const availablePrograms = Array.from(programSelect.options).slice(1).map(option => ({
            value: option.value,
            label: option.textContent,
            departmentId: option.dataset.departmentId
        }));

        function filterPrograms() {
            const selectedDepartment = departmentSelect.value;
            programSelect.innerHTML = '<option value="">Select Program</option>';
            availablePrograms.filter(program => program.departmentId === selectedDepartment).forEach(program => {
                const option = document.createElement('option');
                option.value = program.value;
                option.textContent = program.label;
                programSelect.appendChild(option);
            });
            programSelect.disabled = !selectedDepartment || programSelect.options.length === 1;
        }

        departmentSelect.addEventListener('change', filterPrograms);

        function refreshCaptcha() {
            captchaImage.src = `captcha.php?refresh=${Date.now()}`;
            captchaAnswer.value = '';
        }

        captchaRefresh.addEventListener('click', refreshCaptcha);

        // Validation debounce timers
        let studentIdCheckTimer;
        let emailCheckTimer;

        // ============================================================
        // Show/Hide Password Toggle
        // ============================================================

        function setupPasswordToggle(toggleBtn, passwordField) {
            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const isPassword = passwordField.type === 'password';
                passwordField.type = isPassword ? 'text' : 'password';
                toggleBtn.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            });
        }

        setupPasswordToggle(document.getElementById('passwordToggle'), passwordInput);
        setupPasswordToggle(document.getElementById('confirmPasswordToggle'), confirmPasswordInput);

        // ============================================================
        // Password Match Validation
        // ============================================================

        function validatePasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (confirmPassword === '') return true;

            if (password !== confirmPassword) {
                confirmPasswordInput.classList.add('is-invalid');
                confirmPasswordInput.classList.remove('is-valid');
                return false;
            } else {
                confirmPasswordInput.classList.add('is-valid');
                confirmPasswordInput.classList.remove('is-invalid');
                return true;
            }
        }

        confirmPasswordInput.addEventListener('blur', validatePasswordMatch);
        confirmPasswordInput.addEventListener('input', validatePasswordMatch);

        // ============================================================
        // Real-Time Student ID Validation
        // ============================================================

        function checkStudentIdAvailability() {
            const studentId = studentIdInput.value.trim();
            const statusDiv = document.getElementById('studentIdStatus');

            if (studentId.length === 0) {
                statusDiv.textContent = '';
                statusDiv.classList.remove('checking', 'valid', 'invalid');
                return;
            }

            statusDiv.textContent = 'Checking availability...';
            statusDiv.classList.add('checking');

            clearTimeout(studentIdCheckTimer);
            studentIdCheckTimer = setTimeout(() => {
                fetch('../auth/api/validate_fields.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=check_student_id&student_id=${encodeURIComponent(studentId)}`
                })
                .then(response => response.json())
                .then(data => {
                    statusDiv.classList.remove('checking');
                    if (data.available) {
                        statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> Student ID is available';
                        statusDiv.classList.add('valid');
                        studentIdInput.classList.add('is-valid');
                        studentIdInput.classList.remove('is-invalid');
                    } else {
                        statusDiv.innerHTML = '<i class="fas fa-times-circle"></i> ' + data.message;
                        statusDiv.classList.add('invalid');
                        studentIdInput.classList.add('is-invalid');
                        studentIdInput.classList.remove('is-valid');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusDiv.classList.remove('checking');
                });
            }, 500);
        }

        studentIdInput.addEventListener('blur', checkStudentIdAvailability);
        studentIdInput.addEventListener('input', () => {
            clearTimeout(studentIdCheckTimer);
            document.getElementById('studentIdStatus').textContent = '';
        });

        // ============================================================
        // Real-Time Email Validation
        // ============================================================

        function checkEmailAvailability() {
            const email = emailInput.value.trim();
            const statusDiv = document.getElementById('emailStatus');

            if (email.length === 0 || !isValidEmail(email)) {
                statusDiv.textContent = '';
                statusDiv.classList.remove('checking', 'valid', 'invalid');
                return;
            }

            statusDiv.textContent = 'Checking availability...';
            statusDiv.classList.add('checking');

            clearTimeout(emailCheckTimer);
            emailCheckTimer = setTimeout(() => {
                fetch('../auth/api/validate_fields.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=check_email&email=${encodeURIComponent(email)}`
                })
                .then(response => response.json())
                .then(data => {
                    statusDiv.classList.remove('checking');
                    if (data.available) {
                        statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> Email is available';
                        statusDiv.classList.add('valid');
                        emailInput.classList.add('is-valid');
                        emailInput.classList.remove('is-invalid');
                    } else {
                        statusDiv.innerHTML = '<i class="fas fa-times-circle"></i> Email already registered';
                        statusDiv.classList.add('invalid');
                        emailInput.classList.add('is-invalid');
                        emailInput.classList.remove('is-valid');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusDiv.classList.remove('checking');
                });
            }, 500);
        }

        emailInput.addEventListener('blur', checkEmailAvailability);
        emailInput.addEventListener('input', () => {
            clearTimeout(emailCheckTimer);
            document.getElementById('emailStatus').textContent = '';
        });

        // ============================================================
        // Email Validation Helper
        // ============================================================

        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // ============================================================
        // Alert System
        // ============================================================

        function showAlert(type, message) {
            const alertMap = {
                'success': { element: 'successAlert', messageElement: 'successMessage' },
                'error': { element: 'errorAlert', messageElement: 'errorMessage' },
                'warning': { element: 'warningAlert', messageElement: 'warningMessage' }
            };

            const config = alertMap[type];
            if (!config) return;

            const alertElement = document.getElementById(config.element);
            const messageElement = document.getElementById(config.messageElement);

            messageElement.textContent = message;
            alertElement.classList.add('show');

            window.scrollTo({ top: 0, behavior: 'smooth' });

            if (type !== 'error') {
                setTimeout(() => {
                    alertElement.classList.remove('show');
                }, 5000);
            }
        }

        // ============================================================
        // Form Submission
        // ============================================================

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Validate all fields
            if (!form.checkValidity() || !validatePasswordMatch()) {
                form.classList.add('was-validated');
                showAlert('error', 'Please fill all required fields correctly.');
                return;
            }

            // Check if passwords match
            if (passwordInput.value !== confirmPasswordInput.value) {
                showAlert('error', 'Passwords do not match.');
                return;
            }

            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm loading" role="status" aria-hidden="true"></span> Creating Account...';

            try {
                // Prepare form data
                const formData = new FormData(form);

                // Submit registration
                const response = await fetch('../auth/api/register_student_api.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Hide form and show success message
                    form.style.display = 'none';
                    const successContainer = document.getElementById('successMessageContainer');
                    successContainer.classList.add('show');

                    // Populate success details
                    const successDetails = document.getElementById('successDetails');
                    successDetails.innerHTML = `
                        <div class="success-details-item">
                            <strong>Student ID:</strong>
                            <span>${data.student_id}</span>
                        </div>
                        <div class="success-details-item">
                            <strong>Name:</strong>
                            <span>${data.full_name}</span>
                        </div>
                        <div class="success-details-item">
                            <strong>Email:</strong>
                            <span>${data.email}</span>
                        </div>
                        <div class="success-details-item">
                            <strong>Program:</strong>
                            <span>${data.program}</span>
                        </div>
                        <div class="success-details-item">
                            <strong>Year Level:</strong>
                            <span>${data.year_level}</span>
                        </div>
                    `;

                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    showAlert('error', data.message || 'Registration failed. Please try again.');
                    refreshCaptcha();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('error', 'An error occurred. Please try again.');
                refreshCaptcha();
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
            }
        });

        // ============================================================
        // Form Reset Handler
        // ============================================================

        form.addEventListener('reset', () => {
            form.classList.remove('was-validated');
            window.setTimeout(refreshCaptcha, 0);
        });
    </script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
