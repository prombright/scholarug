<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { electionsResultsApi } from '../services/api'

const route = useRoute()
const electionId = computed(() => route.params.id)

const loading = ref(true)
const election = ref(null)
const phase = ref('')
const positions = ref([])

async function load() {
  loading.value = true
  try {
    const { data } = await electionsResultsApi.get(electionId.value)
    election.value = data.election
    phase.value = data.phase
    positions.value = data.positions
  } finally {
    loading.value = false
  }
}
onMounted(load)

const phaseLabel = computed(() => phase.value.charAt(0).toUpperCase() + phase.value.slice(1))
</script>

<template>
  <p><router-link to="/elections" class="back">&larr; All Elections</router-link></p>
  <p v-if="loading" class="empty">Loading…</p>
  <template v-else-if="election">
    <h1 class="page-title">Results — {{ election.title }}</h1>

    <div class="disclaimer">
      Turnout is tracked completely separately from votes — this page (and this schema) can
      never show who voted for whom, only how many students have voted and how the tally
      stands. Current phase: <strong>{{ phaseLabel }}</strong>.
    </div>

    <div v-if="!positions.length" class="section"><div class="empty">No positions yet.</div></div>

    <div v-for="p in positions" :key="p.id" class="section">
      <h3>{{ p.title }}</h3>
      <div class="turnout">{{ p.turnout.voted }} of {{ p.turnout.eligible }} eligible students have voted ({{ p.turnout.percentage }}%)</div>

      <div v-if="!p.tally.candidates.length" class="empty">No approved candidates for this position yet.</div>
      <template v-else>
        <div v-for="c in p.tally.candidates" :key="c.candidate_name" class="bar-row">
          <div class="bar-name">{{ c.candidate_name }}</div>
          <div class="bar-bg"><div class="bar-fill" :style="{ width: c.percentage + '%' }"></div></div>
          <div class="bar-stat">{{ c.votes }} votes ({{ c.percentage }}%)</div>
        </div>
        <div class="total-votes">Total votes cast for this position: {{ p.tally.total_votes }}</div>
      </template>
    </div>
  </template>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.disclaimer{background:rgba(0,168,168,0.08);border:1px solid rgba(0,168,168,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:var(--cyan);margin-bottom:20px;line-height:1.5;}
h3{font-size:1rem;margin:0 0 4px;}
.turnout{color:var(--muted);font-size:0.8rem;margin-bottom:14px;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.bar-name{width:160px;flex-shrink:0;font-size:0.85rem;}
.bar-bg{flex:1;background:var(--border);border-radius:6px;overflow:hidden;height:20px;position:relative;}
.bar-fill{height:100%;background:var(--cyan);}
.bar-stat{width:110px;flex-shrink:0;text-align:right;font-size:0.8rem;color:var(--muted);}
.total-votes{color:var(--muted);font-size:0.78rem;margin-top:8px;}
.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:12px 0;}
</style>
