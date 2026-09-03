import { createRouter, createWebHashHistory } from 'vue-router'
import HrLayout from '../layouts/HrLayout.vue'
import Dashboard from '../pages/Dashboard.vue'
import Leave from '../pages/Leave.vue'
import Payroll from '../pages/Payroll.vue'
import SmsWallet from '../pages/SmsWallet.vue'
import SmsContacts from '../pages/SmsContacts.vue'
import SmsSend from '../pages/SmsSend.vue'
import SmsHistory from '../pages/SmsHistory.vue'
import SmsWhatsapp from '../pages/SmsWhatsapp.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/',
      component: HrLayout,
      children: [
        { path: '', name: 'dashboard', component: Dashboard },
        { path: 'leave', name: 'leave', component: Leave },
        { path: 'payroll', name: 'payroll', component: Payroll },
        { path: 'sms/wallet', name: 'sms-wallet', component: SmsWallet },
        { path: 'sms/contacts', name: 'sms-contacts', component: SmsContacts },
        { path: 'sms/send', name: 'sms-send', component: SmsSend },
        { path: 'sms/history', name: 'sms-history', component: SmsHistory },
        { path: 'sms/whatsapp', name: 'sms-whatsapp', component: SmsWhatsapp }
      ]
    }
  ]
})

export default router
