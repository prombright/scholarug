<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — CONTACT DEVELOPER (school admin <-> developer chat)
|--------------------------------------------------------------------------
| Same WhatsApp-style chat UI/pattern as student_messages.php and
| teacher_messages.php (shared _chat_helpers.php/_chat_style.php), backed
| by developer_conversations/developer_messages in the same `scholar`
| database instead of the old cross-database bridge into devportal's own
| `abn_platform` DB, which is what made this show "temporarily
| unavailable" -- there's nothing left here that can go unreachable.
|
| One thread per school (developer_conversations.UNIQUE(school_id)),
| auto-created on first message. The developer's side of this same thread
| lives at scholar/developer/messages.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_chat_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();

$find = $pdo->prepare('SELECT id FROM developer_conversations WHERE school_id = ?');
$find->execute([$school_id]);
$conversation_id = $find->fetchColumn();

if (($_GET['poll'] ?? '') === '1') {
    header('Content-Type: application/json');
    if (!$conversation_id) {
        echo json_encode(['messages' => []]);
        exit;
    }
    $after_id = (int) ($_GET['after_id'] ?? 0);
    $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'developer' AND read_at IS NULL")
        ->execute([$conversation_id]);
    $poll_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC');
    $poll_stmt->execute([$conversation_id, $after_id]);
    echo json_encode(['messages' => $poll_stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $body = trim($_POST['body'] ?? '');
    $inserted = null;

    if ($body !== '') {
        if (!$conversation_id) {
            $ins = $pdo->prepare('INSERT INTO developer_conversations (school_id) VALUES (?)');
            $ins->execute([$school_id]);
            $conversation_id = (int) $pdo->lastInsertId();
        }

        $send = $pdo->prepare("INSERT INTO developer_messages (conversation_id, sender_role, body) VALUES (?, 'school_admin', ?)");
        $send->execute([$conversation_id, $body]);
        $inserted = [
            'id' => (int) $pdo->lastInsertId(),
            'sender_role' => 'school_admin',
            'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $inserted !== null, 'message' => $inserted]);
        exit;
    }
    header('Location: contact_developer.php');
    exit;
}

$messages = [];
if ($conversation_id) {
    $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'developer' AND read_at IS NULL")
        ->execute([$conversation_id]);
    $msgs_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $msgs_stmt->execute([$conversation_id]);
    $messages = $msgs_stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
<?php require __DIR__ . '/../_chat_style.php'; ?>
.chat-layout{grid-template-columns:1fr;}
</style>
<main class="main-content">
<div class="page-inner">

<div class="page-title">Contact Developer</div>

    <div class="chat-layout">
        <div class="chat-main">
            <div class="chat-header">
                <span class="avatar">SU</span>
                <span class="chat-header-name">ScholarUg Developer</span>
            </div>
            <div class="chat-messages" id="chatMessages" data-conversation-id="<?= (int) $conversation_id ?>" data-last-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>">
                <?php if (empty($messages)): ?>
                    <div class="empty">No messages yet — describe your question or issue below and the developer will get back to you here.</div>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <div class="msg-row <?= $m['sender_role'] === 'school_admin' ? 'out' : 'in' ?>">
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
        </div>
    </div>

<script>
(function () {
    var container = document.getElementById('chatMessages');
    var composer = document.getElementById('chatComposer');
    var input = document.getElementById('chatInput');
    if (!container || !composer || !input) return;

    function scrollToBottom() { container.scrollTop = container.scrollHeight; }
    scrollToBottom();

    function bubbleHtml(m) {
        var side = m.sender_role === 'school_admin' ? 'out' : 'in';
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
        fetch('contact_developer.php', { method: 'POST', body: fd })
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
        fetch('contact_developer.php?poll=1&after_id=' + afterId).then(function (r) { return r.json(); }).then(function (data) {
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

</div><!-- /.page-inner -->
</main>
</div><!-- /.app-shell -->
</body>
</html>
