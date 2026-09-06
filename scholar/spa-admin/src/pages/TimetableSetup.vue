<script setup>
import { ref, onMounted } from 'vue'
import { timetableSetupApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const dayNames = ref({})
const byDay = ref({})

const form = ref({
  days: [1, 2, 3, 4, 5],
  day_start: '08:00',
  lesson_minutes: 40,
  periods_per_day: 8,
  break_after: 2,
  break_minutes: 20,
  lunch_after: 4,
  lunch_minutes: 45
})

async function load() {
  loading.value = true
  try {
    const { data } = await timetableSetupApi.get()
    dayNames.value = data.day_names
    byDay.value = data.by_day
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load the timetable structure.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

function toggleDay(num) {
  const idx = form.value.days.indexOf(num)
  if (idx === -1) form.value.days.push(num); else form.value.days.splice(idx, 1)
}

async function quickSetup() {
  busy.value = true
  message.value = null
  try {
    const { data } = await timetableSetupApi.action({ action: 'quick_setup', ...form.value })
    byDay.value = data.by_day
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not save the day structure.' }
  } finally {
    busy.value = false
  }
}

async function updatePeriod(p) {
  busy.value = true
  message.value = null
  try {
    const { data } = await timetableSetupApi.action({
      action: 'update_period', period_id: p.id, label: p.label,
      start_time: p.start_time.slice(0, 5), end_time: p.end_time.slice(0, 5),
      is_teaching_period: !!Number(p.is_teaching_period)
    })
    byDay.value = data.by_day
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not update this period.' }
  } finally {
    busy.value = false
  }
}

async function deletePeriod(p) {
  busy.value = true
  message.value = null
  try {
    const { data } = await timetableSetupApi.action({ action: 'delete_period', period_id: p.id })
    byDay.value = data.by_day
    message.value = { type: 'success', text: data.message }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not remove this period.' }
  } finally {
    busy.value = false
  }
}

const hasAnyDay = () => Object.keys(byDay.value).length > 0
</script>

<template>
  <h1 class="page-title">Timetable — Day Structure</h1>
  <p class="sub">Set up your school's periods, breaks and lunch before generating a timetable. This only needs doing once (or whenever your daily schedule changes).</p>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <div class="section">
    <h2>Quick Setup</h2>
    <p class="sub">Builds the same pattern across every day you tick below, replacing whatever those days currently have.</p>
    <form @submit.prevent="quickSetup">
      <label>Days</label>
      <div>
        <label v-for="(name, num) in dayNames" :key="num" class="day-check">
          <input type="checkbox" :checked="form.days.includes(Number(num))" @change="toggleDay(Number(num))">
          {{ name }}
        </label>
      </div>
      <div class="row">
        <div><label>Day Starts At</label><input type="time" v-model="form.day_start" required></div>
        <div><label>Minutes per Lesson</label><input type="number" v-model.number="form.lesson_minutes" min="20" max="120" required></div>
        <div><label>Lessons per Day</label><input type="number" v-model.number="form.periods_per_day" min="1" max="14" required></div>
      </div>
      <div class="row">
        <div><label>Break After Lesson #</label><input type="number" v-model.number="form.break_after" min="0" max="14"></div>
        <div><label>Break Length (min)</label><input type="number" v-model.number="form.break_minutes" min="0" max="60"></div>
        <div><label>Lunch After Lesson #</label><input type="number" v-model.number="form.lunch_after" min="0" max="14"></div>
        <div><label>Lunch Length (min)</label><input type="number" v-model.number="form.lunch_minutes" min="0" max="90"></div>
      </div>
      <button type="submit" :disabled="busy">Save Day Structure</button>
    </form>
  </div>

  <div class="section">
    <h2>Current Structure</h2>
    <p v-if="loading" class="empty">Loading…</p>
    <template v-else>
      <div v-if="!hasAnyDay()" class="empty">Nothing set up yet — use Quick Setup above.</div>
      <template v-for="(name, num) in dayNames" :key="num">
        <template v-if="byDay[num] && byDay[num].length">
          <div class="day-heading">{{ name }}</div>
          <div class="table-wrap">
            <table>
              <tr><th>#</th><th>Label</th><th>Start</th><th>End</th><th>Teaching?</th><th></th></tr>
              <tr v-for="p in byDay[num]" :key="p.id">
                <td>{{ p.period_number }}</td>
                <template v-if="Number(p.is_teaching_period) !== 1">
                  <td colspan="3" class="brk">{{ p.label }} ({{ p.start_time.slice(0,5) }}–{{ p.end_time.slice(0,5) }})</td>
                  <td class="brk">Break/Lunch</td>
                  <td><button type="button" class="danger-btn" :disabled="busy" @click="deletePeriod(p)">Remove</button></td>
                </template>
                <template v-else>
                  <td><input type="text" v-model="p.label"></td>
                  <td><input type="time" :value="p.start_time.slice(0,5)" @change="p.start_time = $event.target.value"></td>
                  <td><input type="time" :value="p.end_time.slice(0,5)" @change="p.end_time = $event.target.value"></td>
                  <td><input type="checkbox" :checked="true" style="width:auto;" disabled></td>
                  <td>
                    <button type="button" style="padding:6px 10px;font-size:0.72rem;margin:0;" :disabled="busy" @click="updatePeriod(p)">Save</button>
                    <button type="button" class="danger-btn" :disabled="busy" @click="deletePeriod(p)">Remove</button>
                  </td>
                </template>
              </tr>
            </table>
          </div>
        </template>
      </template>
    </template>
  </div>

  <div class="section" style="text-align:center;">
    <router-link to="/timetable-generate" class="cta-link">Continue to Generate Timetable &rarr;</router-link>
  </div>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 18px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 6px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
select,input{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.75rem;margin-top:0;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.day-check{display:inline-flex;align-items:center;gap:6px;padding:6px 12px 6px 0;font-size:0.85rem;margin:0;}
.day-check input{width:auto;}
table{width:100%;border-collapse:collapse;font-size:0.82rem;min-width:480px;}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:140px;}
.empty{color:var(--muted);font-size:0.85rem;}
.day-heading{font-size:0.95rem;margin:22px 0 10px;color:var(--cyan);}
.brk{color:var(--muted);font-style:italic;}
.table-wrap{overflow-x:auto;}
.cta-link{color:#04222a;background:var(--cyan);padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-size:0.9rem;}
</style>
