<script setup>
import { ref, onMounted } from 'vue'
import { resultsApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const marks = ref([])
const reportUrl = ref('#')

onMounted(async () => {
  try {
    const { data } = await resultsApi.get()
    marks.value = data.marks
    reportUrl.value = data.report_url
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your results.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="section-title">My Results</div>
  <a class="print-btn" :href="reportUrl" target="_blank">Print My Report Card</a>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <div v-else-if="!marks.length" class="empty">No published results yet. Your school will publish results here once marking is complete.</div>
  <div v-else class="table-wrap">
    <table>
      <thead><tr><th>Assessment</th><th>Subject</th><th>Marks</th><th>Term</th></tr></thead>
      <tbody>
        <tr v-for="(m, i) in marks" :key="i">
          <td>{{ m.assessment_title }}</td>
          <td>{{ m.subject_name }}<span v-if="m.papers_count > 1"> (Paper {{ m.paper_number }})</span></td>
          <td>{{ m.marks }}</td>
          <td>{{ m.term }} {{ m.year }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:0.9rem;min-width:480px;}
th,td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.72rem;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.empty{color:var(--muted);font-size:0.9rem;padding:16px;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
.print-btn{display:inline-block;margin-bottom:18px;padding:10px 18px;border-radius:8px;background:var(--cyan);color:#04121a;font-weight:700;font-size:0.85rem;text-decoration:none;}
.table-wrap{overflow-x:auto;}
</style>
