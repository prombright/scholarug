<script setup>
import { ref, computed, onMounted } from 'vue'
import { studentsApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const success = ref(null)
const busy = ref(false)

const schoolType = ref('Secondary')
const students = ref([])
const classes = ref([])
const total = ref(0)
const male = ref(0)
const female = ref(0)

const search = ref('')
const classFilter = ref('')

// Register-new-student form state
const form = ref({ full_name: '', gender: '', level_type: 'O-Level', class_id: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await studentsApi.get()
    schoolType.value = data.school_type
    students.value = data.students
    classes.value = data.classes
    total.value = data.total
    male.value = data.male
    female.value = data.female
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load students.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Same S.<number> >= 5 => A-Level rule as admin_student_level_type() on the server.
function classLevel(className) {
  const m = className.match(/([1-9][0-9]*)/)
  if (!m) return ''
  return parseInt(m[1], 10) >= 5 ? 'A-Level' : 'O-Level'
}
const filteredClassOptions = computed(() =>
  schoolType.value === 'Primary' ? classes.value : classes.value.filter((c) => classLevel(c.class_name) === form.value.level_type)
)

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return students.value.filter((s) => {
    if (classFilter.value && String(s.class_id) !== classFilter.value) return false
    if (q && !s.full_name.toLowerCase().includes(q)) return false
    return true
  })
})

async function submitAction(payload, successOverride) {
  busy.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await studentsApi.action(payload)
    students.value = data.students
    classes.value = data.classes
    total.value = data.total
    male.value = data.male
    female.value = data.female
    success.value = successOverride || data.message
  } catch (e) {
    error.value = e.response?.data?.message || 'That action failed.'
  } finally {
    busy.value = false
  }
}

function registerStudent() {
  if (!form.value.full_name || !form.value.gender || !form.value.class_id) return
  submitAction({
    action: 'add_student',
    full_name: form.value.full_name,
    gender: form.value.gender,
    class_id: form.value.class_id,
    level_type: schoolType.value === 'Primary' ? 'Primary' : form.value.level_type
  })
  form.value = { full_name: '', gender: '', level_type: 'O-Level', class_id: '' }
}

function createLogin(row) {
  if (!confirm('Create a portal login for this student?')) return
  submitAction({ action: 'create_student_login', student_id: row.id })
}
function regeneratePassword(row) {
  if (!confirm("Generate a new password for this student? Their old password will stop working immediately.")) return
  submitAction({ action: 'regenerate_student_password', student_id: row.id })
}

const templateUrl = `${SB}school_admin/students.php?download_template=csv`
const printCredentialsUrl = `${SB}school_admin/print_student_credentials.php`
const csvImportAction = `${SB}school_admin/students.php`
</script>

<template>
  <div class="page-header">
    <div><h1>Student Management</h1></div>
    <div class="header-actions">
      <a :href="templateUrl" class="btn btn-ghost btn-sm">Download CSV Template</a>
      <a :href="printCredentialsUrl" class="btn btn-ghost btn-sm">Print Class Credentials</a>
    </div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="stat-grid">
      <div class="stat-card"><div class="n cyan">{{ total.toLocaleString() }}</div><div class="label">Total Students</div></div>
      <div class="stat-card"><div class="n">{{ male.toLocaleString() }}</div><div class="label">Male Students</div></div>
      <div class="stat-card"><div class="n">{{ female.toLocaleString() }}</div><div class="label">Female Students</div></div>
    </div>

    <details class="section-toggle">
      <summary>Register New Student</summary>
      <div class="form-body">
        <form @submit.prevent="registerStudent">
          <div class="row">
            <div><label>Full Name</label><input type="text" v-model="form.full_name" required placeholder="e.g. John Doe"></div>
            <div>
              <label>Gender</label>
              <select v-model="form.gender" required>
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div v-if="schoolType !== 'Primary'">
              <label>Level Type</label>
              <select v-model="form.level_type">
                <option value="O-Level">O-Level (S.1 - S.4)</option>
                <option value="A-Level">A-Level (S.5 - S.6)</option>
              </select>
            </div>
            <div>
              <label>Class</label>
              <select v-model="form.class_id" required>
                <option value="">Select Class</option>
                <option v-for="c in filteredClassOptions" :key="c.id" :value="c.id">{{ c.class_name }}</option>
              </select>
            </div>
          </div>
          <button type="submit" :disabled="busy" style="margin-top:16px;">Save Student</button>
        </form>
      </div>
    </details>

    <details class="section-toggle">
      <summary>Bulk CSV Import</summary>
      <div class="form-body">
        <form method="POST" enctype="multipart/form-data" :action="csvImportAction">
          <input type="hidden" name="action" value="import_csv">
          <p class="hint" style="margin-top:0;">Upload a CSV file to register multiple students at once. Format: <strong>Full Name, Gender, Class Name</strong> — O-Level/A-Level is set automatically from the class. Use "Download CSV Template" above for a ready-made example.</p>
          <label>Select CSV File</label>
          <input type="file" name="csv_file" accept=".csv" required>
          <button type="submit" style="margin-top:16px;">Upload &amp; Import</button>
        </form>
      </div>
    </details>

    <div class="filter-bar">
      <div class="row" style="flex:2;min-width:220px;">
        <div><label style="margin-top:0;">Search</label><input type="text" v-model="search" placeholder="Search by student full name..."></div>
      </div>
      <div class="row" style="flex:1;min-width:160px;">
        <div>
          <label style="margin-top:0;">Class</label>
          <select v-model="classFilter">
            <option value="">All Classes</option>
            <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.class_name }}</option>
          </select>
        </div>
      </div>
      <div class="hint" style="align-self:center;margin-top:0;">{{ filtered.length }} of {{ students.length }} students</div>
    </div>

    <div class="table-wrap">
      <table>
        <tr><th>ID</th><th>Full Name</th><th>Gender</th><th>Class</th><th>Level Type</th><th style="text-align:right;">Actions</th></tr>
        <tr v-for="row in filtered" :key="row.id">
          <td class="hint">#{{ row.id }}</td>
          <td><strong>{{ row.full_name }}</strong></td>
          <td><span class="pill muted">{{ row.gender || row.sex || 'N/A' }}</span></td>
          <td>{{ row.class_name || 'Unassigned' }}</td>
          <td><span class="pill">{{ row.level_type || '—' }}</span></td>
          <td>
            <div class="actions">
              <router-link :to="`/students/${row.id}`" class="btn btn-ghost btn-sm">Profile</router-link>
              <a :href="row.edit_url" class="btn btn-ghost btn-sm">Edit</a>
              <a :href="row.report_url" class="btn btn-ghost btn-sm" target="_blank">Report Card</a>
              <router-link :to="`/student-subjects/${row.id}`" class="btn btn-ghost btn-sm">Subjects</router-link>
              <span v-if="row.login_username" class="pill green" title="Portal username">{{ row.login_username }}</span>
              <span v-if="row.reset_requested" class="pill amber" title="A class teacher requested a password reset for this student">Reset requested</span>
              <button v-if="row.login_username" type="button" class="btn btn-ghost btn-sm" :disabled="busy" @click="regeneratePassword(row)">Regenerate Password</button>
              <button v-else type="button" class="btn btn-ghost btn-sm" :disabled="busy" @click="createLogin(row)">Create Login</button>
            </div>
          </td>
        </tr>
        <tr v-if="!filtered.length"><td colspan="6" class="empty-row">No student records found.</td></tr>
      </table>
    </div>
  </template>
</template>

<style scoped>
.page-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:22px;}
.page-header h1{font-size:1.4rem;margin:0 0 4px;}
.header-actions{display:flex;flex-wrap:wrap;gap:10px;}
.btn{display:inline-flex;align-items:center;gap:6px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:0.85rem;text-decoration:none;}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);}
.btn-sm{padding:6px 12px;font-size:0.78rem;}
.btn:disabled{opacity:0.6;cursor:default;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.stat-card .n{font-size:1.9rem;font-weight:700;}
.stat-card .n.cyan{color:var(--cyan);}
.stat-card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
details.section-toggle{background:var(--panel);border:1px solid var(--border);border-radius:10px;margin-bottom:16px;}
details.section-toggle summary{cursor:pointer;padding:16px 20px;font-weight:700;font-size:0.95rem;list-style:none;}
details.section-toggle summary::-webkit-details-marker{display:none;}
details.section-toggle summary::before{content:"+ ";color:var(--cyan);}
details.section-toggle[open] summary::before{content:"– ";}
details.section-toggle .form-body{padding:0 20px 20px;border-top:1px solid var(--border);padding-top:16px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:180px;}
.hint{color:var(--muted);font-size:0.8rem;margin-top:10px;}
.filter-bar{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.filter-bar .row > div{margin-bottom:0;}
table{width:100%;border-collapse:collapse;font-size:0.88rem;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;min-width:640px;}
th,td{text-align:left;padding:12px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.pill.green{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.muted{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.amber{background:rgba(245,158,11,0.15);color:var(--amber);}
.actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;align-items:center;}
.empty-row{text-align:center;color:var(--muted);padding:30px !important;}
.table-wrap{overflow-x:auto;}
</style>
