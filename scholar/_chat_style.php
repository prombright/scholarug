<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — CHAT STYLE
|--------------------------------------------------------------------------
| Shared by student_messages.php and teacher_messages.php -- meant to be
| require'd from inside a <style> block. WhatsApp-style conventions:
| avatar-led thread rows, tailed bubbles anchored to opposite sides,
| bottom-pinned pill composer with a round send button.
|--------------------------------------------------------------------------
*/
?>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.alert-success{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(16,185,129,0.12);color:var(--green,#10b981);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;}

.avatar{
    flex-shrink:0;width:40px;height:40px;border-radius:50%;
    background:linear-gradient(135deg,var(--cyan),#0a7d7d);
    color:#04222a;font-weight:700;font-size:0.85rem;
    display:flex;align-items:center;justify-content:center;
}

.chat-layout{display:grid;grid-template-columns:280px 1fr;gap:0;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;height:min(640px,75vh);}
@media(max-width:700px){.chat-layout{grid-template-columns:1fr;height:auto;}}

.chat-sidebar{border-right:1px solid var(--border);overflow-y:auto;}
.thread-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);color:var(--text);text-decoration:none;}
.thread-row:last-child{border-bottom:none;}
.thread-row.active,.thread-row:hover{background:rgba(0,168,168,0.08);}
.thread-row-body{flex:1;min-width:0;}
.thread-row-top{display:flex;align-items:baseline;justify-content:space-between;gap:8px;}
.thread-row-name{font-size:0.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.thread-row-time{font-size:0.7rem;color:var(--muted);flex-shrink:0;}
.thread-row .unread{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;background:var(--cyan);color:#04222a;font-size:0.7rem;font-weight:700;border-radius:20px;}

.chat-main{display:flex;flex-direction:column;min-height:0;}
.chat-placeholder{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:10px;}
.chat-placeholder i{font-size:2.5rem;opacity:0.4;}
.chat-placeholder p{font-size:0.85rem;margin:0;}

.chat-header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);}
.chat-header-name{font-weight:600;font-size:0.92rem;}

.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:2px;background:
    radial-gradient(circle at 20% 20%, rgba(0,168,168,0.03) 0, transparent 40%),
    radial-gradient(circle at 80% 80%, rgba(0,168,168,0.03) 0, transparent 40%);}

.msg-row{display:flex;margin:4px 0;}
.msg-row.out{justify-content:flex-end;}
.msg-row.in{justify-content:flex-start;}

.msg{
    position:relative;max-width:72%;padding:8px 12px 18px;border-radius:12px;
    font-size:0.88rem;line-height:1.4;word-wrap:break-word;
}
.msg-row.out .msg{background:var(--cyan);color:#04222a;border-bottom-right-radius:3px;}
.msg-row.in .msg{background:#1c2536;color:var(--text);border-bottom-left-radius:3px;}

.msg .meta{position:absolute;right:10px;bottom:4px;font-size:0.65rem;opacity:0.65;white-space:nowrap;}

.chat-composer{display:flex;align-items:flex-end;gap:10px;padding:12px 14px;border-top:1px solid var(--border);}
.chat-composer textarea{
    flex:1;resize:none;max-height:120px;background:var(--bg,#0b0d12);border:1px solid var(--border);
    color:var(--text);border-radius:22px;padding:11px 18px;font-family:inherit;font-size:0.88rem;line-height:1.3;
}
.chat-composer textarea:focus{outline:none;border-color:var(--cyan);}
.chat-composer button{
    flex-shrink:0;width:42px;height:42px;border-radius:50%;border:none;background:var(--cyan);
    color:#04121a;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
}
.chat-composer button:hover{background:#0a7d7d;color:#fff;}
