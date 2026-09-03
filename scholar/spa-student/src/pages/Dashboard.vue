<script setup>
import { ref, computed, onMounted } from 'vue'
import { dashboardApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const student = ref(null)
const marksCount = ref(0)
const fees = ref({ expected: 0, paid: 0, balance: 0 })
const attendance = ref({ present: 0, absent: 0, sick: 0, permission: 0 })
const openPositionsToVote = ref(0)
const unreadMessageCount = ref(0)

onMounted(async () => {
  try {
    const { data } = await dashboardApi.get()
    student.value = data.student
    marksCount.value = data.marks_count
    fees.value = data.fees
    attendance.value = data.attendance
    openPositionsToVote.value = data.open_positions_to_vote
    unreadMessageCount.value = data.unread_message_count
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your dashboard.'
  } finally {
    loading.value = false
  }
})

const ATTENDANCE_COLORS = { present: 'var(--green)', absent: 'var(--danger)', sick: 'var(--amber)', permission: 'var(--purple)' }
const attendanceTotal = computed(() => Object.values(attendance.value).reduce((a, b) => a + b, 0))
const attendanceGradient = computed(() => {
  let cursor = 0
  const stops = []
  for (const [status, color] of Object.entries(ATTENDANCE_COLORS)) {
    const n = attendance.value[status]
    if (!n) continue
    const pct = Math.round((n / attendanceTotal.value) * 100)
    stops.push(`${color} ${cursor}% ${cursor + pct}%`)
    cursor += pct
  }
  return stops.join(', ')
})
const attendanceLegend = computed(() =>
  Object.entries(ATTENDANCE_COLORS)
    .filter(([status]) => attendance.value[status] > 0)
    .map(([status, color]) => ({ label: status.charAt(0).toUpperCase() + status.slice(1), n: attendance.value[status], color }))
)

function tint(hex, alpha = 0.16) {
  const h = hex.replace('#', '')
  const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const cards = computed(() => [
  { route: '/results', icon: 'bi-mortarboard', color: '#00A8A8', title: 'My Results', desc: 'Published assessment marks and your report card.', stat: `${marksCount.value} published` },
  { route: '/fees', icon: 'bi-cash-coin', color: '#10b981', title: 'Fees', desc: "What you owe and what you've paid so far.", stat: fees.value.balance > 0 ? `UGX ${Math.round(fees.value.balance).toLocaleString()} due` : 'Fully paid', danger: fees.value.balance > 0, green: fees.value.balance <= 0 },
  { route: '/attendance', icon: 'bi-calendar-check', color: '#f59e0b', title: 'Attendance', desc: 'Your present/absent record for the term.', stat: `${attendance.value.present} days present` },
  { route: '/library', icon: 'bi-book', color: '#8b5cf6', title: 'Library', desc: 'Notes and past papers shared by your teachers.' },
  { route: '/elections', icon: 'bi-check2-square', color: '#ec4899', title: 'Elections', desc: 'Vote for student leadership positions.', stat: openPositionsToVote.value > 0 ? `${openPositionsToVote.value} awaiting your vote` : 'No open votes', danger: openPositionsToVote.value > 0 },
  { route: '/messages', icon: 'bi-chat-dots', color: '#3b82f6', title: 'Messages', desc: 'Talk directly with your subject teachers.', stat: unreadMessageCount.value > 0 ? `${unreadMessageCount.value} unread` : null, danger: unreadMessageCount.value > 0 }
])
</script>

<template>
  <div class="header">
    <div>
      <h1>{{ student?.full_name }}</h1>
      <div class="sub">{{ student?.class_name || 'Unassigned class' }} · {{ student?.level_type }}<span v-if="student?.student_no"> · No. {{ student.student_no }}</span></div>
    </div>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <div v-if="attendanceTotal > 0" class="chart-card">
      <h2>My Attendance</h2>
      <div class="donut" :style="{ background: `conic-gradient(${attendanceGradient})` }">
        <div class="donut-center"><div class="n">{{ attendanceTotal }}</div><div class="label">Days Recorded</div></div>
      </div>
      <div class="donut-legend">
        <span v-for="e in attendanceLegend" :key="e.label"><span class="dot" :style="{ background: e.color }"></span>{{ e.label }} {{ e.n }}</span>
      </div>
    </div>

    <div class="card-grid">
      <router-link v-for="c in cards" :key="c.title" :to="c.route" class="feature-card" :style="{ '--icon-color': c.color, '--icon-tint': tint(c.color) }">
        <i class="bi bi-arrow-right arrow"></i>
        <div class="icon"><i class="bi" :class="c.icon"></i></div>
        <h3>{{ c.title }}</h3>
        <p>{{ c.desc }}</p>
        <span v-if="c.stat" class="stat" :class="{ danger: c.danger, green: c.green }">{{ c.stat }}</span>
      </router-link>
    </div>
  </template>
</template>

<style>
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
.header .sub{color:var(--muted);font-size:0.85rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;}
.feature-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;text-decoration:none;color:var(--text);transition:transform .15s,border-color .15s,box-shadow .15s;position:relative;overflow:hidden;}
.feature-card:hover{transform:translateY(-4px);border-color:var(--icon-color, var(--cyan));box-shadow:0 12px 30px rgba(0,0,0,.3);}
.feature-card .icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:16px;background:var(--icon-tint);color:var(--icon-color);}
.feature-card h3{margin:0 0 6px;font-size:1.05rem;}
.feature-card p{margin:0;color:var(--muted);font-size:0.82rem;line-height:1.5;}
.feature-card .stat{display:inline-block;margin-top:14px;font-size:0.75rem;font-weight:700;padding:4px 10px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.feature-card .stat.danger{background:rgba(239,68,68,0.14);color:var(--danger);}
.feature-card .stat.green{background:rgba(16,185,129,0.14);color:var(--green);}
.feature-card .arrow{position:absolute;top:22px;right:22px;color:var(--muted);font-size:1.1rem;opacity:0;transition:opacity .15s,transform .15s;}
.feature-card:hover .arrow{opacity:1;transform:translateX(3px);}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px;display:flex;flex-direction:column;align-items:center;}
.chart-card h2{font-size:0.9rem;margin:0 0 18px;align-self:flex-start;}
.donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;}
.donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-center .n{font-size:1.4rem;font-weight:700;}
.donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.donut-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;font-size:0.8rem;color:var(--muted);}
.donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
</style>
