<script setup>
import { ref, onMounted } from 'vue'
import { payrollApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const busy = ref(false)
const message = ref('')
const error = ref('')

const staff = ref([])
const history = ref([])
const months = ref([])

const today = new Date()
const form = ref({
  staff_id: '',
  pay_period_month: String(today.getMonth() + 1),
  pay_period_year: String(today.getFullYear()),
  payment_date: today.toISOString().slice(0, 10),
  amount: '',
  notes: ''
})

async function load() {
  loading.value = true
  try {
    const { data } = await payrollApi.get()
    staff.value = data.staff
    history.value = data.history
    months.value = data.months
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function recordPayment() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await payrollApi.record(form.value)
    history.value = data.history
    message.value = data.message
    form.value.amount = ''
    form.value.notes = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not record that payment.'
  } finally {
    busy.value = false
  }
}

function fmt(n) {
  return 'UGX ' + Math.round(n).toLocaleString()
}
</script>

<template>
  <h1 class="page-title">Payroll</h1>

  <div v-if="message" class="pr-alert pr-alert-success">{{ message }}</div>
  <div v-if="error" class="pr-alert pr-alert-danger">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="pr-section">
      <form @submit.prevent="recordPayment">
        <label>Staff Member</label>
        <select v-model="form.staff_id" required>
          <option value="">-- Select --</option>
          <option v-for="s in staff" :key="s.staff_id" :value="String(s.staff_id)">{{ s.first_name }} {{ s.last_name }}</option>
        </select>
        <div class="pr-row">
          <div>
            <label>Month</label>
            <select v-model="form.pay_period_month" required>
              <option v-for="m in 12" :key="m" :value="String(m)">{{ months[m] }}</option>
            </select>
          </div>
          <div>
            <label>Year</label>
            <input type="number" v-model="form.pay_period_year" required>
          </div>
          <div>
            <label>Payment Date</label>
            <input type="date" v-model="form.payment_date" required>
          </div>
        </div>
        <label>Amount (UGX)</label>
        <input type="number" v-model="form.amount" min="0" step="0.01" required>
        <label>Notes (optional)</label>
        <input type="text" v-model="form.notes" placeholder="e.g. Net salary after advance deduction">
        <button type="submit" :disabled="busy">Record Payment</button>
      </form>
    </div>

    <div class="pr-section">
      <table>
        <tr><th>Staff</th><th>Period</th><th>Amount</th><th>Paid</th><th></th></tr>
        <tr v-if="!history.length"><td colspan="5" class="pr-empty">No payments recorded yet.</td></tr>
        <tr v-for="h in history" :key="h.id">
          <td>{{ h.staff_name }}</td>
          <td>{{ months[h.pay_period_month] }} {{ h.pay_period_year }}</td>
          <td>{{ fmt(h.amount) }}</td>
          <td>{{ new Date(h.payment_date).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' }) }}</td>
          <td><a class="pr-print-link" :href="`${SB}hr/payroll.php?print=${h.id}`" target="_blank">Print Payslip</a></td>
        </tr>
      </table>
    </div>
  </template>
</template>

<style scoped>
.page-title{margin:0 0 24px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.pr-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.pr-alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.pr-alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.pr-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.pr-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.pr-section label:first-child{margin-top:0;}
.pr-section input,.pr-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.pr-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
@media(max-width:600px){.pr-row{grid-template-columns:1fr;}}
.pr-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.pr-section button:disabled{opacity:0.6;cursor:default;}
.pr-section .table-wrap,.pr-section{overflow-x:auto;}
.pr-section table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:520px;}
.pr-section th,.pr-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.pr-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pr-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
a.pr-print-link{color:var(--cyan);text-decoration:none;font-size:0.78rem;}
</style>
