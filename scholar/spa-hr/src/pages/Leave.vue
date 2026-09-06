<script setup>
import { ref, onMounted } from 'vue'
import { leaveApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref('')
const requests = ref([])

async function load() {
  loading.value = true
  try {
    const { data } = await leaveApi.get()
    requests.value = data.requests
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function review(r, action) {
  busy.value = true
  try {
    const { data } = await leaveApi.review({ request_id: r.id, action })
    requests.value = data.requests
    message.value = data.message
  } finally {
    busy.value = false
  }
}

function fmtRange(start, end) {
  const s = new Date(start).toLocaleDateString([], { day: '2-digit', month: 'short' })
  const e = new Date(end).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' })
  return `${s} – ${e}`
}
</script>

<template>
  <h1 class="page-title">Leave Requests</h1>

  <div v-if="message" class="alert">{{ message }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <div v-else class="lr-section">
    <table>
      <tr><th>Staff</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th></th></tr>
      <tr v-if="!requests.length"><td colspan="6" class="lr-empty">No leave requests yet.</td></tr>
      <tr v-for="r in requests" :key="r.id">
        <td>{{ r.staff_name }}</td>
        <td>{{ r.leave_type }}</td>
        <td>{{ fmtRange(r.start_date, r.end_date) }}</td>
        <td style="max-width:200px;">{{ r.reason || '—' }}</td>
        <td><span class="lr-pill" :class="`lr-pill-${r.status}`">{{ r.status.charAt(0).toUpperCase() + r.status.slice(1) }}</span></td>
        <td>
          <div v-if="r.status === 'pending'" class="lr-actions">
            <button type="button" class="lr-btn-approve" :disabled="busy" @click="review(r, 'approve')">Approve</button>
            <button type="button" class="lr-btn-reject" :disabled="busy" @click="review(r, 'reject')">Reject</button>
          </div>
        </td>
      </tr>
    </table>
  </div>
</template>

<style scoped>
.page-title{margin:0 0 24px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.lr-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.lr-section .table-wrap,.lr-section{overflow-x:auto;}
.lr-section table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:640px;}
.lr-section th,.lr-section td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
.lr-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.lr-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.lr-pill-pending{background:rgba(245,158,11,0.14);color:var(--amber);}
.lr-pill-approved{background:rgba(16,185,129,0.12);color:var(--green);}
.lr-pill-rejected{background:rgba(239,68,68,0.12);color:var(--danger);}
.lr-actions{display:flex;gap:6px;}
.lr-actions button{cursor:pointer;border:none;border-radius:6px;padding:6px 12px;font-weight:700;font-size:0.75rem;}
.lr-actions button:disabled{opacity:0.6;cursor:default;}
.lr-btn-approve{background:var(--green);color:#04221a;}
.lr-btn-reject{background:transparent;border:1px solid rgba(239,68,68,0.4);color:var(--danger);}
.lr-empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(16,185,129,0.12);color:var(--green);}
</style>
