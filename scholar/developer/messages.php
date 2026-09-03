<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — MESSAGES (school admin <-> developer chat)
|--------------------------------------------------------------------------
| The developer's side of school_admin/contact_developer.php's thread.
| Same shared chat UI (_chat_helpers.php/_chat_style.php) as the
| student<->teacher chat, sidebar keyed by school instead of by person --
| every school that has ever opened a thread shows up here, most recently
| active first.
|--------------------------------------------------------------------------
*/

require '../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../_chat_helpers.php';

// Every school with an existing thread, latest message first.
$threads_stmt = $pdo->query("
    SELECT dc.id AS conversation_id, dc.school_id, s.school_name,
        (SELECT COUNT(*) FROM developer_messages dm WHERE dm.conversation_id = dc.id AND dm.sender_role = 'school_admin' AND dm.read_at IS NULL) AS unread,
        (SELECT MAX(created_at) FROM developer_messages dm WHERE dm.conversation_id = dc.id) AS last_at
    FROM developer_conversations dc
    JOIN schools s ON s.id = dc.school_id
    ORDER BY last_at DESC
");
$threads = $threads_stmt->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['poll_threads'] ?? '') === '1') {
    header('Content-Type: application/json');
    $out = array_map(static function ($t) {
        return [
            'school_id' => (int) $t['school_id'],
            'school_name' => $t['school_name'],
            'unread' => (int) $t['unread'],
            'last_at_label' => chat_relative_time($t['last_at']),
        ];
    }, $threads);
    echo json_encode(['threads' => $out]);
    exit;
}

$open_school_id = isset($_GET['school_id']) ? (int) $_GET['school_id'] : null;
$conversation_id = null;
$open_school_name = '';

if ($open_school_id !== null) {
    foreach ($threads as $t) {
        if ((int) $t['school_id'] === $open_school_id) {
            $conversation_id = (int) $t['conversation_id'];
            $open_school_name = $t['school_name'];
        }
    }
    if ($conversation_id === null) {
        $open_school_id = null;
    }
}

if ($conversation_id !== null && ($_GET['poll'] ?? '') === '1') {
    header('Content-Type: application/json');
    $after_id = (int) ($_GET['after_id'] ?? 0);
    $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'school_admin' AND read_at IS NULL")
        ->execute([$conversation_id]);
    $poll_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC');
    $poll_stmt->execute([$conversation_id, $after_id]);
    echo json_encode(['messages' => $poll_stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($conversation_id !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $body = trim($_POST['body'] ?? '');
    $inserted = null;
    if ($body !== '') {
        $send = $pdo->prepare("INSERT INTO developer_messages (conversation_id, sender_role, body) VALUES (?, 'developer', ?)");
        $send->execute([$conversation_id, $body]);
        $inserted = [
            'id' => (int) $pdo->lastInsertId(),
            'sender_role' => 'developer',
            'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $inserted !== null, 'message' => $inserted]);
        exit;
    }
    header('Location: messages.php?school_id=' . $open_school_id);
    exit;
}

$messages = [];
if ($conversation_id !== null) {
    $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'school_admin' AND read_at IS NULL")
        ->execute([$conversation_id]);
    $msgs_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $msgs_stmt->execute([$conversation_id]);
    $messages = $msgs_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>ScholarUg | Messages</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{ --bg:#080b11; --panel:#0d1118; --border:#1e293b; --text:#e2e8f0; --muted:#64748b; --cyan:#06b6d4; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);font-family:Inter,"Segoe UI",sans-serif;color:var(--text);}
.container{max-width:1100px;margin:auto;padding:30px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}
h1{color:white;margin:0;font-size:1.3rem;}
a.back{color:var(--cyan);text-decoration:none;font-size:0.85rem;}
<?php require __DIR__ . '/../_chat_style.php'; ?>
.chat-layout{height:min(640px,75vh);}
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1>Messages</h1>
    <a class="back" href="developer_dashboard.php">&larr; Dashboard</a>
</div>

    <div class="chat-layout">
        <div class="chat-sidebar" id="chatSidebar" data-open-id="<?= $open_school_id !== null ? (int) $open_school_id : '' ?>">
            <?php if (empty($threads)): ?>
                <div class="empty">No school has messaged you yet.</div>
            <?php else: ?>
                <?php foreach ($threads as $t): $sid = (int) $t['school_id']; ?>
                    <a href="?school_id=<?= $sid ?>" class="thread-row <?= $open_school_id === $sid ? 'active' : '' ?>">
                        <span class="avatar"><?= chat_initials($t['school_name']) ?></span>
                        <span class="thread-row-body">
                            <span class="thread-row-top">
                                <span class="thread-row-name"><?= htmlspecialchars($t['school_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($t['last_at']): ?><span class="thread-row-time"><?= chat_relative_time($t['last_at']) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <?php if ((int) $t['unread'] > 0): ?><span class="unread"><?= (int) $t['unread'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="chat-main">
            <?php if ($open_school_id === null): ?>
                <div class="chat-placeholder">
                    <i class="bi bi-chat-dots"></i>
                    <p>Pick a school on the left to view its conversation.</p>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <span class="avatar"><?= chat_initials($open_school_name) ?></span>
                    <span class="chat-header-name"><?= htmlspecialchars($open_school_name, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="chat-messages" id="chatMessages" data-conversation-id="<?= (int) $conversation_id ?>" data-last-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>">
                    <?php if (empty($messages)): ?>
                        <div class="empty">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <div class="msg-row <?= $m['sender_role'] === 'developer' ? 'out' : 'in' ?>">
                                <div class="msg">
                                    <?= nl2br(htmlspecialchars($m['body'], ENT_QUOTES, 'UTF-8')) ?>
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

</div>

<script>
(function () {
    var sidebar = document.getElementById('chatSidebar');
    if (!sidebar) return;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function rowHtml(t) {
        var openId = sidebar.dataset.openId;
        var active = (openId && String(t.school_id) === openId) ? ' active' : '';
        var initials = t.school_name.trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase() || '?';
        var time = t.last_at_label ? '<span class="thread-row-time">' + escapeHtml(t.last_at_label) + '</span>' : '';
        var unread = t.unread > 0 ? '<span class="unread">' + t.unread + '</span>' : '';
        return '<a href="?school_id=' + t.school_id + '" class="thread-row' + active + '">' +
            '<span class="avatar">' + escapeHtml(initials) + '</span>' +
            '<span class="thread-row-body"><span class="thread-row-top">' +
            '<span class="thread-row-name">' + escapeHtml(t.school_name) + '</span>' + time +
            '</span></span>' + unread + '</a>';
    }

    function pollThreads() {
        if (document.hidden) return;
        fetch('messages.php?poll_threads=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.threads) return;
                if (!data.threads.length) {
                    sidebar.innerHTML = '<div class="empty">No school has messaged you yet.</div>';
                    return;
                }
                sidebar.innerHTML = data.threads.map(rowHtml).join('');
            })
            .catch(function () {});
    }
    setInterval(pollThreads, 6000);
})();
</script>

<?php if ($open_school_id !== null): ?>
<script>
(function () {
    var container = document.getElementById('chatMessages');
    var composer = document.getElementById('chatComposer');
    var input = document.getElementById('chatInput');
    if (!container || !composer || !input) return;

    function scrollToBottom() { container.scrollTop = container.scrollHeight; }
    scrollToBottom();

    function bubbleHtml(m) {
        var side = m.sender_role === 'developer' ? 'out' : 'in';
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
        var url = window.location.pathname + '?school_id=<?= (int) $open_school_id ?>&poll=1&after_id=' + afterId;
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
</body>
</html>
