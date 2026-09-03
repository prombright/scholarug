<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { electionsPositionsApi } from '../services/api'

const route = useRoute()
const electionId = computed(() => route.params.id)

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const success = ref('')
const election = ref(null)
const locked = ref(false)
const positions = ref([])
const newTitle = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await electionsPositionsApi.get(electionId.value)
    election.value = data.election
    locked.value = data.locked
    positions.value = data.positions
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function addPosition() {
  if (!newTitle.value.trim()) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const { data } = await electionsPositionsApi.action({ action: 'add_position', election_id: electionId.value, title: newTitle.value })
    positions.value = data.positions
    success.value = data.message
    newTitle.value = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not add position.'
  } finally {
    busy.value = false
  }
}

async function removePosition(p) {
  if (!confirm('Remove this position and all its applications?')) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const { data } = await electionsPositionsApi.action({ action: 'delete_position', election_id: electionId.value, position_id: p.id })
    positions.value = data.positions
    success.value = data.message
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not remove position.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <p><router-link to="/elections" class="back">&larr; All Elections</router-link></p>
  <p v-if="loading" class="empty">Loading…</p>
  <template v-else-if="election">
    <h1 class="page-title">Positions — {{ election.title }}</h1>

    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="success" class="alert success">{{ success }}</div>

    <div v-if="locked" class="disclaimer">Voting has started (or ended) for this election — positions are locked and can no longer be added or removed.</div>

    <div v-if="!locked" class="section">
      <form class="row" @submit.prevent="addPosition">
        <div>
          <label>New Position</label>
          <input type="text" v-model="newTitle" placeholder="e.g. Head Prefect" required>
        </div>
        <button type="submit" :disabled="busy">Add Position</button>
      </form>
    </div>

    <div class="section">
      <table>
        <thead><tr><th>Position</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!positions.length"><td colspan="2" class="empty">No positions yet.</td></tr>
          <tr v-for="p in positions" :key="p.id">
            <td>{{ p.title }}</td>
            <td>
              <router-link class="act" :to="`/elections/${electionId}/candidates#position-${p.id}`">Candidates</router-link>
              <button v-if="!locked" type="button" class="danger" :disabled="busy" style="margin-left:12px;" @click="removePosition(p)">Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:var(--amber);margin-bottom:20px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
.row{display:flex;gap:14px;align-items:end;margin-bottom:14px;}
.row > div:first-child{flex:1;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
button.danger{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.78rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;}
.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
