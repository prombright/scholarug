import axios from 'axios'

// Same idea as eSpace's api.ts: one shared axios instance, everything routes
// through it. Simpler here because auth is the existing PHP session cookie
// (auth_guard.php) -- withCredentials is the only piece that matters, there's
// no separate token/CSRF-header scheme to reimplement.
const api = axios.create({
  // app_teacher.php injects the real path (built from PHP's SCHOLAR_BASE)
  // before this bundle loads -- can't rely on plain relative resolution
  // here because the page also carries a <base href> for its JS/CSS,
  // which would otherwise hijack this too. Falls back to the XAMPP dev
  // path for `npm run dev` against vite.config.js's proxy.
  baseURL: window.__SCHOLAR_API_BASE__ || '/ScholarUg/scholar/api/',
  withCredentials: true,
  headers: { Accept: 'application/json' }
})

// auth_guard.php redirects an expired/missing session to login.php as HTML.
// The browser follows that redirect transparently, so axios sees an
// ordinary 200 -- never an error -- just with an HTML body instead of the
// JSON this app expects. A non-JSON content-type is the actual signal.
function isAuthRedirect(response) {
  const contentType = response?.headers?.['content-type'] || ''
  return !contentType.includes('application/json')
}

// Absolute (from window.__SCHOLAR_BASE__), not relative -- the page also
// carries a <base href> for its JS/CSS, under which a relative "../login.php"
// would resolve against the wrong directory (assets/spa-teacher/, not
// scholar/). Falls back to the XAMPP dev path for `npm run dev`.
const loginUrl = () => (window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/') + 'login.php'

api.interceptors.response.use(
  (response) => {
    if (isAuthRedirect(response)) {
      window.location.href = loginUrl()
      return new Promise(() => {}) // navigating away; never resolve
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

export const analyticsApi = {
  get: (classId, subjectId) =>
    api.get('teacher/analytics.php', {
      params: classId && subjectId ? { class_id: classId, subject_id: subjectId } : {}
    })
}

export const timetableApi = {
  get: () => api.get('teacher/timetable.php')
}

export const messagesApi = {
  get: (params) => api.get('teacher/messages.php', { params }),
  send: (payload) => api.post('teacher/messages.php', payload)
}

export const libraryApi = {
  get: () => api.get('teacher/library.php'),
  delete: (docId) => api.post('teacher/library.php', { action: 'delete', doc_id: docId }),
  toggleStatus: (docId) => api.post('teacher/library.php', { action: 'toggle_status', doc_id: docId })
}

export const attendanceApi = {
  get: (classId, date) => api.get('teacher/attendance.php', { params: { class_id: classId || '', attendance_date: date } }),
  save: (payload) => api.post('teacher/attendance.php', payload)
}

export const marksApi = {
  get: (assessmentId, classId, subjectId, paperNumber) =>
    api.get('teacher/marks.php', {
      params: assessmentId ? { assessment_id: assessmentId, class_id: classId, subject_id: subjectId, paper_number: paperNumber } : {}
    }),
  save: (payload) => api.post('teacher/marks.php', payload)
}

// CSV template download / import stay on the classic page (see
// api/teacher/marks.php's header comment) -- these build the same URLs
// teacher_marks_entry.php's own links use, so a click here still hits the
// real, unchanged file-transfer endpoints.
export const scholarBase = () => window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'

export default api
