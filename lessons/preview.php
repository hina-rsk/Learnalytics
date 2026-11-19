<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$attachment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attachment_id <= 0) {
    http_response_code(400);
    echo 'Invalid attachment ID.';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM lesson_attachments WHERE id = ?');
$stmt->execute([$attachment_id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$file_path = __DIR__ . '/../' . $attachment['file_path'];
if (!is_file($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$extension = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
$previewable = [
    'pdf' => true,
    'png' => true,
    'jpg' => true,
    'jpeg' => true,
    'gif' => true,
    'txt' => true,
    'csv' => true,
    'mp4' => true,
];

$canPreview = isset($previewable[$extension]);
$mime_types = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'mp4' => 'video/mp4',
];
$mime_type = $mime_types[$extension] ?? 'application/octet-stream';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="../subjects/index.php" style="color: #2563eb;">Subjects</a></li>
            <li class="breadcrumb-item active" style="color: #1e3a8a;">Preview Attachment</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Preview: <?php echo htmlspecialchars($attachment['file_name']); ?></h5>
                </div>
                <div class="card-body">
                    <?php if ($canPreview): ?>
                        <?php if ($extension === 'mp4'): ?>
                            <video controls style="width: 100%; max-height: 600px;">
                                <source src="attachment.php?id=<?php echo $attachment['id']; ?>" type="<?php echo $mime_type; ?>">
                                Your browser does not support video playback.
                            </video>
                        <?php elseif (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'])): ?>
                            <div class="text-center">
                                <img src="attachment.php?id=<?php echo $attachment['id']; ?>" alt="<?php echo htmlspecialchars($attachment['file_name']); ?>" class="img-fluid rounded">
                            </div>
                        <?php else: ?>
                            <div class="ratio ratio-16x9 mb-3">
                                <iframe src="attachment.php?id=<?php echo $attachment['id']; ?>" style="border: none;"></iframe>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                            <i class="fas fa-info-circle me-2"></i>This file type cannot be previewed directly in the browser.
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 d-flex flex-column flex-sm-row gap-2">
                        <button type="button" onclick="openLmsFileViewer('../<?php echo htmlspecialchars($attachment['file_path']); ?>', '<?php echo addslashes($attachment['file_name']); ?>', '<?php echo htmlspecialchars($extension); ?>'); return false;" class="btn btn-primary">
                            <i class="fas fa-eye me-1"></i> View in File Viewer Panel
                        </button>
                        <?php if ($canPreview): ?>
                            <a href="attachment.php?id=<?php echo $attachment['id']; ?>" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-external-link-alt me-1"></i> Direct Link
                            </a>
                        <?php endif; ?>
                        <a href="../<?php echo htmlspecialchars($attachment['file_path']); ?>" class="btn btn-outline-secondary" download>
                            <i class="fas fa-download me-1"></i> Download
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
