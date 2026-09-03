<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { subjectEnrollmentApi } from '../services/api'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const classes = ref([])
const selClass = ref(null)
const electives = ref([])
const selSubject = ref(null)
const students = ref([])
const enrolledIds = ref(new Set())

const selClassId = ref(route.query.class_id || '')
const selSubjectId = ref(route.query.subject_id || '')

async function load() {
  loading.value = true
  try {
    const { data } = await subjectEnrollmentApi.get(selClassId.value, selSubjectId.value)
    classes.value = data.classes
    selClass.value = data.sel_class
    electives.value = data.electives
    selSubject.value = data.sel_subject
    students.value = data.students
    enrolledIds.value = new Set(data.enrolled_ids.map(String))
    // Deep-link resolution may fill in a class_id we didn't have yet.
    if (data.sel_class) selClassId.value = String(data.sel_class.id)
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load this page.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

function onClassChange() {
  selSubjectId.value = ''
  router.replace({ query: { class_id: selClassId.value || undefined } })
  load()
}
function onSubjectChange() {
  router.replace({ query: { class_id: selClassId.value || undefined, subject_id: selSubjectId.value || undefined } })
  load()
}

async function save() {
  busy.value = true
  message.value = null
  try {
    const { data } = await subjectEnrollmentApi.save({
      class_id: selClassId.value,
      subject_id: selSubjectId.value,
      student_ids: Array.from(enrolledIds.value)
    })
    message.value = { type: 'success', text: data.message }
    students.value = data.students
    enrolledIds.value = new Set(data.enrolled_ids.map(String))
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'That action failed.' }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <h1 class="page-title">Assign Students to an Elective</h1>
  <p class="sub">Pick a class and elective, then tick who takes it. Prefer doing this per-student instead? Use the Students list's "Subjects" link on any student.</p>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <div v-else class="section">
    <div class="picker-row">
      <div>
        <label>Class</label>
        <select v-model="selClassId" @change="onClassChange">
          <option value="">-- Select Class --</option>
          <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.class_name }}</option>
        </select>
      </div>
      <div v-if="selClass">
        <label>Elective Subject</label>
        <select v-model="selSubjectId" @change="onSubjectChange">
          <option value="">-- Select Elective --</option>
          <option v-for="e in electives" :key="e.id" :value="String(e.id)">{{ e.subject_name }} ({{ e.subject_code }})</option>
        </select>
      </div>
    </div>

    <p v-if="selClass && !electives.length" class="empty" style="margin-top:14px;">No elective subjects exist for {{ selClass.class_name }} yet. Add one via Subject Matrix, marking it Elective.</p>

    <template v-if="selClass && selSubject">
      <p v-if="!students.length" class="empty">No students in this class yet.</p>
      <template v-else>
        <div class="student-grid">
          <label v-for="s in students" :key="s.id" class="student-tile">
            <input type="checkbox" :value="String(s.id)" v-model="enrolledIds">
            {{ s.full_name }}
          </label>
        </div>
        <button type="button" :disabled="busy" @click="save">Save Enrollment</button>
      </template>
    </template>
  </div>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin-top:-4px;margin-bottom:20px;}
.empty{color:var(--muted);font-size:0.85rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.picker-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:6px;}
.picker-row > div{flex:1;min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
.student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin:20px 0;}
.student-tile{display:flex;align-items:center;gap:10px;background:var(--panel-raised);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:0.85rem;}
.student-tile input[type=checkbox]{width:auto;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
</style>
