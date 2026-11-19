<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

// Fetch filter options from database
$stmt = $pdo->query("SELECT id, name FROM programs WHERE status = 'active' ORDER BY name");
$programs = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
$departments = $stmt->fetchAll();

$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Other'];
$statuses = ['Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">
            <i class="fas fa-magnifying-glass me-2"></i>Smart Student Search
        </h1>
    </div>

    <!-- Search and Filter Card -->
    <div class="card dashboard-card shadow-sm mb-4">
        <div class="card-header card-header-custom">
            <h5 class="card-title mb-0">
                <i class="fas fa-sliders-h me-2"></i>Search & Filter Students
            </h5>
        </div>
        <div class="card-body">
            <!-- Main Search Input -->
            <div class="mb-4">
                <label for="searchInput" class="form-label fw-bold">
                    <i class="fas fa-search me-2" style="color: #2563eb;"></i>Search by Name, Email, or Student ID
                </label>
                <input 
                    type="text" 
                    class="form-control form-control-lg" 
                    id="searchInput" 
                    placeholder="Type to search..." 
                    style="border-color: #2563eb; border-width: 2px;"
                    autocomplete="off"
                >
                <small class="form-text text-muted mt-2">
                    <i class="fas fa-info-circle me-1"></i>Results update as you type
                </small>
            </div>

            <!-- Filters Row -->
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label for="programFilter" class="form-label fw-bold">Program</label>
                    <select class="form-select" id="programFilter" style="border-color: #2563eb;">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['id']; ?>">
                                <?php echo htmlspecialchars($prog['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label for="departmentFilter" class="form-label fw-bold">Department</label>
                    <select class="form-select" id="departmentFilter" style="border-color: #2563eb;">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>">
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label for="yearLevelFilter" class="form-label fw-bold">Year Level</label>
                    <select class="form-select" id="yearLevelFilter" style="border-color: #2563eb;">
                        <option value="">All Year Levels</option>
                        <?php foreach ($yearLevels as $year): ?>
                            <option value="<?php echo htmlspecialchars($year); ?>">
                                <?php echo htmlspecialchars($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label for="statusFilter" class="form-label fw-bold">Enrollment Status</label>
                    <select class="form-select" id="statusFilter" style="border-color: #2563eb;">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $stat): ?>
                            <option value="<?php echo htmlspecialchars($stat); ?>">
                                <?php echo htmlspecialchars($stat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row g-2 mt-3">
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-secondary" id="clearFiltersBtn">
                        <i class="fas fa-times me-2"></i>Clear Filters
                    </button>
                </div>
                <div class="col-auto">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Results: <strong><span id="resultCount">0</span></strong> students found
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Table Card -->
    <div class="card dashboard-card shadow-sm">
        <div class="card-header card-header-custom">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Search Results
            </h5>
        </div>
        <div class="card-body p-0">
            <!-- Loading Spinner -->
            <div id="loadingSpinner" class="text-center py-5" style="display: none;">
                <div class="spinner-border" style="color: #2563eb;" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-3">Searching students...</p>
            </div>

            <!-- Results Table -->
            <div id="resultsContainer" style="display: none;">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead style="background-color: #eff6ff; border-bottom: 2px solid #2563eb;">
                            <tr>
                                <th style="color: #1e3a8a; width: 20%;">
                                    <i class="fas fa-user me-2"></i>Name
                                </th>
                                <th style="color: #1e3a8a; width: 15%;">
                                    <i class="fas fa-id-badge me-2"></i>Student ID
                                </th>
                                <th style="color: #1e3a8a; width: 20%;">
                                    <i class="fas fa-envelope me-2"></i>Email
                                </th>
                                <th style="color: #1e3a8a; width: 12%;">
                                    <i class="fas fa-book me-2"></i>Program
                                </th>
                                <th style="color: #1e3a8a; width: 10%;">
                                    <i class="fas fa-layer-group me-2"></i>Year
                                </th>
                                <th style="color: #1e3a8a; width: 12%;">
                                    <i class="fas fa-flag me-2"></i>Status
                                </th>
                                <th style="color: #1e3a8a; width: 11%;">
                                    <i class="fas fa-cogs me-2"></i>Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody id="resultsTableBody">
                            <!-- Results will be inserted here -->
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div id="paginationContainer" class="d-flex justify-content-between align-items-center p-3 border-top">
                    <div>
                        <small class="text-muted">
                            Page <strong id="currentPage">1</strong> of <strong id="totalPages">1</strong>
                        </small>
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <li class="page-item" id="prevPageItem">
                                <button class="page-link" id="prevPageBtn" style="color: #2563eb;">
                                    <i class="fas fa-chevron-left me-1"></i>Previous
                                </button>
                            </li>
                            <li class="page-item" id="nextPageItem">
                                <button class="page-link" id="nextPageBtn" style="color: #2563eb;">
                                    Next <i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <!-- Empty State -->
            <div id="emptyState" class="text-center py-5">
                <i class="fas fa-search" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem; display: block;"></i>
                <p class="text-muted">Start typing to search for students</p>
            </div>

            <!-- No Results State -->
            <div id="noResultsState" class="text-center py-5" style="display: none;">
                <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem; display: block;"></i>
                <p class="text-muted">No students found matching your criteria</p>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>

<!-- Styles -->
<style>
    .dashboard-card {
        border: none;
        border-radius: 8px;
    }

    .card-header-custom {
        background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
        color: white;
        border-radius: 8px 8px 0 0;
    }

    .form-select:focus,
    .form-control:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
    }

    .btn-outline-secondary:hover {
        background-color: #e5e7eb;
        border-color: #6b7280;
    }

    .table-hover tbody tr:hover {
        background-color: #f3f4f6;
    }

    .badge {
        padding: 6px 12px;
        font-weight: 500;
    }

    .badge-active {
        background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    }

    .badge-inactive {
        background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
    }

    .badge-graduated {
        background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    }

    .badge-suspended {
        background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
    }

    .badge-dropped {
        background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    }

    .btn-primary {
        background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
        border: none;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    }

    .spinner-border {
        width: 3rem;
        height: 3rem;
    }
</style>

<!-- JavaScript for Real-time Search -->
<script>
    let currentPage = 1;
    let searchTimeout;

    // Elements
    const searchInput = document.getElementById('searchInput');
    const programFilter = document.getElementById('programFilter');
    const departmentFilter = document.getElementById('departmentFilter');
    const yearLevelFilter = document.getElementById('yearLevelFilter');
    const statusFilter = document.getElementById('statusFilter');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const resultsContainer = document.getElementById('resultsContainer');
    const emptyState = document.getElementById('emptyState');
    const noResultsState = document.getElementById('noResultsState');
    const resultCount = document.getElementById('resultCount');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');

    // Event Listeners
    searchInput.addEventListener('input', debounceSearch);
    programFilter.addEventListener('change', () => { currentPage = 1; performSearch(); });
    departmentFilter.addEventListener('change', () => { currentPage = 1; performSearch(); });
    yearLevelFilter.addEventListener('change', () => { currentPage = 1; performSearch(); });
    statusFilter.addEventListener('change', () => { currentPage = 1; performSearch(); });
    clearFiltersBtn.addEventListener('click', clearFilters);
    prevPageBtn.addEventListener('click', previousPage);
    nextPageBtn.addEventListener('click', nextPage);

    // Debounce search to reduce API calls
    function debounceSearch() {
        clearTimeout(searchTimeout);
        currentPage = 1;
        searchTimeout = setTimeout(performSearch, 300);
    }

    // Perform search
    function performSearch() {
        const searchTerm = searchInput.value.trim();
        const program = programFilter.value;
        const department = departmentFilter.value;
        const yearLevel = yearLevelFilter.value;
        const status = statusFilter.value;

        // Show loading spinner
        loadingSpinner.style.display = 'block';
        resultsContainer.style.display = 'none';
        emptyState.style.display = 'none';
        noResultsState.style.display = 'none';

        // Build query string
        const params = new URLSearchParams({
            search: searchTerm,
            program: program,
            department: department,
            year_level: yearLevel,
            status: status,
            page: currentPage,
            per_page: 10
        });

        // Fetch results
        fetch(`api/search_students.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayResults(data.data, data.pagination);
                } else {
                    console.error('Search error:', data.error);
                    noResultsState.style.display = 'block';
                }
                loadingSpinner.style.display = 'none';
            })
            .catch(error => {
                console.error('Error:', error);
                loadingSpinner.style.display = 'none';
                noResultsState.style.display = 'block';
            });
    }

    // Display search results
    function displayResults(students, pagination) {
        const resultsTableBody = document.getElementById('resultsTableBody');
        resultCount.textContent = pagination.total;

        if (students.length === 0) {
            emptyState.style.display = searchInput.value.trim() === '' && 
                                       programFilter.value === '' && 
                                       departmentFilter.value === '' && 
                                       yearLevelFilter.value === '' && 
                                       statusFilter.value === '' ? 'block' : 'none';
            noResultsState.style.display = 'block';
            resultsContainer.style.display = 'none';
            return;
        }

        resultsTableBody.innerHTML = '';
        students.forEach(student => {
            const row = createResultRow(student);
            resultsTableBody.appendChild(row);
        });

        // Update pagination
        document.getElementById('currentPage').textContent = pagination.current_page;
        document.getElementById('totalPages').textContent = pagination.total_pages;
        
        prevPageBtn.disabled = pagination.current_page === 1;
        nextPageBtn.disabled = pagination.current_page === pagination.total_pages;

        document.getElementById('prevPageItem').classList.toggle('disabled', pagination.current_page === 1);
        document.getElementById('nextPageItem').classList.toggle('disabled', pagination.current_page === pagination.total_pages);

        resultsContainer.style.display = 'block';
        emptyState.style.display = 'none';
        noResultsState.style.display = 'none';
    }

    // Create result row
    function createResultRow(student) {
        const row = document.createElement('tr');
        
        const statusBadgeClass = {
            'Active': 'badge-active',
            'Inactive': 'badge-inactive',
            'Graduated': 'badge-graduated',
            'Suspended': 'badge-suspended',
            'Dropped': 'badge-dropped'
        }[student.enrollment_status] || 'badge-active';

        const yearLevelIcon = getYearLevelIcon(student.year_level);
        const programName = student.program_name || 'N/A';

        row.innerHTML = `
            <td>
                <i class="fas fa-user-circle me-2" style="color: #2563eb;"></i>
                <strong>${escapeHtml(student.name)}</strong>
            </td>
            <td>
                <code style="background-color: #f3f4f6; padding: 4px 8px; border-radius: 4px;">
                    ${escapeHtml(student.student_id_number || 'N/A')}
                </code>
            </td>
            <td>
                <i class="fas fa-envelope me-2" style="color: #6b7280;"></i>
                ${escapeHtml(student.email)}
            </td>
            <td>
                <small>${escapeHtml(programName)}</small>
            </td>
            <td>
                <small>
                    ${yearLevelIcon}
                    ${escapeHtml(student.year_level || 'N/A')}
                </small>
            </td>
            <td>
                <span class="badge ${statusBadgeClass}" style="color: white;">
                    ${escapeHtml(student.enrollment_status || 'Active')}
                </span>
            </td>
            <td>
                <a href="user_performance.php?user_id=${student.id}" 
                   class="btn btn-sm btn-primary" 
                   title="View Student Profile">
                    <i class="fas fa-eye me-1"></i>View
                </a>
            </td>
        `;

        return row;
    }

    // Get year level icon
    function getYearLevelIcon(yearLevel) {
        const icons = {
            '1st Year': '<i class="fas fa-1"></i> ',
            '2nd Year': '<i class="fas fa-2"></i> ',
            '3rd Year': '<i class="fas fa-3"></i> ',
            '4th Year': '<i class="fas fa-4"></i> '
        };
        return icons[yearLevel] || '<i class="fas fa-book"></i> ';
    }

    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Clear filters
    function clearFilters() {
        searchInput.value = '';
        programFilter.value = '';
        departmentFilter.value = '';
        yearLevelFilter.value = '';
        statusFilter.value = '';
        currentPage = 1;
        performSearch();
    }

    // Pagination
    function previousPage() {
        if (currentPage > 1) {
            currentPage--;
            performSearch();
            window.scrollTo(0, 0);
        }
    }

    function nextPage() {
        const totalPages = parseInt(document.getElementById('totalPages').textContent);
        if (currentPage < totalPages) {
            currentPage++;
            performSearch();
            window.scrollTo(0, 0);
        }
    }
</script>
