<script setup>
import { ref, onMounted } from 'vue'
import { projectsApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const message = ref('')

const classes = ref([])
const teachers = ref([])
const selClassId = ref('')

const project = ref(null)
const assignedTeachers = ref([])
const students = ref([])
const stagesByStudent = ref({})

const newProject = ref({ title: '', description: '', teacher_id: '' })
const assignTeacherId = ref('')

async function load(classId) {
  loading.value = true
  try {
    const { data } = await projectsApi.get(classId ?? selClassId.value)
    classes.value = data.classes
    teachers.value = data.teachers
    project.value = data.project
    assignedTeachers.value = data.assigned_teachers
    students.value = data.students
    stagesByStudent.value = data.stages_by_student
  } finally {
    loading.value = false
  }
}
onMounted(() => load(''))

function onLoadClass() {
  error.value = ''
  message.value = ''
  load(selClassId.value)
}

async function createProject() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await projectsApi.action({ action: 'create_project', class_id: selClassId.value, ...newProject.value })
    message.value = data.message
    project.value = data.project
    assignedTeachers.value = data.assigned_teachers
    students.value = data.students
    stagesByStudent.value = data.stages_by_student
    newProject.value = { title: '', description: '', teacher_id: '' }
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not create project.'
  } finally {
    busy.value = false
  }
}

async function assignTeacher() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await projectsApi.action({ action: 'assign_teacher', class_id: selClassId.value, project_id: project.value.id, teacher_id: assignTeacherId.value })
    message.value = data.message
    assignedTeachers.value = data.assigned_teachers
    assignTeacherId.value = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not assign teacher.'
  } finally {
    busy.value = false
  }
}

function photoUrl(path) {
  return `${SB}${path}`
}
function formatDate(dt) {
  return new Date(dt.replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <h1 class="page-title">Projects</h1>
  <p class="sub">UNEB O-Level project work, S.3-S.6 only.</p>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="message" class="alert success">{{ message }}</div>

  <div class="section">
    <form class="filter-bar" @submit.prevent="onLoadClass">
      <div>
        <label>Class</label>
        <select v-model="selClassId">
          <option value="">-- Select Class --</option>
          <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.class_name }}{{ c.stream_name ? ' - ' + c.stream_name : '' }}</option>
        </select>
      </div>
      <div><button type="submit" :disabled="busy">Load</button></div>
    </form>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else-if="selClassId">
    <template v-if="!project">
      <div class="section">
        <h2 style="font-size:1rem;margin:0;">Create Project</h2>
        <form @submit.prevent="createProject">
          <label>Title</label>
          <input type="text" v-model="newProject.title" placeholder="e.g. S.3 Community-Based Project" required>
          <label>Description</label>
          <textarea v-model="newProject.description" rows="3"></textarea>
          <label>Monitoring Teacher</label>
          <select v-model="newProject.teacher_id" required>
            <option value="">-- Select Teacher --</option>
            <option v-for="t in teachers" :key="t.staff_id" :value="String(t.staff_id)">{{ t.first_name }} {{ t.last_name }}</option>
          </select>
          <button type="submit" :disabled="busy">Create Project</button>
        </form>
      </div>
    </template>
    <template v-else>
      <div class="section">
        <h2 style="font-size:1rem;margin:0 0 8px;">{{ project.title }}</h2>
        <p v-if="project.description" style="color:var(--muted);font-size:0.85rem;">{{ project.description }}</p>
        <div style="margin:10px 0;">
          <span v-for="t in assignedTeachers" :key="t.staff_id" class="pill">{{ t.teacher_name }}</span>
        </div>
        <form style="display:flex;gap:10px;align-items:flex-end;" @submit.prevent="assignTeacher">
          <div style="flex:1;">
            <label style="margin-top:0;">Assign Another Teacher</label>
            <select v-model="assignTeacherId" required>
              <option value="">-- Select Teacher --</option>
              <option v-for="t in teachers" :key="t.staff_id" :value="String(t.staff_id)">{{ t.first_name }} {{ t.last_name }}</option>
            </select>
          </div>
          <button type="submit" :disabled="busy" style="margin-top:0;">Assign</button>
        </form>
      </div>

      <div class="section">
        <h2 style="font-size:1rem;margin:0 0 8px;">Student Progress</h2>
        <p v-if="!students.length" class="empty">No students in this class.</p>
        <template v-else>
          <div v-for="s in students" :key="s.id" class="student-block">
            <div class="student-name">{{ s.full_name }}</div>
            <div v-if="!(stagesByStudent[s.id] && stagesByStudent[s.id].length)" class="stage" style="color:var(--muted);">No stages logged yet.</div>
            <template v-else>
              <div v-for="(st, i) in stagesByStudent[s.id]" :key="i" class="stage">
                <strong>{{ st.stage_title }}</strong>
                <div v-if="st.description">{{ st.description }}</div>
                <div v-if="st.photo_paths" class="photos">
                  <img v-for="(path, j) in st.photo_paths.split('|')" :key="j" :src="photoUrl(path)" alt="">
                </div>
                <div class="meta">{{ formatDate(st.recorded_at) }}</div>
              </div>
            </template>
          </div>
        </template>
      </div>
    </template>
  </template>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;}
.filter-bar div{min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
label:first-child{margin-top:0;}
select,input,textarea{width:100%;padding:9px 10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;margin-top:16px;}
button:disabled{opacity:0.6;cursor:default;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);margin-right:6px;}
.student-block{border-bottom:1px solid var(--border);padding:16px 0;}
.student-block:last-child{border-bottom:none;}
.student-name{font-weight:700;margin-bottom:8px;}
.stage{background:var(--panel-raised);border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:0.85rem;}
.stage .meta{color:var(--muted);font-size:0.72rem;margin-top:4px;}
.photos{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
.photos img{width:60px;height:60px;object-fit:cover;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;text-align:center;}
</style>
