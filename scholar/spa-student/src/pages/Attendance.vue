<script setup>
import { ref, onMounted } from 'vue'
import { attendanceApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const attendance = ref({ present: 0, absent: 0, sick: 0, permission: 0 })

onMounted(async () => {
  try {
    const { data } = await attendanceApi.get()
    attendance.value = data.attendance
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your attendance.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="section-title">Attendance <span class="pill">all recorded terms</span></div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <div v-else class="grid">
    <div class="card"><div class="n green">{{ attendance.present }}</div><div class="label">Present</div></div>
    <div class="card"><div class="n danger">{{ attendance.absent }}</div><div class="label">Absent</div></div>
    <div class="card"><div class="n amber">{{ attendance.sick }}</div><div class="label">Sick</div></div>
    <div class="card"><div class="n">{{ attendance.permission }}</div><div class="label">Permission</div></div>
  </div>
</template>

<style scoped>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.empty{color:var(--muted);font-size:0.9rem;padding:16px;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-align:center;}
.card .n{font-size:1.8rem;font-weight:700;}
.card .n.green{color:var(--green);}
.card .n.danger{color:var(--danger);}
.card .n.amber{color:var(--amber);}
.card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:0.75rem;background:rgba(148,163,184,0.15);color:var(--muted);margin-left:8px;}
</style>
