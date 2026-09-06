<script setup>
import { ref, onMounted } from 'vue'
import { gradingScalesApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const LEVELS = [
  {
    level: 'O-Level',
    title: 'O-Level Grading Bands',
    desc: "A student's weighted % score on each subject is matched against these bands to produce the grade/descriptor shown on the report card.",
    resetAction: 'seed_competency_defaults',
    resetLabel: 'Reset to Competency-Based Defaults',
    resetPrompt: 'This replaces ALL current O-Level grading bands with the competency-based defaults. Continue?',
  },
  {
    level: 'A-Level',
    title: 'A-Level Grading Bands',
    desc: 'Used for UACE points on A-Level report cards (principal subjects, General Paper, and the assigned Subsidiary). Needs a real F band so a fail is actually detected.',
    resetAction: 'seed_uace_defaults',
    resetLabel: 'Reset to UACE Standard Scale',
    resetPrompt: 'This replaces ALL current A-Level grading bands with the UACE standard defaults. Continue?',
  },
]

const bands = ref({ 'O-Level': [], 'A-Level': [] })
const skills = ref([])

function blankBand() {
  return { grade: '', min_mark: '', max_mark: '', remark: '', points: '', use_color: false, color: '#ffffff' }
}
const newBand = ref({ 'O-Level': blankBand(), 'A-Level': blankBand() })
const newSkillName = ref('')

function decorateBands(raw) {
  return {
    'O-Level': (raw?.['O-Level'] || []).map((b) => ({ ...b, use_color: !!b.color, color: b.color || '#ffffff' })),
    'A-Level': (raw?.['A-Level'] || []).map((b) => ({ ...b, use_color: !!b.color, color: b.color || '#ffffff' })),
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await gradingScalesApi.get()
    bands.value = decorateBands(data.bands)
    skills.value = data.skills
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load grading scale settings.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function runAction(payload, successOverride) {
  busy.value = true
  message.value = null
  try {
    const { data } = await gradingScalesApi.action(payload)
    bands.value = decorateBands(data.bands)
    skills.value = data.skills
    message.value = { type: 'success', text: successOverride || data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'That action failed.' }
  } finally {
    busy.value = false
  }
}

function createBand(level) {
  runAction({ action: 'create_band', level_type: level, ...newBand.value[level] })
  newBand.value[level] = blankBand()
}
function saveBand(b) {
  runAction({ action: 'update_band', id: b.id, grade: b.grade, min_mark: b.min_mark, max_mark: b.max_mark, remark: b.remark, points: b.points, use_color: b.use_color, color: b.color })
}
function deleteBand(b) {
  if (!confirm('Delete this grading band?')) return
  runAction({ action: 'delete_band', id: b.id })
}
function resetDefaults(sec) {
  if (!confirm(sec.resetPrompt)) return
  runAction({ action: sec.resetAction })
}
function createSkill() {
  if (!newSkillName.value.trim()) return
  runAction({ action: 'create_skill', skill_name: newSkillName.value })
  newSkillName.value = ''
}
function toggleSkill(s) {
  runAction({ action: 'toggle_skill', id: s.id })
}
function deleteSkill(s) {
  if (!confirm('Delete this skill? Past ratings for it are removed too.')) return
  runAction({ action: 'delete_skill', id: s.id })
}
function seedDefaultSkills() {
  runAction({ action: 'seed_default_skills' })
}
</script>

<template>
  <h1 class="page-title">Report Cards</h1>

  <div class="report-tabs">
    <router-link to="/grading" class="active">Grading &amp; Bands</router-link>
    <router-link to="/report-settings">Display Settings</router-link>
    <a :href="`${SB}school_admin/bulk_report_print.php`">Print Reports</a>
    <a :href="`${SB}school_admin/remarks.php`">Remarks</a>
  </div>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <div class="disclaimer">
    <strong>Heads up:</strong> O-Level and A-Level keep independent grading scales. The "reset to
    competency-based defaults" O-Level band labels (A - Exceptional through E - Elementary, no F) match
    Uganda's new lower-secondary curriculum; the A-Level "UACE Standard Scale" is the traditional A-F
    letter scale used for UACE points. Percentage cutoffs on both are starting points, not an official
    boundary — adjust them below if your school's guidance differs.
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="section" v-for="sec in LEVELS" :key="sec.level">
      <h2>{{ sec.title }}</h2>
      <p class="sub">{{ sec.desc }}</p>

      <div class="table-wrap">
        <table class="bands-table">
          <tr><th>Grade / Descriptor</th><th>Min %</th><th>Max %</th><th>Remark</th><th>Points</th><th>Color</th><th></th><th></th></tr>
          <tr v-if="!bands[sec.level].length"><td colspan="8" class="empty-cell">No {{ sec.level }} bands configured yet.</td></tr>
          <tr v-for="b in bands[sec.level]" :key="b.id">
            <td style="min-width:130px;"><input type="text" v-model="b.grade"></td>
            <td style="max-width:90px;"><input type="number" step="0.01" v-model.number="b.min_mark"></td>
            <td style="max-width:90px;"><input type="number" step="0.01" v-model.number="b.max_mark"></td>
            <td style="min-width:200px;"><input type="text" v-model="b.remark"></td>
            <td style="max-width:80px;"><input type="number" step="0.01" v-model.number="b.points"></td>
            <td style="white-space:nowrap;">
              <label class="color-toggle">
                <input type="checkbox" v-model="b.use_color">
                <input type="color" v-model="b.color" style="width:36px;height:28px;padding:2px;margin:0;">
              </label>
            </td>
            <td style="white-space:nowrap;"><button type="button" class="ghost-btn small-btn" :disabled="busy" @click="saveBand(b)">Save</button></td>
            <td style="white-space:nowrap;"><button type="button" class="danger-btn small-btn" :disabled="busy" @click="deleteBand(b)">Delete</button></td>
          </tr>
        </table>
      </div>

      <form class="row" style="margin-top:20px;" @submit.prevent="createBand(sec.level)">
        <div><label>Grade / Descriptor</label><input type="text" v-model="newBand[sec.level].grade" placeholder="e.g. A or Outstanding" required></div>
        <div><label>Min %</label><input type="number" step="0.01" v-model.number="newBand[sec.level].min_mark" required></div>
        <div><label>Max %</label><input type="number" step="0.01" v-model.number="newBand[sec.level].max_mark" required></div>
        <div><label>Remark</label><input type="text" v-model="newBand[sec.level].remark" placeholder="Shown on the report card"></div>
        <div><label>Points (optional)</label><input type="number" step="0.01" v-model.number="newBand[sec.level].points"></div>
        <div style="flex:0 0 auto;">
          <label class="color-check"><input type="checkbox" v-model="newBand[sec.level].use_color"> Color</label>
          <input type="color" v-model="newBand[sec.level].color" style="width:60px;height:38px;padding:2px;">
        </div>
        <div style="flex:0 0 auto;align-self:flex-end;"><button type="submit" :disabled="busy">Add {{ sec.level }} Band</button></div>
      </form>

      <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
        <button type="button" class="ghost-btn" :disabled="busy" @click="resetDefaults(sec)">{{ sec.resetLabel }}</button>
      </div>
    </div>

    <div class="section">
      <h2>Generic Skills (optional)</h2>
      <p class="sub">When at least one active skill exists, class teachers can rate students against it each term, and it appears as a supplementary section on the report card.</p>

      <div class="table-wrap">
        <table>
          <tr><th>Skill</th><th>Status</th><th></th></tr>
          <tr v-if="!skills.length"><td colspan="3" class="empty-cell">No skills defined yet.</td></tr>
          <tr v-for="s in skills" :key="s.id">
            <td>{{ s.skill_name }}</td>
            <td><span class="pill" :class="{ muted: !s.is_active }">{{ s.is_active ? 'Active' : 'Hidden' }}</span></td>
            <td style="white-space:nowrap;">
              <button type="button" class="ghost-btn small-btn" :disabled="busy" @click="toggleSkill(s)">{{ s.is_active ? 'Hide' : 'Activate' }}</button>
              <button type="button" class="danger-btn small-btn" :disabled="busy" @click="deleteSkill(s)">Delete</button>
            </td>
          </tr>
        </table>
      </div>

      <div class="row" style="margin-top:16px;">
        <form class="row" style="flex:1;" @submit.prevent="createSkill">
          <div><label>New Skill Name</label><input type="text" v-model="newSkillName" placeholder="e.g. Critical Thinking" required></div>
          <div style="flex:0 0 auto;align-self:flex-end;"><button type="submit" :disabled="busy">Add Skill</button></div>
        </form>
        <div style="flex:0 0 auto;align-self:flex-end;"><button type="button" class="ghost-btn" :disabled="busy" @click="seedDefaultSkills">Seed Default Skills</button></div>
      </div>
    </div>
  </template>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 6px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 14px;}
.empty{color:var(--muted);font-size:0.85rem;}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:20px;line-height:1.5;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
label:first-child{margin-top:0;}
input{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
table input{margin:0;padding:7px 8px;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);}
.ghost-btn{background:transparent;color:var(--text);border:1px solid var(--border);}
.small-btn{margin:0;padding:6px 12px;font-size:0.78rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:360px;}
.bands-table{min-width:760px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.empty-cell{text-align:center;color:var(--muted);padding:20px;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:120px;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.pill.muted{background:rgba(100,116,139,0.15);color:var(--muted);}
.color-toggle{display:inline-flex;align-items:center;gap:5px;margin:0;font-size:0.75rem;color:var(--muted);}
.color-check{display:flex;align-items:center;gap:6px;margin:0;}
.table-wrap{overflow-x:auto;}
</style>
