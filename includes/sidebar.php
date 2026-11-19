<nav id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-section-title">
            <i class="fa-solid fa-compass me-2"></i>
            <span><?php echo strtoupper($_SESSION['user_role'] ?? 'Menu'); ?> PORTAL</span>
        </div>
    </div>
    <div class="position-sticky pt-0">
        <ul class="nav flex-column">
            <li class="nav-item">
                <?php
                    $curPage = $_SERVER['PHP_SELF'];
                    $curTab = $_GET['tab'] ?? '';
                    $isDashActive = (strpos($curPage, 'dean_dashboard') !== false) ? empty($curTab) : (strpos($curPage, 'dashboard') !== false);
                ?>
                <a class="nav-link <?php echo $isDashActive ? 'active' : ''; ?>" href="<?php
                    $role = $_SESSION['user_role'] ?? 'student';
                    if ($role === 'admin') {
                        echo $baseUrl . '/admin_dashboard.php';
                    } elseif ($role === 'instructor') {
                        echo $baseUrl . '/instructor_dashboard.php';
                    } elseif ($role === 'dean') {
                        echo $baseUrl . '/dean_dashboard.php';
                    } else {
                        echo $baseUrl . '/student_dashboard.php';
                    }
                ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'instructor'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'my_classes') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/my_classes.php">
                        <i class="fas fa-chalkboard"></i> <span>My Classes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'student_performance') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/instructor/student_performance.php">
                        <i class="fas fa-user-graduate"></i> <span>Student Analytics</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/grades/manage.php') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/grades/manage.php">
                        <i class="fas fa-clipboard-check"></i> <span>Grade Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/exams/') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/exams/index.php">
                        <i class="fas fa-clipboard-list"></i> <span>Exam Records</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'dean'): ?>
                <?php
                    $currentPage = $_SERVER['PHP_SELF'];
                    $currentTab = $_GET['tab'] ?? '';
                    $isDean = (strpos($currentPage, 'dean_dashboard') !== false);
                ?>
            <?php endif; ?>
            <?php if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'dean')): ?>
                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === 'student'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'subjects') !== false && strpos($_SERVER['PHP_SELF'], 'my_classes') === false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/student/subjects.php">
                        <i class="fas fa-book"></i> <span>My Classes</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === 'instructor'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'assignments') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/assignments/index.php">
                        <i class="fas fa-tasks"></i> <span>Assignments</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'lessons') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/lessons/index.php">
                        <i class="fas fa-video"></i> <span>Lessons</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'student'): ?>
                <?php
                    $isAssessmentPage = strpos($_SERVER['PHP_SELF'], '/student/assignments.php') !== false
                        || strpos($_SERVER['PHP_SELF'], '/student/assessment_results.php') !== false
                        || strpos($_SERVER['PHP_SELF'], '/quizzes/') !== false;
                ?>
                <li class="nav-item assessment-nav-item">
                    <button class="nav-link assessment-toggle <?php echo $isAssessmentPage ? 'active' : ''; ?>"
                            type="button"
                            aria-expanded="<?php echo $isAssessmentPage ? 'true' : 'false'; ?>"
                            aria-controls="assessmentSubmenu"
                            data-assessment-toggle>
                        <i class="fas fa-clipboard-check"></i>
                        <span>Assessment</span>
                        <i class="fas fa-chevron-down assessment-chevron" aria-hidden="true"></i>
                    </button>
                    <ul class="assessment-submenu <?php echo $isAssessmentPage ? 'is-open' : ''; ?>" id="assessmentSubmenu">
                        <li>
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/student/assignments.php') !== false ? 'active' : ''; ?>"
                               href="<?php echo $baseUrl; ?>/student/assignments.php">
                                <i class="fas fa-tasks"></i><span>Assignments</span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/quizzes/') !== false ? 'active' : ''; ?>"
                               href="<?php echo $baseUrl; ?>/quizzes/index.php">
                                <i class="fas fa-question-circle"></i><span>Quizzes</span>
                            </a>
                        </li>
                        <li>
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/student/assessment_results.php') !== false ? 'active' : ''; ?>"
                               href="<?php echo $baseUrl; ?>/student/assessment_results.php">
                                <i class="fas fa-chart-bar"></i><span>Assessment Results</span>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'instructor'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'grades') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/grades/index.php">
                        <i class="fas fa-chart-line"></i> <span>Grades</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === 'instructor'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'attendance') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/attendance/index.php">
                        <i class="fas fa-calendar-check"></i> <span>Attendance</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student'): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'quizzes') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/quizzes/index.php">
                    <i class="fas fa-question-circle"></i> <span>Quizzes</span>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/messages/') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/messages/index.php">
                    <i class="fas fa-comments"></i> <span>Messages</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'profile') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/profile/index.php">
                    <i class="fas fa-user"></i> <span>Profile</span>
                </a>
            </li>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <li class="nav-item mt-2">
                    <div class="sidebar-heading">Admin Panel</div>
                </li>
                <li class="nav-item">
                    <a class="nav-link admin-link <?php echo (strpos($_SERVER['PHP_SELF'], 'admin/users.php') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/admin/users.php">
                        <i class="fas fa-users admin-icon"></i> <span>User Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link admin-link <?php echo (strpos($_SERVER['PHP_SELF'], 'courses_departments.php') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/admin/courses_departments.php">
                        <i class="fas fa-building-columns admin-icon"></i> <span>Programs &amp; Departments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link admin-link <?php echo (strpos($_SERVER['PHP_SELF'], 'academic_year_management.php') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/academic_year_management.php">
                        <i class="fas fa-calendar-alt admin-icon"></i> <span>Academic Year Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link admin-link <?php echo (strpos($_SERVER['PHP_SELF'], 'notifications/index.php') !== false) ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/notifications/index.php">
                        <i class="fas fa-bell admin-icon"></i> <span>Notifications</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- App Content Area -->
<div class="app-content">
