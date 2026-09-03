<script setup>
import { ref, computed, onMounted } from 'vue'
import api, { scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const isAnyClassTeacher = ref(false)
const unreadMessageCount = ref(0)
const assignmentCount = ref(0)

onMounted(async () => {
  try {
    const { data } = await api.get('teacher/dashboard.php')
    isAnyClassTeacher.value = data.is_any_class_teacher
    unreadMessageCount.value = data.unread_message_count
    assignmentCount.value = data.assignment_count
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your dashboard.'
  } finally {
    loading.value = false
  }
})

// `route` cards are migrated pages inside this SPA (router-link, no
// reload); `href` cards still go to the classic PHP app. Mirrors the
// $cards array teachers_portal.php builds server-side.
const cards = computed(() => {
  const list = [
    { route: '/marks', icon: 'bi-pencil-square', color: '#00A8A8', title: 'Marks Entry', desc: 'Enter or import assessment marks for your classes.', stat: `${assignmentCount.value} assignment${assignmentCount.value === 1 ? '' : 's'}` },
    { route: '/analytics', icon: 'bi-bar-chart-line', color: '#00A8A8', title: 'Performance Analytics', desc: 'Class averages, gender comparison, top performer and the most consistent student.' },
    { route: '/timetable', icon: 'bi-calendar3', color: '#f59e0b', title: 'My Timetable', desc: 'Your weekly teaching schedule.' },
    { route: '/attendance', icon: 'bi-calendar-check', color: '#10b981', title: 'Roll Call', desc: 'Take attendance for any class in the school.' },
    { route: '/library', icon: 'bi-book', color: '#8b5cf6', title: 'Library', desc: 'Upload notes and past papers for your classes.' },
    { route: '/messages', icon: 'bi-chat-dots', color: '#3b82f6', title: 'Messages', desc: 'Talk directly with your students.', stat: unreadMessageCount.value > 0 ? `${unreadMessageCount.value} unread` : null, danger: unreadMessageCount.value > 0 },
    { href: SB + 'leave_requests.php', icon: 'bi-calendar-minus', color: '#14b8a6', title: 'My Leave', desc: 'Apply for leave and track your requests.' },
    { href: SB + 'projects/teacher_project.php', icon: 'bi-kanban', color: '#f97316', title: 'Project Work', desc: 'Log stage progress for a class project you monitor.' }
  ]
  if (isAnyClassTeacher.value) {
    list.push(
      { href: SB + 'school_admin/bulk_report_print.php', icon: 'bi-printer', color: '#ec4899', title: 'Print Class Reports', desc: 'Bulk-print report cards for your class.' },
      { href: SB + 'school_admin/remarks.php', icon: 'bi-chat-square-text', color: '#8b5cf6', title: 'Class Remarks', desc: "Write each student's report card remark." },
      { href: SB + 'generic_skills_entry.php', icon: 'bi-star', color: '#eab308', title: 'Generic Skills', desc: "Rate your class's generic skills." },
      { href: SB + 'teacher_class_logins.php', icon: 'bi-key', color: '#64748b', title: "Students' Logins", desc: 'Generate/reset logins for your class.' }
    )
  }
  return list
})

// Plain rgba(), not CSS color-mix() -- some budget Android browsers still
// in use at schools don't support color-mix() yet (same reasoning as the
// classic page's hex_to_tint()).
function tint(hex, alpha = 0.16) {
  const h = hex.replace('#', '')
  const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}
</script>

<template>
  <div class="page-title">Teacher Portal</div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <div v-else class="card-grid">
    <component
      v-for="c in cards" :key="c.title"
      :is="c.route ? 'router-link' : 'a'"
      :to="c.route" :href="c.href"
      class="feature-card"
      :style="{ '--icon-color': c.color, '--icon-tint': tint(c.color) }"
    >
      <i class="bi bi-arrow-right arrow"></i>
      <div class="icon"><i class="bi" :class="c.icon"></i></div>
      <h3>{{ c.title }}</h3>
      <p>{{ c.desc }}</p>
      <span v-if="c.stat" class="stat" :class="{ danger: c.danger }">{{ c.stat }}</span>
    </component>
  </div>
</template>

<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;}
.feature-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;text-decoration:none;color:var(--text);transition:transform .15s,border-color .15s,box-shadow .15s;position:relative;overflow:hidden;}
.feature-card:hover{transform:translateY(-4px);border-color:var(--icon-color, var(--cyan));box-shadow:0 12px 30px rgba(0,0,0,.3);}
.feature-card .icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:16px;background:var(--icon-tint);color:var(--icon-color);}
.feature-card h3{margin:0 0 6px;font-size:1.05rem;}
.feature-card p{margin:0;color:var(--muted);font-size:0.82rem;line-height:1.5;}
.feature-card .stat{display:inline-block;margin-top:14px;font-size:0.75rem;font-weight:700;padding:4px 10px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.feature-card .stat.danger{background:rgba(239,68,68,0.14);color:var(--danger);}
.feature-card .arrow{position:absolute;top:22px;right:22px;color:var(--muted);font-size:1.1rem;opacity:0;transition:opacity .15s,transform .15s;}
.feature-card:hover .arrow{opacity:1;transform:translateX(3px);}
</style>
