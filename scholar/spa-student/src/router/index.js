import { createRouter, createWebHashHistory } from 'vue-router'
import StudentLayout from '../layouts/StudentLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import Results from '../pages/Results.vue'
import Fees from '../pages/Fees.vue'
import Attendance from '../pages/Attendance.vue'
import Library from '../pages/Library.vue'
import Elections from '../pages/Elections.vue'
import Messages from '../pages/Messages.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/',
      component: StudentLayout,
      children: [
        { path: '', name: 'dashboard', component: Dashboard },
        { path: 'results', name: 'results', component: Results },
        { path: 'fees', name: 'fees', component: Fees },
        { path: 'attendance', name: 'attendance', component: Attendance },
        { path: 'library', name: 'library', component: Library },
        { path: 'elections', name: 'elections', component: Elections },
        { path: 'messages', name: 'messages', component: Messages }
      ]
    }
  ]
})

export default router
