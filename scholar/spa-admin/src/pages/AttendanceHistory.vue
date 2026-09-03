<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { studentAttendanceHistoryApi } from '../services/api'

const route = useRoute()
const studentId = computed(() => route.params.id)

const loading = ref(true)
const error = ref(null)
const student = ref(null)
const rows = ref([])

const statusColors = {
  present: '#10b981',
  absent: '#ef4444',
  sick: '#f59e0b',
  permission: '#00A8A8'
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await studentAttendanceHistoryApi.get(studentId.value)
    student.value = data.student
    rows.value = data.rows
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load attendance history.'
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <p v-if="loading" class="empty">Loading…</p>
  <div v-else-if="error" class="alert error">{{ error }}</div>

  <template v-else-if="student">
    <div class="top">
      <div>
        <h1>Attendance History</h1>
        <p class="sub">{{ student.full_name }} &middot; {{ student.class_name || '—' }}</p>
      </div>
      <router-link :to="`/students/${studentId}`" class="back">&larr; Back to Profile</router-link>
    </div>

    <div v-if="!rows.length" class="empty-box">No attendance has been recorded for this student yet.</div>
    <div v-else class="table-wrap">
      <table>
        <tr><th>Date</th><th>Type</th><th>Status</th></tr>
        <tr v-for="(r, i) in rows" :key="i">
          <td>{{ r.attendance_date }}</td>
          <td>{{ r.subject_id ? (r.subject_name || 'Subject') : 'Daily attendance' }}</td>
          <td><span class="pill" :style="{ background: statusColors[r.status] || '#64748b' }">{{ r.status.charAt(0).toUpperCase() + r.status.slice(1) }}</span></td>
        </tr>
      </table>
    </div>
  </template>
</template>

<style>
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;}
.alert{padding:12px 16px;border-radius:8px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.top h1{font-size:1.3rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0;}
a.back{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.back:hover{background:rgba(0,168,168,0.1);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;min-width:480px;}
th,td{padding:10px 14px;text-align:left;font-size:0.85rem;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.72rem;letter-spacing:0.05em;}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:2px 10px;border-radius:100px;font-size:0.75rem;font-weight:700;color:#04121a;}
.empty-box{text-align:center;padding:40px 20px;color:var(--muted);background:var(--panel);border:1px solid var(--border);border-radius:10px;}
</style>
