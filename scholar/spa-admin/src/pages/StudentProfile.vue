<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { studentProfileApi, scholarBase } from '../services/api'

const SB = scholarBase()
const route = useRoute()
const studentId = computed(() => route.params.id)

const loading = ref(true)
const error = ref(null)
const student = ref(null)
const attendance = ref({ total_days: 0, present_days: 0 })
const fees = ref({ paid: 0, due: 0 })
const results = ref([])

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await studentProfileApi.get(studentId.value)
    student.value = data.student
    attendance.value = data.attendance
    fees.value = data.fees
    results.value = data.results
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load this student profile.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const initial = computed(() => (student.value?.full_name || student.value?.student_name || '?').charAt(0).toUpperCase())
const studentName = computed(() => student.value?.full_name ?? student.value?.student_name ?? '')
</script>

<template>
  <p v-if="loading" class="empty">Loading…</p>
  <div v-else-if="error" class="alert error">{{ error }}</div>

  <template v-else-if="student">
    <header class="profile-header">
      <div class="student-title">
        <div class="avatar">{{ initial }}</div>
        <div>
          <h1>{{ studentName }}</h1>
          <p>Student Profile | {{ student.class_name || 'No Class' }}</p>
        </div>
      </div>
      <div class="actions">
        <router-link to="/students">← Students</router-link>
        <a :href="`${SB}school_admin/edit_student.php?id=${student.id}`">Edit Profile</a>
        <router-link :to="`/student-subjects/${student.id}`">Subjects</router-link>
      </div>
    </header>

    <section class="profile-grid">
      <div class="profile-card">
        <h3>Personal Information</h3>
        <div class="info-row"><label>Admission ID</label><span>#{{ student.id }}</span></div>
        <div class="info-row"><label>Gender</label><span>{{ student.gender }}</span></div>
        <div class="info-row"><label>Date Registered</label><span>{{ student.created_at || 'N/A' }}</span></div>
      </div>

      <div class="profile-card">
        <h3>Parent / Guardian</h3>
        <div class="info-row"><label>Name</label><span>{{ student.parent_name || 'Not Provided' }}</span></div>
        <div class="info-row"><label>Phone</label><span>{{ student.parent_phone || 'Not Provided' }}</span></div>
        <div class="info-row"><label>Relationship</label><span>Guardian</span></div>
      </div>

      <div class="profile-card">
        <h3>Academic Information</h3>
        <div class="info-row"><label>Class</label><span>{{ student.class_name || 'Unassigned' }}</span></div>
        <div class="info-row"><label>Status</label><span class="active">Active</span></div>
      </div>
    </section>

    <section class="stats">
      <div class="stat-card">
        <h4>Attendance</h4>
        <div class="big-number">{{ attendance.present_days || 0 }} / {{ attendance.total_days || 0 }}</div>
        <p>Days Present</p>
      </div>
      <div class="stat-card">
        <h4>Fees Paid</h4>
        <div class="big-number">{{ Number(fees.paid || 0).toLocaleString() }}</div>
        <p>Total Paid</p>
      </div>
      <div class="stat-card">
        <h4>Outstanding Fees</h4>
        <div class="big-number danger">{{ Number(fees.due || 0).toLocaleString() }}</div>
        <p>Balance</p>
      </div>
    </section>

    <section class="panel">
      <h2>Recent Examination Results</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Exam</th><th>Subject</th><th>Marks</th><th>Grade</th><th>Position</th></tr></thead>
          <tbody>
            <tr v-if="!results.length"><td colspan="5" class="empty-row">No examination records available.</td></tr>
            <tr v-for="r in results" :key="r.id">
              <td>{{ r.exam_name }}</td>
              <td>{{ r.subject }}</td>
              <td>{{ r.marks }}</td>
              <td>{{ r.grade }}</td>
              <td>{{ r.position }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="modules">
      <router-link :to="`/attendance-history/${student.id}`">Attendance History</router-link>
      <span class="disabled">Fee Statement<small>Coming soon</small></span>
      <span class="disabled">Documents<small>Coming soon</small></span>
      <span class="disabled">SMS History<small>Coming soon</small></span>
    </section>
  </template>
</template>

<style scoped>
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;}
.alert{padding:12px 16px;border-radius:8px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.profile-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;padding-bottom:25px;border-bottom:1px solid var(--border);margin-bottom:30px;}
.student-title{display:flex;align-items:center;gap:20px;}
.student-title h1{margin:0;font-size:1.5rem;color:var(--text);}
.student-title p{margin-top:6px;color:var(--muted);font-size:0.85rem;}
.avatar{width:64px;height:64px;border-radius:50%;display:flex;justify-content:center;align-items:center;font-size:2rem;background:linear-gradient(135deg,var(--cyan),var(--purple));flex-shrink:0;}
.actions{display:flex;flex-wrap:wrap;gap:8px;}
.actions a{text-decoration:none;color:var(--text);background:var(--panel);border:1px solid var(--border);padding:10px 16px;border-radius:8px;font-size:0.82rem;}
.actions a:hover{border-color:var(--cyan);}
.profile-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:24px;}
.profile-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:22px;}
.profile-card h3{margin-top:0;margin-bottom:16px;color:#94a3b8;font-size:0.8rem;text-transform:uppercase;letter-spacing:1px;}
.info-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);}
.info-row:last-child{border-bottom:none;}
.info-row label{color:var(--muted);font-size:0.78rem;}
.info-row span{color:var(--text);font-weight:600;font-size:0.85rem;}
.active{color:#6ee7b7!important;}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:20px;margin-bottom:24px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:22px;text-align:center;}
.stat-card h4{margin:0;color:var(--muted);text-transform:uppercase;font-size:0.72rem;}
.big-number{margin:16px 0;font-size:1.8rem;font-weight:900;color:var(--text);}
.big-number.danger{color:#f87171;}
.stat-card p{color:var(--muted);margin:0;font-size:0.8rem;}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:22px;margin-bottom:24px;}
.panel h2{margin-top:0;color:#94a3b8;font-size:0.85rem;text-transform:uppercase;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:600px;font-size:0.85rem;}
th{padding:12px;text-align:left;color:var(--muted);font-size:0.68rem;text-transform:uppercase;border-bottom:1px solid var(--border);}
td{padding:13px 12px;border-bottom:1px solid var(--border);}
tr:hover td{background:var(--panel-raised);}
.empty-row{text-align:center;color:var(--muted);}
.modules{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;}
.modules a{text-decoration:none;color:var(--text);background:var(--panel);border:1px solid var(--border);padding:22px;border-radius:12px;text-align:center;font-weight:700;transition:0.2s;font-size:0.85rem;}
.modules a:hover{transform:translateY(-3px);border-color:var(--cyan);}
.modules .disabled{text-decoration:none;color:var(--muted);background:var(--panel);border:1px dashed var(--border);padding:22px;text-align:center;border-radius:12px;font-weight:700;cursor:default;font-size:0.85rem;}
.modules .disabled small{display:block;font-weight:400;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;margin-top:6px;color:var(--muted);}
</style>
