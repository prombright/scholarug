<script setup>
import { ref, onMounted } from 'vue'
import { timetableGenerateApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const term = ref('')
const year = ref('')
const periodCount = ref(0)
const assignmentCount = ref(0)
const hasExisting = ref(0)
const result = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await timetableGenerateApi.get()
    applyOverview(data.overview)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function applyOverview(o) {
  term.value = o.term
  year.value = o.year
  periodCount.value = o.period_count
  assignmentCount.value = o.assignment_count
  hasExisting.value = o.has_existing
}

async function generate() {
  if (!confirm(`Generate the timetable for ${term.value} ${year.value}? Any existing timetable for this term will be replaced.`)) return
  busy.value = true
  try {
    const { data } = await timetableGenerateApi.generate()
    applyOverview(data.overview)
    result.value = data.result
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <h1 class="page-title">Generate Timetable</h1>
  <p v-if="!loading" class="empty" style="margin-bottom:18px;">Generating for <strong>{{ term }}, {{ year }}</strong> — this school's current term/year.</p>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="section">
      <div class="stat-row"><span>Teaching periods/week set up</span><strong>{{ periodCount }}</strong></div>
      <div class="stat-row"><span>Teaching assignments on file</span><strong>{{ assignmentCount }}</strong></div>
      <div class="stat-row"><span>Existing timetable entries this term</span><strong>{{ hasExisting }}</strong></div>
    </div>

    <div v-if="periodCount === 0" class="alert error">No teaching periods set up yet. <router-link to="/timetable" style="color:inherit;text-decoration:underline;">Set up your day structure first</router-link>.</div>
    <div v-else-if="assignmentCount === 0" class="alert error">No teaching assignments on file yet. <router-link to="/assignments" style="color:inherit;text-decoration:underline;">Assign teachers to subjects/classes first</router-link>.</div>
    <template v-else>
      <div v-if="hasExisting > 0 && result === null" class="alert warn">A timetable already exists for this term. Generating again replaces it completely.</div>
      <button type="button" :disabled="busy" @click="generate">Generate Timetable</button>
    </template>

    <template v-if="result !== null">
      <div v-if="result.error" class="alert error" style="margin-top:20px;">{{ result.error }}</div>
      <template v-else>
        <div class="alert" :class="!result.unplaced || !result.unplaced.length ? 'success' : 'warn'" style="margin-top:20px;">
          Placed {{ result.placed_count }} of {{ result.requested_count }} required weekly lessons.
          {{ !result.unplaced || !result.unplaced.length ? ' Every lesson was placed with no conflicts.' : ` ${result.unplaced.length} assignment(s) couldn't be fully placed — see below.` }}
          <router-link to="/timetable-view" style="color:inherit;text-decoration:underline;">View the timetable →</router-link>
        </div>

        <div v-if="result.unplaced && result.unplaced.length" class="section">
          <h2 style="font-size:1rem;margin:0 0 14px;">Couldn't Fully Place</h2>
          <div class="table-wrap">
            <table>
              <tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Needed</th><th>Placed</th></tr>
              <tr v-for="(u, i) in result.unplaced" :key="i">
                <td>{{ u.teacher_name }}</td>
                <td>{{ u.subject_name }}</td>
                <td>{{ u.class_name }}</td>
                <td>{{ u.needed }}</td>
                <td>{{ u.placed }}</td>
              </tr>
            </table>
          </div>
          <p class="empty" style="margin-top:12px;">Usually fixed by adding more teaching periods to the day structure, or lowering this assignment's periods/week.</p>
        </div>
      </template>
    </template>
  </template>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.stat-row{display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid var(--border);font-size:0.85rem;}
.stat-row:first-child{border-top:none;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 24px;border-radius:8px;cursor:pointer;font-size:0.9rem;}
button:disabled{opacity:0.6;cursor:default;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.alert.warn{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#fbbf24;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;min-width:520px;}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.empty{color:var(--muted);font-size:0.85rem;}
</style>
