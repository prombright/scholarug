import { createRouter, createWebHashHistory } from 'vue-router'
import TeacherLayout from '../layouts/TeacherLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import Analytics from '../pages/Analytics.vue'
import MarksEntry from '../pages/MarksEntry.vue'
import Timetable from '../pages/Timetable.vue'
import RollCall from '../pages/RollCall.vue'
import Library from '../pages/Library.vue'
import Messages from '../pages/Messages.vue'

// Hash history, not createWebHistory(): this SPA is mounted at one fixed
// PHP entry point (app_teacher.php), not at a server-controlled base path
// that can rewrite unknown routes back to index.html. As more teacher pages
// migrate and get their own server routing rule, this can move to proper
// history mode -- not worth solving before the whole app needs it.
const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/',
      component: TeacherLayout,
      children: [
        { path: '', name: 'dashboard', component: Dashboard },
        { path: 'analytics', name: 'analytics', component: Analytics },
        { path: 'marks', name: 'marks', component: MarksEntry },
        { path: 'timetable', name: 'timetable', component: Timetable },
        { path: 'attendance', name: 'attendance', component: RollCall },
        { path: 'library', name: 'library', component: Library },
        { path: 'messages', name: 'messages', component: Messages }
      ]
    }
  ]
})

export default router
