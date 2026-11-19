<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';
$subjectId = (int)($_GET['id'] ?? 0);

if ($subjectId <= 0) {
    header('Location: my_classes.php');
    exit;
}

if ($role === 'instructor') {
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND instructor_id = ?');
    $stmt->execute([$subjectId, $userId]);
    $subject = $stmt->fetch();
} elseif ($role === 'student') {
    $stmt = $pdo->prepare('SELECT s.id FROM subjects s JOIN enrollments e ON s.id = e.subject_id WHERE s.id = ? AND e.student_id = ?');
    $stmt->execute([$subjectId, $userId]);
    $subject = $stmt->fetch();
} else {
    $subject = false;
}

if (!$subject) {
    header('Location: my_classes.php');
    exit;
}

$stmt = $pdo->prepare('SELECT cs.meeting_link, s.title FROM class_schedules cs JOIN subjects s ON cs.subject_id = s.id WHERE cs.subject_id = ? ORDER BY cs.id DESC LIMIT 1');
$stmt->execute([$subjectId]);
$schedule = $stmt->fetch();

$backUrl = ($role === 'instructor' ? 'my_class_details.php?id=' : 'student/subject_details.php?id=') . $subjectId;
if (!$schedule || empty($schedule['meeting_link'])) {
    header('Location: ' . $backUrl);
    exit;
}

$meetingLink = trim($schedule['meeting_link']);
if (filter_var($meetingLink, FILTER_VALIDATE_URL)) {
    $roomPath = parse_url($meetingLink, PHP_URL_PATH);
    $roomName = is_string($roomPath) ? basename(rtrim($roomPath, '/')) : '';
} else {
    $roomName = basename(str_replace('\\', '/', $meetingLink));
}

$roomIsValid = (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $roomName);
$pageTitle = 'Virtual Classroom - LearnAlytics';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    .virtual-classroom {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 90px);
        min-height: 520px;
        background: #0f172a;
        color: #fff;
    }
    .virtual-classroom-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.5rem;
        background: #1f2937;
        border-bottom: 1px solid rgba(255,255,255,.1);
    }
    .virtual-classroom-header .text-muted {
        color: #cbd5e1 !important;
    }
    #meeting-container {
        flex: 1;
        min-height: 0;
    }
    #meeting-container iframe {
        border: 0;
    }
    @media (max-width: 576px) {
        .virtual-classroom {
            height: calc(100vh - 80px);
        }
        .virtual-classroom-header {
            align-items: flex-start;
            padding: 1rem;
        }
    }
</style>
<main class="col-md-9 ms-sm-auto col-lg-10 px-0 virtual-classroom">
    <header class="virtual-classroom-header">
        <div>
            <h1 class="h4 mb-1">Virtual Classroom</h1>
            <p class="mb-0 text-muted"><?php echo htmlspecialchars($schedule['title']); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Class
        </a>
    </header>
    <?php if ($roomIsValid): ?>
        <div id="meeting-container" aria-label="Video meeting room"></div>
        <div id="meeting-error" class="alert alert-danger m-3" role="alert" hidden>
            The video room could not be loaded. Check your connection and try again.
        </div>
    <?php else: ?>
        <div class="alert alert-danger m-3" role="alert">
            This class has an invalid room identifier. Ask the instructor to create a new room.
        </div>
    <?php endif; ?>
</main>
<?php if ($roomIsValid): ?>
<script src="https://meet.jit.si/external_api.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const errorBox = document.getElementById('meeting-error');
    if (typeof window.JitsiMeetExternalAPI !== 'function') {
        errorBox.hidden = false;
        return;
    }

    try {
        const api = new window.JitsiMeetExternalAPI('meet.jit.si', {
            roomName: <?php echo json_encode($roomName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            parentNode: document.getElementById('meeting-container'),
            width: '100%',
            height: '100%',
            userInfo: {
                displayName: <?php echo json_encode($_SESSION['user_name'] ?? 'LearnAlytics user', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            },
            configOverwrite: {
                prejoinPageEnabled: true,
                startWithAudioMuted: true,
                startWithVideoMuted: true,
                disableDeepLinking: true
            }
        });

        api.addEventListener('readyToClose', function () {
            window.location.href = <?php echo json_encode($backUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        });
    } catch (error) {
        console.error('Virtual classroom failed to initialize:', error);
        errorBox.hidden = false;
    }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
