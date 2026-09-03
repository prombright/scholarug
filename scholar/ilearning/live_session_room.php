<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);
require_ilearning_addon();

$school_id = current_school_id();
$role = $_SESSION['role'];
$isTeacher = $role === 'teacher';
$session_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ls.*, s.subject_name, c.class_name FROM ilearning_live_sessions ls
    JOIN subjects s ON s.id = ls.subject_id JOIN classes c ON c.id = ls.class_id
    WHERE ls.id = ? AND ls.school_id = ?
");
$stmt->execute([$session_id, $school_id]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    die('Session not found.');
}

if ($isTeacher) {
    if ((int) $session['teacher_id'] !== (int) ($_SESSION['staff_id'] ?? 0)) {
        http_response_code(403);
        die('This is not your session.');
    }
} else {
    $stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
    $stuStmt->execute([current_student_id(), $school_id]);
    if ((int) $stuStmt->fetchColumn() !== (int) $session['class_id']) {
        http_response_code(403);
        die('This session is not for your class.');
    }
}

if ($session['status'] === 'scheduled' && $isTeacher) {
    $pdo->prepare("UPDATE ilearning_live_sessions SET status = 'live' WHERE id = ?")->execute([$session_id]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></title>
<script src="https://meet.jit.si/external_api.js"></script>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.layout{display:grid;grid-template-columns:1fr 320px;height:100vh;}
#meet{width:100%;height:100vh;}
.qa{background:var(--panel);border-left:1px solid var(--border);display:flex;flex-direction:column;padding:16px;overflow-y:auto;}
.qa h3{margin:0 0 10px;font-size:0.95rem;}
.qitem{background:#111826;border-radius:8px;padding:10px;margin-bottom:8px;font-size:0.85rem;}
.qitem .who{color:var(--muted);font-size:0.7rem;text-transform:uppercase;}
.qitem .ans{color:var(--green);margin-top:6px;padding-top:6px;border-top:1px solid var(--border);}
.qform{margin-top:auto;padding-top:10px;border-top:1px solid var(--border);}
textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:8px;border-radius:6px;font-family:inherit;min-height:50px;}
select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:6px;border-radius:6px;margin-bottom:6px;}
button{cursor:pointer;border:none;border-radius:6px;padding:8px 14px;font-weight:700;background:var(--cyan);color:#04121a;margin-top:6px;}
.ansform{display:flex;gap:6px;margin-top:6px;}
.ansform input{flex:1;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:6px;border-radius:4px;}
@media (max-width:768px){ .layout{grid-template-columns:1fr;} .qa{height:40vh;} }
/* This page is a full-screen video call with no shell/sidebar around it,
   so without this there is genuinely no way back except the browser's
   own back button -- a floating pill over the call, matching the same
   dark+blur treatment as the mobile sidebar backdrop elsewhere. */
.leave-session-btn{position:fixed;top:14px;left:14px;z-index:1000;background:rgba(8,11,17,0.75);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);color:var(--text);text-decoration:none;font-size:0.8rem;font-weight:700;padding:8px 14px;border-radius:8px;border:1px solid var(--border);}
.leave-session-btn:hover{background:rgba(8,11,17,0.92);}
</style>
</head>
<body>
<a href="live_sessions.php" class="leave-session-btn">&larr; Leave Session</a>
<div class="layout">
    <div id="meet"></div>
    <div class="qa">
        <h3>Class Q&amp;A</h3>
        <div id="qList"></div>
        <div class="qform">
            <?php if (!$isTeacher): ?>
            <select id="visibility">
                <option value="public">Ask the whole class</option>
                <option value="private">Ask the teacher privately</option>
            </select>
            <?php endif; ?>
            <textarea id="qText" placeholder="Type your question..."></textarea>
            <button id="askBtn">Ask</button>
        </div>
    </div>
</div>

<script>
(function () {
    "use strict";
    var sessionId = <?= (int) $session_id ?>;
    var isTeacher = <?= $isTeacher ? 'true' : 'false' ?>;
    var roomName = <?= json_encode($session['room_reference']) ?>;

    var domain = 'meet.jit.si';
    var api = new JitsiMeetExternalAPI(domain, {
        roomName: roomName,
        parentNode: document.getElementById('meet'),
        width: '100%',
        height: '100%',
        userInfo: { displayName: <?= json_encode($isTeacher ? 'Teacher' : 'Student') ?> },
        configOverwrite: { prejoinPageEnabled: false },
    });

    function logAttendance(event) {
        fetch('live_attendance.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, event: event }),
        }).catch(function () {});
    }

    api.addEventListener('videoConferenceJoined', function () { logAttendance('joined'); });
    api.addEventListener('videoConferenceLeft', function () { logAttendance('left'); });
    api.addEventListener('readyToClose', function () { logAttendance('left'); });

    // --- Q&A: poll-based, same pattern as payments/subscription_status.php ---
    function renderQuestions(items) {
        var box = document.getElementById('qList');
        box.innerHTML = '';
        items.forEach(function (q) {
            var div = document.createElement('div');
            div.className = 'qitem';
            var who = q.visibility === 'private' ? 'Private question' : 'Public question';
            var html = '<div class="who">' + who + '</div><div>' + q.question_text + '</div>';
            if (q.answer_text) {
                html += '<div class="ans">' + q.answer_text + '</div>';
            } else if (isTeacher) {
                html += '<div class="ansform"><input type="text" data-qid="' + q.id + '" placeholder="Answer..."><button type="button" class="ansBtn" data-qid="' + q.id + '">Send</button></div>';
            } else {
                html += '<div class="ans" style="color:var(--muted);">Awaiting answer...</div>';
            }
            div.innerHTML = html;
            box.appendChild(div);
        });
        document.querySelectorAll('.ansBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var qid = btn.getAttribute('data-qid');
                var input = document.querySelector('input[data-qid="' + qid + '"]');
                if (!input.value.trim()) return;
                fetch('live_question_answer.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question_id: qid, answer_text: input.value.trim() }),
                }).then(poll);
            });
        });
    }

    function poll() {
        fetch('live_questions_list.php?session_id=' + sessionId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { renderQuestions(data.questions || []); })
            .catch(function () {});
    }
    poll();
    setInterval(poll, 5000);

    document.getElementById('askBtn').addEventListener('click', function () {
        var text = document.getElementById('qText').value.trim();
        if (!text) return;
        var visEl = document.getElementById('visibility');
        var visibility = visEl ? visEl.value : 'public';
        fetch('live_question_ask.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, question_text: text, visibility: visibility }),
        }).then(function () {
            document.getElementById('qText').value = '';
            poll();
        });
    });
})();
</script>
</body>
</html>
