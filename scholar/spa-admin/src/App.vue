<script setup>
import { ref, provide, onMounted } from 'vue'
import { dashboardApi } from './services/api'

const brand = ref({ school_name: 'Scholar', badge_url: null })
const bootLoading = ref(true)
const bootError = ref(null)

provide('brand', brand)

onMounted(async () => {
  try {
    const { data } = await dashboardApi.get()
    brand.value = { school_name: data.school_name, badge_url: data.badge_url }
  } catch (e) {
    bootError.value = e.response?.data?.message || 'Could not load the admin panel.'
  } finally {
    bootLoading.value = false
  }
})

// Same "scholar-theme" localStorage key + data-theme attribute
// preloader.php's site-wide toggle uses on every classic page, so the
// choice carries over seamlessly between the classic pages and this SPA.
// The attribute itself is already applied pre-mount by an inline script
// app_admin.php injects (avoids a flash of the wrong theme); this just
// keeps the button's icon and localStorage in sync with it from here on.
const theme = ref(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark')
function toggleTheme() {
  const next = theme.value === 'light' ? 'dark' : 'light'
  theme.value = next
  if (next === 'light') document.documentElement.setAttribute('data-theme', 'light')
  else document.documentElement.removeAttribute('data-theme')
  localStorage.setItem('scholar-theme', next)
}
</script>

<template>
  <button type="button" class="scholar-theme-toggle" aria-label="Toggle dark mode" @click="toggleTheme">
    <svg v-if="theme === 'light'" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1020.354 15.354z" /></svg>
    <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" /></svg>
  </button>
  <div v-if="bootLoading" class="boot-loading">Loading…</div>
  <div v-else-if="bootError" class="boot-loading">{{ bootError }}</div>
  <router-view v-else />
</template>

<style>
:root {
  --bg: #080b11;
  --panel: #131b28;
  --panel-raised: #182233;
  --border: #2a3a52;
  --text: #e2e8f0;
  --muted: #64748b;
  --cyan: #00A8A8;
  --green: #10b981;
  --danger: #ef4444;
  --amber: #f59e0b;
  --purple: #a855f7;
}

/* Same light-mode values preloader.php's [data-theme="light"] block uses
   on every classic page (--panel-raised has no classic equivalent since
   most classic pages don't need it -- picked to sit between --bg and the
   white --panel, same relative "one step up" role it plays in dark mode). */
:root[data-theme="light"] {
  --bg: #F1F5F9;
  --panel: #FFFFFF;
  --panel-raised: #F1F5F9;
  --border: rgba(15, 23, 42, .10);
  --text: #1E293B;
  --muted: #64748B;
}

* { box-sizing: border-box; }
html, body, #app { height: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, "Segoe UI", sans-serif; }
.boot-loading { display: flex; align-items: center; justify-content: center; height: 100vh; color: var(--muted); font-size: 0.9rem; }

.scholar-theme-toggle {
  position: fixed;
  top: 14px;
  right: 14px;
  z-index: 9998;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,.15);
  background: rgba(13,17,24,.85);
  color: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  box-shadow: 0 6px 18px rgba(0,0,0,.35);
  backdrop-filter: blur(6px);
  transition: background .2s ease, border-color .2s ease, transform .15s ease;
}
.scholar-theme-toggle:hover { transform: translateY(-1px); }
[data-theme="light"] .scholar-theme-toggle {
  background: rgba(255,255,255,.92);
  border-color: rgba(15,23,42,.12);
  color: #1e293b;
  box-shadow: 0 6px 18px rgba(15,23,42,.15);
}
</style>
