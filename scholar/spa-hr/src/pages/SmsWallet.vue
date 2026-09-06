<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { smsWalletApi } from '../services/api'

const loading = ref(true)
const balance = ref(0)
const currency = ref('UGX')
const mtnConfigured = ref(true)
const airtelConfigured = ref(true)
const countryCodes = ref([])
const transactions = ref([])
const pendingTopups = ref([])

async function load() {
  loading.value = true
  try {
    const { data } = await smsWalletApi.get()
    balance.value = data.balance
    currency.value = data.currency
    mtnConfigured.value = data.mtn_configured
    airtelConfigured.value = data.airtel_configured
    countryCodes.value = data.country_codes
    transactions.value = data.transactions
    pendingTopups.value = data.pending_topups
  } finally {
    loading.value = false
  }
}
onMounted(load)

const selectedNetwork = ref('')
const showForm = ref(false)
const showWaiting = ref(false)
const waitingText = ref('Check your phone to approve the payment...')
const submitBusy = ref(false)
const alertKind = ref('')
const alertText = ref('')

const form = ref({ dial_code: '+256', phone: '', amount: '' })

function pickNetwork(network) {
  selectedNetwork.value = network
  showForm.value = true
  showWaiting.value = false
  alertText.value = ''
}
function cancelForm() {
  showForm.value = false
  selectedNetwork.value = ''
  alertText.value = ''
  form.value = { dial_code: '+256', phone: '', amount: '' }
}

let pollTimer = null
let pollDeadline = null
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}
onBeforeUnmount(stopPolling)

function pollStatus(reference, onDone) {
  stopPolling()
  pollDeadline = Date.now() + 2 * 60 * 1000
  pollTimer = setInterval(async () => {
    if (Date.now() > pollDeadline) { stopPolling(); onDone('timeout'); return }
    try {
      const { data } = await smsWalletApi.topupStatus(reference)
      if (data.status === 'successful') { stopPolling(); onDone('successful') }
      else if (data.status === 'failed') { stopPolling(); onDone('failed', data.error) }
    } catch (e) { /* keep polling */ }
  }, 3000)
}

async function checkStatus(p) {
  p._checking = true
  try {
    const { data } = await smsWalletApi.topupStatus(p.reference)
    if (data.status === 'successful' || data.status === 'failed') {
      await load()
    }
  } finally {
    p._checking = false
  }
}

async function submitTopup() {
  alertText.value = ''
  submitBusy.value = true
  const formData = new FormData()
  formData.append('network', selectedNetwork.value)
  formData.append('dial_code', form.value.dial_code)
  formData.append('phone', form.value.phone)
  formData.append('amount', form.value.amount)

  try {
    const { data } = await smsWalletApi.topupInitiate(formData)
    if (data.status === 'pending') {
      showForm.value = false
      showWaiting.value = true
      waitingText.value = data.message || 'Check your phone to approve the payment...'
      pollStatus(data.reference, (outcome, error) => {
        if (outcome === 'successful') {
          waitingText.value = 'Payment received! Updating your balance...'
          setTimeout(load, 1200)
          setTimeout(() => { showWaiting.value = false }, 1200)
        } else if (outcome === 'failed') {
          showWaiting.value = false
          showForm.value = true
          submitBusy.value = false
          alertKind.value = 'error'
          alertText.value = error || 'The payment failed or was declined.'
        } else if (outcome === 'timeout') {
          waitingText.value = "Still waiting for confirmation -- your wallet will be credited automatically once approved. You can check back later on this page."
        }
      })
    } else if (data.status === 'not-configured') {
      submitBusy.value = false
      alertKind.value = 'info'
      alertText.value = data.message
    } else {
      submitBusy.value = false
      alertKind.value = 'error'
      alertText.value = data.error || 'Something went wrong. Please try again.'
    }
  } catch (e) {
    submitBusy.value = false
    alertKind.value = 'error'
    alertText.value = 'Could not reach the server. Please check your connection and try again.'
  }
}

function fmt(n) {
  return currency.value + ' ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function isCredit(type) {
  return ['deposit', 'admin_credit', 'refund'].includes(type)
}
</script>

<template>
  <h1 class="page-title">Bulk SMS</h1>
  <div class="sms-tabs">
    <router-link to="/sms/wallet" class="active">Wallet</router-link>
    <router-link to="/sms/contacts">Contacts</router-link>
    <router-link to="/sms/send">Send</router-link>
    <router-link to="/sms/history">History</router-link>
    <router-link to="/sms/whatsapp">WhatsApp</router-link>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="sms-section">
      <div class="sms-balance">{{ fmt(balance) }}</div>
      <div class="sms-balance-label">Wallet Balance</div>
    </div>

    <div class="sms-section">
      <h3>Load Wallet</h3>
      <div v-if="alertText" class="sms-alert" :class="alertKind">{{ alertText }}</div>

      <div v-if="!showWaiting" class="sms-method-grid">
        <button type="button" class="sms-method-card" :class="{ active: selectedNetwork === 'mtn' }" @click="pickNetwork('mtn')">
          <span>MTN Mobile Money</span>
          <span v-if="!mtnConfigured" class="sms-badge">Demo mode</span>
        </button>
        <button type="button" class="sms-method-card" :class="{ active: selectedNetwork === 'airtel' }" @click="pickNetwork('airtel')">
          <span>Airtel Money</span>
          <span v-if="!airtelConfigured" class="sms-badge">Demo mode</span>
        </button>
      </div>

      <form v-if="showForm" @submit.prevent="submitTopup">
        <div style="display:flex;gap:10px;">
          <div style="flex:0 0 140px;">
            <label>Country</label>
            <select v-model="form.dial_code">
              <option v-for="c in countryCodes" :key="c.dial" :value="c.dial">{{ c.dial }} {{ c.name }}</option>
            </select>
          </div>
          <div style="flex:1;">
            <label>Phone Number</label>
            <input type="tel" v-model="form.phone" placeholder="7XX XXX XXX" required>
          </div>
        </div>
        <label>Amount ({{ currency }})</label>
        <input type="number" v-model="form.amount" min="500" max="5000000" step="1" placeholder="10000" required>
        <button type="submit" :disabled="submitBusy">Load Now</button>
        <button type="button" class="ghost" @click="cancelForm">Cancel</button>
      </form>

      <div v-if="showWaiting" class="waiting-box">
        <div class="sms-spinner"></div>
        <p style="margin-top:12px;color:var(--muted);">{{ waitingText }}</p>
      </div>
    </div>

    <div v-if="pendingTopups.length" class="sms-section">
      <h3>Pending Top-ups</h3>
      <table>
        <tr><th>Network</th><th>Amount</th><th>Phone</th><th>Submitted</th><th></th></tr>
        <tr v-for="p in pendingTopups" :key="p.reference">
          <td>{{ p.network.toUpperCase() }}</td>
          <td>{{ p.currency }} {{ Number(p.amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
          <td>{{ p.phone }}</td>
          <td>{{ new Date(p.created_at.replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</td>
          <td><button type="button" class="ghost sm" :disabled="p._checking" @click="checkStatus(p)">{{ p._checking ? '…' : 'Check Status' }}</button></td>
        </tr>
      </table>
    </div>

    <div class="sms-section">
      <h3>Transaction History</h3>
      <div v-if="!transactions.length" class="sms-empty">No activity yet.</div>
      <table v-else>
        <tr><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Date</th></tr>
        <tr v-for="(tx, i) in transactions" :key="i">
          <td>{{ tx.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) }}</td>
          <td :style="{ color: isCredit(tx.type) ? 'var(--green)' : 'var(--danger)', fontWeight: 700 }">{{ isCredit(tx.type) ? '+' : '-' }}{{ currency }} {{ Number(tx.amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
          <td>{{ currency }} {{ Number(tx.balance_after).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
          <td>{{ tx.reference || '—' }}</td>
          <td>{{ new Date(tx.created_at.replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</td>
        </tr>
      </table>
    </div>
  </template>
</template>

<style scoped>
.page-title{margin:0 0 16px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.sms-section h3{margin:0 0 16px;font-size:1rem;}
.sms-balance{font-size:2.2rem;font-weight:800;color:var(--cyan);}
.sms-balance-label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger);}
.sms-alert.info{background:rgba(245,158,11,0.12);color:var(--amber);}
.sms-method-grid{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.sms-method-card{display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 24px;border-radius:10px;border:1px solid var(--border);background:var(--panel);color:var(--text);cursor:pointer;min-width:120px;}
.sms-method-card.active{border-color:var(--cyan);background:rgba(0,168,168,0.08);}
.sms-badge{font-size:0.65rem;padding:2px 8px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section input,.sms-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button:disabled{opacity:0.6;cursor:default;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--text);margin-left:8px;}
.sms-section button.ghost.sm{margin-top:0;padding:6px 12px;font-size:0.75rem;margin-left:0;}
.sms-section .table-wrap,.sms-section{overflow-x:auto;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:520px;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.waiting-box{text-align:center;padding:20px 0;}
.sms-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--cyan);border-radius:50%;margin:0 auto;animation:sms-spin 0.8s linear infinite;}
@keyframes sms-spin{to{transform:rotate(360deg);}}
</style>
