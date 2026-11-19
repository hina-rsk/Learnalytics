<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$success_msg = '';
$error_msg = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrfToken = $_SESSION['messages_csrf'] ?? '';
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['messages_csrf'] = $csrfToken;
}
$chat_with = isset($_GET['chat_with']) && is_numeric($_GET['chat_with']) ? (int)$_GET['chat_with'] : null;
$group_chat = isset($_GET['group_chat']) && is_numeric($_GET['group_chat']) ? (int)$_GET['group_chat'] : null;
$join_group = isset($_POST['join_group']) && is_numeric($_POST['join_group']) ? (int)$_POST['join_group'] : null;
$message_text = '';
$selected_receiver_id = $chat_with;
$activeContact = null;
$activeGroup = null;
$conversation = [];
$courseGroups = [];
$csrfIsValid = isset($_POST['csrf_token'])
    && is_string($_POST['csrf_token'])
    && hash_equals($csrfToken, $_POST['csrf_token']);

// Build available course group chats for the current user
if ($user_role === 'admin') {
    $stmt = $pdo->query("SELECT id, title, description FROM subjects ORDER BY title ASC");
    $accessibleSubjects = $stmt->fetchAll();
} elseif ($user_role === 'instructor') {
    $stmt = $pdo->prepare("SELECT id, title, description FROM subjects WHERE instructor_id = ? ORDER BY title ASC");
    $stmt->execute([$user_id]);
    $accessibleSubjects = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT c.id, c.title, c.description FROM subjects c JOIN enrollments e ON c.id = e.subject_id WHERE e.student_id = ? ORDER BY c.title ASC");
    $stmt->execute([$user_id]);
    $accessibleSubjects = $stmt->fetchAll();
}

foreach ($accessibleSubjects as $subject) {
    $stmt = $pdo->prepare("SELECT id FROM subject_group_chats WHERE subject_id = ?");
    $stmt->execute([$subject['id']]);
    $group = $stmt->fetch();
    if (!$group) {
        $stmt = $pdo->prepare("INSERT INTO subject_group_chats (subject_id, name) VALUES (?, ?)");
        $stmt->execute([$subject['id'], $subject['title'] . ' Group Chat']);
        $groupId = $pdo->lastInsertId();
    } else {
        $groupId = $group['id'];
    }

    if ($user_role === 'instructor') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $user_id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO subject_group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$groupId, $user_id]);
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $user_id]);
    $is_member = $stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_group_members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $member_count = $stmt->fetchColumn();

    $courseGroups[] = [
        'group_id' => $groupId,
        'subject_id' => $subject['id'],
        'subject_title' => $subject['title'],
        'group_name' => $subject['title'] . ' Group Chat',
        'is_member' => $is_member,
        'member_count' => $member_count
    ];
}

if ($isPost && isset($_POST['join_group']) && !$csrfIsValid) {
    $error_msg = "Your session has expired. Refresh the page and try again.";
} elseif ($isPost && isset($_POST['join_group']) && !$join_group) {
    $error_msg = "Invalid group chat request.";
} elseif ($isPost && isset($_POST['join_group'])) {
    $stmt = $pdo->prepare(
        "SELECT c.id AS subject_id, c.title, c.instructor_id,
            EXISTS(SELECT 1 FROM enrollments e WHERE e.subject_id = c.id AND e.student_id = ?) AS is_enrolled
         FROM subject_group_chats cg
         JOIN subjects c ON c.id = cg.subject_id
         WHERE cg.id = ?"
    );
    $stmt->execute([$user_id, $join_group]);
    $joinGroupData = $stmt->fetch();

    if ($joinGroupData && ($user_role === 'admin' || $joinGroupData['instructor_id'] == $user_id || $joinGroupData['is_enrolled'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subject_group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$join_group, $user_id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO subject_group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$join_group, $user_id]);
        }
        header("Location: index.php?group_chat=" . $join_group);
        exit;
    }
    $error_msg = "You are not allowed to join that group chat.";
}

// Handle sending a message
if ($isPost && isset($_POST['send_message']) && !$csrfIsValid) {
    $error_msg = "Your session has expired. Refresh the page and try again.";
}
if ($isPost && isset($_POST['send_message']) && $csrfIsValid) {
    $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
    $selected_receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
    $message_text = trim($_POST['message'] ?? '');
    $chat_with = $group_id ? null : $selected_receiver_id;
    $group_chat = $group_id ? $group_id : $group_chat;

    if ($message_text === '') {
        $error_msg = "Please enter a message before sending.";
    } elseif ($group_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT c.instructor_id,
                EXISTS(SELECT 1 FROM subject_group_members gm WHERE gm.group_id = ? AND gm.user_id = ?) AS is_member
             FROM subject_group_chats cg
             JOIN subjects c ON c.id = cg.subject_id
             WHERE cg.id = ?"
        );
        $stmt->execute([$group_id, $user_id, $group_id]);
        $groupInfo = $stmt->fetch();

        if (!$groupInfo) {
            $error_msg = "Subject group chat not found.";
        } elseif (!$groupInfo['is_member'] && $user_role !== 'admin' && $groupInfo['instructor_id'] != $user_id) {
            $error_msg = "You must join this group before sending messages.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO subject_group_messages (group_id, sender_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$group_id, $user_id, $message_text]);

            $senderName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
            $notificationMsg = $senderName . " posted a message in the group chat.";
            $stmt = $pdo->prepare("SELECT user_id FROM subject_group_members WHERE group_id = ? AND user_id != ?");
            $stmt->execute([$group_id, $user_id]);
            foreach ($stmt->fetchAll() as $memberRow) {
                $stmtNotify = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmtNotify->execute([$memberRow['user_id'], $notificationMsg]);
            }

            $success_msg = "Message sent successfully to the group chat!";
            $message_text = '';
        }
    } else {
        if (!$selected_receiver_id || $selected_receiver_id === $user_id) {
            $error_msg = "Please select a valid recipient.";
        } else {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$selected_receiver_id]);
            $receiver = $stmt->fetch();

            if ($receiver) {
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $selected_receiver_id, $message_text]);
                
                $senderName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
                $notificationMsg = $senderName . " sent you a message";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmt->execute([$selected_receiver_id, $notificationMsg]);
                
                $success_msg = "Message sent successfully!";
                $message_text = '';
            } else {
                $error_msg = "Selected user was not found.";
            }
        }
    }
}

if ($chat_with) {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND id != ?");
    $stmt->execute([$chat_with, $user_id]);
    $activeContact = $stmt->fetch();

    if (!$activeContact) {
        $chat_with = null;
    } else {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt->execute([$chat_with, $user_id]);

        // Load direct message conversation
        $stmt = $pdo->prepare(
            "SELECT m.*, u.name as sender_name FROM messages m
             JOIN users u ON m.sender_id = u.id
             WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
             ORDER BY m.sent_at ASC"
        );
        $stmt->execute([$user_id, $chat_with, $chat_with, $user_id]);
        $conversation = $stmt->fetchAll();
    }
}

if ($group_chat) {
    $stmt = $pdo->prepare(
        "SELECT cg.id AS group_id, cg.name AS group_name, c.id AS subject_id, c.title AS subject_title, c.instructor_id,
            EXISTS(SELECT 1 FROM subject_group_members gm WHERE gm.group_id = cg.id AND gm.user_id = ?) AS is_member
         FROM subject_group_chats cg
         JOIN subjects c ON c.id = cg.subject_id
         WHERE cg.id = ?"
    );
    $stmt->execute([$user_id, $group_chat]);
    $activeGroup = $stmt->fetch();

    if ($activeGroup) {
        $allowed = $activeGroup['is_member'] || $user_role === 'admin' || $activeGroup['instructor_id'] == $user_id;
        if (!$allowed) {
            $error_msg = "You must join this group before opening its chat.";
            $activeGroup = null;
        } else {
            $stmt = $pdo->prepare("SELECT m.*, u.name as sender_name FROM subject_group_messages m JOIN users u ON m.sender_id = u.id WHERE m.group_id = ? ORDER BY m.sent_at ASC");
            $stmt->execute([$group_chat]);
            $conversation = $stmt->fetchAll();
        }
    } else {
        $error_msg = "Subject group chat not found.";
    }
}

$stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id != ? ORDER BY name ASC");
$stmt->execute([$user_id]);
$allUsers = $stmt->fetchAll();
$preselectedRecipient = null;
foreach ($allUsers as $user) {
    if ($selected_receiver_id == $user['id']) {
        $preselectedRecipient = $user;
        break;
    }
}

$stmt = $pdo->prepare(
    "SELECT t.contact_id, u.name, u.email, lm.message AS last_message, lm.sent_at
    FROM (
        SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS contact_id, MAX(sent_at) AS last_at
        FROM messages
        WHERE sender_id = ? OR receiver_id = ?
        GROUP BY contact_id
    ) t
    JOIN users u ON u.id = t.contact_id
    JOIN messages lm ON ((lm.sender_id = ? AND lm.receiver_id = t.contact_id) OR (lm.receiver_id = ? AND lm.sender_id = t.contact_id)) AND lm.sent_at = t.last_at
    ORDER BY t.last_at DESC"
);
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
$contacts = $stmt->fetchAll();

$unreadCounts = [];
$stmt = $pdo->prepare("SELECT sender_id, COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
$stmt->execute([$user_id]);
foreach ($stmt->fetchAll() as $countRow) {
    $unreadCounts[$countRow['sender_id']] = $countRow['unread_count'];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <style>
        .chat-wrapper { display: flex; gap: 1rem; }
        .chat-sidebar { width: 320px; min-width: 280px; }
        .contact-item { cursor: pointer; transition: background .2s, border-color .2s; border-left: 4px solid transparent; }
        .contact-item.active { background: #eff6ff; border-color: #2563eb; }
        .contact-item:hover { background: #f8fafc; }
        .chat-panel { display: flex; flex-direction: column; min-height: 70vh; }
        .chat-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; }
        .chat-body { flex: 1; overflow-y: auto; padding: 1.25rem; background: #f8fafc; }
        .chat-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; background: #fff; }
        .chat-bubble { display: inline-block; padding: 0.9rem 1rem; border-radius: 18px; max-width: 76%; line-height: 1.4; }
        .chat-bubble.sent { margin-left: auto; background: #2563eb; color: #fff; border-bottom-right: 0; }
        .chat-bubble.received { margin-right: auto; background: #e2e8f0; color: #111827; border-bottom-left: 0; }
        .message-meta { font-size: 0.8rem; color: #6b7280; margin-top: 0.4rem; }
        .contact-snippet { font-size: 0.9rem; color: #6b7280; }
        .badge-round { min-width: 1.8rem; }
        @media (max-width: 991px) { .chat-wrapper { flex-direction: column; } .chat-sidebar { width: 100%; } }
    </style>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Messages</h1>
        <button type="button" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;" data-bs-toggle="modal" data-bs-target="#newChatModal">
            <i class="fas fa-comments me-1"></i> New Chat
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #6ee7b7;">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
        </div>
    <?php endif; ?>
    <?php if (isset($error_msg) && $error_msg !== ''): ?>
        <div class="alert alert-danger" style="background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="chat-wrapper">
        <div class="chat-sidebar">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-1">Chats</h5>
                        <p class="mb-0 text-muted">Recent conversations</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input id="contactSearch" type="search" class="form-control" placeholder="Search contacts" style="border-color: #2563eb;">
                    </div>
                    <div class="list-group" id="contactList">
                        <?php if (!empty($contacts)): ?>
                            <?php foreach ($contacts as $contact): ?>
                                <a href="index.php?chat_with=<?php echo $contact['contact_id']; ?>" class="list-group-item list-group-item-action contact-item <?php echo ($activeContact && $activeContact['id'] === $contact['contact_id']) ? 'active' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($contact['name'])); ?>">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1" style="color: #1e3a8a;"><?php echo htmlspecialchars($contact['name']); ?></h6>
                                            <p class="mb-1 contact-snippet"><?php echo htmlspecialchars(strlen($contact['last_message']) > 40 ? substr($contact['last_message'], 0, 40) . '...' : $contact['last_message']); ?></p>
                                        </div>
                                        <?php if (!empty($unreadCounts[$contact['contact_id']])): ?>
                                            <span class="badge bg-primary rounded-pill badge-round"><?php echo $unreadCounts[$contact['contact_id']]; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?php echo date('M d H:i', strtotime($contact['sent_at'])); ?></small>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">No conversations yet. Start a new chat to begin messaging.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-1">Course Group Chats</h5>
                        <p class="mb-0 text-muted">Join group chats for your courses.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input id="groupSearch" type="search" class="form-control" placeholder="Search groups" style="border-color: #2563eb;">
                    </div>
                    <div class="list-group" id="groupList">
                        <?php if (!empty($courseGroups)): ?>
                            <?php foreach ($courseGroups as $group): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start <?php echo ($activeGroup && $activeGroup['group_id'] === $group['group_id']) ? 'active' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($group['group_name'] . ' ' . $group['subject_title'])); ?>">
                                    <div>
                                        <h6 class="mb-1" style="color: #1e3a8a;"><?php echo htmlspecialchars($group['group_name']); ?></h6>
                                        <p class="mb-1 contact-snippet"><?php echo htmlspecialchars($group['subject_title']); ?></p>
                                        <small class="text-muted"><?php echo htmlspecialchars($group['member_count']); ?> member<?php echo $group['member_count'] > 1 ? 's' : ''; ?></small>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($group['is_member']): ?>
                                            <a href="index.php?group_chat=<?php echo $group['group_id']; ?>" class="btn btn-sm btn-outline-primary">Open</a>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="join_group" value="<?php echo (int)$group['group_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Join</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">No course group chats available yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex-fill">
            <div class="card shadow-sm border-0 chat-panel">
                <?php if ($activeContact): ?>
                    <div class="chat-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1" style="color: #1e3a8a;"><?php echo htmlspecialchars($activeContact['name']); ?></h5>
                                <small class="text-muted"><?php echo htmlspecialchars($activeContact['email']); ?></small>
                            </div>
                            <span class="badge bg-success">Active</span>
                        </div>
                    </div>
                    <div class="chat-body">
                        <?php if (!empty($conversation)): ?>
                            <?php foreach ($conversation as $message): ?>
                                <?php $sentByMe = $message['sender_id'] == $user_id; ?>
                                <div class="mb-3" style="display: flex; <?php echo $sentByMe ? 'justify-content:flex-end;' : 'justify-content:flex-start;'; ?>">
                                    <div class="chat-bubble <?php echo $sentByMe ? 'sent' : 'received'; ?>">
                                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                        <div class="message-meta text-end"><?php echo htmlspecialchars($sentByMe ? 'You' : $message['sender_name']); ?> · <?php echo date('M d H:i', strtotime($message['sent_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <p class="mb-2">No messages yet in this conversation.</p>
                                <p class="small">Use the message box below to send the first message.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="chat-footer">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo htmlspecialchars($activeContact['id']); ?>">
                            <div class="mb-3">
                                <textarea name="message" class="form-control" rows="3" placeholder="Type your message..." required style="border-color: #2563eb;"><?php echo htmlspecialchars($message_text); ?></textarea>
                            </div>
                            <div class="text-end">
                                <button type="submit" name="send_message" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;">Send</button>
                            </div>
                        </form>
                    </div>
                <?php elseif ($activeGroup): ?>
                    <div class="chat-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1" style="color: #1e3a8a;"><?php echo htmlspecialchars($activeGroup['group_name']); ?></h5>
                                <p class="mb-1 text-muted small"><?php echo htmlspecialchars($activeGroup['subject_title']); ?></p>
                            </div>
                            <span class="badge bg-success"><?php echo ($activeGroup['is_member'] || $user_role === 'admin' || $activeGroup['instructor_id'] == $user_id) ? 'Joined' : 'Open'; ?></span>
                        </div>
                    </div>
                    <div class="chat-body">
                        <?php if (!empty($conversation)): ?>
                            <?php foreach ($conversation as $message): ?>
                                <?php $sentByMe = $message['sender_id'] == $user_id; ?>
                                <div class="mb-3" style="display: flex; <?php echo $sentByMe ? 'justify-content:flex-end;' : 'justify-content:flex-start;'; ?>">
                                    <div class="chat-bubble <?php echo $sentByMe ? 'sent' : 'received'; ?>">
                                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                        <div class="message-meta text-end"><?php echo htmlspecialchars($sentByMe ? 'You' : $message['sender_name']); ?> · <?php echo date('M d H:i', strtotime($message['sent_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-5">
                                <p class="mb-2">No messages yet in this group chat.</p>
                                <p class="small">Send the first message to get the conversation started.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="chat-footer">
                        <?php if ($activeGroup['is_member'] || $user_role === 'admin' || $activeGroup['instructor_id'] == $user_id): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="group_id" value="<?php echo htmlspecialchars($activeGroup['group_id']); ?>">
                                <div class="mb-3">
                                    <textarea name="message" class="form-control" rows="3" placeholder="Type your message..." required style="border-color: #2563eb;"><?php echo htmlspecialchars($message_text); ?></textarea>
                                </div>
                                <div class="text-end">
                                    <button type="submit" name="send_message" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;">Send</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="join_group" value="<?php echo (int)$activeGroup['group_id']; ?>">
                                    <button type="submit" class="btn btn-success">Join this group to chat</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="chat-header bg-white">
                        <h5 class="mb-0" style="color: #1e3a8a;">Choose a chat or start a new one</h5>
                    </div>
                    <div class="chat-body d-flex align-items-center justify-content-center text-center">
                        <div>
                            <p class="mb-3">Select a contact or a course group on the left, or click "New Chat" to start messaging.</p>
                            <button type="button" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;" data-bs-toggle="modal" data-bs-target="#newChatModal">
                                <i class="fas fa-comments me-1"></i> Start New Chat
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div class="modal fade" id="newChatModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0">
                <div class="modal-header card-header-custom border-0">
                    <h5 class="modal-title" style="color: white;">Start a New Chat</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Recipient</label>
                            <input id="newChatRecipientSearch" type="search" class="form-control" placeholder="Search by name or email" autocomplete="off" value="<?php echo $preselectedRecipient ? htmlspecialchars($preselectedRecipient['name'] . ' (' . $preselectedRecipient['email'] . ')') : ''; ?>" style="border-color: #2563eb;" <?php echo empty($allUsers) ? 'disabled' : ''; ?>>
                            <input id="newChatRecipient" type="hidden" name="receiver_id" value="<?php echo $preselectedRecipient ? (int)$preselectedRecipient['id'] : ''; ?>">
                            <div id="newChatRecipientResults" class="list-group mt-2" role="listbox" hidden></div>
                            <?php if (empty($allUsers)): ?>
                                <div class="form-text text-danger">No other users are available to message.</div>
                            <?php else: ?>
                                <div id="newChatRecipientHint" class="form-text">Choose a recipient from the search results.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Message</label>
                            <textarea name="message" class="form-control" rows="4" placeholder="Write your message..." required style="border-color: #2563eb;"><?php echo htmlspecialchars($message_text); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="send_message" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;" <?php echo empty($allUsers) ? 'disabled' : ''; ?>>Send</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var contactSearch = document.getElementById('contactSearch');
        var contactList = document.getElementById('contactList');
        var groupSearch = document.getElementById('groupSearch');
        var groupList = document.getElementById('groupList');
        var recipientSearch = document.getElementById('newChatRecipientSearch');
        var recipientId = document.getElementById('newChatRecipient');
        var recipientResults = document.getElementById('newChatRecipientResults');
        var recipientHint = document.getElementById('newChatRecipientHint');

        function filterList(searchInput, listElement) {
            if (!searchInput || !listElement) return;
            searchInput.addEventListener('input', function() {
                var query = searchInput.value.trim().toLowerCase();
                Array.from(listElement.children).forEach(function(item) {
                    var search = item.getAttribute('data-search') || item.textContent.toLowerCase();
                    item.style.display = query && search.indexOf(query) === -1 ? 'none' : '';
                });
            });
        }

        filterList(contactSearch, contactList);
        filterList(groupSearch, groupList);

        if (recipientSearch && recipientId && recipientResults) {
            var recipients = <?php echo json_encode(array_map(function ($user) {
                return [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }, $allUsers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

            function renderRecipientResults() {
                var query = recipientSearch.value.trim().toLowerCase();
                recipientResults.replaceChildren();
                recipientId.value = '';
                if (!query) {
                    recipientResults.hidden = true;
                    if (recipientHint) recipientHint.textContent = 'Choose a recipient from the search results.';
                    return;
                }

                var matches = recipients.filter(function(recipient) {
                    return (recipient.name + ' ' + recipient.email).toLowerCase().indexOf(query) !== -1;
                }).slice(0, 8);

                matches.forEach(function(recipient) {
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'list-group-item list-group-item-action';
                    option.setAttribute('role', 'option');
                    option.textContent = recipient.name + ' (' + recipient.email + ')';
                    option.addEventListener('click', function() {
                        recipientSearch.value = option.textContent;
                        recipientId.value = recipient.id;
                        recipientResults.hidden = true;
                        if (recipientHint) recipientHint.textContent = 'Recipient selected.';
                    });
                    recipientResults.appendChild(option);
                });

                if (!matches.length) {
                    var empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted';
                    empty.textContent = 'No matching users found.';
                    recipientResults.appendChild(empty);
                }
                recipientResults.hidden = false;
                if (recipientHint) recipientHint.textContent = 'Select a matching user.';
            }

            recipientSearch.addEventListener('input', function() {
                renderRecipientResults();
            });

            recipientSearch.addEventListener('focus', function() {
                if (recipientSearch.value.trim()) renderRecipientResults();
            });

            recipientSearch.closest('form').addEventListener('submit', function(event) {
                if (!recipientId.value) {
                    event.preventDefault();
                    recipientSearch.setCustomValidity('Select a recipient from the search results.');
                    recipientSearch.reportValidity();
                }
            });
            recipientSearch.addEventListener('input', function() {
                recipientSearch.setCustomValidity('');
            });
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
