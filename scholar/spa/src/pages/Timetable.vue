<script setup>
import { ref, onMounted } from 'vue'
import { timetableApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const term = ref('')
const year = ref('')
const days = ref([])
const rows = ref([])

onMounted(async () => {
  try {
    const { data } = await timetableApi.get()
    term.value = data.term
    year.value = data.year
    days.value = data.days
    rows.value = data.rows
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your timetable.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page-title">My Timetable</div>
  <div v-if="!loading && !error" class="page-sub">{{ term }}, {{ year }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <div v-else-if="!rows.length" class="section"><div class="empty">Your school hasn't set up a timetable yet.</div></div>

  <div v-else class="section">
    <div class="table-wrap">
      <table>
        <tr>
          <th>Time</th>
          <th v-for="d in days" :key="d.number">{{ d.name }}</th>
        </tr>
        <tr v-for="(row, i) in rows" :key="i" :class="{ 'brk-row': !row.is_teaching }">
          <template v-if="!row.is_teaching">
            <td class="time-col">{{ row.start }}–{{ row.end }}</td>
            <td :colspan="days.length">{{ row.label }}</td>
          </template>
          <template v-else>
            <td class="time-col">{{ row.label }}<br>{{ row.start }}–{{ row.end }}</td>
            <td v-for="d in days" :key="d.number">
              <span v-if="!row.cells[d.number]" class="free-cell">—</span>
              <div v-else-if="row.cells[d.number] !== 'free'" class="lesson-cell">
                <div class="subj">{{ row.cells[d.number].subject_name }}<template v-if="row.cells[d.number].paper_label"> {{ row.cells[d.number].paper_label }}</template></div>
                <div class="cls">{{ row.cells[d.number].class_name }}</div>
              </div>
              <span v-else class="free-cell">Free</span>
            </td>
          </template>
        </tr>
      </table>
    </div>
  </div>
</template>

<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 4px;}
.page-sub{color:var(--muted);font-size:0.85rem;margin-bottom:18px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top;}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.time-col{white-space:nowrap;color:var(--muted);font-size:0.72rem;}
.brk-row td{background:rgba(255,255,255,0.02);color:var(--muted);font-style:italic;}
.lesson-cell{background:rgba(0,168,168,0.06);border-radius:6px;padding:8px;}
.lesson-cell .subj{font-weight:700;color:var(--text);}
.lesson-cell .cls{color:var(--muted);font-size:0.72rem;margin-top:2px;}
.free-cell{color:var(--muted);font-size:0.75rem;opacity:0.5;}
.empty{color:var(--muted);font-size:0.9rem;}
.table-wrap{overflow-x:auto;}
</style>
