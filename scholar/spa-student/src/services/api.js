import axios from 'axios'

// Same pattern as scholar/spa/src/services/api.js (the teacher pilot) --
// session-cookie auth, no separate token scheme.
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

export const dashboardApi = { get: () => api.get('student/dashboard.php') }
export const resultsApi = { get: () => api.get('student/results.php') }
export const feesApi = { get: () => api.get('student/fees.php') }
export const attendanceApi = { get: () => api.get('student/attendance.php') }
export const libraryApi = { get: () => api.get('student/library.php') }
export const electionsApi = {
  get: () => api.get('student/elections.php'),
  vote: (votes) => api.post('student/elections.php', { votes })
}
export const messagesApi = {
  get: (params) => api.get('student/messages.php', { params }),
  send: (payload) => api.post('student/messages.php', payload)
}

export default api
