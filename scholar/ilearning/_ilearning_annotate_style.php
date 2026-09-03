<?php /* Shared CSS for the text-marking tool (assets/js/ilearning-annotate.js)
-- included (inside a <style> tag) by both topic_roster.php (PDF-activity
answers) and grade_open_answers.php (short-answer answers), so the two
call sites can't silently drift the way generate_report.php and
bulk_report_print.php used to before _report_card_style.php existed. */ ?>
.ilearn-annotatable { white-space: pre-wrap; line-height: 1.6; cursor: text; }

.ilearn-mark { border-radius: 3px; padding: 1px 2px; cursor: pointer; }
.ilearn-mark-correct { background: rgba(16,185,129,0.28); border-bottom: 2px solid #10b981; }
.ilearn-mark-incorrect { background: rgba(239,68,68,0.28); border-bottom: 2px solid #ef4444; }
.ilearn-mark-note { background: rgba(245,158,11,0.28); border-bottom: 2px solid #f59e0b; }

.ilearn-mark-popup {
    position: absolute;
    z-index: 4000;
    background: #131b28;
    border: 1px solid #2a3a52;
    border-radius: 8px;
    padding: 10px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);
    font-size: 0.8rem;
    max-width: 260px;
}
.ilearn-mark-types { display: flex; gap: 6px; margin-bottom: 8px; }
.ilearn-mark-types button {
    flex: 1;
    background: #111826;
    border: 1px solid #2a3a52;
    color: #e2e8f0;
    border-radius: 6px;
    padding: 6px 0;
    cursor: pointer;
    font-size: 0.9rem;
}
.ilearn-mark-types button[data-type="correct"].active { background: rgba(16,185,129,0.25); border-color: #10b981; color: #10b981; }
.ilearn-mark-types button[data-type="incorrect"].active { background: rgba(239,68,68,0.25); border-color: #ef4444; color: #ef4444; }
.ilearn-mark-types button[data-type="note"].active { background: rgba(245,158,11,0.25); border-color: #f59e0b; color: #f59e0b; }

.ilearn-mark-comment {
    width: 100%;
    box-sizing: border-box;
    background: #111826;
    border: 1px solid #2a3a52;
    color: #e2e8f0;
    border-radius: 6px;
    padding: 7px 8px;
    font-size: 0.8rem;
    margin-bottom: 8px;
}
.ilearn-mark-actions { display: flex; gap: 6px; justify-content: flex-end; }
.ilearn-mark-actions button {
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
}
.ilearn-mark-save { background: #00A8A8; color: #04121a; }
.ilearn-mark-save:disabled { opacity: 0.4; cursor: not-allowed; }
.ilearn-mark-cancel { background: transparent; color: #64748b; border: 1px solid #2a3a52 !important; }
.ilearn-mark-delete { background: rgba(239,68,68,0.15); color: #ef4444; }

.ilearn-comment-bubble .ilearn-comment-text { margin-bottom: 8px; color: #e2e8f0; line-height: 1.4; }
.ilearn-comment-bubble .ilearn-comment-empty { color: #64748b; font-style: italic; }