<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['instructor', 'student'], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
if (empty($_SESSION['assignment_review_csrf'])) {
    $_SESSION['assignment_review_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'instructor') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only the assigned instructor can save a review.']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !is_string($payload['csrf'] ?? null) || !hash_equals($_SESSION['assignment_review_csrf'], $payload['csrf'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Your session expired. Refresh and try again.']);
        exit;
    }

    $submissionId = filter_var($payload['submission_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $payload['status'] ?? 'under_review';
    $comments = trim((string)($payload['comments'] ?? ''));
    $allowResubmission = !empty($payload['allow_resubmission']) ? 1 : 0;
    $maximumScore = filter_var($payload['maximum_score'] ?? null, FILTER_VALIDATE_FLOAT);
    $scoreInput = trim((string)($payload['score'] ?? ''));
    $score = $scoreInput === '' ? null : filter_var($scoreInput, FILTER_VALIDATE_FLOAT);
    $annotations = $payload['annotations'] ?? [];
    $validStatuses = ['under_review', 'checked', 'returned_for_revision'];
    $validTypes = ['pen', 'highlighter', 'comment', 'arrow', 'circle', 'rectangle', 'line'];

    if (!$submissionId || !in_array($status, $validStatuses, true) || !is_array($annotations) || count($annotations) > 500) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Review data is invalid.']);
        exit;
    }

    $submissionStmt = $pdo->prepare(" 
        SELECT sub.id, sub.assignment_id, sub.student_id, a.points
        FROM submissions sub
        INNER JOIN assignments a ON a.id = sub.assignment_id
        INNER JOIN subjects s ON s.id = a.subject_id
        WHERE sub.id = ? AND s.instructor_id = ?
    ");
    $submissionStmt->execute([$submissionId, $userId]);
    $submission = $submissionStmt->fetch();
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Submission not found or not assigned to you.']);
        exit;
    }

    $maximumScore = $maximumScore === false || $maximumScore === null ? (float)$submission['points'] : $maximumScore;
    if ($maximumScore <= 0 || $maximumScore > 100000 || ($scoreInput !== '' && ($score === false || $score < 0 || $score > $maximumScore))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Enter a score within the maximum possible score.']);
        exit;
    }
    if (strlen($comments) > 20000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Feedback must be 20,000 characters or fewer.']);
        exit;
    }

    $validatedAnnotations = [];
    foreach ($annotations as $annotation) {
        if (!is_array($annotation) || !in_array($annotation['type'] ?? '', $validTypes, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'An annotation is invalid.']);
            exit;
        }
        $pageNumber = filter_var($annotation['page'] ?? 1, FILTER_VALIDATE_INT);
        $data = $annotation['data'] ?? null;
        $content = trim((string)($annotation['content'] ?? ''));
        if (!$pageNumber || $pageNumber > 500 || !is_array($data) || strlen($content) > 4000) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'An annotation contains invalid coordinates or text.']);
            exit;
        }
        $encodedData = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($encodedData === false || strlen($encodedData) > 100000) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'An annotation is too large to save.']);
            exit;
        }
        $validatedAnnotations[] = [
            'page' => $pageNumber,
            'type' => $annotation['type'],
            'content' => $content,
            'data' => $encodedData
        ];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM assignment_annotations WHERE submission_id = ?')->execute([$submissionId]);
        $insertAnnotation = $pdo->prepare('INSERT INTO assignment_annotations (submission_id, instructor_id, page_number, annotation_type, annotation_content, annotation_data) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($validatedAnnotations as $annotation) {
            $insertAnnotation->execute([$submissionId, $userId, $annotation['page'], $annotation['type'], $annotation['content'] ?: null, $annotation['data']]);
        }

        $feedbackStmt = $pdo->prepare(" 
            INSERT INTO assignment_feedback
                (submission_id, instructor_id, comments, score, maximum_score, status, allow_resubmission, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'under_review', NULL, NOW()))
            ON DUPLICATE KEY UPDATE
                instructor_id = VALUES(instructor_id),
                comments = VALUES(comments),
                score = VALUES(score),
                maximum_score = VALUES(maximum_score),
                status = VALUES(status),
                allow_resubmission = VALUES(allow_resubmission),
                reviewed_at = IF(VALUES(status) = 'under_review', reviewed_at, NOW())
        ");
        $feedbackStmt->execute([$submissionId, $userId, $comments ?: null, $score, $maximumScore, $status, $allowResubmission, $status]);

        $legacyGrade = $score === null ? null : number_format((float)$score, 2, '.', '');
        $pdo->prepare('UPDATE submissions SET grade = ?, feedback = ? WHERE id = ?')
            ->execute([$legacyGrade, $comments ?: null, $submissionId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'saved_at' => date('c')]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Assignment review save failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Review could not be saved. Please try again.']);
    }
    exit;
}

$submissionId = (int)($_GET['submission_id'] ?? 0);
$pageTitle = 'Assignment Review - LearnAlytics';
$statusFilter = $_GET['status'] ?? '';
$courseFilter = (int)($_GET['course'] ?? 0);
$searchTerm = trim($_GET['search'] ?? '');
$errorMessage = '';
$courses = [];
$submissions = [];
$annotations = [];
$fileName = '';
$fileExtension = '';

if ($submissionId > 0) {
    $submissionStmt = $pdo->prepare(" 
        SELECT sub.*, a.title AS assignment_title, a.points AS assignment_points,
               s.id AS subject_id, s.title AS subject_title, s.instructor_id,
               u.name AS student_name,
               COALESCE(sp.student_id_number, u.student_id_number, CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_id_number,
               f.comments AS review_comments, f.score AS review_score,
               f.maximum_score, f.status AS saved_status, f.allow_resubmission,
               f.reviewed_at, reviewer.name AS reviewer_name
        FROM submissions sub
        INNER JOIN assignments a ON a.id = sub.assignment_id
        INNER JOIN subjects s ON s.id = a.subject_id
        INNER JOIN users u ON u.id = sub.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
        LEFT JOIN users reviewer ON reviewer.id = f.instructor_id
        WHERE sub.id = ?
    ");
    $submissionStmt->execute([$submissionId]);
    $submission = $submissionStmt->fetch();

    $authorized = $submission && (($role === 'instructor' && (int)$submission['instructor_id'] === $userId)
        || ($role === 'student' && (int)$submission['student_id'] === $userId));
    if (!$authorized) {
        http_response_code(403);
        exit('You are not allowed to view this assignment review.');
    }

    $annotationsStmt = $pdo->prepare(" 
         SELECT aa.page_number, aa.annotation_type, aa.annotation_content, aa.annotation_data,
             aa.created_at, author.name AS instructor_name
         FROM assignment_annotations aa
         INNER JOIN users author ON author.id = aa.instructor_id
        WHERE submission_id = ?
         ORDER BY aa.annotation_id ASC
    ");
    $annotationsStmt->execute([$submissionId]);
    $annotations = array_map(static function ($annotation) {
        return [
            'page' => (int)$annotation['page_number'],
            'type' => $annotation['annotation_type'],
            'content' => $annotation['annotation_content'] ?? '',
            'author' => $annotation['instructor_name'],
            'created_at' => $annotation['created_at'],
            'data' => json_decode($annotation['annotation_data'], true) ?: []
        ];
    }, $annotationsStmt->fetchAll());

    $fileName = $submission['file_path'] ? basename($submission['file_path']) : 'Text submission';
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $initialStatus = $submission['saved_status'] ?: ($submission['grade'] !== null ? 'checked' : 'submitted');
    $savedScore = $submission['review_score'] ?? $submission['grade'];
    $savedMaximum = $submission['maximum_score'] ?? $submission['assignment_points'];
    $savedComments = $submission['review_comments'] ?? $submission['feedback'] ?? '';
    $pageTitle = 'Review: ' . $submission['assignment_title'] . ' - LearnAlytics';
} elseif ($role === 'instructor') {
    $coursesStmt = $pdo->prepare('SELECT id, title FROM subjects WHERE instructor_id = ? ORDER BY title');
    $coursesStmt->execute([$userId]);
    $courses = $coursesStmt->fetchAll();

    $where = ['s.instructor_id = ?'];
    $params = [$userId];
    if ($courseFilter > 0) {
        $where[] = 's.id = ?';
        $params[] = $courseFilter;
    }
    if ($searchTerm !== '') {
        $where[] = '(u.name LIKE ? OR a.title LIKE ? OR s.title LIKE ? OR sp.student_id_number LIKE ?)';
        $wildcard = '%' . $searchTerm . '%';
        array_push($params, $wildcard, $wildcard, $wildcard, $wildcard);
    }
    if (in_array($statusFilter, ['submitted', 'under_review', 'checked', 'returned_for_revision'], true)) {
        $where[] = "COALESCE(f.status, CASE WHEN sub.grade IS NULL THEN 'submitted' ELSE 'checked' END) = ?";
        $params[] = $statusFilter;
    }

    $listStmt = $pdo->prepare(" 
        SELECT sub.id, sub.file_path, sub.submitted_at, sub.grade,
               a.id AS assignment_id, a.title AS assignment_title,
               s.title AS subject_title,
               u.name AS student_name,
               COALESCE(sp.student_id_number, u.student_id_number, CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_id_number,
               COALESCE(f.status, CASE WHEN sub.grade IS NULL THEN 'submitted' ELSE 'checked' END) AS review_status
        FROM submissions sub
        INNER JOIN assignments a ON a.id = sub.assignment_id
        INNER JOIN subjects s ON s.id = a.subject_id
        INNER JOIN users u ON u.id = sub.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sub.submitted_at DESC
    ");
    $listStmt->execute($params);
    $submissions = $listStmt->fetchAll();
} else {
    $listStmt = $pdo->prepare(" 
        SELECT sub.id, sub.submitted_at, sub.grade,
               a.id AS assignment_id, a.title AS assignment_title,
               s.title AS subject_title,
               f.score AS review_score, f.maximum_score, f.status AS review_status
        FROM submissions sub
        INNER JOIN assignments a ON a.id = sub.assignment_id
        INNER JOIN subjects s ON s.id = a.subject_id
        LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
        WHERE sub.student_id = ? AND (f.feedback_id IS NOT NULL OR sub.grade IS NOT NULL)
        ORDER BY sub.submitted_at DESC
    ");
    $listStmt->execute([$userId]);
    $submissions = $listStmt->fetchAll();
}

$extraCss = ['assets/css/assignment-review.css'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 assignment-review-page">
    <?php if ($submissionId === 0): ?>
        <div class="review-page-heading">
            <div>
                <h1 class="h2 mb-1"><?php echo $role === 'instructor' ? 'Assignment Reviews' : 'Reviewed Assignments'; ?></h1>
                <p class="text-muted mb-0"><?php echo $role === 'instructor' ? 'Open a student submission to annotate and grade it.' : 'View instructor annotations, feedback, and scores for your submissions.'; ?></p>
            </div>
        </div>
        <?php if ($role === 'instructor'): ?>
        <form method="get" class="review-filters">
            <input class="form-control" type="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search student, assignment, or subject">
            <select class="form-select" name="course" aria-label="Filter by course">
                <option value="0">All subjects</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?php echo (int)$course['id']; ?>" <?php echo $courseFilter === (int)$course['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($course['title']); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="status" aria-label="Filter by review status">
                <option value="">All statuses</option>
                <?php foreach (['submitted' => 'Submitted', 'under_review' => 'Under Review', 'checked' => 'Checked', 'returned_for_revision' => 'Returned for Revision'] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        </form>
        <?php endif; ?>
        <div class="review-list-wrap">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <?php if ($role === 'instructor'): ?>
                    <thead><tr><th>Student</th><th>Student ID</th><th>Subject</th><th>Assignment</th><th>File</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
                    <?php else: ?>
                    <thead><tr><th>Subject</th><th>Assignment</th><th>Submitted</th><th>Score</th><th>Status</th><th></th></tr></thead>
                    <?php endif; ?>
                    <tbody>
                        <?php foreach ($submissions as $item): ?>
                            <?php $statusLabel = ucwords(str_replace('_', ' ', $item['review_status'] ?? 'checked')); ?>
                            <?php if ($role === 'instructor'): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($item['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['student_id_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['subject_title']); ?></td>
                                <td><?php echo htmlspecialchars($item['assignment_title']); ?></td>
                                <td><?php echo htmlspecialchars($item['file_path'] ? basename($item['file_path']) : 'Text submission'); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($item['submitted_at']))); ?></td>
                                <td><span class="review-status status-<?php echo htmlspecialchars($item['review_status']); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="review.php?submission_id=<?php echo (int)$item['id']; ?>"><i class="fas fa-pen-to-square me-1"></i>Review</a></td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['subject_title']); ?></td>
                                <td><?php echo htmlspecialchars($item['assignment_title']); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($item['submitted_at']))); ?></td>
                                <td><?php echo $item['review_score'] !== null ? htmlspecialchars($item['review_score'] . ' / ' . $item['maximum_score']) : ($item['grade'] !== null ? htmlspecialchars($item['grade'] . ' / 100') : 'Not graded'); ?></td>
                                <td><span class="review-status status-<?php echo htmlspecialchars($item['review_status'] ?? 'checked'); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="review.php?submission_id=<?php echo (int)$item['id']; ?>"><i class="fas fa-eye me-1"></i>View Review</a></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($submissions)): ?>
                            <tr><td colspan="<?php echo $role === 'instructor' ? '8' : '6'; ?>" class="text-center text-muted py-5"><?php echo $role === 'instructor' ? 'No submissions match these filters.' : 'No reviewed assignments yet.'; ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="review-page-heading review-detail-heading">
            <div>
                <?php if ($role === 'instructor'): ?><a href="view.php?id=<?php echo (int)$submission['assignment_id']; ?>&amp;tab=submissions" class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>All submissions</a><?php endif; ?>
                <h1 class="h3 mb-1"><?php echo htmlspecialchars($submission['assignment_title']); ?></h1>
                <div class="text-muted"><?php echo htmlspecialchars($submission['subject_title']); ?> · <?php echo htmlspecialchars($submission['student_name']); ?> · ID <?php echo htmlspecialchars($submission['student_id_number']); ?></div>
            </div>
            <span class="review-status status-<?php echo htmlspecialchars($initialStatus); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $initialStatus))); ?></span>
        </div>

        <div class="review-workspace" id="assignmentReview" data-role="<?php echo htmlspecialchars($role); ?>" data-file-type="<?php echo htmlspecialchars($fileExtension); ?>" data-file-url="review_file.php?submission_id=<?php echo $submissionId; ?>" data-save-url="review.php" data-csrf="<?php echo htmlspecialchars($_SESSION['assignment_review_csrf']); ?>" data-submission-id="<?php echo $submissionId; ?>">
            <section class="review-document-panel">
                <div class="review-document-toolbar">
                    <?php if ($role === 'instructor'): ?>
                        <div class="tool-group" role="toolbar" aria-label="Annotation tools">
                            <button type="button" class="review-tool is-active" data-tool="pen" title="Pen"><i class="fas fa-pen"></i></button>
                            <button type="button" class="review-tool" data-tool="highlighter" title="Highlighter"><i class="fas fa-highlighter"></i></button>
                            <button type="button" class="review-tool" data-tool="comment" title="Text comment"><i class="fas fa-comment-medical"></i></button>
                            <button type="button" class="review-tool" data-tool="eraser" title="Eraser"><i class="fas fa-eraser"></i></button>
                            <select class="form-select form-select-sm review-shape-select" id="reviewShape" title="Shape tool">
                                <option value="arrow">Arrow</option><option value="line">Line</option><option value="rectangle">Rectangle</option><option value="circle">Circle</option>
                            </select>
                        </div>
                        <input type="color" id="reviewColor" class="review-color" value="#e11d48" aria-label="Annotation color" title="Annotation color">
                        <div class="tool-group">
                            <button type="button" class="review-tool" id="reviewUndo" title="Undo"><i class="fas fa-rotate-left"></i></button>
                            <button type="button" class="review-tool" id="reviewRedo" title="Redo"><i class="fas fa-rotate-right"></i></button>
                        </div>
                    <?php endif; ?>
                    <div class="tool-group ms-auto">
                        <button type="button" class="review-tool" id="reviewZoomOut" title="Zoom out"><i class="fas fa-minus"></i></button>
                        <span class="review-zoom-label" id="reviewZoomLabel">100%</span>
                        <button type="button" class="review-tool" id="reviewZoomIn" title="Zoom in"><i class="fas fa-plus"></i></button>
                    </div>
                    <div class="tool-group review-page-controls">
                        <button type="button" class="review-tool" id="reviewPrevPage" title="Previous page"><i class="fas fa-chevron-left"></i></button>
                        <span class="review-zoom-label"><span id="reviewPageNumber">1</span> / <span id="reviewPageCount">1</span></span>
                        <button type="button" class="review-tool" id="reviewNextPage" title="Next page"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="review-document-viewport" id="reviewViewport">
                    <div class="review-document-stage" id="reviewStage">
                        <?php if ($submission['file_path']): ?>
                            <canvas id="reviewDocumentCanvas" class="review-base-canvas"></canvas>
                            <img id="reviewImage" alt="Submitted assignment" hidden>
                            <div id="reviewOffice" class="review-office-pages" hidden></div>
                        <?php else: ?>
                            <div class="review-text-document"><?php echo nl2br(htmlspecialchars($submission['content'] ?? 'No text or file was submitted.')); ?></div>
                        <?php endif; ?>
                        <canvas id="reviewAnnotationCanvas" class="review-annotation-canvas" aria-label="Assignment annotations"></canvas>
                    </div>
                    <div class="review-load-error" id="reviewLoadError" hidden></div>
                </div>
            </section>

            <aside class="review-feedback-panel">
                <div class="review-feedback-heading"><i class="fas fa-message me-2"></i><?php echo $role === 'instructor' ? 'Review and feedback' : 'Instructor feedback'; ?></div>
                <?php if ($role === 'instructor'): ?>
                    <label class="form-label" for="reviewComments">General feedback</label>
                    <textarea id="reviewComments" class="form-control mb-3" rows="6" maxlength="20000" placeholder="Write feedback for the student..."><?php echo htmlspecialchars($savedComments); ?></textarea>
                    <div class="row g-2 mb-3">
                        <div class="col-7"><label class="form-label" for="reviewScore">Score</label><input id="reviewScore" class="form-control" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars((string)$savedScore); ?>"></div>
                        <div class="col-5"><label class="form-label" for="reviewMaximum">Maximum</label><input id="reviewMaximum" class="form-control" type="number" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)$savedMaximum); ?>"></div>
                    </div>
                    <label class="form-label" for="reviewStatus">Status</label>
                    <select id="reviewStatus" class="form-select mb-3">
                        <option value="under_review" <?php echo $initialStatus === 'under_review' || $initialStatus === 'submitted' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="checked" <?php echo $initialStatus === 'checked' ? 'selected' : ''; ?>>Checked</option>
                        <option value="returned_for_revision" <?php echo $initialStatus === 'returned_for_revision' ? 'selected' : ''; ?>>Returned for Revision</option>
                    </select>
                    <label class="form-check mb-3"><input class="form-check-input" type="checkbox" id="reviewAllowResubmission" <?php echo !empty($submission['allow_resubmission']) ? 'checked' : ''; ?>><span class="form-check-label">Allow one resubmission</span></label>
                    <div class="review-save-state mb-2" id="reviewSaveState" role="status">Changes save automatically.</div>
                    <button type="button" class="btn btn-primary w-100" id="reviewSaveButton"><i class="fas fa-save me-2"></i>Save Review</button>
                <?php else: ?>
                    <div class="review-score-display">
                        <span>Score</span>
                        <strong><?php echo $savedScore !== null ? htmlspecialchars(rtrim(rtrim(number_format((float)$savedScore, 2, '.', ''), '0'), '.')) . ' / ' . htmlspecialchars(rtrim(rtrim(number_format((float)$savedMaximum, 2, '.', ''), '0'), '.')) : 'Not graded'; ?></strong>
                    </div>
                    <div class="review-student-feedback">
                        <h2>Feedback</h2>
                        <p><?php echo $savedComments !== '' ? nl2br(htmlspecialchars($savedComments)) : 'No written feedback yet.'; ?></p>
                    </div>
                    <?php if (!empty($submission['reviewer_name']) && !empty($submission['reviewed_at'])): ?>
                        <div class="reviewed-by">Reviewed by <?php echo htmlspecialchars($submission['reviewer_name']); ?><br><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($submission['reviewed_at']))); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($submission['saved_status']) && $submission['saved_status'] === 'returned_for_revision' && !empty($submission['allow_resubmission'])): ?>
                        <div class="alert alert-info mt-3 mb-0">Your instructor has requested a revision. Open the assignment page to submit an updated version.</div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="review-annotation-notes mt-4">
                    <h2>Document comments</h2>
                    <div id="reviewCommentList" class="review-comment-list"></div>
                </div>
            </aside>
        </div>
        <script>
        window.assignmentReviewInitial = <?php echo json_encode([
            'annotations' => $annotations,
            'editable' => $role === 'instructor',
            'extension' => $fileExtension,
            'fileName' => $fileName,
            'content' => $submission['content'] ?? ''
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        </script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
        <script src="../assets/js/assignment-review.js" defer></script>
    <?php endif; ?>
</main>
<?php include '../includes/footer.php'; ?>