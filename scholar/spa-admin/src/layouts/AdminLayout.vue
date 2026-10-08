<script setup>
import { ref, inject, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { notificationsApi, searchApi } from '../services/api'
import { useRouter } from 'vue-router'

const SB = window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'
const role = inject('role', 'school_admin')

// `route:` items are migrated pages inside this SPA (router-link, no
// reload); `href:` items still go to the classic PHP app. Mirrors
// _admin_shell.php's $nav_items grouping exactly.
//
// HR used to be a single href out to app_hr.php -- a completely separate
// built Vue app, so clicking it did a full browser reload (new JS/CSS
// download, fresh app boot) instead of the instant in-app navigation
// every other item gets. Its pages now live here as real routes (see
// src/pages/hr/), so this is a normal nav group like Academics/Finance.
// Staff stays an href -- same reasoning as ever (its form bundles a photo
// upload with a dozen other fields, kept classic) -- that part of the
// old disconnect is intentional, not a bug.
const HR_GROUP = {
  key: 'hr', label: 'Human Resources', icon: 'bi-briefcase', children: [
    { key: 'hr_dashboard', label: 'HR Dashboard', icon: 'bi-grid-1x2', route: '/hr' },
    { key: 'hr_staff', label: 'Staff', icon: 'bi-people', href: SB + 'staff_manager.php' },
    { key: 'hr_leave', label: 'Leave Management', icon: 'bi-calendar2-week', route: '/hr/leave' },
    { key: 'hr_payroll', label: 'Payroll', icon: 'bi-cash-stack', route: '/hr/payroll' },
    { key: 'hr_sms_wallet', label: 'SMS Wallet', icon: 'bi-wallet2', route: '/hr/sms/wallet' },
    { key: 'hr_sms_contacts', label: 'SMS Contacts', icon: 'bi-person-lines-fill', route: '/hr/sms/contacts' },
    { key: 'hr_sms_send', label: 'Send SMS', icon: 'bi-send', route: '/hr/sms/send' },
    { key: 'hr_sms_history', label: 'SMS History', icon: 'bi-clock-history', route: '/hr/sms/history' },
    { key: 'hr_sms_whatsapp', label: 'WhatsApp', icon: 'bi-whatsapp', route: '/hr/sms/whatsapp' }
  ]
}

const ADMIN_NAV_GROUPS = [
  { key: 'home', label: 'Home', icon: 'bi-grid-1x2', route: '/' },
  HR_GROUP,
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

// dos/headteacher/bursar only get scoped access to one page each (see
// app_admin.php's require_role) -- they never see the full admin nav,
// just their one feature plus a way back to their own classic dashboard.
const ROLE_NAV_GROUPS = {
  dos: [
    { key: 'home', label: 'My Dashboard', icon: 'bi-grid-1x2', href: SB + 'dos_dashboard.php' },
    { key: 'assessments', label: 'Assessments', icon: 'bi-clipboard-data', route: '/assessments' }
  ],
  headteacher: [
    { key: 'home', label: 'My Dashboard', icon: 'bi-grid-1x2', href: SB + 'headteacher_dashboard.php' },
    { key: 'library', label: 'Library', icon: 'bi-book', route: '/library' }
  ],
  bursar: [
    { key: 'home', label: 'My Dashboard', icon: 'bi-grid-1x2', href: SB + 'bursar_dashboard.php' },
    { key: 'fees', label: 'Fees', icon: 'bi-cash-coin', route: '/fees' }
  ],
  // hr gets its whole section (dashboard + staff + leave + payroll + sms),
  // unlike dos/headteacher/bursar's one-page scope -- it's a role built
  // around several related tasks, not a single feature.
  hr: HR_GROUP.children
}
const NAV_GROUPS = ROLE_NAV_GROUPS[role] || ADMIN_NAV_GROUPS
const logoutHref = SB + 'logout.php'

const route = useRoute()
const router = useRouter()
const brand = inject('brand', ref({ school_name: 'Scholar', badge_url: null }))
const mobileOpen = ref(false)

// Click-to-expand replaces the old hover-to-expand -- resizing the whole
// rail just because the pointer passed near it was the actual complaint.
// Same localStorage key the classic (non-SPA) shells use, so the
// preference carries over between this SPA and any classic page a user
// lands on.
const STORAGE_KEY = 'scholarSidebarExpanded'
let savedExpanded = false
try { savedExpanded = localStorage.getItem(STORAGE_KEY) === '1' } catch (e) {}
const sidebarExpanded = ref(savedExpanded)
function toggleSidebar() {
  sidebarExpanded.value = !sidebarExpanded.value
  try { localStorage.setItem(STORAGE_KEY, sidebarExpanded.value ? '1' : '0') } catch (e) {}
}

function isActive(item) {
  return item.route ? route.path === item.route : false
}
function groupActive(group) {
  return (group.children || []).some(isActive)
}

// Global search -- students link straight to their SPA profile route;
// staff has no individual profile page in this app yet, so results just
// go to the staff list, already filtered down by name in the result label.
const searchQuery = ref('')
const searchResults = ref({ students: [], staff: [] })
const searchOpen = ref(false)
const searching = ref(false)
let searchDebounce = null

function onSearchInput() {
  clearTimeout(searchDebounce)
  const q = searchQuery.value.trim()
  if (q.length < 2) {
    searchResults.value = { students: [], staff: [] }
    searchOpen.value = q.length > 0
    return
  }
  searchDebounce = setTimeout(async () => {
    searching.value = true
    try {
      const { data } = await searchApi.get(q)
      searchResults.value = { students: data.students, staff: data.staff }
      searchOpen.value = true
    } catch (e) {
      // Search is a convenience, not critical path -- fail silently.
    } finally {
      searching.value = false
    }
  }, 250)
}

function goToStudent(student) {
  searchOpen.value = false
  searchQuery.value = ''
  router.push(`/students/${student.id}`)
}

function goToStaffList() {
  searchOpen.value = false
  searchQuery.value = ''
  window.location.href = SB + 'staff_manager.php'
}

// Notification bell -- only rendered for school_admin/dos/headteacher/bursar
// (api/admin/notifications.php is gated the same way), so it's hidden
// outright for any other injected role rather than fetching and failing.
const notifications = ref([])
const unreadCount = ref(0)
const bellOpen = ref(false)

async function fetchNotifications() {
  try {
    const { data } = await notificationsApi.get()
    notifications.value = data.items
    unreadCount.value = data.unread_count
  } catch (e) {
    // The bell is a convenience, not critical path -- fail silently.
  }
}

function toggleBell() {
  bellOpen.value = !bellOpen.value
}

async function markAllRead() {
  if (unreadCount.value === 0) return
  notifications.value = notifications.value.map((n) => ({ ...n, is_read: 1 }))
  unreadCount.value = 0
  try {
    await notificationsApi.action({ action: 'mark_all_read' })
  } catch (e) {}
}

async function markRead(item) {
  if (item.is_read) return
  item.is_read = 1
  unreadCount.value = Math.max(0, unreadCount.value - 1)
  try {
    await notificationsApi.action({ action: 'mark_read', id: item.id })
  } catch (e) {}
}

onMounted(fetchNotifications)
</script>

<template>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <div class="sidebar-backdrop" :class="{ open: mobileOpen }" @click="mobileOpen = false"></div>
  <div class="app-shell">
    <aside class="sidebar" :class="{ open: mobileOpen, expanded: sidebarExpanded }">
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
        <button type="button" class="sidebar-collapse-toggle" :aria-expanded="sidebarExpanded" :aria-label="sidebarExpanded ? 'Collapse sidebar' : 'Expand sidebar'" @click="toggleSidebar">
          <i class="bi bi-chevron-double-right"></i>
          <span>Collapse</span>
        </button>
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
        <div class="topbar-right">
          <div class="search-wrap">
            <i class="bi bi-search search-icon"></i>
            <input
              type="text"
              class="search-input"
              placeholder="Search students or staff..."
              v-model="searchQuery"
              @input="onSearchInput"
              @focus="searchOpen = searchQuery.trim().length > 0"
            >
            <div v-if="searchOpen" class="bell-backdrop" @click="searchOpen = false"></div>
            <div v-if="searchOpen" class="search-panel">
              <div v-if="searching" class="bell-empty">Searching...</div>
              <template v-else-if="searchResults.students.length || searchResults.staff.length">
                <div v-if="searchResults.students.length" class="search-group-label">Students</div>
                <button v-for="s in searchResults.students" :key="'s' + s.id" type="button" class="search-item" @click="goToStudent(s)">
                  <div class="search-item-title">{{ s.full_name }}</div>
                  <div class="search-item-sub">{{ s.student_no }} &middot; {{ s.class_name }}{{ s.stream_name ? ' - ' + s.stream_name : '' }}</div>
                </button>
                <div v-if="searchResults.staff.length" class="search-group-label">Staff</div>
                <button v-for="st in searchResults.staff" :key="'t' + st.id" type="button" class="search-item" @click="goToStaffList">
                  <div class="search-item-title">{{ st.first_name }} {{ st.last_name }}</div>
                  <div class="search-item-sub">{{ st.staff_code }} &middot; {{ st.role }}</div>
                </button>
              </template>
              <div v-else class="bell-empty">No matches for "{{ searchQuery }}".</div>
            </div>
          </div>
          <div class="bell-wrap">
            <button type="button" class="bell-btn" :class="{ active: bellOpen }" :aria-expanded="bellOpen" aria-label="Notifications" @click="toggleBell">
              <i class="bi bi-bell"></i>
              <span v-if="unreadCount > 0" class="bell-badge">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
            </button>
            <div v-if="bellOpen" class="bell-backdrop" @click="bellOpen = false"></div>
            <div v-if="bellOpen" class="bell-panel">
              <div class="bell-head">
                <span>Notifications</span>
                <button v-if="unreadCount > 0" type="button" class="bell-mark-all" @click="markAllRead">Mark all read</button>
              </div>
              <div v-if="!notifications.length" class="bell-empty">You're all caught up.</div>
              <div v-else class="bell-list">
                <button v-for="n in notifications" :key="n.id" type="button" class="bell-item" :class="{ unread: !n.is_read }" @click="markRead(n)">
                  <div class="bell-item-title">{{ n.title }}</div>
                  <div class="bell-item-msg">{{ n.message }}</div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="page-inner">
        <router-view />
      </div>
    </div>
  </div>
</template>

<style scoped>
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
.topbar-right{margin-left:auto;display:flex;align-items:center;}
.page-inner{width:100%;}
.mobile-nav-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:1.1rem;cursor:pointer;}

.search-wrap{position:relative;}
.search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.9rem;pointer-events:none;}
.search-input{width:220px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--panel);color:var(--text);font-size:0.82rem;font-family:inherit;padding:0 12px 0 34px;}
.search-input:focus{outline:none;border-color:var(--cyan);}
.search-input::placeholder{color:var(--muted);}
.search-panel{position:absolute;top:calc(100% + 10px);left:0;width:320px;max-width:calc(100vw - 32px);background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.25);z-index:25;overflow:hidden;max-height:380px;overflow-y:auto;}
.search-group-label{padding:10px 16px 6px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);}
.search-item{display:block;width:100%;text-align:left;background:none;border:none;border-bottom:1px solid var(--border);padding:10px 16px;cursor:pointer;font-family:inherit;}
.search-item:hover{background:var(--panel-raised);}
.search-item-title{font-size:0.82rem;font-weight:700;color:var(--text);}
.search-item-sub{font-size:0.75rem;color:var(--muted);margin-top:2px;}
@media (max-width:860px){.search-input{width:150px;}}
.bell-wrap{position:relative;}
.bell-btn{position:relative;display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--panel);color:var(--text);font-size:1.05rem;cursor:pointer;}
.bell-btn:hover,.bell-btn.active{border-color:var(--cyan);color:var(--cyan);}
.bell-badge{position:absolute;top:-5px;right:-5px;background:var(--danger);color:#fff;font-size:0.62rem;font-weight:800;line-height:1;padding:3px 5px;border-radius:20px;min-width:16px;text-align:center;}
.bell-backdrop{position:fixed;inset:0;z-index:24;background:transparent;}
.bell-panel{position:absolute;top:calc(100% + 10px);right:0;width:330px;max-width:calc(100vw - 32px);background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.25);z-index:25;overflow:hidden;}
.bell-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);font-weight:700;font-size:0.88rem;}
.bell-mark-all{background:none;border:none;color:var(--cyan);font-size:0.75rem;font-weight:600;cursor:pointer;font-family:inherit;}
.bell-empty{padding:28px 16px;text-align:center;color:var(--muted);font-size:0.82rem;}
.bell-list{max-height:360px;overflow-y:auto;}
.bell-item{display:block;width:100%;text-align:left;background:none;border:none;border-bottom:1px solid var(--border);padding:12px 16px;cursor:pointer;font-family:inherit;}
.bell-item:last-child{border-bottom:none;}
.bell-item:hover{background:var(--panel-raised);}
.bell-item.unread{background:rgba(0,168,168,.06);}
.bell-item-title{font-size:0.82rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px;}
.bell-item.unread .bell-item-title::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--cyan);flex-shrink:0;}
.bell-item-msg{font-size:0.78rem;color:var(--muted);margin-top:3px;line-height:1.4;}
.sidebar-collapse-toggle{display:none;width:100%;align-items:center;gap:10px;padding:12px;color:var(--muted);background:none;border:none;border-radius:8px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;text-align:left;}
.sidebar-collapse-toggle:hover{background:var(--panel-raised);color:var(--text);}
.sidebar-collapse-toggle i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0;transition:transform .22s ease;}

/* Collapses to a slim icon rail by default; the chevron toggle above
   switches it to the full labeled width on click. Used to expand on
   :hover instead -- distracting, since the whole rail resized itself
   just from the pointer passing near it, not from the user actually
   wanting it open. */
@media (min-width:861px){
  .sidebar{width:76px;border-radius:0 18px 18px 0;overflow:hidden;transition:width .22s ease;}
  .sidebar.expanded{width:230px;}
  .sidebar-brand{padding:16px 8px;transition:padding .22s ease;}
  .sidebar.expanded .sidebar-brand{padding:24px 20px;}
  .sidebar-brand .badge,.sidebar-brand .badge-fallback{width:40px;height:40px;font-size:16px;transition:width .22s ease,height .22s ease;}
  .sidebar.expanded .sidebar-brand .badge,.sidebar.expanded .sidebar-brand .badge-fallback{width:56px;height:56px;font-size:22px;}
  .sidebar-brand .text{overflow:hidden;}
  .sidebar-brand .name{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar.expanded .sidebar-brand .name{max-width:160px;opacity:1;transition-delay:.05s;}
  .sidebar-brand .tag{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar.expanded .sidebar-brand .tag{max-width:160px;opacity:1;transition-delay:.05s;}
  .sidebar-nav a span,.nav-group summary span,.sidebar-collapse-toggle span{display:inline-block;max-width:0;opacity:0;overflow:hidden;white-space:nowrap;transition:max-width .18s ease,opacity .12s ease;}
  .sidebar.expanded .sidebar-nav a span,.sidebar.expanded .nav-group summary span,.sidebar.expanded .sidebar-collapse-toggle span{max-width:160px;opacity:1;transition-delay:.05s;}
  .nav-group summary::after{opacity:0;transition:opacity .12s ease;}
  .sidebar.expanded .nav-group summary::after{opacity:1;transition-delay:.05s;}
  .nav-group nav{display:none;}
  .sidebar.expanded .nav-group nav{display:flex;}
  .sidebar-collapse-toggle{display:flex;}
  .sidebar.expanded .sidebar-collapse-toggle i{transform:rotate(180deg);}
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
