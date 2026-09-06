import { createRouter, createWebHashHistory } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import Classes from '../pages/Classes.vue'
import Students from '../pages/Students.vue'
import Attendance from '../pages/Attendance.vue'
import Fees from '../pages/Fees.vue'
import Subjects from '../pages/Subjects.vue'
import Assessments from '../pages/Assessments.vue'
import TeacherSubmissions from '../pages/TeacherSubmissions.vue'
import Assignments from '../pages/Assignments.vue'
import LibraryOverview from '../pages/LibraryOverview.vue'
import ManageParents from '../pages/ManageParents.vue'
import TimetableSetup from '../pages/TimetableSetup.vue'
import GradingScales from '../pages/GradingScales.vue'
import Settings from '../pages/Settings.vue'
import ContactDeveloper from '../pages/ContactDeveloper.vue'
import Elections from '../pages/Elections.vue'
import StudentProfile from '../pages/StudentProfile.vue'
import AttendanceHistory from '../pages/AttendanceHistory.vue'
import ReportSettings from '../pages/ReportSettings.vue'
import StudentSubjects from '../pages/StudentSubjects.vue'
import SubjectEnrollment from '../pages/SubjectEnrollment.vue'
import TimetableGenerate from '../pages/TimetableGenerate.vue'
import TimetableView from '../pages/TimetableView.vue'
import ElectionPositions from '../pages/ElectionPositions.vue'
import ElectionCandidates from '../pages/ElectionCandidates.vue'
import ElectionResults from '../pages/ElectionResults.vue'
import Projects from '../pages/Projects.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/',
      component: AdminLayout,
      children: [
        { path: '', name: 'dashboard', component: Dashboard },
        { path: 'classes', name: 'classes', component: Classes },
        { path: 'students', name: 'students', component: Students },
        { path: 'attendance', name: 'attendance', component: Attendance },
        { path: 'fees', name: 'fees', component: Fees },
        { path: 'subjects', name: 'subjects', component: Subjects },
        { path: 'assessments', name: 'assessments', component: Assessments },
        { path: 'teacher-submissions', name: 'teacher-submissions', component: TeacherSubmissions },
        { path: 'assignments', name: 'assignments', component: Assignments },
        { path: 'library', name: 'library', component: LibraryOverview },
        { path: 'parents', name: 'parents', component: ManageParents },
        { path: 'timetable', name: 'timetable', component: TimetableSetup },
        { path: 'grading', name: 'grading', component: GradingScales },
        { path: 'settings', name: 'settings', component: Settings },
        { path: 'contact-developer', name: 'contact-developer', component: ContactDeveloper },
        { path: 'elections', name: 'elections', component: Elections },
        { path: 'students/:id', name: 'student-profile', component: StudentProfile },
        { path: 'attendance-history/:id', name: 'attendance-history', component: AttendanceHistory },
        { path: 'report-settings', name: 'report-settings', component: ReportSettings },
        { path: 'student-subjects/:id', name: 'student-subjects', component: StudentSubjects },
        { path: 'subject-enrollment', name: 'subject-enrollment', component: SubjectEnrollment },
        { path: 'timetable-generate', name: 'timetable-generate', component: TimetableGenerate },
        { path: 'timetable-view', name: 'timetable-view', component: TimetableView },
        { path: 'elections/:id/positions', name: 'election-positions', component: ElectionPositions },
        { path: 'elections/:id/candidates', name: 'election-candidates', component: ElectionCandidates },
        { path: 'elections/:id/results', name: 'election-results', component: ElectionResults },
        { path: 'projects', name: 'projects', component: Projects }
      ]
    }
  ]
})

// dos/headteacher/bursar are scoped to one page each (see app_admin.php's
// require_role and AdminLayout.vue's nav filtering) -- Dashboard.vue calls
// the school_admin-only dashboard.php endpoint, so send these roles
// straight to their one page instead of a 403 on the landing route.
const ROLE_HOME = { dos: 'assessments', headteacher: 'library', bursar: 'fees' }
router.beforeEach((to) => {
  const home = ROLE_HOME[window.__SCHOLAR_ROLE__]
  if (home && to.name === 'dashboard') {
    return { name: home }
  }
})

export default router
