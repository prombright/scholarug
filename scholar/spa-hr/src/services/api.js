import axios from 'axios'

// Same pattern as scholar/spa/, scholar/spa-student/, and scholar/spa-admin/'s api.js.
const api = axios.create({
  baseURL: window.__SCHOLAR_API_BASE__ || '/ScholarUg/scholar/api/',
  withCredentials: true,
  headers: { Accept: 'application/json' }
})

// Raw calls into the existing hr/sms/topup_*.php endpoints, which predate
// this SPA and already speak JSON directly (not under api/hr/'s envelope
// convention) -- used unchanged by the wallet top-up flow so real-money
// payment code isn't touched by this conversion.
const raw = axios.create({
  baseURL: window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/',
  withCredentials: true,
  headers: { Accept: 'application/json' }
})

function isAuthRedirect(response) {
  const contentType = response?.headers?.['content-type'] || ''
  return !contentType.includes('application/json')
}

const loginUrl = () => (window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/') + 'login.php'

function installAuthRedirect(instance) {
  instance.interceptors.response.use(
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
}
installAuthRedirect(api)
installAuthRedirect(raw)

export const scholarBase = () => window.__SCHOLAR_BASE__ || '/ScholarUg/scholar/'

export const dashboardApi = { get: () => api.get('hr/dashboard.php') }
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
