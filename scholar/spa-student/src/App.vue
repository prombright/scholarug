<script setup>
import { ref, provide, onMounted } from 'vue'
import { dashboardApi } from './services/api'

// One shared fetch for brand info -- provided down to the layout, same
// pattern as the teacher pilot's App.vue.
const brand = ref({ school_name: 'Scholar', badge_url: null, student_name: 'Student' })
const bootLoading = ref(true)
const bootError = ref(null)

provide('brand', brand)

onMounted(async () => {
  try {
    const { data } = await dashboardApi.get()
    brand.value = { school_name: data.school_name, badge_url: data.badge_url, student_name: data.student.full_name }
  } catch (e) {
    bootError.value = e.response?.data?.message || 'Could not load your portal.'
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

* { box-sizing: border-box; }
html, body, #app { height: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, "Segoe UI", sans-serif; }
.boot-loading { display: flex; align-items: center; justify-content: center; height: 100vh; color: var(--muted); font-size: 0.9rem; }
</style>
