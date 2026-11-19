<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Administrator';

// Get search parameters
$searchQuery = trim($_GET['q'] ?? '');
$searchType = $_GET['type'] ?? 'all';

// Initialize results
$results = [];
$resultCount = 0;
$searchPerformed = false;

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!empty($searchQuery)) {
    $searchPerformed = true;
    $searchTerm = "%{$searchQuery}%";

    switch ($searchType) {
        case 'student':
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.role, u.created_at,
                       sp.student_id_number, sp.year_level, sp.enrollment_status
                FROM users u
                LEFT JOIN student_profiles sp ON sp.student_id = u.id
                WHERE u.role = 'student' 
                AND (u.name LIKE ? OR u.email LIKE ? OR sp.student_id_number LIKE ?)
                ORDER BY u.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $results = $stmt->fetchAll();

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM users u
                LEFT JOIN student_profiles sp ON sp.student_id = u.id
                WHERE u.role = 'student' 
                AND (u.name LIKE ? OR u.email LIKE ? OR sp.student_id_number LIKE ?)
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $resultCount = $countStmt->fetchColumn();
            break;

        case 'instructor':
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.role, u.created_at,
                       COUNT(s.id) as subject_count
                FROM users u
                LEFT JOIN subjects s ON s.instructor_id = u.id
                WHERE u.role = 'instructor' 
                AND (u.name LIKE ? OR u.email LIKE ?)
                GROUP BY u.id
                ORDER BY u.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $perPage, $offset]);
            $results = $stmt->fetchAll();

            $countStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT u.id) FROM users u
                WHERE u.role = 'instructor' 
                AND (u.name LIKE ? OR u.email LIKE ?)
            ");
            $countStmt->execute([$searchTerm, $searchTerm]);
            $resultCount = $countStmt->fetchColumn();
            break;

        case 'course':
            $stmt = $pdo->prepare("
                SELECT s.id, s.title, s.class_code, s.status, s.description,
                       u.name as instructor_name,
                       COUNT(e.id) as enrollment_count
                FROM subjects s
                LEFT JOIN users u ON u.id = s.instructor_id
                LEFT JOIN enrollments e ON e.subject_id = s.id
                WHERE s.title LIKE ? OR s.class_code LIKE ? OR s.description LIKE ?
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $results = $stmt->fetchAll();

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM subjects s
                WHERE s.title LIKE ? OR s.class_code LIKE ? OR s.description LIKE ?
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $resultCount = $countStmt->fetchColumn();
            break;

        case 'enrollment':
            $stmt = $pdo->prepare("
                SELECT e.id, e.enrolled_at,
                       u.name as student_name, u.email as student_email,
                       s.title as subject_title, s.class_code,
                       p.name as program_name
                FROM enrollments e
                LEFT JOIN users u ON u.id = e.student_id
                LEFT JOIN subjects s ON s.id = e.subject_id
                LEFT JOIN programs p ON p.id = s.program_id
                WHERE u.name LIKE ? OR u.email LIKE ? OR s.title LIKE ? OR p.name LIKE ?
                ORDER BY e.enrolled_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $results = $stmt->fetchAll();

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM enrollments e
                LEFT JOIN users u ON u.id = e.student_id
                LEFT JOIN subjects s ON s.id = e.subject_id
                LEFT JOIN programs p ON p.id = s.program_id
                WHERE u.name LIKE ? OR u.email LIKE ? OR s.title LIKE ? OR p.name LIKE ?
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $resultCount = $countStmt->fetchColumn();
            break;

        default: // Search all
            $stmt = $pdo->prepare("
                (SELECT u.id, u.name, u.email, u.role, u.created_at,
                        sp.student_id_number, sp.year_level, sp.enrollment_status,
                        NULL as subject_title, NULL as class_code, NULL as instructor_name,
                        NULL as enrollment_count, NULL as enrolled_at, NULL as program_name,
                        'user' as result_type
                 FROM users u
                 LEFT JOIN student_profiles sp ON sp.student_id = u.id
                 WHERE u.role IN ('student', 'instructor')
                 AND (u.name LIKE ? OR u.email LIKE ? OR sp.student_id_number LIKE ?)
                 ORDER BY u.created_at DESC)

                UNION ALL

                (SELECT s.id, s.title as name, s.class_code as email, s.status as role, s.created_at,
                        NULL as student_id_number, NULL as year_level, NULL as enrollment_status,
                        s.title as subject_title, s.class_code, u.name as instructor_name,
                        COUNT(e.id) as enrollment_count, NULL as enrolled_at, NULL as program_name,
                        'course' as result_type
                 FROM subjects s
                 LEFT JOIN users u ON u.id = s.instructor_id
                 LEFT JOIN enrollments e ON e.subject_id = s.id
                 WHERE s.title LIKE ? OR s.class_code LIKE ?
                 GROUP BY s.id
                 ORDER BY s.created_at DESC)

                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $perPage, $offset]);
            $results = $stmt->fetchAll();

            $countStmt = $pdo->prepare("
                SELECT (
                    SELECT COUNT(*) FROM users WHERE role IN ('student', 'instructor')
                    AND (name LIKE ? OR email LIKE ?)
                ) + (
                    SELECT COUNT(*) FROM subjects
                    WHERE title LIKE ? OR class_code LIKE ?
                ) as total
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $resultCount = $countStmt->fetchColumn();
            break;
    }
}

// Get recent searches for history (optional)
$recentSearches = [];
if (isset($_SESSION['recent_searches'])) {
    $recentSearches = array_slice($_SESSION['recent_searches'], 0, 5);
}
if (!empty($searchQuery) && $searchPerformed) {
    if (!isset($_SESSION['recent_searches'])) {
        $_SESSION['recent_searches'] = [];
    }
    if (!in_array($searchQuery, $_SESSION['recent_searches'])) {
        array_unshift($_SESSION['recent_searches'], $searchQuery);
        $_SESSION['recent_searches'] = array_slice($_SESSION['recent_searches'], 0, 10);
    }
}

// Calculate pagination
$totalPages = ceil($resultCount / $perPage);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-blue: #3b82f6;
    }

    body { background: #f1f5f9; }

    .search-results-container {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    }

    .result-item {
        padding: 1rem;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.2s ease;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .result-item:hover {
        background: #f8fafc;
    }

    .result-item:last-child {
        border-bottom: none;
    }

    .result-avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .result-info {
        flex-grow: 1;
    }

    .result-title {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }

    .result-subtitle {
        font-size: 0.85rem;
        color: #64748b;
    }

    .result-meta {
        text-align: right;
        flex-shrink: 0;
    }

    .result-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 0.75rem;
        padding: 0.35rem 0.75rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid #2563eb;
        color: #2563eb;
        text-decoration: none;
        background: white;
        transition: background 0.2s ease, color 0.2s ease;
    }

    .result-action:hover {
        background: #2563eb;
        color: white;
    }

    .result-type {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .type-student { background: #dbeafe; color: #1e40af; }
    .type-instructor { background: #fef3c7; color: #78350f; }
    .type-course { background: #dcfce7; color: #166534; }
    .type-enrollment { background: #e0e7ff; color: #4338ca; }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .status-active { background: #dcfce7; color: #166534; }
    .status-draft { background: #f1f5f9; color: #475569; }
    .status-completed { background: #dbeafe; color: #1e40af; }
    .status-archived { background: #fef3c7; color: #78350f; }

    .search-summary {
        background: #f8fafc;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .no-results {
        text-align: center;
        padding: 3rem;
        color: #64748b;
    }

    .no-results i {
        font-size: 3rem;
        color: #cbd5e1;
        margin-bottom: 1rem;
    }

    .pagination .page-link {
        border: none;
        color: var(--secondary-blue);
        border-radius: 8px;
        margin: 0 2px;
        padding: 0.5rem 0.875rem;
    }

    .pagination .page-link:hover {
        background: #dbeafe;
    }

    .pagination .page-item.active .page-link {
        background: var(--secondary-blue);
        color: white;
    }

    .back-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--secondary-blue);
        text-decoration: none;
        font-weight: 500;
        margin-bottom: 1rem;
    }

    .back-btn:hover {
        color: var(--primary-blue);
    }

    @media (max-width: 768px) {
        .result-item {
            flex-wrap: wrap;
        }
        .result-meta {
            width: 100%;
            text-align: left;
            margin-top: 0.5rem;
        }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <div class="pt-3 pb-3 mb-4">
        <a href="../admin_dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <h1 class="h2 fw-bold" style="color: var(--primary-blue);">
            <i class="fas fa-search me-2" style="color: var(--secondary-blue);"></i>Search Results
        </h1>
        <p class="text-muted mb-0">
            <?php if ($searchPerformed): ?>
                Showing results for "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"
                <?php if ($searchType !== 'all'): ?>
                    in <strong><?php echo ucfirst($searchType); ?>s</strong>
                <?php endif; ?>
            <?php else: ?>
                Enter a search term to find students, instructors, courses, or enrollments.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($searchPerformed): ?>
        <!-- Search Summary -->
        <div class="search-summary">
            <div>
                <span class="fw-bold" style="color: var(--primary-blue);"><?php echo number_format($resultCount); ?></span>
                result<?php echo $resultCount !== 1 ? 's' : ''; ?> found
            </div>
            <div>
                <span class="text-muted">Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?></span>
            </div>
        </div>

        <!-- Results -->
        <div class="search-results-container">
            <?php if (!empty($results)): ?>
                <?php foreach ($results as $result): ?>
                    <div class="result-item">
                        <div class="result-avatar" style="
                            background: <?php
                                $type = $result['result_type'] ?? $result['role'] ?? 'course';
                                $colors = [
                                    'student' => '#dbeafe',
                                    'instructor' => '#fef3c7',
                                    'course' => '#dcfce7',
                                    'user' => ($result['role'] === 'student' ? '#dbeafe' : '#fef3c7')
                                ];
                                echo $colors[$type] ?? '#e0e7ff';
                            ?>;
                            color: <?php
                                $textColors = [
                                    'student' => '#2563eb',
                                    'instructor' => '#d97706',
                                    'course' => '#059669',
                                    'user' => ($result['role'] === 'student' ? '#2563eb' : '#d97706')
                                ];
                                echo $textColors[$type] ?? '#4f46e5';
                            ?>;">
                            <?php echo strtoupper(substr($result['name'] ?? 'N/A', 0, 2)); ?>
                        </div>
                        <div class="result-info">
                            <div class="result-title">
                                <?php echo htmlspecialchars($result['name'] ?? 'N/A'); ?>
                                <?php if (isset($result['subject_title']) && $result['subject_title']): ?>
                                    <small class="text-muted">- <?php echo htmlspecialchars($result['subject_title']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="result-subtitle">
                                <?php
                                $details = [];
                                if (isset($result['email']) && $result['email']) $details[] = htmlspecialchars($result['email']);
                                if (isset($result['student_id_number']) && $result['student_id_number']) $details[] = 'ID: ' . htmlspecialchars($result['student_id_number']);
                                if (isset($result['class_code']) && $result['class_code']) $details[] = 'Code: ' . htmlspecialchars($result['class_code']);
                                if (isset($result['instructor_name']) && $result['instructor_name']) $details[] = 'Instructor: ' . htmlspecialchars($result['instructor_name']);
                                if (isset($result['year_level']) && $result['year_level']) $details[] = htmlspecialchars($result['year_level']);
                                if (isset($result['enrollment_count']) && $result['enrollment_count'] !== null) $details[] = $result['enrollment_count'] . ' enrollments';
                                if (isset($result['program_name']) && $result['program_name']) $details[] = htmlspecialchars($result['program_name']);
                                echo implode(' · ', $details);
                                ?>
                            </div>
                        </div>
                        <div class="result-meta">
                            <?php
                            $resultType = $result['result_type'] ?? $result['role'] ?? 'course';
                            $typeClass = in_array($resultType, ['student', 'instructor', 'course', 'enrollment', 'user']) ? 'type-' . ($resultType === 'user' ? ($result['role'] ?? 'student') : $resultType) : 'type-course';
                            ?>
                            <span class="result-type <?php echo $typeClass; ?>">
                                <?php echo ucfirst($resultType === 'user' ? ($result['role'] ?? 'Student') : $resultType); ?>
                            </span>
                            <?php if (isset($result['status']) && $result['status']): ?>
                                <br>
                                <span class="status-badge status-<?php echo htmlspecialchars($result['status']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($result['status'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (isset($result['enrollment_status']) && $result['enrollment_status']): ?>
                                <br>
                                <span class="status-badge status-<?php echo htmlspecialchars($result['enrollment_status']); ?>">
                                    <?php echo htmlspecialchars($result['enrollment_status']); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (($result['result_type'] ?? ($result['role'] ?? '')) === 'user' || in_array($result['role'] ?? '', ['student', 'instructor'])): ?>
                                <a href="user_performance.php?user_id=<?php echo (int)$result['id']; ?>" class="result-action">
                                    <i class="fas fa-eye me-1"></i> View
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <h5>No results found</h5>
                    <p>Try adjusting your search terms or filters.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&type=<?php echo urlencode($searchType); ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&type=<?php echo urlencode($searchType); ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&type=<?php echo urlencode($searchType); ?>&page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="search-results-container text-center">
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h5>Start Searching</h5>
                <p>Use the search bar above to find students, instructors, courses, or enrollments.</p>
                <a href="../admin_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>