import axios from 'axios'

// Same pattern as scholar/spa/ and scholar/spa-student/'s api.js.
const api = axios.create({
  baseURL: window.__SCHOLAR_API_BASE__ || '/ScholarUg/scholar/api/',
  withCredentials: true,
  headers: { Accept: 'application/json' }
})

function isAuthRedirect(response) {
  const contentType = response?.headers?.['content-type'] || ''
  return !contentType.includes('application/json')
}

const loginUrl = () => (window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/') + 'login.php'

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
  create: (payload) => api.post('admin/manage_parents.php', payload)
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
  get: (q, classId) => api.get('admin/fees.php', { params: { q: q || '', class_id: classId || '' } }),
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

export default api
