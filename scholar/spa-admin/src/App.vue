<script setup>
import { ref, provide, onMounted } from 'vue'
import { brandApi } from './services/api'

const brand = ref({ school_name: 'Scholar', badge_url: null })
const bootLoading = ref(true)
const bootError = ref(null)

// dos/headteacher/bursar are let into this SPA too, scoped to one page
// each (see app_admin.php) -- AdminLayout.vue reads this to show only
// the nav items each role can actually reach.
const role = window.__SCHOLAR_ROLE__ || 'school_admin'
provide('brand', brand)
provide('role', role)

onMounted(async () => {
  try {
    const { data } = await brandApi.get()
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
// keeps localStorage in sync with it from here on. The toggle button
// itself used to render here as a position:fixed circle pinned to the
// viewport corner -- it sat on top of the topbar's own search box and
// notification bell once those were added, since neither knew the other
// existed. Rendered inside AdminLayout.vue's topbar instead now (state/
// logic stays here, provided down), as a normal flex item alongside
// them, so all three share the space instead of stacking on top of it.
const theme = ref(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark')
function toggleTheme() {
  const next = theme.value === 'light' ? 'dark' : 'light'
  theme.value = next
  if (next === 'light') document.documentElement.setAttribute('data-theme', 'light')
  else document.documentElement.removeAttribute('data-theme')
  localStorage.setItem('scholar-theme', next)
}
provide('theme', theme)
provide('toggleTheme', toggleTheme)
</script>

<template>
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
</style>
