<script setup>
import { ref, onMounted, watch } from 'vue'
import { teacherSubmissionsApi } from '../services/api'

const loading = ref(true)
const assessments = ref([])
const classes = ref([])
const rows = ref([])

const selectedAssessment = ref('')
const selectedClass = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await teacherSubmissionsApi.get(selectedAssessment.value, selectedClass.value)
    assessments.value = data.assessments
    classes.value = data.classes
    rows.value = data.rows
  } finally {
    loading.value = false
  }
}
onMounted(load)

function pillFor(r) {
  if (r.submitted_count == 0 && r.draft_count == 0) return { cls: 'pill-none', label: 'Not Started' }
  if (r.draft_count > 0) return { cls: 'pill-progress', label: 'In Progress' }
  return { cls: 'pill-done', label: 'Submitted' }
}
</script>

<template>
  <h1 class="page-title">Teacher Submissions</h1>

  <div class="section">
    <form class="filter-bar" @submit.prevent="load">
      <div>
        <label>Assessment</label>
        <select v-model="selectedAssessment" required>
          <option value="">-- Select Assessment --</option>
          <option v-for="a in assessments" :key="a.id" :value="a.id">{{ a.title }} ({{ a.term }} {{ a.year }})</option>
        </select>
      </div>
      <div>
        <label>Class (optional)</label>
        <select v-model="selectedClass">
          <option value="">All Classes</option>
          <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.class_name }}</option>
        </select>
      </div>
      <div><button type="submit">Filter</button></div>
    </form>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="!selectedAssessment" class="empty">Pick an assessment above to see submission status per teacher.</p>
  <div v-else class="section" style="padding:0;">
    <div class="table-wrap">
      <table>
        <tr><th>Teacher</th><th>Class</th><th>Subject</th><th>Submitted</th><th>Draft</th><th>Status</th></tr>
        <tr v-if="!rows.length"><td colspan="6" class="empty">No teacher assignments found for this filter.</td></tr>
        <tr v-for="(r, i) in rows" :key="i">
          <td>{{ r.teacher_name }}</td>
          <td>{{ r.class_name }}</td>
          <td>{{ r.subject_name }}</td>
          <td>{{ r.submitted_count }}</td>
          <td>{{ r.draft_count }}</td>
          <td><span class="pill" :class="pillFor(r).cls">{{ pillFor(r).label }}</span></td>
        </tr>
      </table>
    </div>
  </div>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;}
.filter-bar div{min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select{width:100%;padding:9px 10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.pill-none{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill-progress{background:rgba(245,158,11,0.14);color:var(--amber);}
.pill-done{background:rgba(16,185,129,0.12);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
.table-wrap{overflow-x:auto;}
</style>
