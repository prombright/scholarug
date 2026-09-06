<script setup>
import { ref, reactive, onMounted } from 'vue'
import { electionsApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const submitting = ref(false)
const ballot = ref([])
const notices = ref({})
const selections = reactive({}) // position_id => candidate_id

async function load() {
  loading.value = true
  try {
    const { data } = await electionsApi.get()
    ballot.value = data.ballot
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the ballot.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function submit() {
  submitting.value = true
  notices.value = {}
  try {
    const { data } = await electionsApi.vote(selections)
    notices.value = data.notices
    ballot.value = data.ballot
    for (const k of Object.keys(selections)) delete selections[k]
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not submit your vote(s).'
  } finally {
    submitting.value = false
  }
}

function noticeFor(positionId) {
  const n = notices.value[positionId]
  if (!n) return null
  if (n.ok) return { type: 'success', text: 'Vote recorded.' }
  if (n.reason === 'already_voted') return { type: 'error', text: "You'd already voted for that position — your original vote stands." }
  return { type: 'error', text: "That vote couldn't be recorded (voting may have just closed). Please refresh." }
}
</script>

<template>
  <div class="page-title">Cast Your Vote</div>
  <div class="links-row">
    <a :href="`${SB}elections/apply.php`">Apply to Run &rarr;</a>
    <a :href="`${SB}elections/my_applications.php`">My Candidacy Status &rarr;</a>
    <a :href="`${SB}elections/my_results.php`">Past Results &rarr;</a>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <template v-for="position in ballot" :key="position.id">
      <div v-if="noticeFor(position.id)" class="alert" :class="noticeFor(position.id).type">{{ noticeFor(position.id).text }}</div>
    </template>

    <div v-if="!ballot.length" class="card"><div class="empty">No positions are open for voting right now.</div></div>

    <form v-else @submit.prevent="submit">
      <div v-for="position in ballot" :key="position.id" class="card">
        <div class="pos-title">{{ position.title }}</div>
        <div class="pos-election">{{ position.election_title }}</div>

        <div v-if="position.already_voted" class="already">You've already voted for this position.</div>
        <div v-else-if="!position.candidates.length" class="already">No approved candidates for this position.</div>
        <div v-else class="cand-grid">
          <div v-for="c in position.candidates" :key="c.id" class="cand">
            <label>
              <input type="radio" :name="'vote_' + position.id" :value="c.id" v-model.number="selections[position.id]" required>
              <div class="cand-body">
                <img v-if="c.photo_path" :src="SB + c.photo_path" alt="">
                <div v-else class="cand-noimg">No Photo</div>
                <div class="cand-name">{{ c.candidate_name }}</div>
                <div v-if="c.manifesto" class="cand-manifesto">{{ c.manifesto }}</div>
              </div>
            </label>
          </div>
        </div>
      </div>
      <button type="submit" :disabled="submitting">{{ submitting ? 'Submitting…' : 'Submit My Vote(s)' }}</button>
    </form>
  </template>
</template>

<style scoped>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.links-row{margin-bottom:16px;}
.links-row a{color:var(--cyan);font-size:0.8rem;margin-right:16px;text-decoration:none;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:0.82rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.pos-title{font-weight:700;font-size:0.95rem;}
.pos-election{color:var(--muted);font-size:0.8rem;}
.cand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:12px 0;}
.cand{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:12px;cursor:pointer;}
.cand:has(input:checked){border-color:var(--cyan);background:rgba(0,168,168,0.08);}
.cand img{width:100%;height:100px;object-fit:cover;border-radius:6px;margin-bottom:6px;}
.cand-noimg{width:100%;height:100px;border-radius:6px;margin-bottom:6px;background:var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:0.72rem;}
.cand-name{font-weight:600;font-size:0.85rem;}
.cand-manifesto{color:var(--muted);font-size:0.75rem;margin-top:4px;white-space:pre-wrap;}
.cand label{display:flex;align-items:flex-start;gap:8px;cursor:pointer;}
.cand-body{flex:1;}
.already{color:var(--muted);font-size:0.85rem;background:var(--panel);border:1px solid var(--border);border-radius:6px;padding:12px;margin-top:12px;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:12px 26px;border-radius:8px;cursor:pointer;font-size:0.9rem;margin-top:10px;}
button:disabled{opacity:0.6;cursor:default;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
