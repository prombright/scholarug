<script setup>
import { ref, reactive, onMounted } from 'vue'
import { classesApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const success = ref(null)
const busy = ref(false)

const allClassNames = ref([])
const levelClasses = ref({})
const schoolType = ref('Secondary')
const classes = ref([])
const teachingStaff = ref([])
const streamsByClass = ref({})

const newStreamName = reactive({}) // class_name => text input
const teacherPick = reactive({}) // class_id => staff_id

async function load() {
  loading.value = true
  try {
    const { data } = await classesApi.get()
    allClassNames.value = data.all_class_names
    levelClasses.value = data.level_classes
    schoolType.value = data.school_type
    classes.value = data.classes
    teachingStaff.value = data.teaching_staff
    streamsByClass.value = data.streams_by_class
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load classes.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function runAction(payload) {
  busy.value = true
  error.value = null
  success.value = null
  try {
    const { data } = await classesApi.action(payload)
    classes.value = data.classes
    teachingStaff.value = data.teaching_staff
    streamsByClass.value = data.streams_by_class
    success.value = data.message
  } catch (e) {
    error.value = e.response?.data?.message || 'That action failed.'
  } finally {
    busy.value = false
  }
}

function addStream(className) {
  const name = (newStreamName[className] || '').trim()
  if (!name) return
  runAction({ action: 'add_stream', class_name: className, stream_name: name })
  newStreamName[className] = ''
}
function seedAlevel(className) {
  runAction({ action: 'seed_alevel_streams', class_name: className })
}
function deleteClass(c) {
  if (!confirm("Delete this class? Students in it will become unassigned, not deleted.")) return
  runAction({ action: 'delete_class', class_id: c.id })
}
function assignTeacher(c) {
  const teacherId = teacherPick[c.id]
  if (!teacherId) return
  runAction({ action: 'assign_class_teacher', class_id: c.id, teacher_staff_id: teacherId })
}
function removeTeacher(c) {
  runAction({ action: 'remove_class_teacher', class_id: c.id })
}
function isAlevel(className) {
  return schoolType.value === 'Secondary' && (levelClasses.value['A-Level'] || []).includes(className)
}
</script>

<template>
  <h1 class="page-title">Manage Classes</h1>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="success" class="alert success">{{ success }}</div>

    <div class="section">
      <h2>Streams</h2>
      <p class="muted">Every class from {{ allClassNames[0] }} to {{ allClassNames[allClassNames.length - 1] }} already exists below — only add a stream here if this school actually splits a class into more than one (e.g. S.1 A / S.1 B). No streams means the class stays as one group.</p>

      <div v-for="cn in allClassNames" :key="cn" class="stream-block">
        <strong>{{ cn }}</strong>
        <template v-if="streamsByClass[cn]?.length">
          <span v-for="sn in streamsByClass[cn]" :key="sn" class="pill">{{ sn }}</span>
        </template>
        <span v-else class="muted">No streams.</span>

        <form class="inline-form" @submit.prevent="addStream(cn)">
          <input type="text" v-model="newStreamName[cn]" placeholder="e.g. A, Blue, Sciences" required>
          <button type="submit" :disabled="busy" class="small-btn">+ Add Stream</button>
        </form>

        <button v-if="isAlevel(cn) && !streamsByClass[cn]?.length" type="button" class="ghost-btn small-btn" :disabled="busy" @click="seedAlevel(cn)">+ Seed Sciences &amp; Arts</button>
      </div>
    </div>

    <div class="section">
      <h2>All Classes</h2>
      <p class="muted" style="margin-top:-8px;">The class teacher can view and print report cards for students in their class, and is who a "Print My Class's Reports" bulk action scopes to.</p>
      <div class="table-wrap">
        <table>
          <tr><th>Class</th><th>Stream</th><th>Students</th><th>Class Teacher</th><th></th></tr>
          <tr v-for="c in classes" :key="c.id">
            <td>{{ c.class_name }}</td>
            <td>{{ c.stream_name || '—' }}</td>
            <td>{{ c.student_count }}</td>
            <td>
              <template v-if="c.class_teacher_id">
                <span class="pill">{{ c.class_teacher_name?.trim() }}</span>
                <button type="button" class="danger-btn small-btn" :disabled="busy" @click="removeTeacher(c)">Remove</button>
              </template>
              <div v-else class="row">
                <select v-model="teacherPick[c.id]" required>
                  <option value="">-- Select teacher --</option>
                  <option v-for="t in teachingStaff" :key="t.staff_id" :value="t.staff_id">{{ t.full_name?.trim() }}</option>
                </select>
                <button type="button" :disabled="busy" class="small-btn" @click="assignTeacher(c)">Assign</button>
              </div>
            </td>
            <td>
              <button type="button" class="danger-btn" :disabled="busy" @click="deleteClass(c)">Delete</button>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </template>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.empty{color:var(--muted);font-size:0.85rem;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 14px;}
input,select{padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.small-btn{padding:6px 12px;font-size:0.78rem;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);}
.ghost-btn{background:transparent;color:var(--purple);border:1px solid rgba(168,85,247,0.4);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:520px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;margin:2px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.muted{color:var(--muted);font-size:0.8rem;}
.row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.stream-block{border-top:1px solid var(--border);padding-top:14px;margin-top:14px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;}
.inline-form{display:inline-flex;gap:6px;align-items:center;margin-left:10px;}
.inline-form input{width:150px;padding:6px 8px;}
.table-wrap{overflow-x:auto;}
</style>
