<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="LearnAlytics — A modern Learning Management System with advanced analytics, risk assessment, and comprehensive academic management.">
    <title>LearnAlytics — System Demonstration</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Demo Styles -->
    <link rel="stylesheet" href="assets/css/demo.css">
</head>
<body class="demo-page">

<!-- ============ NAVIGATION ============ -->
<nav class="demo-nav" id="demoNav">
    <div class="nav-inner">
        <a href="#" class="logo">
            <i class="fas fa-chart-line"></i>
            <span>LearnAlytics</span>
        </a>
        <ul class="demo-nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#walkthrough">Walkthrough</a></li>
            <li><a href="#architecture">Architecture</a></li>
            <li><a href="#credentials">Demo Access</a></li>
        </ul>
        <a href="auth/login.php" class="btn-try">
            <i class="fas fa-arrow-right-to-bracket"></i> Launch App
        </a>
    </div>
</nav>

<!-- ============ HERO SECTION ============ -->
<section class="demo-hero" id="hero">
    <!-- Animated Particles -->
    <div class="demo-particles" id="particles"></div>

    <div class="demo-hero-content">
        <div class="demo-badge">
            <span class="pulse-dot"></span>
            Learning Management System
        </div>

        <h1>
            Smart Academic Management<br>
            with <span class="gradient-text">LearnAlytics</span>
        </h1>

        <p class="lead">
            A comprehensive LMS featuring real-time analytics, academic risk assessment,
            interactive assignment grading, quiz management, and multi-role dashboards
            — designed for modern educational institutions.
        </p>

        <div class="demo-hero-actions">
            <a href="#walkthrough" class="btn-hero-primary">
                <i class="fas fa-play-circle"></i> Start Demo Walkthrough
            </a>
            <a href="#credentials" class="btn-hero-secondary">
                <i class="fas fa-key"></i> View Demo Credentials
            </a>
        </div>
    </div>
</section>

<!-- ============ STATS BAR ============ -->
<section class="demo-stats-bar" style="background: var(--demo-surface-dark);">
    <div class="demo-stats-grid">
        <div class="demo-stat-item fade-in-section">
            <div class="demo-stat-number">
                <span class="counter-animate" data-target="4">0</span><span class="accent">+</span>
            </div>
            <div class="demo-stat-label">User Roles</div>
        </div>
        <div class="demo-stat-item fade-in-section">
            <div class="demo-stat-number">
                <span class="counter-animate" data-target="12">0</span><span class="accent">+</span>
            </div>
            <div class="demo-stat-label">Core Modules</div>
        </div>
        <div class="demo-stat-item fade-in-section">
            <div class="demo-stat-number">
                <span class="counter-animate" data-target="50">0</span><span class="accent">+</span>
            </div>
            <div class="demo-stat-label">Features</div>
        </div>
        <div class="demo-stat-item fade-in-section">
            <div class="demo-stat-number">
                <span class="counter-animate" data-target="100">0</span><span class="accent">%</span>
            </div>
            <div class="demo-stat-label">Web-Based</div>
        </div>
    </div>
</section>

<!-- ============ FEATURES BY ROLE ============ -->
<section class="demo-section" id="features">
    <div class="demo-section-header fade-in-section">
        <div class="section-tag"><i class="fas fa-sparkles"></i> Features</div>
        <h2>Powerful Features for Every Role</h2>
        <p>LearnAlytics provides tailored dashboards and tools for administrators, deans, instructors, and students — all working together seamlessly.</p>
    </div>

    <!-- Role Tabs -->
    <div class="demo-role-tabs fade-in-section">
        <button class="demo-role-tab active" data-role="admin" onclick="switchRole('admin')">
            <i class="fas fa-shield-halved"></i> Admin
        </button>
        <button class="demo-role-tab" data-role="dean" onclick="switchRole('dean')">
            <i class="fas fa-building-columns"></i> Dean
        </button>
        <button class="demo-role-tab" data-role="instructor" onclick="switchRole('instructor')">
            <i class="fas fa-chalkboard-user"></i> Instructor
        </button>
        <button class="demo-role-tab" data-role="student" onclick="switchRole('student')">
            <i class="fas fa-graduation-cap"></i> Student
        </button>
    </div>

    <!-- Admin Panel -->
    <div class="demo-role-panel active" id="panel-admin">
        <div class="demo-features-grid">
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-red"><i class="fas fa-users-gear"></i></div>
                <h3>User Management</h3>
                <p>Create, edit, and manage all user accounts across roles. Toggle account statuses, assign departments, and audit user activities with full traceability.</p>
                <div class="feature-tags">
                    <span class="feature-tag">CRUD</span>
                    <span class="feature-tag">Role Assignment</span>
                    <span class="feature-tag">Audit Logs</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-blue"><i class="fas fa-calendar-check"></i></div>
                <h3>Academic Year Setup</h3>
                <p>Configure academic years with terms, date ranges, and active status. Scope all institutional data — enrollments, classes, grades — to the correct academic period.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Year Config</span>
                    <span class="feature-tag">Term Management</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-purple"><i class="fas fa-sitemap"></i></div>
                <h3>Programs & Departments</h3>
                <p>Organize the institution's academic structure — create departments, assign programs, and manage sections for streamlined student grouping.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Departments</span>
                    <span class="feature-tag">Programs</span>
                    <span class="feature-tag">Sections</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-cyan"><i class="fas fa-chart-bar"></i></div>
                <h3>System Analytics</h3>
                <p>Monitor institution-wide metrics including enrollment trends, course completion rates, daily active users, and system health at a glance.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Charts</span>
                    <span class="feature-tag">Trends</span>
                    <span class="feature-tag">CSV Export</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-amber"><i class="fas fa-bullhorn"></i></div>
                <h3>System Announcements</h3>
                <p>Broadcast institution-wide announcements to all users. Target specific roles or departments with important updates and notifications.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Broadcast</span>
                    <span class="feature-tag">Targeted</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-green"><i class="fas fa-magnifying-glass"></i></div>
                <h3>Global Search</h3>
                <p>Search across users, courses, and enrollments instantly. Find and navigate to any record in the system with powerful filtering capabilities.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Users</span>
                    <span class="feature-tag">Courses</span>
                    <span class="feature-tag">Enrollments</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Dean Panel -->
    <div class="demo-role-panel" id="panel-dean">
        <div class="demo-features-grid">
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-red"><i class="fas fa-triangle-exclamation"></i></div>
                <h3>At-Risk Student Monitoring</h3>
                <p>Multi-factor early warning engine that identifies struggling students based on grades, attendance, quiz scores, and performance decline trends.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Risk Engine</span>
                    <span class="feature-tag">Multi-Factor</span>
                    <span class="feature-tag">Alerts</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-amber"><i class="fas fa-sliders"></i></div>
                <h3>Configurable Risk Thresholds</h3>
                <p>Fine-tune risk sensitivity with adjustable thresholds — grade below 75%, attendance below 80%, quiz scores below 70%, and performance decline ≥10%.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Custom Thresholds</span>
                    <span class="feature-tag">Adaptive</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-green"><i class="fas fa-hand-holding-medical"></i></div>
                <h3>Academic Interventions</h3>
                <p>Record, assign, and track student interventions — remedial sessions, counseling, and mentoring — with post-intervention grade improvement tracking.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Tracking</span>
                    <span class="feature-tag">Counseling</span>
                    <span class="feature-tag">Outcomes</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-blue"><i class="fas fa-chart-pie"></i></div>
                <h3>Department Analytics</h3>
                <p>Department-wide insights on student passing rates, faculty course loads, program enrollment distribution, and academic performance trends.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Passing Rates</span>
                    <span class="feature-tag">Faculty Loads</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-purple"><i class="fas fa-users-viewfinder"></i></div>
                <h3>Faculty & Student Overview</h3>
                <p>Monitor all faculty and students within the department. View teaching loads, enrollment counts, and individual performance summaries.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Faculty</span>
                    <span class="feature-tag">Students</span>
                    <span class="feature-tag">Overview</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-cyan"><i class="fas fa-file-contract"></i></div>
                <h3>Reports & Announcements</h3>
                <p>Generate academic reports and send targeted announcements to department faculty or specific program cohorts with rich content support.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Reports</span>
                    <span class="feature-tag">Department Comms</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Instructor Panel -->
    <div class="demo-role-panel" id="panel-instructor">
        <div class="demo-features-grid">
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-cyan"><i class="fas fa-book-open"></i></div>
                <h3>Class Management</h3>
                <p>Create subjects with auto-generated 6-character class codes. Manage class streams, enrolled students, and content — all from one intuitive hub.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Class Codes</span>
                    <span class="feature-tag">Stream</span>
                    <span class="feature-tag">Enrollment</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-blue"><i class="fas fa-file-pen"></i></div>
                <h3>Assignment Review Suite</h3>
                <p>Full in-browser canvas annotation tool for grading submissions — with pen, highlighter, shapes, sticky comments, and automatic scoring for PDFs, Word docs, and images.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Canvas Annotation</span>
                    <span class="feature-tag">PDF/DOCX/PPT</span>
                    <span class="feature-tag">Auto-Save</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-purple"><i class="fas fa-puzzle-piece"></i></div>
                <h3>Quiz Builder</h3>
                <p>Build interactive quizzes with multiple question types — multiple choice, checkboxes, short answer, paragraph, true/false, and dropdowns — with auto-scoring and manual grading queues.</p>
                <div class="feature-tags">
                    <span class="feature-tag">6 Question Types</span>
                    <span class="feature-tag">Auto-Scoring</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-green"><i class="fas fa-clipboard-check"></i></div>
                <h3>Attendance Tracking</h3>
                <p>Record daily class attendance with Present, Absent, and Late statuses. View summary analytics and student attendance rate trends over time.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Daily Records</span>
                    <span class="feature-tag">Analytics</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-amber"><i class="fas fa-calculator"></i></div>
                <h3>Gradebook & Exams</h3>
                <p>Manage course gradebooks with weighted score computation. Record formal exam results and track student performance across all assessments.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Weighted Grades</span>
                    <span class="feature-tag">Exam Records</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-red"><i class="fas fa-chart-line"></i></div>
                <h3>Student Analytics</h3>
                <p>Comprehensive student performance metrics, grade distributions, risk identification indicators, and detailed progress tracking per class.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Performance</span>
                    <span class="feature-tag">Risk Flags</span>
                    <span class="feature-tag">Charts</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Panel -->
    <div class="demo-role-panel" id="panel-student">
        <div class="demo-features-grid">
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-green"><i class="fas fa-right-to-bracket"></i></div>
                <h3>Easy Class Enrollment</h3>
                <p>Join classes instantly using a 6-character class code shared by the instructor, or browse and enroll through your program section — no hassle, no waiting.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Class Code</span>
                    <span class="feature-tag">Self-Enroll</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-blue"><i class="fas fa-book-bookmark"></i></div>
                <h3>Lesson Materials</h3>
                <p>Access class streams, view announcements, and download lesson materials — including documents, videos, and file attachments — all organized by class.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Stream</span>
                    <span class="feature-tag">Downloads</span>
                    <span class="feature-tag">Media</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-purple"><i class="fas fa-paper-plane"></i></div>
                <h3>Assignment Submissions</h3>
                <p>Submit deliverables via file upload or rich text. View detailed instructor annotations, feedback marks, and graded scores directly in the browser.</p>
                <div class="feature-tags">
                    <span class="feature-tag">File Upload</span>
                    <span class="feature-tag">Annotations</span>
                    <span class="feature-tag">Feedback</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-cyan"><i class="fas fa-circle-question"></i></div>
                <h3>Online Quizzes</h3>
                <p>Take interactive quizzes with instant automated scoring and detailed feedback. Review results and see correct answers for self-paced learning.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Auto-Score</span>
                    <span class="feature-tag">Instant Feedback</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-amber"><i class="fas fa-chart-simple"></i></div>
                <h3>Grades & Progress</h3>
                <p>Track your GPA, class grades, attendance rates, and overall academic progress — all in a single personal dashboard with visual indicators.</p>
                <div class="feature-tags">
                    <span class="feature-tag">GPA</span>
                    <span class="feature-tag">Attendance</span>
                    <span class="feature-tag">Progress</span>
                </div>
            </div>
            <div class="demo-feature-card fade-in-section">
                <div class="demo-feature-icon icon-red"><i class="fas fa-comments"></i></div>
                <h3>Messaging</h3>
                <p>Communicate directly with instructors and peers through the built-in messaging system. Stay connected and get help when you need it.</p>
                <div class="feature-tags">
                    <span class="feature-tag">Direct Messages</span>
                    <span class="feature-tag">Real-Time</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ DEMO WALKTHROUGH ============ -->
<section class="demo-section-dark" id="walkthrough">
    <div class="section-inner">
        <div class="demo-section-header fade-in-section">
            <div class="section-tag"><i class="fas fa-route"></i> Walkthrough</div>
            <h2>Step-by-Step Demo Guide</h2>
            <p>Follow these steps to experience the full capabilities of LearnAlytics during the demonstration.</p>
        </div>

        <div class="demo-steps">
            <!-- Step 1 -->
            <div class="demo-step">
                <div class="demo-step-number">1</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-admin"><i class="fas fa-shield-halved"></i> Admin</span>
                    <h3>System Setup & User Management</h3>
                    <p>Log in as <strong>Admin</strong>. Navigate to the dashboard to see system-wide analytics — enrollment trends, user distribution, and activity charts. Then go to <strong>User Management</strong> to create an instructor account and a student account.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> admin_dashboard.php → admin/users.php</div>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="demo-step">
                <div class="demo-step-number">2</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-admin"><i class="fas fa-shield-halved"></i> Admin</span>
                    <h3>Configure Academic Structure</h3>
                    <p>Set up the <strong>Academic Year</strong> (e.g., 2025–2026) and create <strong>Departments</strong> and <strong>Programs</strong>. This establishes the institutional hierarchy that all other roles operate within.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> academic_year_management.php → admin/courses_departments.php</div>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="demo-step">
                <div class="demo-step-number">3</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-instructor"><i class="fas fa-chalkboard-user"></i> Instructor</span>
                    <h3>Create a Class & Publish Content</h3>
                    <p>Switch to <strong>Instructor</strong>. Create a new class — note the auto-generated <strong>6-character Class Code</strong>. Upload lesson materials, create an assignment with a due date, and build a quiz with multiple question types.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> my_classes.php → lessons/ → assignments/create.php → quizzes/create.php</div>
                </div>
            </div>

            <!-- Step 4 -->
            <div class="demo-step">
                <div class="demo-step-number">4</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-student"><i class="fas fa-graduation-cap"></i> Student</span>
                    <h3>Enroll, Submit & Take Quiz</h3>
                    <p>Log in as <strong>Student</strong>. Use the class code to <strong>join the class</strong>. Browse lesson materials, <strong>submit an assignment</strong> (file upload), and <strong>take the quiz</strong> to see auto-scoring in action.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> subjects/join.php → assignments/ → quizzes/</div>
                </div>
            </div>

            <!-- Step 5 -->
            <div class="demo-step">
                <div class="demo-step-number">5</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-instructor"><i class="fas fa-chalkboard-user"></i> Instructor</span>
                    <h3>Grade with Canvas Annotations</h3>
                    <p>Switch back to <strong>Instructor</strong>. Open the <strong>Assignment Review Suite</strong> to annotate the student's submission with pen, highlighter, shapes, and sticky comments. Assign a score and provide feedback. Record <strong>Attendance</strong> for the class.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> assignments/review.php → attendance/</div>
                </div>
            </div>

            <!-- Step 6 -->
            <div class="demo-step">
                <div class="demo-step-number">6</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-student"><i class="fas fa-graduation-cap"></i> Student</span>
                    <h3>View Grades & Feedback</h3>
                    <p>Switch to <strong>Student</strong> again. View the annotated assignment with the instructor's markup and score. Check the <strong>Grades</strong> page to see your cumulative performance and the <strong>Student Dashboard</strong> for an overview of progress.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> assignments/view.php → grades/ → student_dashboard.php</div>
                </div>
            </div>

            <!-- Step 7 -->
            <div class="demo-step">
                <div class="demo-step-number">7</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-dean"><i class="fas fa-building-columns"></i> Dean</span>
                    <h3>Monitor Risk & Intervene</h3>
                    <p>Log in as <strong>Dean</strong>. Explore the <strong>Department Dashboard</strong> — review at-risk students flagged by the risk engine, adjust <strong>risk thresholds</strong>, record an <strong>academic intervention</strong>, and review department-wide analytics and trends.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> dean_dashboard.php (Overview → Students → Analytics)</div>
                </div>
            </div>

            <!-- Step 8 -->
            <div class="demo-step">
                <div class="demo-step-number">8</div>
                <div class="demo-step-card">
                    <span class="demo-step-role role-instructor"><i class="fas fa-chalkboard-user"></i> Instructor</span>
                    <h3>Review Student Analytics</h3>
                    <p>As <strong>Instructor</strong>, open <strong>Student Analytics</strong> to see performance distributions, risk indicators, and grade trends across your classes. Demonstrate the <strong>Quiz Analytics</strong> page for item analysis.</p>
                    <div class="step-action"><i class="fas fa-arrow-right"></i> instructor/student_performance.php → quizzes/analytics.php</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ SYSTEM ARCHITECTURE ============ -->
<section class="demo-section-dark" id="architecture" style="border-top: 1px solid rgba(255,255,255,0.04);">
    <div class="section-inner">
        <div class="demo-section-header fade-in-section">
            <div class="section-tag"><i class="fas fa-layer-group"></i> Architecture</div>
            <h2>System Architecture</h2>
            <p>Built with proven technologies for reliability, performance, and ease of deployment.</p>
        </div>

        <div class="demo-arch-visual fade-in-section">
            <div class="arch-layer">
                <div class="arch-layer-icon" style="background: rgba(37,99,235,0.15); color: var(--demo-primary-light);">
                    <i class="fas fa-desktop"></i>
                </div>
                <h4>Frontend</h4>
                <ul>
                    <li>Bootstrap 5.3</li>
                    <li>Chart.js 4.4</li>
                    <li>Font Awesome 6</li>
                    <li>Google Fonts (Inter)</li>
                    <li>Custom Canvas API</li>
                    <li>PDF.js / docx-preview</li>
                </ul>
            </div>

            <div class="arch-connector"><i class="fas fa-arrows-left-right"></i></div>

            <div class="arch-layer">
                <div class="arch-layer-icon" style="background: rgba(139,92,246,0.15); color: var(--demo-accent-2);">
                    <i class="fas fa-server"></i>
                </div>
                <h4>Backend</h4>
                <ul>
                    <li>PHP 8 (PDO)</li>
                    <li>Session Auth</li>
                    <li>CAPTCHA Security</li>
                    <li>Role-Based Guards</li>
                    <li>AJAX API Endpoints</li>
                    <li>File Upload Handler</li>
                </ul>
            </div>

            <div class="arch-connector"><i class="fas fa-arrows-left-right"></i></div>

            <div class="arch-layer">
                <div class="arch-layer-icon" style="background: rgba(6,182,212,0.15); color: var(--demo-accent);">
                    <i class="fas fa-database"></i>
                </div>
                <h4>Database</h4>
                <ul>
                    <li>MySQL / MariaDB</li>
                    <li>utf8mb4 Charset</li>
                    <li>Relational Schema</li>
                    <li>Prepared Statements</li>
                    <li>Migration Scripts</li>
                    <li>Seed Data</li>
                </ul>
            </div>

            <div class="arch-connector"><i class="fas fa-arrows-left-right"></i></div>

            <div class="arch-layer">
                <div class="arch-layer-icon" style="background: rgba(16,185,129,0.15); color: var(--demo-success);">
                    <i class="fas fa-cloud"></i>
                </div>
                <h4>Infrastructure</h4>
                <ul>
                    <li>Apache (XAMPP)</li>
                    <li>Responsive Design</li>
                    <li>CDN Assets</li>
                    <li>Google Analytics</li>
                    <li>Export CSV/PDF</li>
                    <li>Real-time Notifications</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ DEMO CREDENTIALS ============ -->
<section class="demo-section" id="credentials">
    <div class="demo-section-header fade-in-section">
        <div class="section-tag"><i class="fas fa-key"></i> Demo Access</div>
        <h2>Demo Login Credentials</h2>
        <p>Use these accounts to explore each role during the demonstration. All accounts share the same password for convenience.</p>
    </div>

    <div class="demo-credentials fade-in-section">
        <div class="demo-credential-card card-admin">
            <div class="cred-icon"><i class="fas fa-shield-halved"></i></div>
            <h4>Administrator</h4>
            <div class="cred-field">
                <span class="label">Email</span>
                <span class="value">admin@learnAlytics.com</span>
            </div>
            <div class="cred-field">
                <span class="label">Password</span>
                <span class="value">password123</span>
            </div>
        </div>

        <div class="demo-credential-card card-dean">
            <div class="cred-icon"><i class="fas fa-building-columns"></i></div>
            <h4>Dean</h4>
            <div class="cred-field">
                <span class="label">Email</span>
                <span class="value">dean@learnAlytics.com</span>
            </div>
            <div class="cred-field">
                <span class="label">Password</span>
                <span class="value">password123</span>
            </div>
        </div>

        <div class="demo-credential-card card-instructor">
            <div class="cred-icon"><i class="fas fa-chalkboard-user"></i></div>
            <h4>Instructor</h4>
            <div class="cred-field">
                <span class="label">Email</span>
                <span class="value">instructor@learnAlytics.com</span>
            </div>
            <div class="cred-field">
                <span class="label">Password</span>
                <span class="value">password123</span>
            </div>
        </div>

        <div class="demo-credential-card card-student">
            <div class="cred-icon"><i class="fas fa-graduation-cap"></i></div>
            <h4>Student</h4>
            <div class="cred-field">
                <span class="label">Email</span>
                <span class="value">student@learnAlytics.com</span>
            </div>
            <div class="cred-field">
                <span class="label">Password</span>
                <span class="value">password123</span>
            </div>
        </div>
    </div>

    <p style="text-align: center; margin-top: 32px; color: var(--demo-text-muted); font-size: 0.88rem;">
        <i class="fas fa-info-circle"></i>&nbsp;
        Update the credentials above to match your actual demo accounts before presenting.
    </p>
</section>

<!-- ============ FOOTER ============ -->
<footer class="demo-footer">
    <p>
        <span class="footer-brand"><i class="fas fa-chart-line"></i> LearnAlytics</span>
        &nbsp;&mdash;&nbsp; Learning Management System &nbsp;&bull;&nbsp; <?php echo date('Y'); ?>
    </p>
</footer>

<!-- ============ SCRIPTS ============ -->
<script>
(function() {
    'use strict';

    // ---- Navbar scroll effect ----
    const nav = document.getElementById('demoNav');
    window.addEventListener('scroll', () => {
        nav.classList.toggle('scrolled', window.scrollY > 60);
    });

    // ---- Generate floating particles ----
    const particlesEl = document.getElementById('particles');
    if (particlesEl) {
        for (let i = 0; i < 30; i++) {
            const span = document.createElement('span');
            const size = Math.random() * 6 + 2;
            span.style.width = size + 'px';
            span.style.height = size + 'px';
            span.style.left = Math.random() * 100 + '%';
            span.style.top = (Math.random() * 100 + 100) + '%';
            span.style.animationDuration = (Math.random() * 20 + 15) + 's';
            span.style.animationDelay = (Math.random() * 10) + 's';
            particlesEl.appendChild(span);
        }
    }

    // ---- Counter Animation ----
    const counters = document.querySelectorAll('.counter-animate');
    let countersAnimated = false;

    function animateCounters() {
        if (countersAnimated) return;
        countersAnimated = true;
        counters.forEach(counter => {
            const target = +counter.getAttribute('data-target');
            const duration = 2000;
            const start = performance.now();
            function update(now) {
                const elapsed = now - start;
                const progress = Math.min(elapsed / duration, 1);
                // Ease out cubic
                const eased = 1 - Math.pow(1 - progress, 3);
                counter.textContent = Math.floor(eased * target);
                if (progress < 1) {
                    requestAnimationFrame(update);
                } else {
                    counter.textContent = target;
                }
            }
            requestAnimationFrame(update);
        });
    }

    // ---- Scroll Animation Observer ----
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                // Trigger counters when stats bar is visible
                if (entry.target.closest('.demo-stats-bar')) {
                    animateCounters();
                }
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    document.querySelectorAll('.fade-in-section, .demo-step').forEach(el => {
        observer.observe(el);
    });

    // ---- Smooth Scroll for Nav Links ----
    document.querySelectorAll('.demo-nav-links a, .btn-hero-primary, .btn-hero-secondary').forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });

})();

// ---- Role Tab Switching ----
function switchRole(role) {
    // Update tabs
    document.querySelectorAll('.demo-role-tab').forEach(tab => {
        tab.classList.toggle('active', tab.getAttribute('data-role') === role);
    });
    // Update panels
    document.querySelectorAll('.demo-role-panel').forEach(panel => {
        panel.classList.remove('active');
    });
    const activePanel = document.getElementById('panel-' + role);
    if (activePanel) {
        activePanel.classList.add('active');
        // Re-observe new cards for animation
        activePanel.querySelectorAll('.fade-in-section').forEach(el => {
            el.classList.remove('visible');
            setTimeout(() => el.classList.add('visible'), 50);
        });
    }
}
</script>

</body>
</html>
