<script setup>
import { ref, onMounted } from 'vue'
import { libraryOverviewApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const docs = ref([])

onMounted(async () => {
  try {
    const { data } = await libraryOverviewApi.get()
    docs.value = data.documents
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the library overview.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <h2>Library Overview</h2>
  <p class="sub">School-wide view of notes and past papers shared by teachers (latest 200).</p>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <div v-else class="lib-card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Title</th><th>Type</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Term</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-if="!docs.length"><td colspan="7" class="empty-cell">No documents yet.</td></tr>
          <tr v-for="(d, i) in docs" :key="i">
            <td>{{ d.title }}</td>
            <td>{{ d.category === 'notes' ? 'Notes' : 'Past Paper' }}</td>
            <td>{{ d.class_name }}</td>
            <td>{{ d.subject_name }}</td>
            <td>{{ d.teacher_name }}</td>
            <td>{{ (d.term || d.year) ? `${d.term || ''} ${d.year || ''}`.trim() : '—' }}</td>
            <td><span class="badge" :class="'badge-' + d.status.toLowerCase()">{{ d.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
h2{color:var(--text);margin:0 0 4px;}
.sub{color:var(--muted);margin:0 0 20px;}
.empty{color:var(--muted);font-size:0.85rem;}
.lib-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:680px;}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.empty-cell{color:var(--muted);}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;}
.badge-draft{background:rgba(100,116,139,0.2);color:var(--muted);}
.badge-published{background:rgba(16,185,129,0.15);color:var(--green);}
.table-wrap{overflow-x:auto;}
</style>
