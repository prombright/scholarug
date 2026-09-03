<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT PORTAL SHELL
|--------------------------------------------------------------------------
| Shared topbar + sidebar for every student page EXCEPT the landing
| (student_portal.php, which is deliberately just a card grid with no
| sidebar -- the sidebar only appears once you've opened a specific
| feature, per the "cards first, sidebar once inside" pattern).
|
| The including page must set before requiring this file:
|   $ACTIVE_NAV            -- one of the keys in $STUDENT_NAV_ITEMS below
|   $student                -- the students row (for name/class display)
|   $__badge_url            -- school badge image URL, or null
|   $__school_brand         -- ['school_name' => ...] fetched by the caller
| Optional (default to 0 if unset):
|   $unread_message_count
|   $open_positions_to_vote
|
| After requiring this file, the page prints its own content, then closes
| with the matching </div></main></div></body></html> (see
| student_results.php for the reference pattern).
|--------------------------------------------------------------------------
*/

$unread_message_count = $unread_message_count ?? 0;
$open_positions_to_vote = $open_positions_to_vote ?? 0;

// Absolute-from-domain-root (SCHOLAR_BASE, e.g. "/ABNsystems/scholar") --
// this shell is included from both scholar/*.php AND subdirectory pages
// like scholar/elections/ballot.php and scholar/library/student_library.php,
// so a bare relative href ("student_portal.php") would resolve wrong
// depending on which directory the including page lives in.
$__SB = rtrim(SCHOLAR_BASE, '/');

$STUDENT_NAV_ITEMS = [
    'dashboard'  => ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'href' => "$__SB/student_portal.php"],
    'pilot'      => ['label' => 'Student Portal (Vue pilot)', 'icon' => 'bi-lightning-charge', 'href' => "$__SB/app_student.php"],
    'results'    => ['label' => 'My Results', 'icon' => 'bi-mortarboard', 'href' => "$__SB/student_results.php"],
    'fees'       => ['label' => 'Fees', 'icon' => 'bi-cash-coin', 'href' => "$__SB/student_fees.php"],
    'attendance' => ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'href' => "$__SB/student_attendance.php"],
    'library'    => ['label' => 'Library', 'icon' => 'bi-book', 'href' => "$__SB/library/student_library.php"],
    'elections'  => ['label' => 'Elections', 'icon' => 'bi-check2-square', 'href' => "$__SB/elections/ballot.php", 'badge' => $open_positions_to_vote],
    'messages'   => ['label' => 'Messages', 'icon' => 'bi-chat-dots', 'href' => "$__SB/student_messages.php", 'badge' => $unread_message_count],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Portal<?= isset($STUDENT_NAV_ITEMS[$ACTIVE_NAV]) ? ' — ' . htmlspecialchars($STUDENT_NAV_ITEMS[$ACTIVE_NAV]['label'], ENT_QUOTES, 'UTF-8') : '' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.app-shell{display:flex;min-height:100vh;}
.sidebar{width:230px;background:var(--panel);border-right:1px solid var(--border);flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;}
.sidebar-brand{display:flex;align-items:center;gap:10px;padding:20px 18px;border-bottom:1px solid var(--border);}
.sidebar-brand img,.sidebar-brand .fallback{width:34px;height:34px;border-radius:8px;object-fit:contain;flex-shrink:0;}
.sidebar-brand .fallback{background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;}
.sidebar-brand .name{font-size:0.85rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sidebar-nav{padding:14px 10px;display:flex;flex-direction:column;gap:2px;}
.sidebar-nav a{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:0.86rem;font-weight:500;transition:background .15s,color .15s;}
.sidebar-nav a:hover{background:rgba(255,255,255,0.04);color:var(--text);}
.sidebar-nav a.active{background:rgba(0,168,168,0.12);color:var(--cyan);font-weight:700;}
.sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;}
.sidebar-nav .badge{margin-left:auto;background:var(--cyan);color:#04222a;font-size:0.68rem;font-weight:800;padding:1px 7px;border-radius:20px;}
.main-col{flex:1;min-width:0;display:flex;flex-direction:column;}
.topbar{display:flex;justify-content:flex-end;align-items:center;padding:16px 28px;border-bottom:1px solid var(--border);}
.page-inner{padding:28px;max-width:960px;width:100%;margin:0;}
/* A page can opt into a wider layout (e.g. a two-column PDF+answer split)
   by setting $STUDENT_WIDE_PAGE = true before requiring this shell -- see
   ilearning/view_topic.php's pdf_activity case for the one page that needs it. */
body.wide-page .page-inner{max-width:1200px;}
/* Collapses to a slim icon rail by default, expanding on hover to reveal
   labels -- gives the page more room when the sidebar isn't actively being
   used to navigate, without losing the full labeled nav the moment it's
   needed. Desktop-only (min-width guard): hover has no meaning on a touch
   screen, where the sidebar already has its own off-canvas open/close
   toggle below -- this doesn't touch that behavior at all. */
@media(min-width:861px){
    .sidebar{width:76px;border-radius:0 18px 18px 0;overflow:hidden;transition:width .22s ease;z-index:40;}
    .sidebar:hover{width:230px;}
    .sidebar-brand .name{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
    .sidebar:hover .sidebar-brand .name{max-width:160px;opacity:1;transition-delay:.05s;}
    .sidebar-nav a span{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
    .sidebar:hover .sidebar-nav a span{max-width:160px;opacity:1;transition-delay:.05s;}
}
@media(max-width:860px){
    .sidebar{position:fixed;left:-230px;z-index:50;transition:left .2s;}
    .sidebar.open{left:0;}
    .mobile-nav-toggle{display:inline-flex;}
    /* Dims + blurs the page behind the sidebar while it's open on mobile,
       so the open menu reads as the thing in focus -- also gives a
       click-outside-to-close target, since there's otherwise no way back
       out of the sidebar except re-tapping the same small toggle button. */
    .sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(4,5,7,0.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:45;}
    .sidebar-backdrop.open{display:block;}
}
.mobile-nav-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:1.1rem;cursor:pointer;margin-right:auto;}
</style>
</head>
<body<?= !empty($STUDENT_WIDE_PAGE) ? ' class="wide-page"' : '' ?>>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="sidebar-backdrop" id="studentSidebarBackdrop" onclick="document.getElementById('studentSidebar').classList.remove('open');this.classList.remove('open');"></div>
<div class="app-shell">
    <aside class="sidebar" id="studentSidebar">
        <div class="sidebar-brand">
            <?php if (!empty($__badge_url)): ?>
                <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="">
            <?php else: ?>
                <div class="fallback"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <div class="name"><?= htmlspecialchars($student['full_name'] ?? 'Student', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($STUDENT_NAV_ITEMS as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="<?= $ACTIVE_NAV === $key ? 'active' : '' ?>">
                    <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($item['badge'])): ?><span class="badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="main-col">
        <div class="topbar">
            <button class="mobile-nav-toggle" onclick="document.getElementById('studentSidebar').classList.toggle('open');document.getElementById('studentSidebarBackdrop').classList.toggle('open');"><i class="bi bi-list"></i></button>
            <a class="scholar-logout-btn" href="<?= $__SB ?>/logout.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
        <div class="page-inner">
