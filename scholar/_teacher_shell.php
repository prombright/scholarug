<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER PORTAL SHELL
|--------------------------------------------------------------------------
| Mirrors _student_shell.php -- shared topbar + sidebar for every teacher
| page EXCEPT the landing (teachers_portal.php, a pure card grid, no
| sidebar -- sidebar only appears once you've opened a specific feature).
|
| The including page must set before requiring this file:
|   $ACTIVE_NAV       -- one of the keys in $TEACHER_NAV_ITEMS below
|   $__badge_url       -- school badge image URL, or null
|   $__school_brand    -- ['school_name' => ...] fetched by the caller
| Optional (default to falsy if unset):
|   $unread_message_count
|   $is_any_class_teacher -- shows the three class-teacher-only nav items
|--------------------------------------------------------------------------
*/

$unread_message_count = $unread_message_count ?? 0;
$is_any_class_teacher = $is_any_class_teacher ?? false;

// Absolute-from-domain-root -- see _student_shell.php's identical comment.
$__SB = rtrim(SCHOLAR_BASE, '/');

$TEACHER_NAV_ITEMS = [
    'dashboard'  => ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'href' => "$__SB/app_teacher.php"],
    'marks'      => ['label' => 'Marks Entry', 'icon' => 'bi-pencil-square', 'href' => "$__SB/teacher_marks_entry.php"],
    'analytics'  => ['label' => 'Performance Analytics', 'icon' => 'bi-bar-chart-line', 'href' => "$__SB/performance_analytics.php"],
    'timetable'  => ['label' => 'My Timetable', 'icon' => 'bi-calendar3', 'href' => "$__SB/my_timetable.php"],
    'attendance' => ['label' => 'Roll Call', 'icon' => 'bi-calendar-check', 'href' => "$__SB/teacher_attendance.php"],
    'library'    => ['label' => 'Library', 'icon' => 'bi-book', 'href' => "$__SB/library/manage.php"],
    'messages'   => ['label' => 'Messages', 'icon' => 'bi-chat-dots', 'href' => "$__SB/teacher_messages.php", 'badge' => $unread_message_count],
];
if ($is_any_class_teacher) {
    $TEACHER_NAV_ITEMS['bulk_print'] = ['label' => "Print Class Reports", 'icon' => 'bi-printer', 'href' => "$__SB/school_admin/bulk_report_print.php"];
    $TEACHER_NAV_ITEMS['generic_skills'] = ['label' => 'Generic Skills', 'icon' => 'bi-star', 'href' => "$__SB/generic_skills_entry.php"];
    $TEACHER_NAV_ITEMS['class_logins'] = ['label' => "Students' Logins", 'icon' => 'bi-key', 'href' => "$__SB/teacher_class_logins.php"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Portal<?= isset($TEACHER_NAV_ITEMS[$ACTIVE_NAV]) ? ' — ' . htmlspecialchars($TEACHER_NAV_ITEMS[$ACTIVE_NAV]['label'], ENT_QUOTES, 'UTF-8') : '' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.app-shell{display:flex;min-height:100vh;}
.sidebar{width:230px;background:var(--panel);border-right:1px solid var(--border);flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;}
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
.page-inner{padding:28px;width:100%;margin:0;}
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
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="sidebar-backdrop" id="teacherSidebarBackdrop" onclick="document.getElementById('teacherSidebar').classList.remove('open');this.classList.remove('open');"></div>
<div class="app-shell">
    <aside class="sidebar" id="teacherSidebar">
        <div class="sidebar-brand">
            <?php if (!empty($__badge_url)): ?>
                <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="">
            <?php else: ?>
                <div class="fallback"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <div class="name"><?= htmlspecialchars($__school_brand['school_name'] ?? 'Scholar', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($TEACHER_NAV_ITEMS as $key => $item): ?>
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
            <button class="mobile-nav-toggle" onclick="document.getElementById('teacherSidebar').classList.toggle('open');document.getElementById('teacherSidebarBackdrop').classList.toggle('open');"><i class="bi bi-list"></i></button>
            <a class="scholar-logout-btn" href="<?= $__SB ?>/logout.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
        <div class="page-inner">
