<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER MESSAGES
|--------------------------------------------------------------------------
| Teacher side of student_messages.php. Threads a student already opened
| still show up here as before (conversations.teacher_id = their own
| staff_id), but a teacher can now ALSO start a new conversation
| themselves -- either with one specific student, or broadcast the same
| message to every student in one class, in one action.
|
| Composing (not viewing existing threads) is class-scoped: a teacher
| assigned to more than one class picks which one first, and the
| reachable roster for that class respects the same Core/Elective rule
| every other part of this app uses (teacher_marks_entry.php,
| _report_card_render.php) -- a Core/compulsory subject means the whole
| class, an Elective one is filtered by an actual student_subjects
| enrollment row, so a teacher of an elective (e.g. French) can't
| message a student who never took French just because they're in the
| same class. The one exception: a CLASS TEACHER reaches every student
| in their class regardless of subject, same pastoral-scope precedent as
| bulk_report_print.php/teacher_class_logins.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_chat_helpers.php';
require_once __DIR__ . '/_teacher_messages_helpers.php';

require_role(['teacher']);

$school_id = current_school_id();
$staff_id = current_staff_id();

$my_classes = teacher_reachable_classes($pdo, $school_id, $staff_id);

$selected_class_id = null;
if (isset($_POST['class_id'])) {
    $selected_class_id = (int) $_POST['class_id'];
} elseif (isset($_GET['class_id'])) {
    $selected_class_id = (int) $_GET['class_id'];
} elseif (count($my_classes) === 1) {
    // Only one class -- skip making them pick it.
    $selected_class_id = (int) $my_classes[0]['id'];
}

$selected_class = null;
foreach ($my_classes as $c) {
    if ((int) $c['id'] === $selected_class_id) {
        $selected_class = $c;
    }
}

// The reachable roster for $selected_class specifically -- not this
// teacher's other classes.
$class_roster = [];
if ($selected_class !== null) {
    $class_roster = teacher_class_roster($pdo, $school_id, $staff_id, $selected_class);
}
$class_roster_ids = array_map('intval', array_column($class_roster, 'id'));

$broadcast_error = '';
$broadcast_count = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_individual') {
    $target_id = (int) ($_POST['student_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');

    if ($selected_class === null) {
        $broadcast_error = 'Pick a class first.';
    } else {
        $result = teacher_send_individual($pdo, $school_id, $staff_id, $class_roster_ids, $target_id, $body);
        if (!$result['ok']) {
            $broadcast_error = $result['error'];
        } else {
            header('Location: teacher_messages.php?student_id=' . $target_id . '&class_id=' . $selected_class['id']);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_broadcast') {
    $body = trim($_POST['body'] ?? '');

    if ($selected_class === null) {
        $broadcast_error = 'Pick a class first.';
    } else {
        $result = teacher_send_broadcast($pdo, $school_id, $staff_id, $class_roster_ids, $body);
        if (!$result['ok']) {
            $broadcast_error = $result['error'];
        } else {
            header('Location: teacher_messages.php?broadcast_sent=' . $result['count'] . '&class_id=' . $selected_class['id']);
            exit;
        }
    }
}

$threads = teacher_message_threads($pdo, $school_id, $staff_id);

// Live sidebar refresh -- already sorted by last_at DESC from the query
// above, so this is a straight pass-through to JSON (unlike the student
// side, a teacher's thread list has no "always messageable" roster to
// merge in -- it's exactly the conversations that exist).
if (($_GET['poll_threads'] ?? '') === '1') {
    header('Content-Type: application/json');
    $out = array_map(function ($th) {
        return [
            'student_id' => (int) $th['student_id'],
            'student_name' => $th['student_name'],
            'unread' => (int) $th['unread'],
            'last_at_label' => chat_relative_time($th['last_at']),
        ];
    }, $threads);
    echo json_encode(['threads' => $out]);
    exit;
}

$open_student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;
$conversation_id = null;
$open_student_name = '';
$error = '';

if ($open_student_id !== null) {
    foreach ($threads as $th) {
        if ((int) $th['student_id'] === $open_student_id) {
            $conversation_id = (int) $th['id'];
            $open_student_name = $th['student_name'];
        }
    }

    if ($conversation_id === null) {
        $error = "You don't have a conversation with that student.";
        $open_student_id = null;
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
            $body = trim($_POST['body'] ?? '');
            $inserted = null;
            if ($body !== '') {
                $send = $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'teacher', ?)");
                $send->execute([$conversation_id, $body]);
                $inserted = [
                    'id' => (int) $pdo->lastInsertId(),
                    'sender_role' => 'teacher',
                    'body' => $body,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            if (($_POST['ajax'] ?? '') === '1') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $inserted !== null, 'message' => $inserted]);
                exit;
            }
            header('Location: teacher_messages.php?student_id=' . $open_student_id);
            exit;
        }

        // Polling endpoint the open thread's JS calls every few seconds --
        // mirrors student_messages.php's poll branch.
        if (($_GET['poll'] ?? '') === '1') {
            header('Content-Type: application/json');
            $after_id = (int) ($_GET['after_id'] ?? 0);
            echo json_encode(['messages' => teacher_poll_messages($pdo, $conversation_id, $after_id)]);
            exit;
        }

        // Viewing the thread marks the student's messages as read.
        $messages = teacher_conversation_messages($pdo, $conversation_id);
    }
}
$messages = $messages ?? [];

$class_teacher_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

$unread_message_count = array_sum(array_column($threads, 'unread'));

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'messages';
require_once __DIR__ . '/_teacher_shell.php';
?>
<style>
<?php require __DIR__ . '/_chat_style.php'; ?>
.new-message{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:20px;}
.new-message .recipient-toggle{display:flex;gap:8px;margin-bottom:12px;}
.new-message .recipient-toggle button{background:transparent;border:1px solid var(--border);color:var(--muted);padding:7px 14px;border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer;}
.new-message .recipient-toggle button.active{background:var(--cyan);border-color:var(--cyan);color:#04121a;}
.new-message select,.new-message textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:9px 10px;font-family:inherit;font-size:0.85rem;box-sizing:border-box;margin-bottom:10px;}
.new-message textarea{resize:vertical;}
.new-message .send-btn{background:var(--cyan);color:#04121a;border:none;border-radius:6px;padding:9px 18px;font-weight:700;font-size:0.85rem;cursor:pointer;}
.new-message .hint{color:var(--muted);font-size:0.78rem;margin-bottom:10px;}
.class-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.class-tabs a{padding:8px 16px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.82rem;font-weight:600;}
.class-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
</style>
<div class="page-title">Messages</div>

    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($broadcast_error): ?><div class="alert"><?= htmlspecialchars($broadcast_error) ?></div><?php endif; ?>
    <?php if (isset($_GET['broadcast_sent'])): ?>
        <div class="alert-success">Message sent to <?= (int) $_GET['broadcast_sent'] ?> student(s).</div>
    <?php endif; ?>

    <div class="section new-message">
        <?php if (empty($my_classes)): ?>
            <div class="empty">You're not assigned to teach any class yet.</div>
        <?php else: ?>
            <?php if (count($my_classes) > 1): ?>
                <div class="class-tabs">
                    <?php foreach ($my_classes as $c): ?>
                        <a href="?class_id=<?= (int) $c['id'] ?>" class="<?= $selected_class_id === (int) $c['id'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($selected_class === null): ?>
                <div class="empty">Pick a class above to message its students.</div>
            <?php else: ?>
                <form method="POST" id="newMessageForm">
                    <input type="hidden" name="action" id="newMessageAction" value="send_individual">
                    <input type="hidden" name="class_id" value="<?= (int) $selected_class['id'] ?>">
                    <div class="recipient-toggle">
                        <button type="button" id="modeIndividualBtn" class="active" onclick="scholarSetMessageMode('individual')">Individual Student</button>
                        <button type="button" id="modeBroadcastBtn" onclick="scholarSetMessageMode('broadcast')">All Students In This Class</button>
                    </div>
                    <select name="student_id" id="newMessageStudent">
                        <option value="">-- Select Student --</option>
                        <?php foreach ($class_roster as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint" id="broadcastHint" style="display:none;">
                        This sends the same message to all <?= count($class_roster) ?> student(s) in <?= htmlspecialchars($selected_class['class_name']) ?><?= $selected_class['is_class_teacher'] ? '' : ' who take a subject you teach them' ?>, as an individual message in each of their inboxes.
                    </div>
                    <?php if (empty($class_roster)): ?>
                        <div class="hint">No reachable students in this class yet.</div>
                    <?php endif; ?>
                    <textarea name="body" rows="3" required placeholder="Type a new message..."></textarea>
                    <button type="submit" class="send-btn" id="newMessageSubmit">Send</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
    function scholarSetMessageMode(mode) {
        var isBroadcast = mode === 'broadcast';
        document.getElementById('newMessageAction').value = isBroadcast ? 'send_broadcast' : 'send_individual';
        document.getElementById('modeIndividualBtn').classList.toggle('active', !isBroadcast);
        document.getElementById('modeBroadcastBtn').classList.toggle('active', isBroadcast);
        document.getElementById('newMessageStudent').style.display = isBroadcast ? 'none' : 'block';
        document.getElementById('newMessageStudent').required = !isBroadcast;
        document.getElementById('broadcastHint').style.display = isBroadcast ? 'block' : 'none';
    }
    </script>

    <div class="chat-layout">
        <div class="chat-sidebar" id="chatSidebar" data-open-id="<?= $open_student_id !== null ? (int) $open_student_id : '' ?>">
            <?php if (empty($threads)): ?>
                <div class="empty">No messages from students yet.</div>
            <?php else: ?>
                <?php foreach ($threads as $th):
                    $sid = (int) $th['student_id'];
                    $unread = (int) $th['unread'];
                ?>
                    <a href="?student_id=<?= $sid ?>" class="thread-row <?= $open_student_id === $sid ? 'active' : '' ?>">
                        <span class="avatar"><?= chat_initials($th['student_name']) ?></span>
                        <span class="thread-row-body">
                            <span class="thread-row-top">
                                <span class="thread-row-name"><?= htmlspecialchars($th['student_name']) ?></span>
                                <?php if (!empty($th['last_at'])): ?><span class="thread-row-time"><?= chat_relative_time($th['last_at']) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <?php if ($unread > 0): ?><span class="unread"><?= $unread ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="chat-main">
            <?php if ($open_student_id === null): ?>
                <div class="chat-placeholder">
                    <i class="bi bi-chat-dots"></i>
                    <p>Pick a student on the left to view the conversation.</p>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <span class="avatar"><?= chat_initials($open_student_name) ?></span>
                    <span class="chat-header-name"><?= htmlspecialchars($open_student_name) ?></span>
                </div>
                <div class="chat-messages" id="chatMessages" data-last-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>">
                    <?php if (empty($messages)): ?>
                        <div class="empty">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="msg-row <?= $m['sender_role'] === 'teacher' ? 'out' : 'in' ?>">
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
                    <textarea name="body" id="chatInput" rows="1" required placeholder="Reply to <?= htmlspecialchars($open_student_name) ?>..."></textarea>
                    <button type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<script>
// Sidebar live refresh -- mirrors student_messages.php's version. A new
// conversation a student just started, or a new unread count, shows up
// here even while the teacher is looking at a different thread.
(function () {
    var sidebar = document.getElementById('chatSidebar');
    if (!sidebar) return;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function rowHtml(t) {
        var openId = sidebar.dataset.openId;
        var active = (openId && String(t.student_id) === openId) ? ' active' : '';
        var initials = t.student_name.trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase() || '?';
        var time = t.last_at_label ? '<span class="thread-row-time">' + escapeHtml(t.last_at_label) + '</span>' : '';
        var unread = t.unread > 0 ? '<span class="unread">' + t.unread + '</span>' : '';
        return '<a href="?student_id=' + t.student_id + '" class="thread-row' + active + '">' +
            '<span class="avatar">' + escapeHtml(initials) + '</span>' +
            '<span class="thread-row-body"><span class="thread-row-top">' +
            '<span class="thread-row-name">' + escapeHtml(t.student_name) + '</span>' + time +
            '</span></span>' + unread + '</a>';
    }

    function pollThreads() {
        if (document.hidden) return;
        fetch(window.location.pathname + '?poll_threads=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.threads) return;
                if (!data.threads.length) {
                    sidebar.innerHTML = '<div class="empty">No messages from students yet.</div>';
                    return;
                }
                sidebar.innerHTML = data.threads.map(rowHtml).join('');
            })
            .catch(function () {});
    }
    setInterval(pollThreads, 6000);
})();
</script>

<?php if ($open_student_id !== null): ?>
<script>
(function () {
    var container = document.getElementById('chatMessages');
    var composer = document.getElementById('chatComposer');
    var input = document.getElementById('chatInput');
    if (!container || !composer || !input) return;

    function scrollToBottom() { container.scrollTop = container.scrollHeight; }
    scrollToBottom();

    function bubbleHtml(m) {
        var side = m.sender_role === 'teacher' ? 'out' : 'in';
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

    function poll() {
        if (document.hidden) return;
        var afterId = container.dataset.lastId || 0;
        var url = window.location.pathname + '?student_id=<?= (int) $open_student_id ?>&poll=1&after_id=' + afterId;
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
