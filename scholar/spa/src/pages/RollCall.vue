<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { attendanceApi } from '../services/api'

const STATUS_LABELS = { present: 'Present', absent: 'Absent', sick: 'Sick', permission: 'Permission' }
const today = new Date().toISOString().slice(0, 10)

const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const message = ref(null)

const classes = ref([])
const selectedClass = ref('')
const selectedDate = ref(today)
const roster = ref([])
const takenAt = ref(null)
const counts = ref(null)
const activeFilter = ref('all')

async function loadClasses() {
  try {
    const { data } = await attendanceApi.get(null, selectedDate.value)
    classes.value = data.classes
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your classes.'
  } finally {
    loading.value = false
  }
}

async function loadRoster() {
  if (!selectedClass.value) { roster.value = []; return }
  loading.value = true
  error.value = null
  activeFilter.value = 'all'
  try {
    const { data } = await attendanceApi.get(selectedClass.value, selectedDate.value)
    roster.value = data.roster.map((r) => ({ ...r, status: r.status || 'present' }))
    takenAt.value = data.taken_at
    counts.value = data.counts
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load this roster.'
  } finally {
    loading.value = false
  }
}

onMounted(loadClasses)
watch([selectedClass, selectedDate], loadRoster)

const visibleRoster = computed(() =>
  activeFilter.value === 'all' ? roster.value : roster.value.filter((r) => r.status === activeFilter.value)
)

async function save() {
  saving.value = true
  message.value = null
  try {
    const status = {}
    roster.value.forEach((r) => { status[r.id] = r.status })
    const { data } = await attendanceApi.save({ class_id: selectedClass.value, attendance_date: selectedDate.value, status })
    roster.value = data.roster.map((r) => ({ ...r, status: r.status || 'present' }))
    takenAt.value = data.taken_at
    counts.value = data.counts
    message.value = { type: 'success', text: `Roll call saved for ${data.touched} student(s).` }
  } catch (e) {
    message.value = { type: 'danger', text: e.response?.data?.message || 'Could not save roll call.' }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-title">Roll Call</div>

  <div v-if="message" class="alert" :class="'alert-' + message.type">{{ message.text }}</div>

  <div class="filters">
    <select v-model="selectedClass">
      <option value="">Choose class…</option>
      <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.class_name }} {{ c.stream_name || '' }}</option>
    </select>
    <input type="date" v-model="selectedDate">
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else-if="roster.length">
    <p v-if="takenAt" style="color:var(--muted);font-size:0.85rem;margin:-8px 0 16px;">
      Roll call taken at {{ new Date(takenAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }}
      on {{ new Date(takenAt).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' }) }}.
    </p>

    <div v-if="takenAt" class="stat-cards">
      <button type="button" class="stat-card all" :class="{ active: activeFilter === 'all' }" @click="activeFilter = 'all'">
        <div class="n">{{ roster.length }}</div><div class="label">All</div>
      </button>
      <button type="button" class="stat-card" style="--stat-color:var(--green);" :class="{ active: activeFilter === 'present' }" @click="activeFilter = 'present'">
        <div class="n">{{ counts.present }}</div><div class="label">Present</div>
      </button>
      <button type="button" class="stat-card" style="--stat-color:var(--danger);" :class="{ active: activeFilter === 'absent' }" @click="activeFilter = 'absent'">
        <div class="n">{{ counts.absent }}</div><div class="label">Absent</div>
      </button>
      <button type="button" class="stat-card" style="--stat-color:var(--amber);" :class="{ active: activeFilter === 'sick' }" @click="activeFilter = 'sick'">
        <div class="n">{{ counts.sick }}</div><div class="label">Sick</div>
      </button>
      <button type="button" class="stat-card" style="--stat-color:var(--cyan);" :class="{ active: activeFilter === 'permission' }" @click="activeFilter = 'permission'">
        <div class="n">{{ counts.permission }}</div><div class="label">Permission</div>
      </button>
    </div>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Student</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="r in visibleRoster" :key="r.id">
            <td>{{ r.full_name }} <span style="color:var(--muted);">({{ r.student_no || '—' }})</span></td>
            <td>
              <div class="status-group">
                <label v-for="(label, val) in STATUS_LABELS" :key="val">
                  <input type="radio" :name="'status-' + r.id" :value="val" v-model="r.status">
                  {{ label }}
                </label>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div style="margin-top:16px;"><button type="button" class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save Roll Call' }}</button></div>
  </template>
  <p v-else-if="selectedClass" class="empty">No students found in this class.</p>
  <p v-else class="empty">Pick a class and date to take the register.</p>
</template>

<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.filters{display:flex;gap:12px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:20px;}
select,input[type=date]{background:var(--panel);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;}
button{cursor:pointer;border:none;border-radius:6px;padding:8px 16px;font-weight:700;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.btn-primary{background:var(--cyan);color:#04121a;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);}
.status-group{display:flex;gap:10px;flex-wrap:wrap;}
.status-group label{display:flex;align-items:center;gap:4px;font-size:0.78rem;color:var(--muted);cursor:pointer;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.stat-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:14px 16px;cursor:pointer;text-align:left;font-family:inherit;transition:border-color .15s,transform .1s;}
.stat-card:hover{border-color:var(--stat-color,var(--cyan));}
.stat-card.active{border-color:var(--stat-color,var(--cyan));background:color-mix(in srgb, var(--stat-color,var(--cyan)) 12%, var(--panel));}
.stat-card .n{font-size:1.6rem;font-weight:700;color:var(--stat-color,var(--cyan));}
.stat-card .label{color:var(--muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.stat-card.all{--stat-color:var(--text);}
.table-wrap{overflow-x:auto;}
</style>
