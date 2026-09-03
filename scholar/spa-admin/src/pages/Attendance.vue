<script setup>
import { ref, watch, onMounted } from 'vue'
import { attendanceApi, scholarBase } from '../services/api'

const SB = scholarBase()
const today = new Date().toISOString().slice(0, 10)

const loading = ref(true)
const saving = ref(false)
const message = ref(null)

const classes = ref([])
const selectedClass = ref('')
const selectedDate = ref(today)
const students = ref([])
const todaySummary = ref({})
const statusByStudent = ref({})

async function loadClasses() {
  try {
    const { data } = await attendanceApi.get(null, selectedDate.value)
    classes.value = data.classes
    todaySummary.value = data.today_summary
  } finally {
    loading.value = false
  }
}

async function loadStudents() {
  if (!selectedClass.value) { students.value = []; return }
  loading.value = true
  try {
    const { data } = await attendanceApi.get(selectedClass.value, selectedDate.value)
    students.value = data.students
    todaySummary.value = data.today_summary
    const map = {}
    students.value.forEach((s) => { map[s.id] = 'Present' })
    statusByStudent.value = map
  } finally {
    loading.value = false
  }
}

onMounted(loadClasses)
watch([selectedClass, selectedDate], loadStudents)

async function save() {
  saving.value = true
  message.value = null
  try {
    const { data } = await attendanceApi.save({ class_id: selectedClass.value, attendance_date: selectedDate.value, attendance: statusByStudent.value })
    message.value = { type: 'success', text: data.message }
    todaySummary.value = data.today_summary
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Unable to save attendance.' }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <header class="topbar">
    <div>
      <h1>Attendance Management</h1>
      <p>Daily Student Attendance</p>
    </div>
    <a :href="`${SB}school_admin/school_admin_dashboard.php`" class="back-btn">← Dashboard</a>
  </header>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <section class="panel">
    <h2>Select Attendance Session</h2>
    <div class="form-grid">
      <div>
        <label>Class</label>
        <select v-model="selectedClass">
          <option value="">Select Class</option>
          <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.class_name }}</option>
        </select>
      </div>
      <div>
        <label>Attendance Date</label>
        <input type="date" v-model="selectedDate">
      </div>
    </div>
  </section>

  <section class="stats">
    <div class="stat-card"><h3>Present</h3><strong>{{ todaySummary.Present || 0 }}</strong></div>
    <div class="stat-card"><h3>Absent</h3><strong>{{ todaySummary.Absent || 0 }}</strong></div>
    <div class="stat-card"><h3>Late</h3><strong>{{ todaySummary.Late || 0 }}</strong></div>
  </section>

  <section class="panel">
    <h2>Student Attendance Sheet</h2>

    <div v-if="loading" class="empty">Loading…</div>
    <div v-else-if="!selectedClass" class="empty">Please select a class to load students.</div>
    <div v-else-if="!students.length" class="empty">No students found in this class.</div>
    <template v-else>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Admission</th><th>Student Name</th><th>Gender</th><th>Attendance Status</th></tr></thead>
          <tbody>
            <tr v-for="s in students" :key="s.id">
              <td>#{{ s.id }}</td>
              <td><strong>{{ s.student_name }}</strong></td>
              <td>{{ s.gender }}</td>
              <td>
                <select v-model="statusByStudent[s.id]" class="attendance-select">
                  <option value="Present">Present</option>
                  <option value="Absent">Absent</option>
                  <option value="Late">Late</option>
                  <option value="Excused">Excused</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn" :disabled="saving" @click="save">{{ saving ? 'Saving…' : '✓ Save Attendance' }}</button>
    </template>
  </section>

  <section class="modules">
    <a :href="`${SB}school_admin/students.php`">Students</a>
    <a :href="`${SB}school_admin/student_profile.php`">Profiles</a>
    <span class="disabled">Reports<small>Coming soon</small></span>
    <span class="disabled">SMS Parents<small>Coming soon</small></span>
  </section>
</template>

<style>
.topbar{display:flex;justify-content:space-between;align-items:center;padding-bottom:25px;margin-bottom:30px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:15px;}
.topbar h1{margin:0;font-size:1.7rem;color:var(--text);}
.topbar p{color:var(--muted);margin:4px 0 0;}
.back-btn{text-decoration:none;color:var(--text);background:var(--panel);border:1px solid var(--border);padding:12px 18px;border-radius:8px;}
.alert{padding:15px;border-radius:10px;margin-bottom:25px;}
.alert.success{background:rgba(16,185,129,.1);border:1px solid var(--green);color:var(--green);}
.alert.error{background:rgba(239,68,68,.1);border:1px solid var(--danger);color:var(--danger);}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:15px;padding:25px;margin-bottom:25px;}
.panel h2{margin-top:0;color:var(--muted);font-size:.85rem;text-transform:uppercase;letter-spacing:1px;}
.empty{padding:40px;text-align:center;color:var(--muted);border:1px dashed var(--border);border-radius:10px;}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;}
label{display:block;color:var(--muted);font-size:.75rem;text-transform:uppercase;margin-bottom:8px;font-weight:700;}
input,select{width:100%;padding:13px;background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:8px;outline:none;box-sizing:border-box;}
input:focus,select:focus{border-color:var(--cyan);}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:30px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:15px;padding:25px;text-align:center;}
.stat-card h3{margin:0;color:var(--muted);text-transform:uppercase;font-size:.75rem;}
.stat-card strong{display:block;margin-top:15px;font-size:2.2rem;color:var(--text);}
.stat-card:nth-child(1){border-top:3px solid var(--green);}
.stat-card:nth-child(2){border-top:3px solid var(--danger);}
.stat-card:nth-child(3){border-top:3px solid var(--amber);}
.table-wrapper{overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:800px;}
th{padding:14px;text-align:left;color:var(--muted);font-size:.7rem;text-transform:uppercase;border-bottom:1px solid var(--border);}
td{padding:15px;border-bottom:1px solid var(--border);}
tr:hover{background:var(--border);}
.attendance-select{max-width:180px;}
.btn{margin-top:20px;background:linear-gradient(135deg,var(--cyan),var(--purple));border:none;color:white;padding:14px 25px;border-radius:8px;cursor:pointer;font-weight:800;}
.btn:disabled{opacity:.6;cursor:default;}
.modules{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.modules a{text-decoration:none;color:var(--text);background:var(--panel);border:1px solid var(--border);padding:25px;text-align:center;border-radius:15px;font-weight:700;transition:.3s;}
.modules a:hover{transform:translateY(-5px);border-color:var(--cyan);}
.modules .disabled{text-decoration:none;color:var(--muted);background:var(--panel);border:1px dashed var(--border);padding:25px;text-align:center;border-radius:15px;font-weight:700;cursor:default;}
.modules .disabled small{display:block;font-weight:400;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;margin-top:6px;color:var(--muted);}
@media(max-width:1100px){.stats{grid-template-columns:1fr;}.modules{grid-template-columns:repeat(2,1fr);}}
@media(max-width:650px){.topbar{flex-direction:column;align-items:flex-start;}.form-grid{grid-template-columns:1fr;}.modules{grid-template-columns:1fr;}}
</style>
