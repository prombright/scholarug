<script setup>
import { ref, computed, onMounted } from 'vue'
import { dashboardApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const isPrimary = ref(false)
const studentCount = ref(0)
const staffCount = ref(0)
const classCount = ref(0)
const pendingFeedback = ref(0)
const classDistribution = ref([])
const gender = ref({ male: 0, female: 0 })

onMounted(async () => {
  try {
    const { data } = await dashboardApi.get()
    isPrimary.value = data.is_primary
    studentCount.value = data.student_count
    staffCount.value = data.staff_count
    classCount.value = data.class_count
    pendingFeedback.value = data.pending_feedback
    classDistribution.value = data.class_distribution
    gender.value = data.gender
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the dashboard.'
  } finally {
    loading.value = false
  }
})

const classDistMax = computed(() => Math.max(1, ...classDistribution.value.map((r) => r.count)))
const genderTotal = computed(() => gender.value.male + gender.value.female)
const genderMalePct = computed(() => (genderTotal.value ? Math.round((gender.value.male / genderTotal.value) * 100) : 0))
const genderFemalePct = computed(() => (genderTotal.value ? 100 - genderMalePct.value : 0))

// `route:` items are migrated; `href:` still classic PHP.
const modules = [
  { route: '/classes', icon: 'bi-diagram-3', title: 'Classes', desc: 'Set up classes, streams, and assign class teachers.' },
  { route: '/students', icon: 'bi-mortarboard', title: 'Students', desc: 'Enroll and manage student records.' },
  { href: SB + 'staff_manager.php', icon: 'bi-person-badge', title: 'Teachers', desc: 'Register staff and generate login codes.' },
  { route: '/assignments', icon: 'bi-clipboard-check', title: 'Assignments', desc: 'Assign teachers to subjects and classes.' },
  { route: '/subjects', icon: 'bi-journal-bookmark', title: 'Subjects', desc: "Tick which subjects your school offers." },
  { route: '/parents', icon: 'bi-people', title: 'Parent Accounts', desc: 'Create parent logins and link them to students.' },
  { href: SB + 'school_admin/message_parents.php', icon: 'bi-chat-dots', title: 'Message Parents', desc: 'Send a bulk SMS to a class or the whole school.' },
  { route: '/fees', icon: 'bi-cash-coin', title: 'Fees', desc: 'Record and review student fee payments.' },
  { route: '/settings', icon: 'bi-gear', title: 'School Settings', desc: 'School name, logo, term, and academic year.' },
  { route: '/grading', icon: 'bi-file-earmark-text', title: 'Report Cards', desc: 'Grading bands, display settings, and bulk/single printing.' },
  { route: '/elections', icon: 'bi-award', title: 'Student Elections', desc: 'Review candidacies and run leadership elections, with live turnout.' },
  { route: '/assessments', icon: 'bi-clipboard-data', title: 'Assessments', desc: 'Create assessments and choose which count toward the report.' },
  { href: SB + 'portal_handoff.php?to=analytics', icon: 'bi-bar-chart-line', title: 'Performance Analytics', desc: 'Class, subject, and gender performance, ranked reports as PDF.' }
]
</script>

<template>
  <h1>Welcome back</h1>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <div class="welcome-sub">{{ isPrimary ? 'Primary School' : 'Secondary School' }} Admin Panel ({{ isPrimary ? 'Baby Class – P.7' : 'S.1 – S.6' }})</div>

    <div class="stat-grid">
      <div class="stat-card students"><div class="stat-icon"><i class="bi bi-people"></i></div><div><div class="n">{{ studentCount }}</div><div class="label">Students</div></div></div>
      <div class="stat-card staff"><div class="stat-icon"><i class="bi bi-person-badge"></i></div><div><div class="n">{{ staffCount }}</div><div class="label">Staff</div></div></div>
      <div class="stat-card classes"><div class="stat-icon"><i class="bi bi-diagram-3"></i></div><div><div class="n">{{ classCount }}</div><div class="label">Classes</div></div></div>
      <div class="stat-card messages" :class="{ alert: pendingFeedback > 0 }"><div class="stat-icon"><i class="bi bi-chat-dots"></i></div><div><div class="n">{{ pendingFeedback }}</div><div class="label">New Parent Messages</div></div></div>
    </div>

    <div class="section-label">Functionalities</div>
    <div class="module-grid">
      <component v-for="m in modules" :key="m.title" :is="m.route ? 'router-link' : 'a'" :to="m.route" :href="m.href" class="module-card">
        <div class="icon"><i class="bi" :class="m.icon"></i></div>
        <div class="title">{{ m.title }}</div>
        <div class="desc">{{ m.desc }}</div>
      </component>
    </div>

    <div class="section-label">Enrollment at a Glance</div>
    <div class="chart-section">
      <div class="chart-card">
        <h2>Students per Class</h2>
        <div v-if="!classDistribution.length" class="chart-empty">No classes set up yet -- add one under Classes to see this chart.</div>
        <div v-else v-for="row in classDistribution" :key="row.label" class="bar-row">
          <span class="bar-label">{{ row.label }}</span>
          <span class="bar-track"><span class="bar-fill" :style="{ width: Math.round((row.count / classDistMax) * 100) + '%' }"></span></span>
          <span class="bar-count">{{ row.count }}</span>
        </div>
      </div>
      <div class="chart-card donut-wrap">
        <h2 style="align-self:flex-start;">Gender Split</h2>
        <div v-if="genderTotal === 0" class="chart-empty">No student records with gender set yet.</div>
        <template v-else>
          <div class="donut" :style="{ background: `conic-gradient(var(--cyan) 0% ${genderMalePct}%, var(--purple) ${genderMalePct}% 100%)` }">
            <div class="donut-center"><div class="n">{{ genderTotal }}</div><div class="label">Students</div></div>
          </div>
          <div class="donut-legend">
            <span><span class="dot" style="background:var(--cyan);"></span>Male {{ genderMalePct }}%</span>
            <span><span class="dot" style="background:var(--purple);"></span>Female {{ genderFemalePct }}%</span>
          </div>
        </template>
      </div>
    </div>
  </template>
</template>

<style scoped>
h1{font-size:1.4rem;margin:0 0 4px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;}
.welcome-sub{color:var(--muted);font-size:0.85rem;margin-bottom:28px;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:32px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:14px;}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;}
.stat-card.students .stat-icon{background:rgba(0,168,168,.15);color:var(--cyan);}
.stat-card.staff .stat-icon{background:rgba(16,185,129,.15);color:var(--green);}
.stat-card.classes .stat-icon{background:rgba(245,158,11,.15);color:var(--amber);}
.stat-card.messages .stat-icon{background:rgba(168,85,247,.15);color:var(--purple);}
.stat-card.messages.alert .stat-icon{background:rgba(239,68,68,.15);color:var(--danger);}
.stat-card .n{font-size:1.7rem;font-weight:700;line-height:1.1;}
.stat-card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.section-label{font-size:0.95rem;font-weight:700;margin:36px 0 16px;}
.module-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
.module-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:22px;text-decoration:none;color:var(--text);display:block;transition:border-color .15s;}
.module-card:hover{border-color:var(--cyan);}
.module-card .icon{width:36px;height:36px;border-radius:10px;background:rgba(0,168,168,.12);color:var(--cyan);display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:14px;}
.module-card .title{font-weight:700;font-size:0.9rem;}
.module-card .desc{color:var(--muted);font-size:0.75rem;margin-top:4px;}
.chart-section{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;}
@media(max-width:760px){.chart-section{grid-template-columns:1fr;}}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;}
.chart-card h2{font-size:0.9rem;margin-bottom:18px;color:var(--text);}
.chart-empty{color:var(--muted);font-size:0.85rem;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:0.8rem;}
.bar-row .bar-label{width:110px;flex-shrink:0;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bar-track{flex:1;background:var(--border);border-radius:6px;height:10px;overflow:hidden;}
.bar-fill{display:block;height:100%;background:var(--cyan);border-radius:6px;transition:width 1s cubic-bezier(.22,1,.36,1);}
.bar-row .bar-count{width:24px;text-align:right;color:var(--text);font-weight:600;}
.donut-wrap{display:flex;flex-direction:column;align-items:center;}
.donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;}
.donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-center .n{font-size:1.4rem;font-weight:700;}
.donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.donut-legend{display:flex;gap:18px;font-size:0.8rem;color:var(--muted);}
.donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
</style>
