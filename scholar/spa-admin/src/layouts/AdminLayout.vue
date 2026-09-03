<script setup>
import { ref, inject } from 'vue'
import { useRoute } from 'vue-router'

const SB = window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'

// `route:` items are migrated pages inside this SPA (router-link, no
// reload); `href:` items still go to the classic PHP app. Mirrors
// _admin_shell.php's $nav_items grouping exactly.
const NAV_GROUPS = [
  { key: 'home', label: 'Home', icon: 'bi-grid-1x2', route: '/' },
  { key: 'hr', label: 'Human Resources', icon: 'bi-briefcase', href: SB + 'hr_dashboard.php' },
  {
    key: 'academics', label: 'Academics', icon: 'bi-mortarboard', children: [
      { key: 'students', label: 'Students', icon: 'bi-people', route: '/students' },
      { key: 'subjects', label: 'Subjects', icon: 'bi-journal-bookmark', route: '/subjects' },
      { key: 'assessments', label: 'Assessments', icon: 'bi-clipboard-data', route: '/assessments' },
      { key: 'report_cards', label: 'Report Cards', icon: 'bi-file-earmark-text', route: '/grading' },
      { key: 'classes', label: 'Classes', icon: 'bi-diagram-3', route: '/classes' },
      { key: 'teacher_submissions', label: 'Teacher Submissions', icon: 'bi-clipboard-check', route: '/teacher-submissions' },
      { key: 'assignments', label: 'Assignments', icon: 'bi-person-check', route: '/assignments' },
      { key: 'attendance', label: 'Attendance', icon: 'bi-calendar-check', route: '/attendance' },
      { key: 'library', label: 'Library', icon: 'bi-book', route: '/library' },
      { key: 'timetable', label: 'Timetable', icon: 'bi-calendar3', route: '/timetable' }
    ]
  },
  {
    key: 'students_family', label: 'Students & Family', icon: 'bi-people-fill', children: [
      { key: 'parents', label: 'Parent Accounts', icon: 'bi-person-hearts', route: '/parents' },
      { key: 'elections', label: 'Student Elections', icon: 'bi-check2-square', route: '/elections' }
    ]
  },
  { key: 'projects', label: 'Projects', icon: 'bi-kanban', route: '/projects' },
  { key: 'finance', label: 'Finance', icon: 'bi-cash-coin', children: [{ key: 'fees', label: 'Fees', icon: 'bi-cash-coin', route: '/fees' }] },
  { key: 'settings', label: 'School Settings', icon: 'bi-gear', route: '/settings' },
  { key: 'contact_dev', label: 'Contact Developer', icon: 'bi-headset', route: '/contact-developer' }
]
const logoutHref = SB + 'logout.php'

const route = useRoute()
const brand = inject('brand', ref({ school_name: 'Scholar', badge_url: null }))
const mobileOpen = ref(false)

function isActive(item) {
  return item.route ? route.path === item.route : false
}
function groupActive(group) {
  return (group.children || []).some(isActive)
}
</script>

<template>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <div class="sidebar-backdrop" :class="{ open: mobileOpen }" @click="mobileOpen = false"></div>
  <div class="app-shell">
    <aside class="sidebar" :class="{ open: mobileOpen }">
      <div class="sidebar-brand">
        <img v-if="brand.badge_url" class="badge" :src="brand.badge_url" alt="">
        <div v-else class="badge-fallback">{{ (brand.school_name || 'S').charAt(0).toUpperCase() }}</div>
        <div class="text">
          <div class="name">{{ brand.school_name || 'Scholar' }}</div>
          <div class="tag">Admin Panel</div>
        </div>
      </div>
      <nav class="sidebar-nav">
        <template v-for="group in NAV_GROUPS" :key="group.key">
          <details v-if="group.children" class="nav-group" :open="groupActive(group)">
            <summary><i class="bi" :class="group.icon"></i><span>{{ group.label }}</span></summary>
            <nav>
              <template v-for="child in group.children" :key="child.key">
                <router-link v-if="child.route" :to="child.route" :class="{ active: isActive(child) }"><i class="bi" :class="child.icon"></i><span>{{ child.label }}</span></router-link>
                <a v-else :href="child.href"><i class="bi" :class="child.icon"></i><span>{{ child.label }}</span></a>
              </template>
            </nav>
          </details>
          <router-link v-else-if="group.route" :to="group.route" :class="{ active: isActive(group) }"><i class="bi" :class="group.icon"></i><span>{{ group.label }}</span></router-link>
          <a v-else :href="group.href"><i class="bi" :class="group.icon"></i><span>{{ group.label }}</span></a>
        </template>
      </nav>
      <div class="sidebar-foot">
        <a :href="logoutHref" class="logout-btn">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Log Out
        </a>
      </div>
    </aside>
    <div class="main-col">
      <div class="topbar">
        <button class="mobile-nav-toggle" @click="mobileOpen = !mobileOpen"><i class="bi bi-list"></i></button>
        <span class="island-pill">⚡ Vue pilot</span>
      </div>
      <div class="page-inner">
        <router-view />
      </div>
    </div>
  </div>
</template>

<style>
.app-shell{display:flex;min-height:100vh;gap:16px;padding:16px;}
.sidebar{width:230px;background:var(--panel);border:1px solid var(--border);border-radius:16px;display:flex;flex-direction:column;position:sticky;top:16px;height:calc(100vh - 32px);overflow:hidden;}
.sidebar-brand{padding:24px 20px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;}
.sidebar-brand .badge,.sidebar-brand .badge-fallback{width:56px;height:56px;border-radius:50%;flex-shrink:0;object-fit:cover;background:var(--panel);border:1px solid var(--border);}
.sidebar-brand .badge-fallback{background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;}
.sidebar-brand .text{min-width:0;}
.sidebar-brand .name{font-size:15px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sidebar-brand .tag{color:var(--muted);font-size:12px;margin-top:3px;}
.sidebar-nav{flex:1;min-height:0;overflow-y:auto;padding:15px 10px;}
.sidebar-nav a{display:flex;align-items:center;gap:10px;padding:12px;color:var(--muted);text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;margin-bottom:4px;}
.sidebar-nav a:hover{background:var(--panel-raised);color:var(--text);}
.sidebar-nav a.active{background:rgba(0,168,168,.15);color:var(--cyan);}
.sidebar-nav a i,.nav-group summary i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0;}
.nav-group{margin-bottom:4px;}
.nav-group summary{display:flex;align-items:center;gap:10px;padding:12px;color:var(--muted);border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;list-style:none;}
.nav-group summary::-webkit-details-marker{display:none;}
.nav-group summary::after{content:'\25B8';font-size:11px;transition:transform .15s ease;margin-left:auto;flex-shrink:0;}
.nav-group[open] summary::after{transform:rotate(90deg);}
.nav-group summary:hover{background:var(--panel-raised);color:var(--text);}
.nav-group nav{display:flex;flex-direction:column;padding-left:16px;}
.nav-group nav a{font-size:13px;padding:10px 12px;}
.sidebar-foot{padding:20px;border-top:1px solid var(--border);}
.logout-btn{display:flex;align-items:center;gap:6px;color:var(--danger);text-decoration:none;font-size:0.85rem;font-weight:700;justify-content:center;}
.main-col{flex:1;min-width:0;overflow:auto;}
.topbar{display:flex;align-items:center;gap:14px;padding:8px 0 16px;}
.island-pill{margin-left:auto;font-size:0.68rem;font-weight:700;letter-spacing:0.03em;color:var(--cyan);background:rgba(0,168,168,0.12);border:1px solid rgba(0,168,168,0.3);padding:4px 10px;border-radius:20px;}
.page-inner{width:100%;}
.mobile-nav-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:1.1rem;cursor:pointer;}

@media (min-width:861px){
  .sidebar{width:76px;border-radius:0 18px 18px 0;overflow:hidden;transition:width .22s ease;}
  .sidebar:hover{width:230px;}
  .sidebar-brand{padding:16px 8px;transition:padding .22s ease;}
  .sidebar:hover .sidebar-brand{padding:24px 20px;}
  .sidebar-brand .badge,.sidebar-brand .badge-fallback{width:40px;height:40px;font-size:16px;transition:width .22s ease,height .22s ease;}
  .sidebar:hover .sidebar-brand .badge,.sidebar:hover .sidebar-brand .badge-fallback{width:56px;height:56px;font-size:22px;}
  .sidebar-brand .text{overflow:hidden;}
  .sidebar-brand .name{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar:hover .sidebar-brand .name{max-width:160px;opacity:1;transition-delay:.05s;}
  .sidebar-brand .tag{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar:hover .sidebar-brand .tag{max-width:160px;opacity:1;transition-delay:.05s;}
  .sidebar-nav a span,.nav-group summary span{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar:hover .sidebar-nav a span,.sidebar:hover .nav-group summary span{max-width:160px;opacity:1;transition-delay:.05s;}
  .nav-group summary::after{opacity:0;transition:opacity .12s ease;}
  .sidebar:hover .nav-group summary::after{opacity:1;transition-delay:.05s;}
  .nav-group nav{display:none;}
  .sidebar:hover .nav-group nav{display:flex;}
}
@media (max-width:860px){
  .app-shell{padding:0;gap:0;}
  .mobile-nav-toggle{display:inline-flex;}
  .sidebar{position:fixed;left:-260px;top:0;z-index:20;width:250px;height:100vh;border-radius:0;transition:left .3s ease;box-shadow:0 0 30px rgba(0,0,0,.5);}
  .sidebar.open{left:0;}
  .sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(4,5,7,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:19;}
  .sidebar-backdrop.open{display:block;}
  .page-inner{padding:18px;}
}
</style>
