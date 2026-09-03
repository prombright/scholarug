<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$student_id = current_student_id();
$topic_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*, s.subject_name FROM ilearning_topics t
    JOIN subjects s ON s.id = t.subject_id
    WHERE t.id = ? AND t.school_id = ? AND t.status = 'Published'
");
$stmt->execute([$topic_id, $school_id]);
$topic = $stmt->fetch();

if (!$topic || !ilearning_student_in_class($pdo, $school_id, $student_id, (int) $topic['class_id'])) {
    http_response_code(403);
    die('This topic is not available to you.');
}

$att_stmt = $pdo->prepare('SELECT file_path, original_name FROM ilearning_attachments WHERE topic_id = ?');
$att_stmt->execute([$topic_id]);
$attachments = $att_stmt->fetchAll();

$primary_pdf = null;
if ($topic['content_type'] !== 'written' && !empty($topic['primary_pdf_attachment_id'])) {
    $p_stmt = $pdo->prepare('SELECT id, original_name FROM ilearning_attachments WHERE id = ?');
    $p_stmt->execute([(int) $topic['primary_pdf_attachment_id']]);
    $primary_pdf = $p_stmt->fetch() ?: null;
}

// Three states once a topic is a pdf_activity: not yet submitted (editable
// form), submitted and awaiting a teacher's grade (locked "Submitted"
// card -- see save_pdf_submission.php, which now rejects a second
// submission once one exists), or graded (a "Graded" card showing the
// score, reusing the exact same student_marks lookup topic_roster.php's
// teacher-side view already uses). Marks left by the teacher via the text
// annotation tool (ilearning_text_annotations) are shown either way, once
// there's something to show them on.
$submission = null;
$submission_annotated_html = null;
$pdf_grade = null;
if ($topic['content_type'] === 'pdf_activity') {
    $s_stmt = $pdo->prepare('SELECT id, answer_text, submitted_at FROM ilearning_pdf_submissions WHERE topic_id = ? AND student_id = ?');
    $s_stmt->execute([$topic_id, $student_id]);
    $submission = $s_stmt->fetch() ?: null;

    if ($submission) {
        $submission_annotated_html = ilearning_render_annotated_text(
            $submission['answer_text'],
            ilearning_fetch_annotations($pdo, 'pdf_submission', (int) $submission['id'])
        );

        if ($topic['assessment_id'] !== null) {
            $grade_stmt = $pdo->prepare(
                'SELECT marks FROM student_marks WHERE school_id = ? AND student_id = ? AND subject_id = ? AND assessment_id = ? AND paper_number = 1'
            );
            $grade_stmt->execute([$school_id, $student_id, $topic['subject_id'], $topic['assessment_id']]);
            $mark = $grade_stmt->fetchColumn();
            $pdf_grade = $mark !== false ? (int) $mark : null;
        }
    }
}

$hasAssessment = $topic['assessment_id'] !== null;
$existingAttempt = null;
if ($hasAssessment) {
    $a_stmt = $pdo->prepare("SELECT id, status FROM ilearning_attempts WHERE student_id = ? AND topic_id = ? AND pool_type='topic_assessment' ORDER BY id DESC LIMIT 1");
    $a_stmt->execute([$student_id, $topic_id]);
    $existingAttempt = $a_stmt->fetch();
}

$stu2_stmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ? AND school_id = ?');
$stu2_stmt->execute([$student_id, $school_id]);
$student = $stu2_stmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../elections/_election_helpers.php';
$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.student_id = ? AND cv.school_id = ? AND cm.sender_role = 'teacher' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$student_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

// The pdf_activity split view (PDF + answer pane side by side) wants more
// horizontal room than the shell's default page width.
$STUDENT_WIDE_PAGE = $topic['content_type'] === 'pdf_activity';

$ACTIVE_NAV = 'ilearning';
require_once __DIR__ . '/../_student_shell.php';
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.meta{color:var(--muted);font-size:0.8rem;margin-bottom:20px;}
.body-content{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:26px;line-height:1.7;white-space:pre-wrap;}
ul.attachments{list-style:none;padding:0;margin:16px 0 0;}
ul.attachments li{padding:10px 14px;background:var(--panel);border:1px solid var(--border);border-radius:6px;margin-bottom:8px;}
ul.attachments a{color:var(--cyan);text-decoration:none;}
.cta{margin-top:24px;text-align:center;}
.cta a{display:inline-block;background:var(--cyan);color:#04121a;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:8px;}
.cta.done a{background:var(--green);}
.pdf-pane{max-height:78vh;overflow-y:auto;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.download-row{margin:16px 0;text-align:center;}
.download-row a{display:inline-block;background:var(--green);color:#04221a;text-decoration:none;font-weight:700;padding:10px 22px;border-radius:8px;font-size:0.85rem;}
.split{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start;}
@media (max-width:860px){.split{grid-template-columns:1fr;}}
.answer-pane{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;position:sticky;top:20px;}
.answer-pane textarea{width:100%;min-height:320px;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:12px;border-radius:6px;font-size:0.9rem;font-family:inherit;resize:vertical;box-sizing:border-box;}
.answer-pane button{margin-top:12px;cursor:pointer;border:none;border-radius:6px;padding:11px 22px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.answer-pane button:disabled{opacity:0.6;cursor:not-allowed;}
.submitted-note{color:var(--muted);font-size:0.78rem;margin-top:10px;}
.submit-error{color:#ef4444;font-size:0.8rem;margin-top:10px;}

.result-card-status{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.result-badge{font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;padding:4px 12px;border-radius:20px;}
.result-badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b;}
.result-badge.graded{background:rgba(16,185,129,0.15);color:var(--green);}
.result-score{font-size:1.4rem;font-weight:900;color:var(--green);}
.result-answer{background:#111826;border:1px solid var(--border);border-radius:6px;padding:14px;white-space:pre-wrap;line-height:1.6;font-size:0.9rem;}
.result-timestamp{color:var(--muted);font-size:0.75rem;margin-top:10px;}

<?php include __DIR__ . '/_ilearning_annotate_style.php'; ?>
<?php include __DIR__ . '/../_rich_toolbar_style.php'; ?>
/* Read-only on the student side (no JS listener attached here, this is
   just the colored highlight + a pure-CSS hover tooltip for the
   comment) -- .ilearn-annotatable's own cursor/interaction styling would
   be misleading here since nothing responds to a click or selection. */
.result-answer .ilearn-mark { cursor: default; position: relative; }
.result-answer .ilearn-mark[data-comment]:not([data-comment=""]):hover::after {
    content: attr(data-comment);
    position: absolute;
    left: 0;
    bottom: 100%;
    margin-bottom: 6px;
    background: #0b0d12;
    color: #e2e8f0;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 0.75rem;
    white-space: normal;
    width: max-content;
    max-width: 240px;
    z-index: 10;
    box-shadow: 0 6px 16px rgba(0,0,0,0.4);
}
</style>
<div class="page-title-row">
    <h1><?= htmlspecialchars($topic['title'], ENT_QUOTES, 'UTF-8') ?></h1>
</div>
<div class="meta"><?= htmlspecialchars($topic['subject_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($topic['term'] . ' ' . $topic['year'], ENT_QUOTES, 'UTF-8') ?></div>

    <?php if ($topic['content_type'] === 'written'): ?>

        <div class="body-content" id="topicBody"><?= scholar_render_rich_text($topic['body']) ?></div>

        <?php if ($attachments): ?>
        <ul class="attachments">
            <?php foreach ($attachments as $att): ?>
            <li><a href="../<?= htmlspecialchars($att['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars($att['original_name'], ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($hasAssessment): ?>
            <div class="cta <?= ($existingAttempt && $existingAttempt['status'] !== 'in_progress') ? 'done' : '' ?>">
                <?php if ($existingAttempt && $existingAttempt['status'] === 'graded'): ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">View Your Graded Result</a>
                <?php elseif ($existingAttempt && $existingAttempt['status'] === 'submitted'): ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">Submitted — Awaiting Teacher Grading</a>
                <?php else: ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">Take the Assessment →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($topic['content_type'] === 'pdf_notes'): ?>

        <?php if (trim($topic['body']) !== ''): ?>
            <div class="body-content" style="margin-bottom:20px;"><?= scholar_render_rich_text($topic['body']) ?></div>
        <?php endif; ?>

        <?php if ($primary_pdf): ?>
            <div class="pdf-pane" id="pdfContainer"></div>
            <div class="download-row">
                <a href="serve_pdf.php?attachment_id=<?= (int) $primary_pdf['id'] ?>&download=1">⬇ Download PDF</a>
            </div>
        <?php else: ?>
            <p style="color:var(--muted);">No PDF has been uploaded for these notes yet.</p>
        <?php endif; ?>

        <?php if ($hasAssessment): ?>
            <div class="cta <?= ($existingAttempt && $existingAttempt['status'] !== 'in_progress') ? 'done' : '' ?>">
                <?php if ($existingAttempt && $existingAttempt['status'] === 'graded'): ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">View Your Graded Result</a>
                <?php elseif ($existingAttempt && $existingAttempt['status'] === 'submitted'): ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">Submitted — Awaiting Teacher Grading</a>
                <?php else: ?>
                    <a href="take_assessment.php?topic_id=<?= $topic_id ?>">Take the Assessment →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($topic['content_type'] === 'pdf_activity'): ?>

        <?php if (trim($topic['body']) !== ''): ?>
            <div class="body-content" style="margin-bottom:20px;"><?= scholar_render_rich_text($topic['body']) ?></div>
        <?php endif; ?>

        <?php if ($primary_pdf): ?>
        <div class="split">
            <div class="pdf-pane" id="pdfContainer"></div>
            <div class="answer-pane" id="answerPane">
                <?php if ($submission === null): ?>
                    <form id="pdfAnswerForm">
                        <input type="hidden" name="topic_id" value="<?= (int) $topic_id ?>">
                        <label style="display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Your Answer</label>
                        <textarea name="answer_text" id="answerText" class="rt-editable" required placeholder="Type your answer here..."></textarea>
                        <button type="submit" id="submitAnswerBtn">Submit Answer</button>
                        <div class="submit-error" id="submitError" style="display:none;"></div>
                    </form>
                <?php elseif ($pdf_grade === null): ?>
                    <div class="result-card-status">
                        <span class="result-badge pending">Submitted</span>
                    </div>
                    <div class="result-answer"><?= $submission_annotated_html ?></div>
                    <div class="result-timestamp">Submitted <?= htmlspecialchars(date('d M Y, H:i', strtotime($submission['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>. Awaiting your teacher's grade.</div>
                <?php else: ?>
                    <div class="result-card-status">
                        <span class="result-badge graded">Graded</span>
                        <span class="result-score"><?= (int) $pdf_grade ?>%</span>
                    </div>
                    <div class="result-answer"><?= $submission_annotated_html ?></div>
                    <div class="result-timestamp">Submitted <?= htmlspecialchars(date('d M Y, H:i', strtotime($submission['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <p style="color:var(--muted);">No PDF has been uploaded for this activity yet.</p>
        <?php endif; ?>

        <?php if ($submission === null && $primary_pdf): ?>
        <script src="../assets/js/rich-toolbar.js"></script>
        <script>
        (function () {
            "use strict";
            var form = document.getElementById('pdfAnswerForm');
            var btn = document.getElementById('submitAnswerBtn');
            var errEl = document.getElementById('submitError');
            var pane = document.getElementById('answerPane');

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                errEl.style.display = 'none';
                btn.disabled = true;
                btn.textContent = 'Submitting...';

                var answerValue = document.getElementById('answerText').value;
                var body = new URLSearchParams();
                body.set('topic_id', form.topic_id.value);
                body.set('answer_text', answerValue);

                fetch('save_pdf_submission.php', { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            // Plain textContent, not the annotated HTML render -- a
                            // brand new submission has no marks on it yet, and won't
                            // until a teacher opens it in topic_roster.php. A full
                            // reload later (once graded) picks up the real
                            // server-rendered annotated version.
                            pane.innerHTML =
                                '<div class="result-card-status"><span class="result-badge pending">Submitted</span></div>' +
                                '<div class="result-answer"></div>' +
                                '<div class="result-timestamp">Submitted just now. Awaiting your teacher\'s grade.</div>';
                            pane.querySelector('.result-answer').textContent = answerValue;
                        } else {
                            errEl.textContent = data.error || 'Something went wrong -- try again.';
                            errEl.style.display = 'block';
                            btn.disabled = false;
                            btn.textContent = 'Submit Answer';
                        }
                    })
                    .catch(function () {
                        errEl.textContent = 'Could not reach the server -- check your connection and try again.';
                        errEl.style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Submit Answer';
                    });
            });
        })();
        </script>
        <?php endif; ?>

    <?php endif; ?>

<?php if ($topic['content_type'] !== 'written' && $primary_pdf): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script src="pdf_viewer.js"></script>
<script>
scholarRenderPdf({
    pdfUrl: 'serve_pdf.php?attachment_id=<?= (int) $primary_pdf['id'] ?>',
    containerEl: document.getElementById('pdfContainer'),
    topicId: <?= (int) $topic_id ?>,
    disableRightClick: <?= $topic['content_type'] === 'pdf_activity' ? 'true' : 'false' ?>
});
</script>
<?php endif; ?>

<?php if ($topic['content_type'] === 'written'): ?>
<script>
(function () {
    "use strict";
    var topicId = <?= (int) $topic_id ?>;
    var pendingSeconds = 0;

    function computePercent() {
        var doc = document.documentElement;
        var scrollable = doc.scrollHeight - doc.clientHeight;
        if (scrollable <= 0) return 100;
        var percent = Math.round(((window.scrollY) / scrollable) * 100);
        return Math.max(0, Math.min(100, percent));
    }

    function sendPing(useBeacon) {
        var payload = JSON.stringify({
            topic_id: topicId,
            percent: computePercent(),
            delta_seconds: pendingSeconds,
        });
        pendingSeconds = 0;
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('progress_ping.php', new Blob([payload], { type: 'application/json' }));
        } else {
            fetch('progress_ping.php', { method: 'POST', body: payload, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } }).catch(function () {});
        }
    }

    // Initial "opened" ping.
    sendPing(false);

    setInterval(function () {
        if (document.visibilityState === 'visible') {
            pendingSeconds += 20;
            sendPing(false);
        }
    }, 20000);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            sendPing(true);
        }
    });
    window.addEventListener('pagehide', function () {
        sendPing(true);
    });
})();
</script>
<?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
