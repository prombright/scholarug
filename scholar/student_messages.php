<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT MESSAGES
|--------------------------------------------------------------------------
| A student can message any teacher who actually teaches them: everyone
| with a teacher_assignments row for the student's own class, plus that
| class's class_teacher_id (who doesn't always have a subject assignment
| of their own). No school-wide staff directory -- the teacher list is
| entirely derived from those two tables, so a student can't open a
| thread with an unrelated teacher by guessing an id.
|
| One thread per (student, teacher) pair (conversations.UNIQUE(student_id,
| teacher_id)) -- reopening the same teacher reuses the same thread.
| Every read/write is scoped to current_student_id(), never trusting a
| posted id alone.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_chat_helpers.php';
require_once __DIR__ . '/_student_messages_helpers.php';

require_role(['student']);

$school_id = current_school_id();
$student_id = current_student_id();

$stu_stmt = $pdo->prepare("SELECT class_id FROM students WHERE id = ? AND school_id = ?");
$stu_stmt->execute([$student_id, $school_id]);
$class_id = (int) ($stu_stmt->fetchColumn() ?: 0);

$error = '';

$teachers = student_reachable_teachers($pdo, $school_id, $class_id);
$allowed_teacher_ids = array_map('intval', array_column($teachers, 'staff_id'));

$threads = student_message_threads($pdo, $school_id, $student_id);

// Live sidebar refresh -- the whole thread list (unread counts, ordering,
// brand-new threads a teacher just started) as JSON, polled independently
// of whether a specific conversation is open. Merges the always-messageable
// teacher list with actual thread data exactly like the render below does,
// so a teacher with zero messages yet still appears.
if (($_GET['poll_threads'] ?? '') === '1') {
    header('Content-Type: application/json');
    $out = array_map(static function ($row) {
        $row['last_at_label'] = chat_relative_time($row['last_at']);
        return $row;
    }, student_message_rows($teachers, $threads));
    echo json_encode(['threads' => $out]);
    exit;
}

// Which teacher's thread is open right now.
$open_teacher_id = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : null;
if ($open_teacher_id !== null && !in_array($open_teacher_id, $allowed_teacher_ids, true)) {
    $error = 'You can only message a teacher who teaches your class.';
    $open_teacher_id = null;
}

$conversation_id = null;
$open_teacher_name = '';

if ($open_teacher_id !== null) {
    foreach ($teachers as $t) {
        if ((int) $t['staff_id'] === $open_teacher_id) {
            $open_teacher_name = $t['teacher_name'];
        }
    }

    $conversation_id = student_find_or_create_conversation($pdo, $school_id, $student_id, $open_teacher_id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
        $body = trim($_POST['body'] ?? '');
        $inserted = null;
        if ($body !== '') {
            $send = $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'student', ?)");
            $send->execute([$conversation_id, $body]);
            $inserted = [
                'id' => (int) $pdo->lastInsertId(),
                'sender_role' => 'student',
                'body' => $body,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        // AJAX path (the live chat UI below) gets JSON back instead of a
        // full-page redirect -- the fallback redirect stays for anyone
        // hitting this with JS disabled.
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $inserted !== null, 'message' => $inserted]);
            exit;
        }
        header('Location: student_messages.php?teacher_id=' . $open_teacher_id);
        exit;
    }

    // Polling endpoint the open thread's JS calls every few seconds --
    // same read-marking and scoping as the normal render below, just
    // returning only what's new since the caller's last known message id
    // instead of the whole page.
    if (($_GET['poll'] ?? '') === '1') {
        header('Content-Type: application/json');
        $after_id = (int) ($_GET['after_id'] ?? 0);
        echo json_encode(['messages' => student_poll_messages($pdo, $conversation_id, $after_id)]);
        exit;
    }

    // Viewing the thread marks the teacher's messages as read.
    $messages = student_conversation_messages($pdo, $conversation_id);
} else {
    $messages = [];
}

require_once __DIR__ . '/elections/_election_helpers.php';
$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);
$unread_message_count = array_sum(array_column($threads, 'unread'));

$student_stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ? AND school_id = ?");
$student_stmt->execute([$student_id, $school_id]);
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'messages';
require_once __DIR__ . '/_student_shell.php';
?>
<style>
<?php require __DIR__ . '/_chat_style.php'; ?>
</style>
<div class="page-title">Messages</div>

    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="chat-layout">
        <div class="chat-sidebar" id="chatSidebar" data-open-id="<?= $open_teacher_id !== null ? (int) $open_teacher_id : '' ?>">
            <?php if (empty($teachers)): ?>
                <div class="empty">No teachers assigned to your class yet.</div>
            <?php else: ?>
                <?php foreach ($teachers as $t):
                    $tid = (int) $t['staff_id'];
                    $unread = 0;
                    $last_at = null;
                    foreach ($threads as $th) {
                        if ((int) $th['teacher_id'] === $tid) {
                            $unread = (int) $th['unread'];
                            $last_at = $th['last_at'];
                        }
                    }
                ?>
                    <a href="?teacher_id=<?= $tid ?>" class="thread-row <?= $open_teacher_id === $tid ? 'active' : '' ?>">
                        <span class="avatar"><?= chat_initials($t['teacher_name']) ?></span>
                        <span class="thread-row-body">
                            <span class="thread-row-top">
                                <span class="thread-row-name"><?= htmlspecialchars($t['teacher_name']) ?></span>
                                <?php if ($last_at): ?><span class="thread-row-time"><?= chat_relative_time($last_at) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <?php if ($unread > 0): ?><span class="unread"><?= $unread ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="chat-main">
            <?php if ($open_teacher_id === null): ?>
                <div class="chat-placeholder">
                    <i class="bi bi-chat-dots"></i>
                    <p>Pick a teacher on the left to start or continue a conversation.</p>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <span class="avatar"><?= chat_initials($open_teacher_name) ?></span>
                    <span class="chat-header-name"><?= htmlspecialchars($open_teacher_name) ?></span>
                </div>
                <div class="chat-messages" id="chatMessages" data-conversation-id="<?= (int) $conversation_id ?>" data-last-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>">
                    <?php if (empty($messages)): ?>
                        <div class="empty">No messages yet — say hello to <?= htmlspecialchars($open_teacher_name) ?>.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="msg-row <?= $m['sender_role'] === 'student' ? 'out' : 'in' ?>">
                                <div class="msg">
                                    <?= nl2br(htmlspecialchars($m['body'])) ?>
                                    <span class="meta"><?= date('H:i', strtotime($m['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <form method="POST" class="chat-composer" id="chatComposer">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="body" id="chatInput" rows="1" required placeholder="Type a message..."></textarea>
                    <button type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<script>
// Sidebar live refresh -- independent of whether a conversation is open,
// so a new/incoming message updates the unread badge and re-orders the
// list even while sitting on the "pick a teacher" placeholder.
(function () {
    var sidebar = document.getElementById('chatSidebar');
    if (!sidebar) return;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function rowHtml(t) {
        var openId = sidebar.dataset.openId;
        var active = (openId && String(t.teacher_id) === openId) ? ' active' : '';
        var initials = t.teacher_name.trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase() || '?';
        var time = t.last_at_label ? '<span class="thread-row-time">' + escapeHtml(t.last_at_label) + '</span>' : '';
        var unread = t.unread > 0 ? '<span class="unread">' + t.unread + '</span>' : '';
        return '<a href="?teacher_id=' + t.teacher_id + '" class="thread-row' + active + '">' +
            '<span class="avatar">' + escapeHtml(initials) + '</span>' +
            '<span class="thread-row-body"><span class="thread-row-top">' +
            '<span class="thread-row-name">' + escapeHtml(t.teacher_name) + '</span>' + time +
            '</span></span>' + unread + '</a>';
    }

    function pollThreads() {
        if (document.hidden) return;
        fetch(window.location.pathname + '?poll_threads=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.threads) return;
                if (!data.threads.length) {
                    sidebar.innerHTML = '<div class="empty">No teachers assigned to your class yet.</div>';
                    return;
                }
                sidebar.innerHTML = data.threads.map(rowHtml).join('');
            })
            .catch(function () {});
    }
    setInterval(pollThreads, 6000);
})();
</script>

<?php if ($open_teacher_id !== null): ?>
<script>
(function () {
    var container = document.getElementById('chatMessages');
    var composer = document.getElementById('chatComposer');
    var input = document.getElementById('chatInput');
    if (!container || !composer || !input) return;

    function scrollToBottom() { container.scrollTop = container.scrollHeight; }
    scrollToBottom();

    function bubbleHtml(m) {
        var side = m.sender_role === 'student' ? 'out' : 'in';
        var body = m.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        var time = new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return '<div class="msg-row ' + side + '"><div class="msg">' + body + '<span class="meta">' + time + '</span></div></div>';
    }

    composer.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = input.value.trim();
        if (!body) return;
        var fd = new FormData(composer);
        fd.set('ajax', '1');
        input.disabled = true;
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                input.disabled = false;
                if (data.ok && data.message) {
                    var empty = container.querySelector('.empty');
                    if (empty) empty.remove();
                    container.insertAdjacentHTML('beforeend', bubbleHtml(data.message));
                    container.dataset.lastId = data.message.id;
                    input.value = '';
                    input.style.height = 'auto';
                    scrollToBottom();
                }
            })
            .catch(function () { input.disabled = false; });
    });

    // Enter sends, Shift+Enter makes a newline -- standard chat-app convention.
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            composer.requestSubmit();
        }
    });
    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    // Light polling for the open thread -- gives the "live" feel without a
    // websocket server. Paused while the tab is hidden to avoid wasting
    // requests on backgrounded tabs.
    function poll() {
        if (document.hidden) return;
        var afterId = container.dataset.lastId || 0;
        var convId = container.dataset.conversationId;
        var url = window.location.pathname + '?teacher_id=<?= (int) $open_teacher_id ?>&poll=1&after_id=' + afterId;
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            if (data.messages && data.messages.length) {
                var empty = container.querySelector('.empty');
                if (empty) empty.remove();
                data.messages.forEach(function (m) {
                    container.insertAdjacentHTML('beforeend', bubbleHtml(m));
                    container.dataset.lastId = m.id;
                });
                scrollToBottom();
            }
        }).catch(function () {});
    }
    setInterval(poll, 4000);
})();
</script>
<?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
