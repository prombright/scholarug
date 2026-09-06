<script setup>
import { ref, onMounted } from 'vue'
import { libraryApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const assignments = ref([])
const documents = ref([])
const busyDocId = ref(null)
const selectedAssignment = ref('')

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await libraryApi.get()
    assignments.value = data.assignments
    documents.value = data.documents
    if (assignments.value.length) selectedAssignment.value = `${assignments.value[0].class_id}|${assignments.value[0].subject_id}`
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your library documents.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function toggleStatus(doc) {
  busyDocId.value = doc.id
  try {
    const { data } = await libraryApi.toggleStatus(doc.id)
    documents.value = data.documents
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not update this document.'
  } finally {
    busyDocId.value = null
  }
}

async function remove(doc) {
  if (!confirm('Delete this document?')) return
  busyDocId.value = doc.id
  try {
    const { data } = await libraryApi.delete(doc.id)
    documents.value = data.documents
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not delete this document.'
  } finally {
    busyDocId.value = null
  }
}

const previewUrl = (doc) => `${SB}library/view.php?id=${doc.id}`
const uploadActionUrl = `${SB}library/manage.php`
</script>

<template>
  <div class="page-title-row"><h1>Library</h1></div>

  <div v-if="error" class="alert alert-error">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>

  <template v-else>
    <div v-if="!assignments.length" class="section"><div class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</div></div>

    <div v-else class="section">
      <form method="POST" enctype="multipart/form-data" :action="uploadActionUrl">
        <input type="hidden" name="action" value="upload">
        <div class="upload-grid">
          <div>
            <label>Title</label>
            <input type="text" name="title" required maxlength="255" placeholder="e.g. Chapter 4 Notes">
          </div>
          <div>
            <label>Type</label>
            <select name="category" required>
              <option value="notes">Notes</option>
              <option value="past_paper">Past Paper</option>
            </select>
          </div>
          <div>
            <label>Class / Subject</label>
            <select name="class_subject" v-model="selectedAssignment" required>
              <option v-for="a in assignments" :key="`${a.class_id}-${a.subject_id}`" :value="`${a.class_id}|${a.subject_id}`">{{ a.class_name }} — {{ a.subject_name }}</option>
            </select>
            <input type="hidden" name="class_id" :value="selectedAssignment.split('|')[0]">
            <input type="hidden" name="subject_id" :value="selectedAssignment.split('|')[1]">
          </div>
          <div>
            <label>Term (optional)</label>
            <input type="text" name="term" maxlength="20" placeholder="e.g. Term 2">
          </div>
          <div>
            <label>Year (optional)</label>
            <input type="number" name="year" min="2000" max="2100">
          </div>
          <div>
            <label>PDF File</label>
            <input type="file" name="pdf" accept="application/pdf" required>
          </div>
          <div>
            <button type="submit">Upload</button>
          </div>
        </div>
      </form>
    </div>

    <div class="section" style="padding:0;">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Title</th><th>Type</th><th>Class</th><th>Subject</th><th>Term</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-if="!documents.length"><td colspan="7" class="empty">No documents yet — upload your first one above.</td></tr>
            <tr v-for="d in documents" :key="d.id">
              <td>{{ d.title }}</td>
              <td :class="'cat-' + d.category">{{ d.category === 'notes' ? 'Notes' : 'Past Paper' }}</td>
              <td>{{ d.class_name }}</td>
              <td>{{ d.subject_name }}</td>
              <td>{{ (d.term || d.year) ? `${d.term || ''} ${d.year || ''}`.trim() : '—' }}</td>
              <td><span class="badge" :class="'badge-' + d.status.toLowerCase()">{{ d.status }}</span></td>
              <td>
                <a class="act" :href="previewUrl(d)" target="_blank">Preview</a>
                <button type="button" class="status-btn" :disabled="busyDocId === d.id" @click="toggleStatus(d)">{{ d.status === 'Published' ? 'Unpublish' : 'Publish' }}</button>
                &nbsp;
                <button type="button" class="link-btn" :disabled="busyDocId === d.id" @click="remove(d)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </template>
</template>

<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-error{background:rgba(239,68,68,0.12);color:var(--danger);}
.alert-ok{background:rgba(16,185,129,0.12);color:var(--green);}
.upload-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
button:disabled{opacity:0.6;cursor:default;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:680px;}
th,td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;}
.badge-draft{background:rgba(100,116,139,0.2);color:var(--muted);}
.badge-published{background:rgba(16,185,129,0.15);color:var(--green);}
.cat-notes{color:var(--cyan);}
.cat-past_paper{color:var(--amber);}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;margin-right:10px;}
button.link-btn{background:none;color:var(--danger);padding:0;font-weight:600;font-size:0.8rem;}
button.status-btn{background:none;color:var(--cyan);padding:0;font-weight:600;font-size:0.8rem;margin-right:10px;}
.table-wrap{overflow-x:auto;}
</style>
