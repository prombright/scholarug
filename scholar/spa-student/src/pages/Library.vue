<script setup>
import { ref, onMounted } from 'vue'
import { libraryApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const notes = ref([])
const pastPapers = ref([])

onMounted(async () => {
  try {
    const { data } = await libraryApi.get()
    notes.value = data.notes
    pastPapers.value = data.past_papers
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the library.'
  } finally {
    loading.value = false
  }
})

const previewUrl = (doc) => `${SB}library/view.php?id=${doc.id}`
</script>

<template>
  <div class="page-title-row"><h1>Library</h1></div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <h2 class="group-title">Notes</h2>
    <div v-if="!notes.length" class="empty">No notes have been shared for your class yet.</div>
    <div v-else class="doc-grid">
      <a v-for="d in notes" :key="d.id" class="doc-card" :href="previewUrl(d)" target="_blank">
        <div class="icon"><i class="bi bi-journal-text"></i></div>
        <div class="title">{{ d.title }}</div>
        <div class="meta">{{ d.subject_name }}<span v-if="d.term"> · {{ d.term }} {{ d.year }}</span></div>
      </a>
    </div>

    <h2 class="group-title">Past Papers</h2>
    <div v-if="!pastPapers.length" class="empty">No past papers have been shared for your class yet.</div>
    <div v-else class="doc-grid">
      <a v-for="d in pastPapers" :key="d.id" class="doc-card" :href="previewUrl(d)" target="_blank">
        <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
        <div class="title">{{ d.title }}</div>
        <div class="meta">{{ d.subject_name }}<span v-if="d.term"> · {{ d.term }} {{ d.year }}</span></div>
      </a>
    </div>
  </template>
</template>

<style scoped>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.group-title{font-size:0.95rem;font-weight:700;margin:26px 0 12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.group-title:first-of-type{margin-top:0;}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;}
.doc-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;display:block;}
.doc-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.doc-card .icon{font-size:1.6rem;color:var(--cyan);margin-bottom:10px;}
.doc-card .title{font-weight:700;font-size:0.9rem;margin-bottom:4px;}
.doc-card .meta{color:var(--muted);font-size:0.75rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:24px;text-align:center;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
</style>
