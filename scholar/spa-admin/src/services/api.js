import axios from 'axios'

// Same pattern as scholar/spa/ and scholar/spa-student/'s api.js.
const api = axios.create({
  baseURL: window.__SCHOLAR_API_BASE__ || '/ScholarUg/scholar/api/',
  withCredentials: true,
  // app_admin.php injects window.__CSRF_TOKEN__ before this bundle loads,
  // same way it injects __SCHOLAR_API_BASE__ -- checked server-side by
  // auth_guard.php's require_csrf_json() on every mutating request.
  headers: { Accept: 'application/json', 'X-CSRF-Token': window.__CSRF_TOKEN__ || '' }
})

// Raw calls into the existing hr/sms/topup_*.php endpoints, which predate
// the HR SPA (now merged into this one -- see AdminLayout.vue's nav) and
// already speak JSON directly (not under api/'s envelope convention) --
// used unchanged by the wallet top-up flow so real-money payment code
// isn't touched by the merge.
const raw = axios.create({
  baseURL: window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/',
  withCredentials: true,
  headers: { Accept: 'application/json', 'X-CSRF-Token': window.__CSRF_TOKEN__ || '' }
})

function isAuthRedirect(response) {
  const contentType = response?.headers?.['content-type'] || ''
  return !contentType.includes('application/json')
}

const loginUrl = () => (window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/') + 'login.php'

raw.interceptors.response.use(
  (response) => {
    if (isAuthRedirect(response)) {
      window.location.href = loginUrl()
      return new Promise(() => {})
    }
    return response
  },
  (error) => {
    if (isAuthRedirect(error.response)) {
      window.location.href = loginUrl()
      return new Promise(() => {})
    }
    return Promise.reject(error)
  }
)

api.interceptors.response.use(
  (response) => {
    if (isAuthRedirect(response)) {
      window.location.href = loginUrl()
      return new Promise(() => {})
    }
    return response
  },
  (error) => {
    if (isAuthRedirect(error.response)) {
      window.location.href = loginUrl()
      return new Promise(() => {})
    }
    return Promise.reject(error)
  }
)

export const scholarBase = () => window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'

export const dashboardApi = { get: () => api.get('admin/dashboard.php') }
export const notificationsApi = {
  get: () => api.get('admin/notifications.php'),
  action: (payload) => api.post('admin/notifications.php', payload)
}
export const searchApi = { get: (q) => api.get('admin/search.php', { params: { q } }) }
export const brandApi = { get: () => api.get('admin/brand.php') }
export const classesApi = {
  get: () => api.get('admin/classes.php'),
  action: (payload) => api.post('admin/classes.php', payload)
}
export const studentsApi = {
  get: () => api.get('admin/students.php'),
  action: (payload) => api.post('admin/students.php', payload)
}
export const attendanceApi = {
  get: (classId, date) => api.get('admin/attendance.php', { params: { class_id: classId || '', date } }),
  save: (payload) => api.post('admin/attendance.php', payload)
}
export const subjectsApi = {
  get: (levelType) => api.get('admin/subjects.php', { params: { level_type: levelType } }),
  action: (payload) => api.post('admin/subjects.php', payload)
}
export const assessmentsApi = {
  get: () => api.get('admin/assessments.php'),
  action: (payload) => api.post('admin/assessments.php', payload)
}
export const teacherSubmissionsApi = {
  get: (assessmentId, classId) => api.get('admin/teacher_submissions.php', { params: { assessment_id: assessmentId || '', class_id: classId || '' } })
}
export const assignTeacherApi = {
  get: (staffId) => api.get('admin/assign_teacher.php', { params: { staff_id: staffId || '' } }),
  action: (payload) => api.post('admin/assign_teacher.php', payload)
}
export const libraryOverviewApi = { get: () => api.get('admin/library.php') }
export const manageParentsApi = {
  get: () => api.get('admin/manage_parents.php'),
  create: (payload) => api.post('admin/manage_parents.php', { action: 'create', ...payload }),
  updateLinks: (payload) => api.post('admin/manage_parents.php', { action: 'update_links', ...payload })
}
export const parentFeedbackApi = {
  get: () => api.get('admin/parent_feedback.php'),
  action: (payload) => api.post('admin/parent_feedback.php', payload)
}
export const timetableSetupApi = {
  get: () => api.get('admin/timetable_setup.php'),
  action: (payload) => api.post('admin/timetable_setup.php', payload)
}
export const gradingScalesApi = {
  get: () => api.get('admin/grading_scales.php'),
  action: (payload) => api.post('admin/grading_scales.php', payload)
}
export const settingsApi = {
  get: () => api.get('admin/settings.php'),
  action: (payload) => api.post('admin/settings.php', payload)
}
export const contactDeveloperApi = {
  get: (params) => api.get('admin/contact_developer.php', { params }),
  send: (body) => api.post('admin/contact_developer.php', { body })
}
export const electionsApi = {
  get: () => api.get('admin/elections.php'),
  action: (payload) => api.post('admin/elections.php', payload)
}
export const feesApi = {
  get: (q, classId, term, year) => api.get('admin/fees.php', { params: { q: q || '', class_id: classId || '', term: term || '', year: year || '' } }),
  action: (payload) => api.post('admin/fees.php', payload)
}
export const studentProfileApi = {
  get: (id) => api.get('admin/student_profile.php', { params: { id } })
}
export const studentAttendanceHistoryApi = {
  get: (id) => api.get('admin/student_attendance_history.php', { params: { id } })
}
export const reportSettingsApi = {
  get: () => api.get('admin/report_settings.php'),
  save: (payload) => api.post('admin/report_settings.php', payload)
}
export const studentSubjectsApi = {
  get: (studentId) => api.get('admin/student_subjects.php', { params: { student_id: studentId } }),
  save: (payload) => api.post('admin/student_subjects.php', payload)
}
export const subjectEnrollmentApi = {
  get: (classId, subjectId) => api.get('admin/subject_enrollment.php', { params: { class_id: classId || '', subject_id: subjectId || '' } }),
  save: (payload) => api.post('admin/subject_enrollment.php', payload)
}
export const timetableGenerateApi = {
  get: () => api.get('admin/timetable_generate.php'),
  generate: () => api.post('admin/timetable_generate.php', { generate: true })
}
export const timetableViewApi = {
  get: (classId) => api.get('admin/timetable_view.php', { params: { class_id: classId || '' } }),
  saveCell: (payload) => api.post('admin/timetable_view.php', payload)
}
export const electionsPositionsApi = {
  get: (electionId) => api.get('admin/elections_positions.php', { params: { election_id: electionId } }),
  action: (payload) => api.post('admin/elections_positions.php', payload)
}
export const electionsCandidatesApi = {
  get: (electionId) => api.get('admin/elections_candidates.php', { params: { election_id: electionId } }),
  review: (payload) => api.post('admin/elections_candidates.php', payload)
}
export const electionsResultsApi = {
  get: (electionId) => api.get('admin/elections_results.php', { params: { election_id: electionId } })
}
export const projectsApi = {
  get: (classId) => api.get('admin/projects.php', { params: { class_id: classId || '' } }),
  action: (payload) => api.post('admin/projects.php', payload)
}

// HR section (formerly the separate spa-hr app, now routed inside this
// one -- see AdminLayout.vue's nav). Same api/hr/*.php backend, untouched.
export const hrDashboardApi = { get: () => api.get('hr/dashboard.php') }
export const leaveApi = {
  get: () => api.get('hr/leave.php'),
  review: (payload) => api.post('hr/leave.php', payload)
}
export const payrollApi = {
  get: () => api.get('hr/payroll.php'),
  record: (payload) => api.post('hr/payroll.php', payload)
}
export const smsWalletApi = {
  get: () => api.get('hr/sms_wallet.php'),
  topupInitiate: (formData) => raw.post('hr/sms/topup_initiate.php', formData),
  topupStatus: (reference) => raw.get('hr/sms/topup_status.php', { params: { reference } })
}
export const smsContactsApi = {
  get: () => api.get('hr/sms_contacts.php'),
  addClassGroup: (classId) => api.post('hr/sms_contacts.php', { action: 'add_class_group', class_id: classId }),
  deleteGroup: (groupId) => api.post('hr/sms_contacts.php', { action: 'delete_group', group_id: groupId }),
  importUrl: () => scholarBase() + 'hr/sms/contacts.php'
}
export const smsSendApi = {
  get: () => api.get('hr/sms_send.php'),
  preview: (payload) => api.post('hr/sms_send.php', { action: 'preview', ...payload }),
  confirm: (payload) => api.post('hr/sms_send.php', { action: 'confirm_send', ...payload })
}
export const smsHistoryApi = {
  get: (campaignId) => api.get('hr/sms_history.php', { params: { campaign_id: campaignId || '' } })
}
export const smsWhatsappApi = {
  get: () => api.get('hr/sms_whatsapp.php'),
  save: (payload) => api.post('hr/sms_whatsapp.php', { action: 'save', ...payload }),
  disable: () => api.post('hr/sms_whatsapp.php', { action: 'disable' })
}

export default api
