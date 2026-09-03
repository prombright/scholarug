<script setup>
import { ref, provide, onMounted } from 'vue'
import { analyticsApi } from './services/api'

// One fetch (no class/subject selected yet) gets both the sidebar's brand
// info and the list of assignments the "pick a class" screen needs --
// provided down so the layout and the page don't each fetch it separately.
const brand = ref({ school_name: 'Scholar', badge_url: null })
const assignments = ref([])
const termLabel = ref('')
const bootLoading = ref(true)
const bootError = ref(null)

provide('brand', brand)
provide('assignments', assignments)
provide('termLabel', termLabel)

onMounted(async () => {
  try {
    const { data } = await analyticsApi.get()
    brand.value = { school_name: data.school_name, badge_url: data.badge_url }
    assignments.value = data.assignments
    termLabel.value = `${data.term} ${data.year}`
  } catch (e) {
    bootError.value = e.response?.data?.message || 'Could not load your teacher assignments.'
  } finally {
    bootLoading.value = false
  }
})
</script>

<template>
  <div v-if="bootLoading" class="boot-loading">Loading…</div>
  <div v-else-if="bootError" class="boot-loading">{{ bootError }}</div>
  <router-view v-else />
</template>

<style>
/* Same token set as scholar/_teacher_shell.php -- not touching ScholarUg's
   palette, just carrying it into a standalone document since this bundle
   isn't included by the PHP shell, it replaces it for this one page. */
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
}

* { box-sizing: border-box; }

html, body, #app {
  height: 100%;
}

body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: Inter, "Segoe UI", sans-serif;
}

.boot-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100vh;
  color: var(--muted);
  font-size: 0.9rem;
}
</style>
