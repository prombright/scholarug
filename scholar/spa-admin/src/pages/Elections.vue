<script setup>
import { ref, onMounted } from 'vue'
import { electionsApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const elections = ref([])
const form = ref({ title: '', term: '', year: '', opens_at: '', closes_at: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await electionsApi.get()
    elections.value = data.elections
    if (!form.value.term) form.value.term = data.current_term
    if (!form.value.year) form.value.year = data.current_year
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load elections.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function createElection() {
  busy.value = true
  message.value = null
  try {
    const { data } = await electionsApi.action({ action: 'create_election', ...form.value })
    elections.value = data.elections
    message.value = { type: 'success', text: data.message }
    form.value.title = ''
    form.value.opens_at = ''
    form.value.closes_at = ''
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not create election.' }
  } finally {
    busy.value = false
  }
}

async function publish(e) {
  busy.value = true
  message.value = null
  try {
    const { data } = await electionsApi.action({ action: 'publish_election', election_id: e.id })
    elections.value = data.elections
    message.value = { type: 'success', text: data.message }
  } catch (err) {
    message.value = { type: 'error', text: err.response?.data?.message || 'Could not publish election.' }
  } finally {
    busy.value = false
  }
}

function fmt(dt) {
  return new Date(dt.replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <h1 class="page-title">Student Elections</h1>
  <p class="sub">Students self-nominate for a position; you review and approve candidacies, then voting runs automatically inside the window you set.</p>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <div class="section">
    <h3>New Election</h3>
    <form @submit.prevent="createElection">
      <div class="row3">
        <div><label>Title</label><input type="text" v-model="form.title" placeholder="e.g. 2026 Student Leadership Elections" required></div>
        <div><label>Term</label><input type="text" v-model="form.term" required></div>
        <div><label>Year</label><input type="text" v-model="form.year" required></div>
      </div>
      <div class="row2">
        <div><label>Nominations Open Until / Voting Opens</label><input type="datetime-local" v-model="form.opens_at" required></div>
        <div><label>Voting Closes</label><input type="datetime-local" v-model="form.closes_at" required></div>
      </div>
      <div class="hint">Students can apply for positions any time before "opens" — voting itself only runs between these two times, then closes automatically.</div>
      <button type="submit" :disabled="busy">Create Election (Draft)</button>
    </form>
  </div>

  <div class="section">
    <p v-if="loading" class="empty">Loading…</p>
    <div v-else class="table-wrap">
      <table>
        <thead><tr><th>Title</th><th>Term</th><th>Status</th><th>Opens</th><th>Closes</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!elections.length"><td colspan="6" class="empty">No elections yet — create one above.</td></tr>
          <tr v-for="e in elections" :key="e.id">
            <td>{{ e.title }}</td>
            <td>{{ e.term }} {{ e.year }}</td>
            <td><span class="pill" :class="e.phase">{{ e.phase.charAt(0).toUpperCase() + e.phase.slice(1) }}</span></td>
            <td>{{ fmt(e.opens_at) }}</td>
            <td>{{ fmt(e.closes_at) }}</td>
            <td style="white-space:nowrap;">
              <router-link class="act" :to="`/elections/${e.id}/positions`">Positions</router-link>
              <router-link class="act" :to="`/elections/${e.id}/candidates`">Candidates</router-link>
              <router-link class="act" :to="`/elections/${e.id}/results`">Results</router-link>
              <button v-if="e.status === 'Draft'" type="button" class="publish-btn" :disabled="busy" @click="publish(e)">Publish</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 20px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h3{margin-top:0;font-size:1rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
.row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
@media(max-width:700px){.row3,.row2{grid-template-columns:1fr;}}
.hint{color:var(--muted);font-size:0.78rem;margin-bottom:14px;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.publish-btn{padding:4px 10px;font-size:0.75rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:640px;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{display:inline-block;font-size:0.7rem;padding:3px 10px;border-radius:20px;font-weight:700;text-transform:uppercase;}
.pill.draft{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.nominating{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.voting{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.closed{background:rgba(239,68,68,0.1);color:var(--danger);}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;margin-right:12px;}
.table-wrap{overflow-x:auto;}
</style>
