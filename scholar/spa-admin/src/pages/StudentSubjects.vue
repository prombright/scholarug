<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { studentSubjectsApi } from '../services/api'

const route = useRoute()
const studentId = computed(() => route.params.id)

const loading = ref(true)
const busy = ref(false)
const message = ref(null)
const notFound = ref(false)

const student = ref(null)
const isALevel = ref(false)
const className = ref('')
const electives = ref([])
const enrolledIds = ref(new Set())
const combinations = ref([])
const comboSubjectMap = ref({})
const selectedCombo = ref('')

async function load() {
  loading.value = true
  message.value = null
  notFound.value = false
  try {
    const { data } = await studentSubjectsApi.get(studentId.value)
    student.value = data.student
    isALevel.value = data.is_a_level
    className.value = data.class_name
    electives.value = data.electives
    enrolledIds.value = new Set(data.enrolled_ids.map(String))
    combinations.value = data.combinations
    comboSubjectMap.value = data.combo_subject_map
    selectedCombo.value = data.student.combination_id ? String(data.student.combination_id) : ''
  } catch (e) {
    notFound.value = true
    message.value = { type: 'error', text: e.response?.data?.message || 'Student not found.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(studentId, load)

function applyCombination() {
  const ids = comboSubjectMap.value[selectedCombo.value] || []
  ids.forEach((id) => enrolledIds.value.add(String(id)))
}

async function save() {
  busy.value = true
  message.value = null
  try {
    const { data } = await studentSubjectsApi.save({
      student_id: studentId.value,
      subject_ids: Array.from(enrolledIds.value),
      combination_id: selectedCombo.value || 0
    })
    message.value = { type: 'success', text: data.message }
    student.value = data.student
    electives.value = data.electives
    enrolledIds.value = new Set(data.enrolled_ids.map(String))
    combinations.value = data.combinations
    comboSubjectMap.value = data.combo_subject_map
    selectedCombo.value = data.student.combination_id ? String(data.student.combination_id) : ''
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'That action failed.' }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <p v-if="loading" class="empty">Loading…</p>

  <template v-else-if="notFound">
    <div class="alert error">{{ message?.text }}</div>
  </template>

  <template v-else-if="student">
    <p><router-link :to="`/students/${studentId}`" class="back-link">&larr; Back to {{ student.full_name }}'s Profile</router-link></p>
    <h1 class="page-title">Assign Subjects — {{ student.full_name }}</h1>
    <p class="sub">Class: {{ className || 'Unassigned' }}</p>

    <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

    <div v-if="className === ''" class="disclaimer">This student isn't assigned to a class yet — set that first on their profile.</div>

    <template v-else>
      <div v-if="isALevel && !combinations.length" class="disclaimer">
        No combinations are ready for {{ className }} yet — adopt one under the "A-Level Combinations" tab of Subject Catalog (its 3 subjects need adopting for this class first).
      </div>

      <div class="section">
        <p v-if="!electives.length" class="empty">No elective subjects exist for {{ className }} yet. Add one via Subject Matrix or the Subject Catalog first, marking it Elective.</p>
        <form v-else @submit.prevent="save">
          <div v-if="isALevel && combinations.length" class="combo-row">
            <label style="margin:0;font-size:0.8rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Combination</label>
            <select v-model="selectedCombo" @change="applyCombination">
              <option value="">— None —</option>
              <option v-for="c in combinations" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
            </select>
            <span style="color:var(--muted);font-size:0.78rem;">Picking a combination auto-ticks its 3 principal subjects below.</span>
          </div>

          <div class="subject-grid">
            <label v-for="e in electives" :key="e.id" class="subject-tile">
              <input type="checkbox" :value="String(e.id)" v-model="enrolledIds">
              <div>
                <div class="name">{{ e.subject_name }}</div>
                <div class="meta">{{ e.subject_code }}</div>
              </div>
            </label>
          </div>
          <button type="submit" :disabled="busy">Save Subjects</button>
        </form>
      </div>
    </template>
  </template>
</template>

<style scoped>
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;}
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin-top:-4px;margin-bottom:20px;}
.back-link{color:var(--muted);text-decoration:none;font-size:0.85rem;}
.back-link:hover{color:var(--cyan);}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:20px;line-height:1.5;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.subject-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:20px;}
.subject-tile{display:flex;align-items:flex-start;gap:10px;background:var(--panel-raised);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.subject-tile input[type=checkbox]{width:auto;margin-top:3px;}
.subject-tile .name{font-weight:600;font-size:0.88rem;}
.subject-tile .meta{color:var(--muted);font-size:0.75rem;margin-top:2px;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.combo-row{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.combo-row select{background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 12px;border-radius:8px;font-size:0.85rem;min-width:260px;}
</style>
