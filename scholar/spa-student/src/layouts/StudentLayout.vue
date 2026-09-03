<script setup>
import { ref, inject } from 'vue'
import { useRoute } from 'vue-router'

const SB = window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'

const NAV_ITEMS = [
  { key: 'dashboard', label: 'Dashboard', icon: 'bi-grid-1x2', route: '/' },
  { key: 'results', label: 'My Results', icon: 'bi-mortarboard', route: '/results' },
  { key: 'fees', label: 'Fees', icon: 'bi-cash-coin', route: '/fees' },
  { key: 'attendance', label: 'Attendance', icon: 'bi-calendar-check', route: '/attendance' },
  { key: 'library', label: 'Library', icon: 'bi-book', route: '/library' },
  { key: 'elections', label: 'Elections', icon: 'bi-check2-square', route: '/elections' },
  { key: 'messages', label: 'Messages', icon: 'bi-chat-dots', route: '/messages' }
]
const logoutHref = SB + 'logout.php'

const route = useRoute()
const brand = inject('brand', ref({ school_name: 'Scholar', badge_url: null, student_name: 'Student' }))
const mobileOpen = ref(false)
</script>

<template>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <div class="sidebar-backdrop" :class="{ open: mobileOpen }" @click="mobileOpen = false"></div>
  <div class="app-shell">
    <aside class="sidebar" :class="{ open: mobileOpen }">
      <div class="sidebar-brand">
        <img v-if="brand.badge_url" :src="brand.badge_url" alt="">
        <div v-else class="fallback">{{ (brand.school_name || 'S').charAt(0).toUpperCase() }}</div>
        <div class="name">{{ brand.student_name || 'Student' }}</div>
      </div>
      <nav class="sidebar-nav">
        <router-link v-for="item in NAV_ITEMS" :key="item.key" :to="item.route" :class="{ active: route.path === item.route }">
          <i class="bi" :class="item.icon"></i>
          <span>{{ item.label }}</span>
        </router-link>
      </nav>
    </aside>
    <div class="main-col">
      <div class="topbar">
        <button class="mobile-nav-toggle" @click="mobileOpen = !mobileOpen"><i class="bi bi-list"></i></button>
        <a class="logout-btn" :href="logoutHref">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Log Out
        </a>
      </div>
      <div class="page-inner">
        <router-view />
      </div>
    </div>
  </div>
</template>

<style>
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
.main-col{flex:1;min-width:0;display:flex;flex-direction:column;}
.topbar{display:flex;align-items:center;gap:14px;padding:16px 28px;border-bottom:1px solid var(--border);}
.logout-btn{display:flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-size:0.82rem;font-weight:600;margin-left:auto;}
.logout-btn:hover{color:var(--text);}
.page-inner{padding:28px;max-width:960px;width:100%;margin:0;}
.mobile-nav-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);font-size:1.1rem;cursor:pointer;}

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
  .sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(4,5,7,0.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:45;}
  .sidebar-backdrop.open{display:block;}
}
</style>
