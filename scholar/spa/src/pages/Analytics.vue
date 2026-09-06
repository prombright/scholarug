<script setup>
import { ref, inject, watch, nextTick, onBeforeUnmount } from 'vue'
import Chart from 'chart.js/auto'
import { analyticsApi } from '../services/api'

const assignments = inject('assignments', ref([]))
const termLabel = inject('termLabel', ref(''))

const selected = ref(null) // the assignment object once chosen
const loading = ref(false)
const error = ref(null)
const subjectAnalytics = ref(null)
const classAnalytics = ref(null)
const isClassTeacherHere = ref(false)

const trendLabel = { up: '▲ Improving', down: '▼ Declining', flat: '— Steady' }
const trendClass = (t) => `trend-${t}`

let rankChart = null
let genderChart = null
const rankCanvas = ref(null)
const genderCanvas = ref(null)

function destroyCharts() {
  rankChart?.destroy()
  genderChart?.destroy()
  rankChart = null
  genderChart = null
}

async function pick(assignment) {
  selected.value = assignment
  loading.value = true
  error.value = null
  try {
    const { data } = await analyticsApi.get(assignment.class_id, assignment.subject_id)
    subjectAnalytics.value = data.subject_analytics
    classAnalytics.value = data.class_analytics
    isClassTeacherHere.value = data.is_class_teacher_here
    await nextTick()
    drawCharts()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load analytics for this class.'
  } finally {
    loading.value = false
  }
}

// Chart.js needs literal color strings, not CSS custom properties -- read
// the live token values so the charts still match whichever theme
// (dark/light) is active when this page draws them.
function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

function drawCharts() {
  destroyCharts()
  const sa = subjectAnalytics.value
  if (!sa || !sa.students.length || !rankCanvas.value) return

  const panel = cssVar('--panel')
  const border = cssVar('--border')
  const text = cssVar('--text')
  const muted = cssVar('--muted')

  const classAvg = sa.class_average ?? 0
  const avgLinePlugin = {
    id: 'avgLine',
    afterDraw(chart) {
      const { ctx, scales } = chart
      const px = scales.x.getPixelForValue(classAvg)
      ctx.save()
      ctx.setLineDash([4, 3])
      ctx.strokeStyle = '#f59e0b'
      ctx.lineWidth = 1.5
      ctx.beginPath()
      ctx.moveTo(px, scales.y.top)
      ctx.lineTo(px, scales.y.bottom)
      ctx.stroke()
      ctx.setLineDash([])
      ctx.fillStyle = '#f59e0b'
      ctx.font = "600 10px 'IBM Plex Mono', monospace"
      ctx.fillText(`avg ${classAvg}`, px + 6, scales.y.top + 10)
      ctx.restore()
    }
  }

  Chart.defaults.color = muted
  Chart.defaults.font.family = "Inter, 'Segoe UI', sans-serif"
  Chart.defaults.font.size = 11

  rankChart = new Chart(rankCanvas.value, {
    type: 'bar',
    data: {
      labels: sa.students.map((s) => s.full_name),
      datasets: [{
        data: sa.students.map((s) => s.average),
        backgroundColor: sa.students.map((s) =>
          s.trend === 'up' ? '#10b981' : s.trend === 'down' ? '#ef4444' : '#64748b'),
        borderRadius: 4,
        barThickness: 14
      }]
    },
    options: {
      indexAxis: 'y',
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: panel, borderColor: border, borderWidth: 1,
          titleColor: text, bodyColor: text, padding: 8,
          callbacks: { label: (ctx) => `Average ${ctx.parsed.x.toFixed(1)}` }
        }
      },
      scales: {
        x: { min: 0, max: 100, grid: { color: border }, ticks: { stepSize: 20 } },
        y: { grid: { display: false } }
      }
    },
    plugins: [avgLinePlugin]
  })

  if (sa.gender.male_avg != null || sa.gender.female_avg != null) {
    genderChart = new Chart(genderCanvas.value, {
      type: 'bar',
      data: {
        labels: [`Boys (${sa.gender.male_count})`, `Girls (${sa.gender.female_count})`],
        datasets: [{
          data: [sa.gender.male_avg ?? 0, sa.gender.female_avg ?? 0],
          backgroundColor: ['#00A8A8', '#f59e0b'],
          borderRadius: 5,
          barThickness: 30
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { min: 0, max: 100, grid: { color: border }, ticks: { stepSize: 25 } },
          y: { grid: { display: false } }
        }
      }
    })
  }
}

onBeforeUnmount(destroyCharts)
</script>

<template>
  <div class="page-title">Performance Analytics</div>

  <template v-if="!selected">
    <p class="page-sub">Choose one of your classes to see how its students are performing ({{ termLabel }}).</p>
    <p v-if="!assignments.length" class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</p>
    <div v-else class="card-grid">
      <a v-for="a in assignments" :key="`${a.class_id}-${a.subject_id}`" class="pick-card" href="#" @click.prevent="pick(a)">
        <h3>{{ a.class_name }}</h3>
        <div class="sub">{{ a.subject_name }} ({{ a.subject_code }})</div>
      </a>
    </div>
  </template>

  <template v-else>
    <div class="breadcrumb"><a href="#" @click.prevent="selected = null">All Classes</a> &rsaquo; {{ selected.class_name }} — {{ selected.subject_name }}</div>

    <p v-if="loading" class="empty">Loading…</p>
    <p v-else-if="error" class="empty">{{ error }}</p>

    <template v-else-if="subjectAnalytics && subjectAnalytics.students.length">
      <div class="section">
        <div class="stat-grid">
          <div class="stat-card"><div class="n">{{ subjectAnalytics.class_average }}</div><div class="l">Class Average</div></div>
          <div class="stat-card"><div class="n">{{ subjectAnalytics.class_highest }}</div><div class="l">Highest Average</div></div>
          <div class="stat-card"><div class="n">{{ subjectAnalytics.class_lowest }}</div><div class="l">Lowest Average</div></div>
          <div class="stat-card"><div class="n">{{ subjectAnalytics.assessment_count }}</div><div class="l">Assessments Counted</div></div>
          <div class="stat-card"><div class="n">{{ subjectAnalytics.gender.male_avg ?? '—' }}</div><div class="l">Boys Avg ({{ subjectAnalytics.gender.male_count }})</div></div>
          <div class="stat-card"><div class="n">{{ subjectAnalytics.gender.female_avg ?? '—' }}</div><div class="l">Girls Avg ({{ subjectAnalytics.gender.female_count }})</div></div>
        </div>
        <div class="highlight-grid">
          <div class="highlight-card">
            <div class="tag">🏆 Overall Winner</div>
            <div class="name">{{ subjectAnalytics.winner?.full_name ?? '—' }}</div>
            <div class="detail">{{ subjectAnalytics.winner ? 'Average: ' + subjectAnalytics.winner.average : 'No data yet' }}</div>
          </div>
          <div class="highlight-card">
            <div class="tag">🎯 Most Consistent</div>
            <div class="name">{{ subjectAnalytics.most_consistent?.full_name ?? '—' }}</div>
            <div class="detail">{{ subjectAnalytics.most_consistent ? `Spread: ±${subjectAnalytics.most_consistent.stddev} across ${subjectAnalytics.most_consistent.count} assessments` : 'Needs 2+ submitted assessments' }}</div>
          </div>
        </div>
      </div>

      <div class="island">
        <div class="island-charts">
          <div class="chart-box">
            <h4>Class ranking, against the class average</h4>
            <div class="chart-canvas-wrap"><canvas ref="rankCanvas"></canvas></div>
          </div>
          <div class="chart-box">
            <h4>Boys vs. girls average</h4>
            <div class="chart-canvas-wrap small"><canvas ref="genderCanvas"></canvas></div>
          </div>
        </div>
      </div>

      <div class="section">
        <div class="section-header">Full Ranking</div>
        <div class="table-wrap">
          <table class="ranking-table">
            <thead><tr><th>#</th><th>Student</th><th>Average</th><th>Highest</th><th>Lowest</th><th>Consistency</th><th>Trend</th></tr></thead>
            <tbody>
              <tr v-for="(s, i) in subjectAnalytics.students" :key="s.student_id">
                <td class="rank">#{{ i + 1 }}</td>
                <td>
                  {{ s.full_name }}
                  <span v-if="subjectAnalytics.winner?.student_id === s.student_id" class="badge-winner">Winner</span>
                  <span v-if="subjectAnalytics.most_consistent?.student_id === s.student_id" class="badge-consistent">Consistent</span>
                </td>
                <td class="num">{{ s.average }}</td>
                <td class="num">{{ s.highest }}</td>
                <td class="num">{{ s.lowest }}</td>
                <td class="num">{{ s.stddev !== null ? '±' + s.stddev : '—' }}</td>
                <td :class="trendClass(s.trend)">{{ trendLabel[s.trend] }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <template v-if="isClassTeacherHere && classAnalytics && classAnalytics.students.length">
        <div class="page-title" style="margin-top:32px;">Whole-Class Overview</div>
        <p class="page-sub">You're the class teacher for {{ selected.class_name }} — this combines every subject the class takes.</p>

        <div class="section">
          <div class="highlight-grid" style="padding-top:20px;">
            <div class="highlight-card">
              <div class="tag">🏆 Overall Winner</div>
              <div class="name">{{ classAnalytics.winner?.full_name ?? '—' }}</div>
              <div class="detail">{{ classAnalytics.winner ? `Overall average: ${classAnalytics.winner.average} across ${classAnalytics.winner.subjects_count} subject(s)` : 'No data yet' }}</div>
            </div>
            <div class="highlight-card">
              <div class="tag">🎯 Most Consistent</div>
              <div class="name">{{ classAnalytics.most_consistent?.full_name ?? '—' }}</div>
              <div class="detail">{{ classAnalytics.most_consistent ? '±' + classAnalytics.most_consistent.stddev : 'Needs 2+ assessments' }}</div>
            </div>
            <div class="highlight-card">
              <div class="tag">Gender Comparison</div>
              <div class="name">Boys {{ classAnalytics.gender.male_avg ?? '—' }} · Girls {{ classAnalytics.gender.female_avg ?? '—' }}</div>
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section-header">Subjects Ranked (Class Average)</div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Subject</th><th>Class Average</th></tr></thead>
              <tbody>
                <tr v-for="(sub, i) in classAnalytics.subjects_ranked" :key="sub.subject_name">
                  <td class="rank">#{{ i + 1 }}</td><td>{{ sub.subject_name }}</td><td class="num">{{ sub.average }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>

    <p v-else class="empty">No submitted marks yet for {{ termLabel }} — analytics will appear here once marks are submitted.</p>
  </template>
</template>

<style scoped>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 4px;}
.page-sub{color:var(--muted);font-size:0.85rem;margin:0 0 18px;}
.breadcrumb{font-size:0.8rem;color:var(--muted);margin-bottom:18px;}
.breadcrumb a{color:var(--cyan);text-decoration:none;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:10px;}
.pick-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;}
.pick-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.pick-card h3{margin:0 0 6px;font-size:1rem;}
.pick-card .sub{color:var(--muted);font-size:0.78rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:22px;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.95rem;font-weight:700;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;padding:20px;}
.stat-card{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px 16px;}
.stat-card .n{font-size:1.5rem;font-weight:800;}
.stat-card .l{font-size:0.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.highlight-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:0 20px 20px;}
.highlight-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:18px;}
.highlight-card .tag{font-size:0.68rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--cyan);font-weight:700;margin-bottom:8px;}
.highlight-card .name{font-size:1.05rem;font-weight:700;}
.highlight-card .detail{font-size:0.78rem;color:var(--muted);margin-top:4px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:320px;}
.ranking-table{min-width:640px;}
th,td{text-align:left;padding:11px 20px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;letter-spacing:0.5px;background:var(--panel-raised);}
tbody tr:last-child td{border-bottom:none;}
.rank{color:var(--muted);font-weight:700;}
.trend-up{color:var(--green);}
.trend-down{color:var(--danger);}
.trend-flat{color:var(--muted);}
.badge-winner{background:rgba(16,185,129,0.15);color:var(--green);font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;text-transform:uppercase;}
.badge-consistent{background:rgba(0,168,168,0.15);color:var(--cyan);font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;text-transform:uppercase;}
.table-wrap{overflow-x:auto;}

.island{border:1.5px dashed var(--cyan);border-radius:10px;background:rgba(0,168,168,0.045);padding:18px;margin-bottom:20px;}
.island-charts{display:grid;grid-template-columns:1.6fr 1fr;gap:18px;}
@media (max-width:640px){ .island-charts{grid-template-columns:1fr;} }
.chart-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px 16px;}
.chart-box h4{margin:0 0 10px;font-size:0.78rem;font-weight:700;color:var(--text);}
.chart-canvas-wrap{position:relative;height:220px;}
.chart-canvas-wrap.small{height:180px;}
</style>
