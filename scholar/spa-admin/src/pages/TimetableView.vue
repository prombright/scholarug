<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { timetableViewApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const success = ref('')

const term = ref('')
const year = ref('')
const classes = ref([])
const selectedClassId = ref(0)
const dayNames = ref({})
const gridRows = ref({})
const activeDays = ref([])
const classAssignments = ref([])
const entries = ref({})

const editDay = ref(0)
const editPeriodId = ref(0)
const editAssignmentId = ref('0')

async function load(classId) {
  loading.value = true
  try {
    const { data } = await timetableViewApi.get(classId ?? selectedClassId.value)
    term.value = data.term
    year.value = data.year
    classes.value = data.classes
    selectedClassId.value = data.selected_class_id
    dayNames.value = data.day_names
    gridRows.value = data.grid_rows
    activeDays.value = data.active_days
    classAssignments.value = data.class_assignments
    entries.value = data.entries
  } finally {
    loading.value = false
  }
}
onMounted(() => load())

function onClassChange(e) {
  editDay.value = 0
  editPeriodId.value = 0
  load(e.target.value)
}

function currentEntry(day, periodId) {
  return entries.value[`${day}:${periodId}`] || null
}

function openEdit(day, periodId) {
  editDay.value = day
  editPeriodId.value = periodId
  const cur = currentEntry(day, periodId)
  const match = cur
    ? classAssignments.value.find((ca) => Number(ca.subject_id) === Number(cur.subject_id) && Number(ca.teacher_id) === Number(cur.teacher_id) && Number(ca.paper_number) === Number(cur.paper_number))
    : null
  editAssignmentId.value = match ? String(match.id) : '0'
  nextTick(() => document.getElementById('edit-panel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
}
function cancelEdit() {
  editDay.value = 0
  editPeriodId.value = 0
}

async function saveCell() {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const { data } = await timetableViewApi.saveCell({
      class_id: selectedClassId.value,
      day: editDay.value,
      period_id: editPeriodId.value,
      assignment_id: editAssignmentId.value
    })
    if (data.save_result.ok) {
      success.value = data.save_result.message
    } else {
      error.value = data.save_result.message
    }
    gridRows.value = data.grid_rows
    activeDays.value = data.active_days
    classAssignments.value = data.class_assignments
    entries.value = data.entries
    editDay.value = 0
    editPeriodId.value = 0
  } finally {
    busy.value = false
  }
}

// Only offer editing a slot that's actually a real teaching period in the
// current grid, for the currently selected day -- mirrors the classic
// page's $editingRow lookup, silently ignoring a stale day/period combo.
const editingRow = computed(() => {
  if (!editDay.value || !editPeriodId.value) return null
  for (const key in gridRows.value) {
    const row = gridRows.value[key]
    if (row.is_teaching && row.by_day[editDay.value] === editPeriodId.value) return row
  }
  return null
})

function editingLabel(row) {
  return `Edit ${dayNames.value[editDay.value]}, ${row.label}`
}
function caLabel(ca) {
  return ca.subject_name + (Number(ca.papers_count) > 1 ? ' P' + ca.paper_number : '') + ' — ' + ca.first_name + ' ' + ca.last_name
}
</script>

<template>
  <h1 class="page-title">Timetable</h1>
  <p v-if="!loading" class="empty" style="margin-bottom:18px;">{{ term }}, {{ year }} — <router-link to="/timetable-generate" style="color:var(--cyan);">Regenerate →</router-link></p>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="section">
      <label style="display:block;font-size:0.8rem;color:var(--muted);margin-bottom:6px;">Class</label>
      <select :value="selectedClassId" @change="onClassChange">
        <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.class_name }} {{ c.stream_name || '' }}</option>
      </select>
    </div>

    <div v-if="!Object.keys(gridRows).length" class="empty">No day structure set up yet. <router-link to="/timetable" style="color:var(--cyan);">Set it up first →</router-link></div>
    <div v-else-if="!classes.length" class="empty">No classes set up yet.</div>
    <template v-else>
      <div v-if="editingRow" id="edit-panel" class="section">
        <h2 style="font-size:1rem;margin:0 0 4px;">{{ editingLabel(editingRow) }}</h2>
        <p class="empty">{{ editingRow.start.slice(0, 5) }}–{{ editingRow.end.slice(0, 5) }}</p>
        <label>Subject / Teacher</label>
        <select v-model="editAssignmentId">
          <option value="0">— Leave Free —</option>
          <option v-for="ca in classAssignments" :key="ca.id" :value="String(ca.id)">{{ caLabel(ca) }}</option>
        </select>
        <div>
          <button type="button" :disabled="busy" @click="saveCell">Save</button>
          <a href="#" class="cancel-link" @click.prevent="cancelEdit">Cancel</a>
        </div>
        <p v-if="!classAssignments.length" class="empty" style="margin-top:10px;">This class has no teaching assignments yet — <router-link to="/assignments" style="color:var(--cyan);">add some first</router-link>.</p>
      </div>

      <div class="section">
        <table>
          <tr>
            <th>Time</th>
            <th v-for="d in activeDays" :key="d">{{ dayNames[d] }}</th>
          </tr>
          <template v-for="(row, key) in gridRows" :key="key">
            <tr v-if="!row.is_teaching" class="brk-row">
              <td class="time-col">{{ row.start.slice(0, 5) }}–{{ row.end.slice(0, 5) }}</td>
              <td :colspan="activeDays.length">{{ row.label }}</td>
            </tr>
            <tr v-else>
              <td class="time-col">{{ row.label }}<br>{{ row.start.slice(0, 5) }}–{{ row.end.slice(0, 5) }}</td>
              <td v-for="d in activeDays" :key="d">
                <span v-if="!row.by_day[d]" class="free-cell">—</span>
                <a v-else href="#" class="cell-link" :class="{ editing: editDay === d && editPeriodId === row.by_day[d] }" @click.prevent="openEdit(d, row.by_day[d])">
                  <div v-if="currentEntry(d, row.by_day[d])" class="lesson-cell">
                    <div class="subj">{{ currentEntry(d, row.by_day[d]).subject_name }}{{ Number(currentEntry(d, row.by_day[d]).papers_count) > 1 ? ' P' + currentEntry(d, row.by_day[d]).paper_number : '' }}</div>
                    <div class="tchr">{{ currentEntry(d, row.by_day[d]).first_name }} {{ currentEntry(d, row.by_day[d]).last_name }}</div>
                  </div>
                  <span v-else class="free-cell">Free</span>
                </a>
              </td>
            </tr>
          </template>
        </table>
      </div>
    </template>
  </template>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
select{padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;max-width:300px;}
table{width:100%;border-collapse:collapse;font-size:0.8rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:top;}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.time-col{white-space:nowrap;color:var(--muted);font-size:0.72rem;}
.brk-row td{background:rgba(255,255,255,0.02);color:var(--muted);font-style:italic;}
.lesson-cell{background:rgba(0,168,168,0.06);border-radius:6px;padding:8px;}
.lesson-cell .subj{font-weight:700;color:var(--text);}
.lesson-cell .tchr{color:var(--muted);font-size:0.72rem;margin-top:2px;}
.free-cell{color:var(--muted);font-size:0.75rem;opacity:0.5;}
.empty{color:var(--muted);font-size:0.85rem;}
.cell-link{display:block;text-decoration:none;color:inherit;border-radius:6px;transition:background .15s;}
.cell-link:hover{background:rgba(255,255,255,0.05);}
.cell-link.editing{outline:2px solid var(--cyan);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;margin-top:14px;}
button:disabled{opacity:0.6;cursor:default;}
.cancel-link{color:var(--muted);font-size:0.8rem;margin-left:14px;text-decoration:none;}
</style>
