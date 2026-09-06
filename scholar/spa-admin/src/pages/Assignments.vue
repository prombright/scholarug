<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { assignTeacherApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const success = ref(null)

const teachers = ref([])
const departments = ref([])
const subjectsByName = ref({})
const classes = ref([])
const detail = ref(null)

const selectedStaffId = ref('')
const deptChecked = ref(new Set())

const newSubjectName = ref('')
const newClassId = ref('')
const newPaperNumber = ref(1)
const newPeriodsPerWeek = ref(5)
const roleChoice = ref('teacher')

async function loadBase() {
  const { data } = await assignTeacherApi.get(selectedStaffId.value)
  teachers.value = data.teachers
  departments.value = data.departments
  subjectsByName.value = data.subjects_by_name
  classes.value = data.classes
  detail.value = data.detail
  if (detail.value) {
    deptChecked.value = new Set(detail.value.departments)
    roleChoice.value = detail.value.current_role || 'teacher'
  }
}

onMounted(async () => {
  loading.value = true
  try {
    await loadBase()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load teacher assignments.'
  } finally {
    loading.value = false
  }
})

watch(selectedStaffId, async () => {
  loading.value = true
  try {
    await loadBase()
  } finally {
    loading.value = false
  }
})

const classOptionsForSubject = computed(() => {
  const entry = subjectsByName.value[newSubjectName.value] || {}
  return classes.value.filter((c) => entry[c.class_name])
})
const currentSubjectEntry = computed(() => {
  const entry = subjectsByName.value[newSubjectName.value] || {}
  const cls = classes.value.find((c) => c.id === Number(newClassId.value))
  return cls ? entry[cls.class_name] : null
})
watch(newSubjectName, () => { newClassId.value = '' })

async function runAction(payload, successMsgOverride) {
  busy.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await assignTeacherApi.action({ staff_id: selectedStaffId.value, ...payload })
    teachers.value = data.teachers
    departments.value = data.departments
    subjectsByName.value = data.subjects_by_name
    classes.value = data.classes
    detail.value = data.detail
    if (detail.value) deptChecked.value = new Set(detail.value.departments)
    success.value = successMsgOverride || data.message
  } catch (e) {
    error.value = e.response?.data?.message || 'That action failed.'
  } finally {
    busy.value = false
  }
}

function toggleDept(id) {
  const s = new Set(deptChecked.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  deptChecked.value = s
}
function saveDepartments() {
  runAction({ action: 'save_departments', department_ids: Array.from(deptChecked.value) })
}
function addAssignment() {
  if (!currentSubjectEntry.value || !newClassId.value) return
  runAction({
    action: 'add_assignment',
    subject_id: currentSubjectEntry.value.id,
    class_id: newClassId.value,
    paper_number: newPaperNumber.value,
    periods_per_week: newPeriodsPerWeek.value
  })
  newSubjectName.value = ''
  newClassId.value = ''
}
function updatePeriods(a) {
  runAction({ action: 'update_periods', assignment_id: a.id, periods_per_week: a.periods_per_week })
}
function removeAssignment(a) {
  runAction({ action: 'remove_assignment', assignment_id: a.id })
}
function changeRole() {
  runAction({ action: 'change_role', role: roleChoice.value })
}
</script>

<template>
  <h1 class="page-title">Teacher Assignments</h1>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <div class="section">
    <label>Select a Teacher</label>
    <select v-model="selectedStaffId">
      <option value="">-- Choose --</option>
      <option v-for="t in teachers" :key="t.staff_id" :value="t.staff_id">{{ t.first_name }} {{ t.last_name }}</option>
    </select>
  </div>

  <p v-if="loading" class="empty">Loading…</p>

  <template v-else-if="detail && detail.teacher">
    <div class="section">
      <h2>Departments</h2>
      <label v-for="d in departments" :key="d.id" class="dept-check">
        <input type="checkbox" :checked="deptChecked.has(d.id)" @change="toggleDept(d.id)">
        {{ d.department_name }}
      </label>
      <div v-if="!departments.length" class="empty">No departments created yet.</div>
      <button type="button" :disabled="busy" @click="saveDepartments">Save Departments</button>
    </div>

    <div class="section">
      <h2>Teaching Assignments</h2>
      <div class="table-wrap">
        <table style="margin-bottom:18px;">
          <tr><th>Subject</th><th>Paper</th><th>Class</th><th>Stream</th><th>Periods/Week</th><th></th></tr>
          <tr v-for="a in detail.assignments" :key="a.id">
            <td>{{ a.subject_name }}</td>
            <td>{{ a.papers_count > 1 ? 'Paper ' + a.paper_number : '—' }}</td>
            <td>{{ a.class_name }}</td>
            <td>{{ a.stream_name || '—' }}</td>
            <td>
              <div style="display:flex;gap:6px;align-items:center;">
                <input type="number" min="1" max="15" v-model.number="a.periods_per_week" style="width:60px;padding:6px;">
                <button type="button" style="padding:6px 10px;font-size:0.75rem;" :disabled="busy" @click="updatePeriods(a)">Save</button>
              </div>
            </td>
            <td><button type="button" class="danger-btn" :disabled="busy" @click="removeAssignment(a)">Remove</button></td>
          </tr>
          <tr v-if="!detail.assignments.length"><td colspan="6" class="empty">No teaching assignments yet.</td></tr>
        </table>
      </div>

      <form @submit.prevent="addAssignment">
        <div class="row">
          <div>
            <label>Subject</label>
            <select v-model="newSubjectName" required>
              <option value="">-- Choose --</option>
              <option v-for="name in Object.keys(subjectsByName).sort()" :key="name" :value="name">{{ name }}</option>
            </select>
          </div>
          <div>
            <label>Class</label>
            <select v-model="newClassId" required :disabled="!newSubjectName">
              <option value="">-- Choose {{ newSubjectName ? '' : 'subject first' }} --</option>
              <option v-for="c in classOptionsForSubject" :key="c.id" :value="c.id">{{ c.class_name }}{{ c.stream_name ? ' ' + c.stream_name : '' }}</option>
            </select>
          </div>
          <div v-if="currentSubjectEntry && currentSubjectEntry.papers_count > 1">
            <label>Paper</label>
            <select v-model.number="newPaperNumber">
              <option v-for="n in currentSubjectEntry.papers_count" :key="n" :value="n">Paper {{ n }}</option>
            </select>
          </div>
          <div>
            <label>Periods/Week</label>
            <input type="number" min="1" max="15" v-model.number="newPeriodsPerWeek" required>
          </div>
        </div>
        <button type="submit" :disabled="busy">Add Assignment</button>
      </form>
    </div>

    <div class="section">
      <h2>Role</h2>
      <div v-if="detail.current_role === null" class="empty">This staff member doesn't have a portal login yet — create one from Manage Teachers first.</div>
      <form v-else @submit.prevent="changeRole">
        <label>Primary Role (one at a time — class teacher is set separately, on the Classes page)</label>
        <select v-model="roleChoice">
          <option value="teacher">Teacher</option>
          <option value="dos">Dos</option>
          <option value="headteacher">Headteacher</option>
          <option value="bursar">Bursar</option>
        </select>
        <button type="submit" :disabled="busy">Update Role</button>
      </form>
    </div>
  </template>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 14px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
label:first-child{margin-top:0;}
select,input{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.75rem;margin-top:0;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.dept-check{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:0.85rem;margin:0;}
.dept-check input{width:auto;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:600px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:160px;}
.empty{color:var(--muted);font-size:0.85rem;}
.table-wrap{overflow-x:auto;}
</style>
