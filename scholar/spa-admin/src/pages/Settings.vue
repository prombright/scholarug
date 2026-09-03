<script setup>
import { ref, computed, onMounted } from 'vue'
import { settingsApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const school = ref({})
const form = ref({ school_name: '', address: '', phone_contact: '', email_contact: '', current_term: 'Term 1', current_academic_year: '2026' })

async function load() {
  loading.value = true
  try {
    const { data } = await settingsApi.get()
    school.value = data.school
    form.value = {
      school_name: data.school.school_name || '',
      address: data.school.address || '',
      phone_contact: data.school.phone_contact || '',
      email_contact: data.school.email_contact || '',
      current_term: data.school.current_term || 'Term 1',
      current_academic_year: data.school.current_year || '2026'
    }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load school settings.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function saveProfile() {
  busy.value = true
  message.value = null
  try {
    const { data } = await settingsApi.action({ action: 'save_profile', existing_logo_path: school.value.school_badge, ...form.value })
    school.value = data.school
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not save settings.' }
  } finally {
    busy.value = false
  }
}

const term = computed(() => school.value.current_term || 'Term 1')
const year = computed(() => school.value.current_year || String(new Date().getFullYear()))
const isTerm3 = computed(() => term.value === 'Term 3')
const nextTermLabel = computed(() => (isTerm3.value ? 'ready for Close Year' : (term.value === 'Term 1' ? 'Term 2' : 'Term 3')))

async function closeTerm() {
  if (!confirm(`Close ${term.value} ${year.value} and lock all its assessments? Teachers will no longer be able to save marks against them. This cannot be undone in bulk.`)) return
  busy.value = true
  message.value = null
  try {
    const { data } = await settingsApi.action({ action: 'close_term' })
    school.value = data.school
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not close the term.' }
  } finally {
    busy.value = false
  }
}

async function closeYear() {
  if (!confirm(`Close ${year.value} and promote every active student to their next class? Graduating students will be flagged as alumni. This cannot be undone in bulk.`)) return
  busy.value = true
  message.value = null
  try {
    const { data } = await settingsApi.action({ action: 'close_year' })
    school.value = data.school
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not close the year.' }
  } finally {
    busy.value = false
  }
}

const logoUploadAction = `${SB}settings.php`
</script>

<template>
  <div class="header-row">
    <div>
      <h1>Setup School Settings</h1>
      <p class="sub">Manage structural parameters, contact coordinates, term limits, and layout branding assets.</p>
    </div>
    <img v-if="school.school_badge" :src="`${SB}${school.school_badge}?t=${Date.now()}`" alt="" class="logo-preview">
  </div>

  <div v-if="message" class="alert" :class="message.type">▶ {{ message.text }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="card">
      <div class="logo-row">
        <div class="logo-box">
          <img v-if="school.school_badge" :src="`${SB}${school.school_badge}?t=${Date.now()}`" alt="">
        </div>
        <div style="flex:1;">
          <label>School Logo / Badge Icon</label>
          <p class="hint">Uploading a new logo happens on the classic settings page (file uploads aren't part of this pilot's JSON flow) —
            <a :href="logoUploadAction">open it here</a> if you need to change the logo.</p>
        </div>
      </div>

      <form @submit.prevent="saveProfile">
        <div class="grid-block">
          <div><label>School Name</label><input type="text" v-model="form.school_name" required placeholder="e.g. Mbarara High School"></div>
          <div><label>Address / Location</label><input type="text" v-model="form.address" placeholder="e.g. P.O. Box 1, Ruharo, Mbarara"></div>
        </div>
        <div class="grid-block">
          <div><label>Contact Number</label><input type="text" v-model="form.phone_contact" placeholder="e.g. +256 701 234567"></div>
          <div><label>School Email</label><input type="email" v-model="form.email_contact" placeholder="e.g. registrar@school.ac.ug"></div>
        </div>
        <div class="grid-block bordered">
          <div>
            <label>Select Current Active Term</label>
            <select v-model="form.current_term">
              <option value="Term 1">Term 1</option>
              <option value="Term 2">Term 2</option>
              <option value="Term 3">Term 3</option>
            </select>
          </div>
          <div>
            <label>Current Academic Year</label>
            <select v-model="form.current_academic_year">
              <option value="2026">2026 Calendar Year</option>
              <option value="2027">2027 Calendar Year</option>
            </select>
          </div>
        </div>
        <div class="save-row">
          <button type="submit" class="primary-btn" :disabled="busy">Save Settings Matrix</button>
        </div>
      </form>
    </div>

    <div class="section">
      <h2>Close Term</h2>
      <p class="sub">Currently on <strong>{{ term }} {{ year }}</strong>.</p>
      <div class="disclaimer">
        Closes every assessment for {{ term }} {{ year }} -- teachers will no longer be able to save marks against
        them, on the entry form or via CSV import -- and advances the school to {{ nextTermLabel }}. This cannot be
        undone in bulk -- reopening an assessment afterward is a one-at-a-time action on
        <a :href="`${SB}school_admin/assessments.php`">Assessments</a>.
      </div>
      <button type="button" class="danger-btn" :disabled="busy" @click="closeTerm">Close {{ term }}</button>
    </div>

    <div class="section">
      <h2>Close Year</h2>
      <p class="sub">Currently on <strong>{{ year }}</strong>.</p>
      <div class="disclaimer">
        Promotes every active student to their next class (e.g. S.1 &rarr; S.2), graduates whoever's at the top of
        the ladder (flagged, not deleted -- their records stay searchable as alumni), and starts {{ Number(year) + 1 }}
        on Term 1. Requires Term 3 {{ year }} to already be closed. This cannot be undone in bulk.
      </div>
      <button type="button" class="danger-btn" :disabled="busy" @click="closeYear">Close {{ year }} &amp; Promote Students</button>
    </div>
  </template>
</template>

<style>
.header-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:30px;gap:20px;}
.header-row h1{margin:0 0 5px;font-size:1.4rem;text-transform:uppercase;letter-spacing:0.5px;}
.sub{color:var(--muted);margin:0;font-size:0.85rem;}
.logo-preview{height:50px;border-radius:6px;border:1px solid var(--border);padding:4px;background:var(--panel-raised);}
.empty{color:var(--muted);font-size:0.85rem;}
.alert{padding:15px;border-radius:8px;font-size:0.85rem;margin-bottom:25px;font-family:monospace;line-height:1.4;}
.alert.success{background:rgba(16,185,129,0.05);border:1px solid #10b981;color:#34d399;}
.alert.error{background:rgba(239,68,68,0.05);border:1px solid #ef4444;color:#f87171;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:30px;box-shadow:0 4px 20px rgba(0,0,0,0.25);margin-bottom:30px;}
.logo-row{display:flex;align-items:center;gap:25px;border-bottom:1px solid var(--border);padding-bottom:25px;margin-bottom:25px;}
.logo-box{width:100px;height:100px;background:var(--panel);border:2px dashed var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.logo-box img{width:100%;height:100%;object-fit:cover;}
.hint{font-size:0.78rem;color:var(--muted);margin:6px 0 0;line-height:1.5;}
.hint a{color:var(--cyan);}
label{font-size:0.72rem;color:var(--muted);text-transform:uppercase;font-weight:bold;display:block;margin-bottom:6px;letter-spacing:0.5px;}
input,select{background:var(--panel-raised);border:1px solid var(--border);padding:12px;border-radius:6px;color:var(--text);font-size:0.85rem;box-sizing:border-box;width:100%;}
input:focus,select:focus{border-color:var(--cyan);outline:none;}
.grid-block{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
.grid-block.bordered{border-top:1px solid var(--border);padding-top:25px;margin-top:25px;}
@media (max-width:768px){.grid-block{grid-template-columns:1fr;}}
.save-row{text-align:right;margin-top:30px;border-top:1px solid var(--border);padding-top:20px;}
.primary-btn{background:var(--cyan);color:#04121a;border:none;padding:12px 35px;border-radius:6px;font-weight:bold;cursor:pointer;font-size:0.85rem;text-transform:uppercase;}
.primary-btn:disabled{opacity:0.6;cursor:default;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 4px;}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:16px;line-height:1.5;}
.disclaimer a{color:var(--cyan);}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:0.85rem;}
.danger-btn:hover{background:rgba(239,68,68,0.08);}
.danger-btn:disabled{opacity:0.6;cursor:default;}
</style>
