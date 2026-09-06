<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { subjectsApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const busy = ref(false)
const message = ref(null)
const schoolType = ref('Secondary')

const view = ref('subjects') // 'subjects' | 'combinations'
const level = ref('O-Level')
const catalog = ref([])
const combinations = ref([])
const query = ref('')

const selectedIds = ref(new Set())
const selectedComboIds = ref(new Set())

async function load() {
  loading.value = true
  try {
    const { data } = await subjectsApi.get(level.value)
    schoolType.value = data.school_type
    catalog.value = data.catalog
    combinations.value = data.combinations
    selectedIds.value = new Set(catalog.value.filter((c) => c.adopted || c.is_compulsory).map((c) => c.id))
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load the subject catalog.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(level, load)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return catalog.value
  return catalog.value.filter((cs) => cs.subject_name.toLowerCase().includes(q) || cs.subject_code.toLowerCase().includes(q))
})

function toggle(id) {
  const s = new Set(selectedIds.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  selectedIds.value = s
}
function toggleCombo(id) {
  const s = new Set(selectedComboIds.value)
  if (s.has(id)) s.delete(id); else s.add(id)
  selectedComboIds.value = s
}

async function adoptSubjects() {
  busy.value = true
  message.value = null
  try {
    const { data } = await subjectsApi.action({ action: 'adopt_subjects', level_type: level.value, catalog_ids: Array.from(selectedIds.value) })
    message.value = { type: data.message_type, text: data.message }
    catalog.value = data.catalog
    combinations.value = data.combinations
    selectedIds.value = new Set(catalog.value.filter((c) => c.adopted || c.is_compulsory).map((c) => c.id))
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not adopt subjects.' }
  } finally {
    busy.value = false
  }
}

async function adoptCombinations() {
  busy.value = true
  message.value = null
  try {
    const { data } = await subjectsApi.action({ action: 'adopt_combinations', combo_ids: Array.from(selectedComboIds.value) })
    message.value = { type: data.message_type, text: data.message }
    combinations.value = data.combinations
    selectedComboIds.value = new Set()
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not adopt combinations.' }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <h1 class="page-title">Subject Catalog</h1>

  <div v-if="schoolType !== 'Secondary'" class="disclaimer">
    This catalog covers O-Level/A-Level (Secondary) subjects only. Your school is set up as Primary —
    use the "Seed Default Subjects" button on <a :href="`${SB}subject_matrix.php`">Subject Matrix</a> instead.
  </div>

  <template v-else>
    <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

    <div class="disclaimer">
      <strong>Heads up:</strong> subject codes here (e.g. <code>ENG</code>, <code>MTC</code>) are Scholar's own
      reference codes, not official UNEB registration codes. Tick what your school offers below; you can edit any
      adopted subject's code afterward via Subject Matrix if it needs to match your official UNEB paperwork.
    </div>

    <div class="section">
      <div class="level-tabs">
        <button type="button" :class="{ active: view === 'subjects' && level === 'O-Level' }" @click="view = 'subjects'; level = 'O-Level'">O-Level (S.1 - S.4)</button>
        <button type="button" :class="{ active: view === 'subjects' && level === 'A-Level' }" @click="view = 'subjects'; level = 'A-Level'">A-Level (S.5 - S.6)</button>
        <button type="button" :class="{ active: view === 'combinations' }" @click="view = 'combinations'">A-Level Combinations</button>
      </div>

      <p v-if="loading" class="empty">Loading…</p>

      <template v-else-if="view === 'combinations'">
        <div class="disclaimer">A combination can only be adopted once your school has already adopted all 3 of its subjects under the A-Level tab above.</div>

        <div class="subject-grid">
          <label v-for="combo in combinations" :key="combo.id" class="subject-tile" :class="{ locked: combo.adopted || combo.missing.length }">
            <input type="checkbox" :checked="combo.adopted || selectedComboIds.has(combo.id)" :disabled="combo.adopted || combo.missing.length > 0" @change="toggleCombo(combo.id)">
            <div>
              <div class="name">{{ combo.code }} <span class="compulsory-badge">{{ combo.name }}</span></div>
              <div class="meta">
                {{ combo.subject_codes.join(' / ') }}
                <span v-if="combo.adopted"> &middot; already added</span>
                <span v-else-if="combo.missing.length"> &middot; adopt {{ combo.missing.join(', ') }} first</span>
              </div>
            </div>
          </label>
        </div>

        <div class="footer-row">
          <span class="leftover-note">Combinations are how A-Level students' subjects get assigned — see each student's "Subjects" page.</span>
          <button type="button" class="primary" :disabled="busy" @click="adoptCombinations">Adopt Ticked Combinations</button>
        </div>
      </template>

      <template v-else>
        <input type="text" v-model="query" class="subject-search" placeholder="Search subjects by name or code…">

        <div class="subject-grid">
          <label v-for="cs in filtered" :key="cs.id" class="subject-tile" :class="{ locked: cs.adopted }">
            <input type="checkbox" :checked="cs.adopted || selectedIds.has(cs.id)" :disabled="cs.adopted" @change="toggle(cs.id)">
            <div>
              <div class="name">{{ cs.subject_name }} <span v-if="cs.is_compulsory" class="compulsory-badge">Compulsory</span></div>
              <div class="meta">
                {{ cs.subject_code }} &middot; {{ cs.papers_count }} paper<span v-if="cs.papers_count > 1">s</span>
                <span v-if="cs.adopted"> &middot; already added</span>
                <router-link v-if="cs.adopted && !cs.is_compulsory" to="/subject-enrollment"> &middot; Assign Students &rarr;</router-link>
              </div>
            </div>
          </label>
          <p v-if="!filtered.length" class="leftover-note" style="padding:16px 0;">No subjects match &ldquo;{{ query }}&rdquo;.</p>
        </div>

        <div class="footer-row">
          <span class="leftover-note">Don't see a subject your school offers? <a :href="`${SB}subject_matrix.php`">Add a custom subject &rarr;</a></span>
          <button type="button" class="primary" :disabled="busy" @click="adoptSubjects">Adopt Ticked Subjects</button>
        </div>
      </template>
    </div>
  </template>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:20px;line-height:1.5;}
.disclaimer a{color:var(--cyan);}
.level-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.level-tabs button{padding:9px 18px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:0.85rem;font-weight:600;cursor:pointer;}
.level-tabs button.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.subject-search{width:100%;max-width:360px;padding:9px 14px;border-radius:8px;border:1px solid var(--border);background:var(--panel);color:var(--text);font-size:0.85rem;margin-bottom:14px;box-sizing:border-box;}
.subject-search:focus{outline:none;border-color:var(--cyan);}
.subject-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin-bottom:20px;}
.subject-tile{display:flex;align-items:flex-start;gap:10px;background:var(--panel-raised);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.subject-tile.locked{opacity:0.6;}
.subject-tile input[type=checkbox]{width:auto;margin-top:3px;}
.subject-tile .name{font-weight:600;font-size:0.88rem;}
.subject-tile .meta{color:var(--muted);font-size:0.75rem;margin-top:2px;}
.subject-tile .meta a{color:var(--cyan);}
.compulsory-badge{display:inline-block;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.4px;color:var(--cyan);border:1px solid rgba(0,168,168,0.4);padding:1px 6px;border-radius:10px;margin-left:6px;}
.footer-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
button.primary{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button.primary:disabled{opacity:0.6;cursor:default;}
.leftover-note{color:var(--muted);font-size:0.85rem;}
.leftover-note a{color:var(--cyan);font-weight:600;}
</style>
