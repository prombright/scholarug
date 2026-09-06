<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { electionsCandidatesApi, scholarBase } from '../services/api'

const SB = scholarBase()
const route = useRoute()
const electionId = computed(() => route.params.id)

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const success = ref('')
const election = ref(null)
const locked = ref(false)
const positions = ref([])

async function load() {
  loading.value = true
  try {
    const { data } = await electionsCandidatesApi.get(electionId.value)
    election.value = data.election
    locked.value = data.locked
    positions.value = data.positions
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function review(candidate, decision) {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const { data } = await electionsCandidatesApi.review({ action: 'review', election_id: electionId.value, candidate_id: candidate.id, decision })
    positions.value = data.positions
    success.value = data.message
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not review this candidate.'
  } finally {
    busy.value = false
  }
}

function photoUrl(path) {
  return `${SB}${path}`
}
</script>

<template>
  <p><router-link to="/elections" class="back">&larr; All Elections</router-link></p>
  <p v-if="loading" class="empty">Loading…</p>
  <template v-else-if="election">
    <h1 class="page-title">Candidates — {{ election.title }}</h1>

    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="success" class="alert success">{{ success }}</div>

    <div v-if="locked" class="disclaimer">Voting has started (or ended) — approvals are locked so every voter sees the same candidate list.</div>

    <div v-if="!positions.length" class="section"><div class="empty">No positions yet — add some on the <router-link :to="`/elections/${electionId}/positions`" style="color:var(--cyan);">Positions</router-link> page first.</div></div>

    <div v-for="p in positions" :key="p.id" class="section" :id="`position-${p.id}`">
      <h3>{{ p.title }}</h3>
      <div v-if="!p.candidates.length" class="empty">No applications yet for this position.</div>
      <div v-else class="cand-grid">
        <div v-for="c in p.candidates" :key="c.id" class="cand-card">
          <img v-if="c.photo_path" :src="photoUrl(c.photo_path)" alt="">
          <div v-else class="no-photo">No Photo</div>
          <div class="pill" :class="c.status">{{ c.status }}</div>
          <div class="cand-name">{{ c.candidate_name }}</div>
          <div v-if="c.manifesto" class="cand-manifesto">{{ c.manifesto }}</div>
          <div v-if="c.status === 'Pending' && !locked" class="cand-actions">
            <button type="button" class="approve" :disabled="busy" @click="review(c, 'Approved')">Approve</button>
            <button type="button" class="reject" :disabled="busy" @click="review(c, 'Rejected')">Reject</button>
          </div>
        </div>
      </div>
    </div>
  </template>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:var(--amber);margin-bottom:20px;}
h3{font-size:1rem;margin:0 0 12px;}
.cand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.cand-card{background:var(--panel-raised);border:1px solid var(--border);border-radius:8px;padding:14px;}
.cand-card img{width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:8px;background:var(--border);}
.no-photo{width:100%;height:120px;border-radius:6px;margin-bottom:8px;background:var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:0.75rem;}
.cand-name{font-weight:600;font-size:0.9rem;}
.cand-manifesto{color:var(--muted);font-size:0.78rem;margin:6px 0;max-height:60px;overflow-y:auto;white-space:pre-line;}
.pill{display:inline-block;font-size:0.68rem;padding:2px 8px;border-radius:20px;font-weight:700;text-transform:uppercase;margin-bottom:8px;}
.pill.Pending{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.Approved{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.Rejected{background:rgba(239,68,68,0.1);color:var(--danger);}
.cand-actions{display:flex;gap:6px;margin-top:8px;}
button.approve{background:var(--green);color:#04221a;border:none;padding:6px 12px;border-radius:6px;font-size:0.75rem;font-weight:700;cursor:pointer;}
button.reject{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;border-radius:6px;font-size:0.75rem;font-weight:700;cursor:pointer;}
button:disabled{opacity:0.6;cursor:default;}
.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:12px 0;}
</style>
