<script setup>
import { ref, onMounted, computed } from 'vue'
import { marksApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const assignments = ref([])
const assessments = ref([])
const progress = ref({})

const selectedAssessment = ref(null)
const selectedAssignment = ref(null)
const roster = ref([])
const saving = ref(null) // 'draft' | 'submitted' | null
const saveMessage = ref(null)

async function loadPicker() {
  loading.value = true
  error.value = null
  try {
    const { data } = await marksApi.get()
    assignments.value = data.assignments
    assessments.value = data.assessments
    progress.value = data.progress || {}
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your assignments.'
  } finally {
    loading.value = false
  }
}

onMounted(loadPicker)

function progressFor(assessmentId) {
  return progress.value[assessmentId] || { done: 0, total: assignments.value.length }
}

function pickAssessment(a) {
  selectedAssessment.value = a
}

async function pickAssignment(assign) {
  selectedAssignment.value = assign
  loading.value = true
  error.value = null
  saveMessage.value = null
  try {
    const { data } = await marksApi.get(selectedAssessment.value.id, assign.class_id, assign.subject_id, assign.paper_number)
    roster.value = data.roster.map((r) => ({ ...r, dirty: false }))
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load this roster.'
  } finally {
    loading.value = false
  }
}

function backToAssessments() {
  selectedAssessment.value = null
  selectedAssignment.value = null
  roster.value = []
  loadPicker()
}

function backToAssignments() {
  selectedAssignment.value = null
  roster.value = []
}

const submittedCount = computed(() => roster.value.filter((s) => !s.dirty && s.submission_status === 'submitted').length)
const draftCount = computed(() => roster.value.filter((s) => !s.dirty && s.submission_status === 'draft').length)
const emptyCount = computed(() => roster.value.filter((s) => !s.dirty && !s.submission_status).length)

async function save(action) {
  saving.value = action
  saveMessage.value = null
  try {
    const marks = {}
    roster.value.forEach((r) => { if (r.marks !== null && r.marks !== '') marks[r.student_id] = r.marks })

    const { data } = await marksApi.save({
      action,
      class_id: selectedAssignment.value.class_id,
      subject_id: selectedAssignment.value.subject_id,
      assessment_id: selectedAssessment.value.id,
      paper_number: selectedAssignment.value.paper_number,
      marks
    })

    roster.value = data.roster.map((r) => ({ ...r, dirty: false }))
    const outOfRangeNote = data.out_of_range > 0 ? ` (${data.out_of_range} out-of-range mark(s) skipped)` : ''
    saveMessage.value = {
      type: 'success',
      text: action === 'submitted'
        ? `${data.touched} mark(s) submitted — now counted on the report card.${outOfRangeNote}`
        : `${data.touched} mark(s) saved as draft — not yet on the report.${outOfRangeNote}`
    }
  } catch (e) {
    saveMessage.value = { type: 'danger', text: e.response?.data?.message || 'Error saving marks.' }
  } finally {
    saving.value = null
  }
}

const templateUrl = () => {
  const a = selectedAssignment.value
  return `${SB}teacher_marks_entry.php?download_marks_template=csv&class_id=${a.class_id}&subject_id=${a.subject_id}&assessment_id=${selectedAssessment.value.id}&paper_number=${a.paper_number}`
}
const importActionUrl = () => `${SB}teacher_marks_entry.php`
</script>

<template>
  <div class="page-title">Assessment Marks Entry</div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else-if="!selectedAssessment">
    <p v-if="!assessments.length" class="empty">No assessments are currently open for marks entry. Check back once your school admin opens one.</p>
    <p v-else-if="!assignments.length" class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</p>
    <template v-else>
      <p class="page-sub">Choose an assessment to enter marks for.</p>
      <div class="card-grid">
        <a v-for="a in assessments" :key="a.id" class="pick-card" href="#" @click.prevent="pickAssessment(a)">
          <h3>{{ a.title }}</h3>
          <div class="sub">{{ a.term }} {{ a.year }} · {{ a.weight_percentage }}% weight</div>
          <div class="progress-bar"><div class="progress-fill" :style="{ width: (progressFor(a.id).total ? Math.round(progressFor(a.id).done / progressFor(a.id).total * 100) : 0) + '%' }"></div></div>
          <div class="progress-label">{{ progressFor(a.id).done }} of {{ progressFor(a.id).total }} of your classes started</div>
        </a>
      </div>
    </template>
  </template>

  <template v-else-if="!selectedAssignment">
    <div class="breadcrumb"><a href="#" @click.prevent="backToAssessments">All Assessments</a> &rsaquo; {{ selectedAssessment.title }}</div>
    <p class="page-sub">Choose which of your classes to enter marks for.</p>
    <div class="card-grid">
      <a v-for="assign in assignments" :key="`${assign.class_id}-${assign.subject_id}-${assign.paper_number}`" class="pick-card" href="#" @click.prevent="pickAssignment(assign)">
        <h3>{{ assign.class_name }}</h3>
        <div class="sub">{{ assign.subject_name }} ({{ assign.subject_code }})<span v-if="assign.papers_count > 1"> — Paper {{ assign.paper_number }}</span></div>
      </a>
    </div>
  </template>

  <template v-else>
    <div class="breadcrumb">
      <a href="#" @click.prevent="backToAssessments">All Assessments</a> &rsaquo;
      <a href="#" @click.prevent="backToAssignments">{{ selectedAssessment.title }}</a> &rsaquo;
      {{ selectedAssignment.class_name }} — {{ selectedAssignment.subject_name }}
    </div>

    <div v-if="saveMessage" class="alert" :class="'alert-' + saveMessage.type">{{ saveMessage.text }}</div>

    <template v-if="roster.length">
      <div class="filters" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
        <a class="btn-link" :href="templateUrl()">Download Marks Template</a>
        <form method="POST" enctype="multipart/form-data" :action="importActionUrl()" style="display:flex;gap:10px;align-items:center;">
          <input type="hidden" name="class_id" :value="selectedAssignment.class_id">
          <input type="hidden" name="subject_id" :value="selectedAssignment.subject_id">
          <input type="hidden" name="assessment_id" :value="selectedAssessment.id">
          <input type="hidden" name="paper_number" :value="selectedAssignment.paper_number">
          <input type="file" name="marks_csv" accept=".csv" required style="width:auto;">
          <button type="submit" name="import_marks_csv" value="1" class="btn-ghost">Import CSV</button>
        </form>
      </div>

      <div class="section">
        <div class="section-header">
          <span>Student Roster Entry<span v-if="selectedAssignment.paper_number > 1"> — Paper {{ selectedAssignment.paper_number }}</span></span>
          <span class="stat-summary">{{ submittedCount }} submitted · {{ draftCount }} draft · {{ emptyCount }} not entered</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Student No.</th><th>Full Name</th><th style="width:160px;">Raw Mark (100%)</th><th style="width:110px;">Status</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in roster" :key="row.student_id">
                <td>{{ i + 1 }}</td>
                <td style="color:var(--muted);">{{ row.student_no || 'N/A' }}</td>
                <td>{{ row.full_name }}</td>
                <td>
                  <input type="number" step="0.1" min="0" max="100" v-model="row.marks" @input="row.dirty = true" placeholder="0 - 100">
                </td>
                <td>
                  <span v-if="row.dirty" class="pill dirty">Unsaved</span>
                  <span v-else-if="row.submission_status === 'submitted'" class="pill submitted">Submitted</span>
                  <span v-else-if="row.submission_status === 'draft'" class="pill draft">Draft</span>
                  <span v-else class="pill empty-pill">Not entered</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="section-footer">
          <button type="button" class="btn-ghost" :disabled="saving !== null" @click="save('draft')">{{ saving === 'draft' ? 'Saving…' : 'Save as Draft' }}</button>
          <button type="button" class="btn-primary" :disabled="saving !== null" @click="save('submitted')">{{ saving === 'submitted' ? 'Submitting…' : 'Submit to Report' }}</button>
        </div>
      </div>
    </template>
    <p v-else class="empty">No students found in this class.</p>
  </template>
</template>

<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.page-sub{color:var(--muted);font-size:0.85rem;margin:-10px 0 18px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:24px;}
input[type=number],input[type=file]{background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
input[type=number]:focus{outline:none;border-color:var(--cyan);}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.btn-primary{background:var(--cyan);color:#04121a;}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.stat-summary{color:var(--muted);font-size:0.78rem;font-weight:500;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:12px 20px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;background:rgba(255,255,255,0.02);}
tbody tr:last-child td{border-bottom:none;}
.section-footer{padding:16px 20px;text-align:right;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.pill{display:inline-block;font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.pill.submitted{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.draft{background:rgba(245,158,11,0.14);color:var(--amber);}
.pill.empty-pill{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.dirty{background:rgba(0,168,168,0.15);color:var(--cyan);}
.table-wrap{overflow-x:auto;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;}
.pick-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;}
.pick-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.pick-card h3{margin:0 0 6px;font-size:1rem;}
.pick-card .sub{color:var(--muted);font-size:0.78rem;margin-bottom:10px;}
.pick-card .progress-bar{background:var(--border);border-radius:6px;overflow:hidden;height:6px;margin-bottom:6px;}
.pick-card .progress-fill{height:100%;background:var(--cyan);}
.pick-card .progress-label{font-size:0.72rem;color:var(--muted);}
.breadcrumb{color:var(--muted);font-size:0.82rem;margin-bottom:18px;}
.breadcrumb a{color:var(--cyan);text-decoration:none;}
</style>
