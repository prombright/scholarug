<?php ob_start();
// declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/config.php'; // defines SCHOLAR_BASE in one place
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

$SCHOLAR_BASE = SCHOLAR_BASE;
$ACTIVE_NAV   = $ACTIVE_NAV ?? '';
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Read the school's name/logo fresh from the database on every load
// instead of $_SESSION['school_name'] (set once at login) -- otherwise
// a name or logo change in settings.php never shows up here until the
// admin logs out and back in, even though the change did save correctly.
$__schoolStmt = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__schoolStmt->execute([current_school_id()]);
$__school = $__schoolStmt->fetch() ?: [];
$SCHOOL_NAME = $__school['school_name'] ?? ($_SESSION['school_name'] ?? 'Scholar');
$SCHOOL_BADGE = $__school['school_badge'] ?? null;
if ($SCHOOL_BADGE && !file_exists(__DIR__ . '/' . $SCHOOL_BADGE)) {
    $SCHOOL_BADGE = null;
}


/*
|--------------------------------------------------------------------------
| AUTH FUNCTIONS
|--------------------------------------------------------------------------
*/

// function require_role($roles): void
// {
//     if (!isset($_SESSION['user_role'])) {
//         header("Location: scholar/login.php");
//         exit();
//     }

//     $roles = is_array($roles) ? $roles : [$roles];

//     if (!in_array($_SESSION['user_role'], $roles, true)) {
//         header("Location: /ABNSystems/scholar/unauthorized.php");
//         exit();
//     }
// }

// function current_school_id(): int
// {
//     return (int)($_SESSION['school_id'] ?? 0);
// }

/*
|--------------------------------------------------------------------------
| DEFAULT VALUES
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| SIDEBAR MENU
|--------------------------------------------------------------------------
*/

// Grouped into dropdown categories (Candidate A) so fewer top-level tabs
// show at once -- only Home, Projects, and School Settings stay
// standalone. "Report Cards" is grading_scales.php, which now carries a
// horizontal tab bar (Grading & Bands / Display Settings / Print Reports)
// linking out to report_settings.php and bulk_report_print.php -- one
// nav entry for what's really three related pages, not three separate
// sidebar links.
$nav_items = [
    'home' => [
        'label' => 'Home',
        'href'  => 'app_admin.php'
    ],

    // Same treatment as 'report_cards' below: Staff / Leave Management /
    // Payroll used to be three separate dropdown entries -- now one entry
    // pointing at hr_dashboard.php (already existed as a stat-card hub
    // linking to all three, just wasn't the sidebar's actual entry point),
    // with a horizontal tab bar on each of the three pages themselves for
    // moving between them without coming back here.
    'hr' => [
        'label' => 'Human Resources',
        'href'  => 'app_hr.php'
    ],

    'academics' => [
        'label' => 'Academics',
        'children' => [
            'students' => [
                'label' => 'Students',
                'href'  => 'school_admin/students.php'
            ],
            'subjects' => [
                'label' => 'Subjects',
                'href'  => 'school_admin/subject_catalog.php'
            ],
            'assessments' => [
                'label' => 'Assessments',
                'href'  => 'school_admin/assessments.php'
            ],
            'report_cards' => [
                'label' => 'Report Cards',
                'href'  => 'school_admin/grading_scales.php'
            ],
            'classes' => [
                'label' => 'Classes',
                'href'  => 'school_admin/classes.php'
            ],
            'teacher_submissions' => [
                'label' => 'Teacher Submissions',
                'href'  => 'school_admin/teacher_submissions.php'
            ],
            'assignments' => [
                'label' => 'Assignments',
                'href'  => 'school_admin/assign_teacher.php'
            ],
            'attendance' => [
                'label' => 'Attendance',
                'href'  => 'school_admin/attendance.php'
            ],
            'library' => [
                'label' => 'Library',
                'href'  => 'library/admin_overview.php'
            ],
            'timetable' => [
                'label' => 'Timetable',
                'href'  => 'school_admin/timetable_setup.php'
            ],
        ],
    ],

    'students_family' => [
        'label' => 'Students & Family',
        'children' => [
            'parents' => [
                'label' => 'Parent Accounts',
                'href'  => 'school_admin/manage_parents.php'
            ],
            'elections' => [
                'label' => 'Student Elections',
                'href'  => 'elections/index.php'
            ],
        ],
    ],

    'projects' => [
        'label' => 'Projects',
        'href'  => 'school_admin/projects.php'
    ],

    'finance' => [
        'label' => 'Finance',
        'children' => [
            'fees' => [
                'label' => 'Fees',
                'href'  => 'school_admin/fees.php'
            ],
        ],
    ],

    'settings' => [
        'label' => 'School Settings',
        'href'  => 'settings.php'
    ],

    'contact_dev' => [
        'label' => 'Contact Developer',
        'href'  => 'school_admin/contact_developer.php'
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scholar Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>

/* Native cross-document View Transition: unsupported browsers (Firefox as
   of this writing) simply ignore this at-rule and keep a plain navigation
   -- pure progressive enhancement, no fallback code needed. Supported
   browsers (Chromium/Safari) cross-fade the old page into the new one
   instead of a hard white-flash reload, which is what gives every sidebar
   click a "still on the same page" feel without building a fragile
   fetch/innerHTML fragment-swap system. */
@view-transition {
    navigation: auto;
}

:root{
    --bg:#080b11;
    --panel:#131b28;
    --border:#2a3a52;
    --text:#e2e8f0;
    --muted:#64748b;
    --cyan:#00A8A8;
    --danger:#ef4444;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    background:var(--bg);
    color:var(--text);
    font-family:Inter,"Segoe UI",sans-serif;
}

.app-shell{
    display:flex;
    min-height:100vh;
    gap:16px;
    padding:16px;
    /* Was a hardcoded #05070b -- the one thing every school_admin page
       wraps its whole layout in, so this alone was why the gutter around
       every panel stayed dark no matter how many per-page hardcoded
       colors got fixed. var(--bg) is what body already correctly uses;
       _teacher_shell.php/_student_shell.php/_hr_shell.php never set a
       background here at all, letting it inherit from body the same way. */
    background:var(--bg);
}

.sidebar{
    width:230px;
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:16px;
    display:flex;
    flex-direction:column;
    position:sticky;
    top:16px;
    height:calc(100vh - 32px);
    overflow:hidden;
    view-transition-name: app-shell-frame;
}

/* Rounded "profile card" at the top of the sidebar -- circular badge,
   name/role stacked and centered underneath, echoing the reference
   dashboard's avatar-card treatment instead of a plain left-aligned
   header row. */
.sidebar-brand{
    padding:24px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    flex-direction:column;
    align-items:center;
    text-align:center;
    gap:10px;
}

.sidebar-brand .badge{
    width:56px;
    height:56px;
    border-radius:50%;
    flex-shrink:0;
    object-fit:cover;
    background:var(--panel);
    border:1px solid var(--border);
}

.sidebar-brand .badge-fallback{
    width:56px;
    height:56px;
    border-radius:50%;
    flex-shrink:0;
    background:var(--cyan);
    color:#04222a;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    font-size:22px;
}

.sidebar-brand .text{
    min-width:0;
}

.sidebar-brand .name{
    font-size:15px;
    font-weight:700;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.sidebar-brand .tag{
    color:var(--muted);
    font-size:12px;
    margin-top:3px;
}

.sidebar-nav{
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:15px 10px;
}

.sidebar-nav a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    color:var(--muted);
    text-decoration:none;
    border-radius:8px;
    font-size:14px;
    font-weight:600;
    margin-bottom:4px;
}

.sidebar-nav a:hover{
    background:var(--panel);
    color:var(--text);
}

.sidebar-nav a.active{
    background:rgba(0,168,168,.15);
    color:var(--cyan);
}

.sidebar-nav a.disabled{
    opacity:.45;
    pointer-events:none;
}

.soon{
    margin-left:auto;
    font-size:10px;
    background:var(--border);
    padding:2px 6px;
    border-radius:20px;
}

.nav-group{
    margin-bottom:4px;
}

.nav-group summary{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    color:var(--muted);
    text-decoration:none;
    border-radius:8px;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    list-style:none;
}

.nav-group summary::-webkit-details-marker{
    display:none;
}

.nav-group summary::before{
    content:'\25B8';
    font-size:11px;
    transition:transform .15s ease;
}

.nav-group[open] summary::before{
    transform:rotate(90deg);
}

.nav-group summary:hover{
    background:var(--panel);
    color:var(--text);
}

.nav-group nav{
    display:flex;
    flex-direction:column;
    padding-left:16px;
}

.nav-group nav a{
    font-size:13px;
    padding:10px 12px;
}

.sidebar-foot{
    padding:20px;
    border-top:1px solid var(--border);
}

.sidebar-foot a{
    color:var(--danger);
    text-decoration:none;
    font-weight:bold;
}

.main-content{
    flex:1;
    overflow:auto;
    min-width:0;
}

.page-inner{
    padding:30px;
}

.app-topbar{
    display:none;
    align-items:center;
    gap:14px;
    padding:14px 18px;
    border-bottom:1px solid var(--border);
    background:var(--panel);
    position:sticky;
    top:0;
    z-index:15;
}

.app-nav-toggle{
    width:38px;
    height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    border:1px solid var(--border);
    background:var(--panel);
    color:var(--text);
    font-size:18px;
    cursor:pointer;
}

.app-topbar .name{
    font-size:15px;
    font-weight:700;
}

table{
    display:block;
    overflow-x:auto;
}

/* Collapses to a slim rail by default, expanding on hover to reveal full
   labels -- gives the page more room when the sidebar isn't actively being
   used to navigate. This sidebar's nav items have no icons (unlike the
   teacher/student/HR shells), so collapsing all the way to icon-only would
   leave an unreadable blank rail -- truncated/ellipsized text instead,
   which stays at least partially readable, then expands to the full label
   on hover. Nested nav-group sub-items hide entirely while collapsed
   (nothing useful to show them in ~90px anyway) and reappear with the
   group's own [open] state once expanded. Desktop-only: hover has no
   meaning on the touch/off-canvas mobile sidebar below. */
@media (min-width:861px){
    .sidebar{width:92px;transition:width .22s ease;}
    .sidebar:hover{width:230px;}
    .sidebar-brand .text{overflow:hidden;}
    .sidebar-nav a,.nav-group summary{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .nav-group nav{display:none;}
    .sidebar:hover .nav-group nav{display:flex;}
}
@media (max-width:860px){
    .app-shell{ padding:0; gap:0; }
    .app-topbar{ display:flex; }
    .sidebar{
        position:fixed;
        left:-260px;
        top:0;
        z-index:20;
        width:250px;
        height:100vh;
        border-radius:0;
        transition:left .3s ease;
        box-shadow:0 0 30px rgba(0,0,0,.5);
    }
    .sidebar.open{ left:0; }
    .sidebar-backdrop{
        display:none;
        position:fixed;
        inset:0;
        background:rgba(4,5,7,.55);
        backdrop-filter:blur(6px);
        -webkit-backdrop-filter:blur(6px);
        z-index:19;
    }
    .sidebar-backdrop.open{ display:block; }
    .page-inner{ padding:18px; }
}

/* Shared scroll-reveal primitive -- any dashboard section can opt in with
   class="reveal"; dashboard-effects.js adds .revealed once it's scrolled
   into view. Starts already-visible with no JS (progressive enhancement)
   and is a no-op under prefers-reduced-motion. */
.reveal{ opacity:0; transform:translateY(16px); transition:opacity .5s ease, transform .5s ease; }
.reveal.revealed{ opacity:1; transform:translateY(0); }
@media (prefers-reduced-motion: reduce){
    .reveal{ opacity:1; transform:none; transition:none; }
}

</style>

</head>

<body>

<?php include __DIR__ . '/preloader.php'; ?>

<div class="app-topbar">
    <button type="button" class="app-nav-toggle" id="appNavToggle" aria-label="Open menu">☰</button>
    <div class="name"><?= htmlspecialchars($_SESSION['school_name'] ?? 'Scholar') ?></div>
    <a href="<?= rtrim($SCHOLAR_BASE, '/') ?>/logout.php" class="scholar-logout-btn icon-only" style="margin-left:auto;" aria-label="Log out" title="Log out">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
</div>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<script>
// Both blocks below look up #appSidebar / .sidebar-nav, but this <script>
// sits earlier in the HTML than that markup (the <aside> is emitted
// further down by this same file) -- running immediately meant
// getElementById/querySelector always returned null and both features
// silently no-opped, which is exactly why the mobile hamburger toggle
// never opened the sidebar. Deferring to DOMContentLoaded runs this once
// the full page (including the later markup) actually exists.
document.addEventListener('DOMContentLoaded', function () {
    (function () {
        var toggle = document.getElementById('appNavToggle');
        var sidebar = document.getElementById('appSidebar');
        var backdrop = document.getElementById('sidebarBackdrop');
        if (!toggle || !sidebar || !backdrop) return;
        function close() {
            sidebar.classList.remove('open');
            backdrop.classList.remove('open');
        }
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('open');
        });
        backdrop.addEventListener('click', close);
    })();

    // Flag every sidebar-triggered navigation as "internal" so
    // preloader.php can skip its splash replay on the next load -- the
    // click itself is left as a completely normal link navigation (no
    // preventDefault/fetch), the @view-transition CSS rule above is what
    // gives it the cross-fade.
    (function () {
        var nav = document.querySelector('.sidebar-nav');
        if (!nav) return;
        nav.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (link) {
                sessionStorage.setItem('scholarInternalNav', '1');
            }
        });
    })();
});
</script>
<script src="<?= rtrim($SCHOLAR_BASE, '/') ?>/assets/js/dashboard-effects.js" defer></script>

<div class="app-shell">

    <aside class="sidebar" id="appSidebar">

        <div class="sidebar-brand">
            <?php if ($SCHOOL_BADGE): ?>
                <img class="badge" src="<?= htmlspecialchars(rtrim($SCHOLAR_BASE, '/') . '/' . ltrim($SCHOOL_BADGE, '/')) ?>?t=<?= time() ?>" alt="">
            <?php else: ?>
                <div class="badge-fallback"><?= htmlspecialchars(strtoupper(substr($SCHOOL_NAME, 0, 1))) ?></div>
            <?php endif; ?>
            <div class="text">
                <div class="name">
                    <?= htmlspecialchars($SCHOOL_NAME) ?>
                </div>

                <div class="tag">
                    Admin Panel
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">

            <?php foreach ($nav_items as $key => $item): ?>

                <?php if (isset($item['children'])): ?>

                    <?php $group_active = in_array($ACTIVE_NAV, array_keys($item['children']), true); ?>

                    <details class="nav-group" <?= $group_active ? 'open' : '' ?>>
                        <summary><?= htmlspecialchars($item['label']) ?></summary>
                        <nav>
                            <?php foreach ($item['children'] as $child_key => $child): ?>
                                <a href="<?= rtrim($SCHOLAR_BASE, '/') . '/' . ltrim($child['href'], '/') ?>"
                                   class="<?= ($ACTIVE_NAV === $child_key) ? 'active' : '' ?>">
                                    <?= htmlspecialchars($child['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </details>

                <?php elseif ($item['href'] === null): ?>

                    <a class="disabled">
                        <?= htmlspecialchars($item['label']) ?>
                        <span class="soon">Soon</span>
                    </a>

                <?php else: ?>

                    <a href="<?= rtrim($SCHOLAR_BASE, '/') . '/' . ltrim($item['href'], '/') ?>"
                       class="<?= ($ACTIVE_NAV === $key) ? 'active' : '' ?>">

                        <?= htmlspecialchars($item['label']) ?>

                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <div class="sidebar-foot">

            <?php if (($_SESSION['role'] ?? '') === 'developer'): ?>
                <a href="<?= rtrim($SCHOLAR_BASE, '/') ?>/developer/developer_dashboard.php" style="display:block;margin-bottom:10px;color:var(--muted);font-weight:normal;">
                    ← Developer Portal
                </a>
            <?php endif; ?>

            <a href="<?= rtrim($SCHOLAR_BASE, '/') ?>/logout.php" class="scholar-logout-btn" style="width:100%; justify-content:center;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>

        </div>

    </aside>

    <?php if (($_SESSION['subscription_locked'] ?? false) && $current_page !== 'renew'): ?>
    <div style="background:#7c2d12;color:#fed7aa;padding:12px 20px;text-align:center;font-size:14px;">
        <i class="bi bi-exclamation-triangle"></i>
        This school's subscription has lapsed.
        <a href="<?= rtrim($SCHOLAR_BASE, '/') ?>/renew.php" style="color:#fff;font-weight:600;text-decoration:underline;">Renew now</a>
        to keep full access.
    </div>
    <?php endif; ?>

<!--
Leave this file open.

Each page should continue with:

<main class="main-content">
    <div class="page-inner">

        Page content...

    </div>
</main>

</div>
</body>
</html>

-->