<script setup>
import { ref, computed, onMounted } from 'vue'
import { manageParentsApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const success = ref(null)

const students = ref([])
const parents = ref([])
const search = ref('')
const selectedIds = ref(new Set())

const form = ref({ full_name: '', username: '', phone: '', email: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await manageParentsApi.get()
    students.value = data.students
    parents.value = data.parents
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load parent accounts.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const grouped = computed(() => {
  const q = search.value.trim().toLowerCase()
  const groups = []
  let current = null
  for (const s of students.value) {
    const label = s.class_name || 'No Class'
    const hay = `${s.full_name} ${label}`.toLowerCase()
    if (q && !hay.includes(q)) continue
    if (label !== current) {
      current = label
      groups.push({ label, students: [] })
    }
    groups[groups.length - 1].students.push(s)
  }
  return groups
})

function toggle(id) {
  const s = new Set(selectedIds.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  selectedIds.value = s
}

async function createParent() {
  busy.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await manageParentsApi.create({ ...form.value, student_ids: Array.from(selectedIds.value) })
    parents.value = data.parents
    students.value = data.students
    success.value = data.message
    form.value = { full_name: '', username: '', phone: '', email: '' }
    selectedIds.value = new Set()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not create parent account.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="container">
    <h1 class="page-title">Manage Parent Accounts</h1>

    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="success" class="alert success">{{ success }}</div>

    <div class="section">
      <h2>Create Parent Account</h2>
      <form @submit.prevent="createParent">
        <label>Parent Full Name</label>
        <input type="text" v-model="form.full_name" required>
        <label>Username (parent will log in with this)</label>
        <input type="text" v-model="form.username" required>
        <label>Phone</label>
        <input type="text" v-model="form.phone">
        <label>Email (optional)</label>
        <input type="email" v-model="form.email">

        <div class="picker-head">
          <label style="margin:12px 0 0;">Link to Child(ren)</label>
          <span class="count">{{ selectedIds.size }} selected</span>
        </div>
        <input type="text" v-model="search" placeholder="Search by student name or class...">
        <div class="student-picker">
          <template v-for="g in grouped" :key="g.label">
            <div class="class-group-label">{{ g.label }}</div>
            <label v-for="s in g.students" :key="s.id" class="student-row">
              <input type="checkbox" :checked="selectedIds.has(s.id)" @change="toggle(s.id)">
              <span>{{ s.full_name }}</span>
              <span class="cls">{{ g.label }}</span>
            </label>
          </template>
          <div v-if="!grouped.length" class="no-match">{{ students.length ? 'No students match your search.' : 'No students enrolled yet.' }}</div>
        </div>
        <button type="submit" :disabled="busy">Create Account</button>
      </form>
    </div>

    <div class="section">
      <h2>Existing Parent Accounts</h2>
      <p v-if="loading" class="empty">Loading…</p>
      <div v-else class="table-wrap">
        <table>
          <tr><th>Username</th><th>Phone</th><th>Linked Children</th></tr>
          <tr v-for="p in parents" :key="p.id">
            <td>{{ p.username }}</td>
            <td>{{ p.phone_number || '—' }}</td>
            <td>{{ p.children || '—' }}</td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.container{max-width:900px;margin:auto;}
.page-title{font-size:1.4rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 14px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
label:first-child{margin-top:0;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;}
button:disabled{opacity:0.6;cursor:default;}
.picker-head{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.picker-head .count{font-size:0.75rem;color:var(--cyan);white-space:nowrap;}
.student-picker{margin-top:8px;max-height:280px;overflow-y:auto;background:var(--panel);border:1px solid var(--border);border-radius:6px;}
.class-group-label{position:sticky;top:0;background:var(--bg);color:var(--muted);font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;padding:6px 12px;border-bottom:1px solid var(--border);}
.student-row{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;font-size:0.85rem;border-bottom:1px solid rgba(42,58,82,0.4);margin:0;}
.student-row:hover{background:rgba(0,168,168,0.06);}
.student-row input{width:auto;accent-color:var(--cyan);}
.student-row .cls{margin-left:auto;color:var(--muted);font-size:0.75rem;}
.no-match{padding:16px;text-align:center;color:var(--muted);font-size:0.82rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:480px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.table-wrap{overflow-x:auto;}
</style>
