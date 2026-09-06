<script setup>
import { ref, computed, onMounted } from 'vue'
import { assessmentsApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const assessments = ref([])
const weightUsedByTerm = ref({})
const currentYear = new Date().getFullYear()

const form = ref({ title: '', weight_percentage: '', term: 'Term 1', year: currentYear, status: 'Open', include_in_report: true })
const rowEdits = ref({}) // id => { weight_percentage, status, include_in_report }

async function load() {
  loading.value = true
  try {
    const { data } = await assessmentsApi.get()
    assessments.value = data.assessments
    weightUsedByTerm.value = data.weight_used_by_term
    const edits = {}
    assessments.value.forEach((a) => {
      edits[a.id] = { weight_percentage: a.weight_percentage, status: a.status, include_in_report: !!a.include_in_report }
    })
    rowEdits.value = edits
  } catch (e) {
    message.value = { type: 'danger', text: e.response?.data?.message || 'Could not load assessments.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

const weightHint = computed(() => {
  if (!form.value.include_in_report) return { text: 'Not counted on the report, so it has no effect on the 100% cap.', warn: false }
  if (!form.value.term || !form.value.year) return { text: '', warn: false }
  const used = weightUsedByTerm.value[`${form.value.term}|${form.value.year}`] || 0
  const weight = parseFloat(form.value.weight_percentage) || 0
  const total = used + weight
  const remaining = Math.max(0, Math.round((100 - used) * 100) / 100)
  if (total > 100.001) {
    return { text: `${form.value.term} ${form.value.year} already has ${used}% allocated -- this would push it to ${Math.round(total * 100) / 100}%, over the 100% cap. Only ${remaining}% is available.`, warn: true }
  }
  return { text: `${form.value.term} ${form.value.year}: ${used}% already allocated, ${remaining}% remaining before adding this one.`, warn: false }
})

async function createAssessment() {
  busy.value = true
  message.value = null
  try {
    const { data } = await assessmentsApi.action({ action: 'create_assessment', ...form.value })
    assessments.value = data.assessments
    weightUsedByTerm.value = data.weight_used_by_term
    message.value = { type: 'success', text: data.message }
    form.value = { title: '', weight_percentage: '', term: 'Term 1', year: currentYear, status: 'Open', include_in_report: true }
  } catch (e) {
    message.value = { type: 'danger', text: e.response?.data?.message || 'Could not create assessment.' }
  } finally {
    busy.value = false
  }
}

async function saveRow(a) {
  busy.value = true
  message.value = null
  try {
    const edit = rowEdits.value[a.id]
    const { data } = await assessmentsApi.action({ action: 'update_assessment', id: a.id, ...edit })
    assessments.value = data.assessments
    weightUsedByTerm.value = data.weight_used_by_term
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'danger', text: e.response?.data?.message || 'Could not update assessment.' }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head"><h1>Assessments</h1></div>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <div class="section">
    <h2>New Assessment</h2>
    <form @submit.prevent="createAssessment">
      <div class="new-form-row">
        <div><label>Title</label><input type="text" v-model="form.title" placeholder="e.g. Midterm Test" required></div>
        <div><label>Weight %</label><input type="number" step="0.01" min="0.01" max="100" v-model="form.weight_percentage" required></div>
        <div>
          <label>Term</label>
          <select v-model="form.term" required>
            <option value="Term 1">Term 1</option>
            <option value="Term 2">Term 2</option>
            <option value="Term 3">Term 3</option>
          </select>
        </div>
        <div><label>Year</label><input type="number" v-model="form.year" required></div>
        <div>
          <label>Status</label>
          <select v-model="form.status">
            <option value="Draft">Draft</option>
            <option value="Open">Open (teachers can enter marks)</option>
            <option value="Closed">Closed</option>
          </select>
        </div>
        <div class="check-row">
          <input type="checkbox" id="include_new" v-model="form.include_in_report">
          <label for="include_new">On report</label>
        </div>
      </div>
      <div class="weight-note" :class="{ 'weight-warn': weightHint.warn }" style="margin-top:10px;" v-html="weightHint.text"></div>
      <div style="margin-top:6px;"><button type="submit" :disabled="busy">Create Assessment</button></div>
    </form>
  </div>

  <div class="section">
    <h2>Existing Assessments</h2>
    <p v-if="loading" class="empty">Loading…</p>
    <div v-else class="table-wrap">
      <table>
        <thead><tr><th>Title</th><th>Term / Year</th><th>Weight %</th><th>Status</th><th>On Report?</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!assessments.length"><td colspan="6" class="empty">No assessments yet — create one above.</td></tr>
          <tr v-for="a in assessments" :key="a.id">
            <td><strong>{{ a.title }}</strong></td>
            <td>{{ a.term }} / {{ a.year }}</td>
            <td class="paper-cell"><input type="number" step="0.01" min="0.01" max="100" v-model="rowEdits[a.id].weight_percentage"></td>
            <td class="status-cell">
              <span class="pill" :class="a.status">{{ a.status }}</span>
              <select v-model="rowEdits[a.id].status">
                <option value="Draft">Draft</option>
                <option value="Open">Open</option>
                <option value="Closed">Closed</option>
              </select>
            </td>
            <td style="text-align:center;"><input type="checkbox" v-model="rowEdits[a.id].include_in_report" style="width:auto;"></td>
            <td><button type="button" class="save-btn" :disabled="busy" @click="saveRow(a)">Save</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="weight-note" style="margin-top:10px;">
      "On Report" controls whether this assessment's marks count toward the weighted grade on the report card.
      Unchecking it keeps the marks recorded but excludes them from the report. Split the 100% however your school
      actually grades a term — e.g. AOI 10%, Mid-Term 10%, Final Exam 80% — a term's report-counted weights just
      can't add up to more than 100%.
    </p>
  </div>
</template>

<style scoped>
.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-head h1{font-size:1.4rem;margin:0;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 16px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.danger{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
input:focus,select:focus{outline:none;border-color:var(--cyan);}
.new-form-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1.4fr auto;gap:14px;align-items:end;}
@media (max-width:900px){.new-form-row{grid-template-columns:1fr 1fr;}}
.check-row{display:flex;align-items:center;gap:8px;padding-bottom:9px;}
.check-row input{width:auto;}
.check-row label{margin:0;text-transform:none;font-size:0.82rem;color:var(--text);letter-spacing:0;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;white-space:nowrap;}
button:disabled{opacity:0.6;cursor:default;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:640px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
td select,td input[type=number]{padding:7px 8px;font-size:0.82rem;}
td.paper-cell{max-width:110px;}
.status-cell{max-width:150px;}
.pill{display:inline-block;font-size:0.68rem;padding:3px 9px;border-radius:20px;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:6px;width:fit-content;}
.pill.Draft{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.Open{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.Closed{background:rgba(239,68,68,0.1);color:var(--danger);}
.weight-note{color:var(--muted);font-size:0.78rem;margin-top:14px;line-height:1.6;}
.weight-note strong{color:var(--text);}
.weight-warn{color:var(--amber);}
.save-btn{background:transparent;color:var(--cyan);border:1px solid rgba(0,168,168,0.4);padding:7px 14px;font-size:0.78rem;}
.table-wrap{overflow-x:auto;}
</style>
