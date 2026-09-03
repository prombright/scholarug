<script setup>
import { ref, onMounted } from 'vue'
import { smsSendApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const error = ref('')

const currency = ref('UGX')
const balance = ref(0)
const groups = ref([])

const groupSelection = ref('')
const messageText = ref('')
const preview = ref(null)
const sentResult = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await smsSendApi.get()
    currency.value = data.currency
    balance.value = data.balance
    groups.value = data.groups
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function submitPreview() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await smsSendApi.preview({ group_selection: groupSelection.value, message: messageText.value })
    preview.value = data.preview
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not preview this send.'
  } finally {
    busy.value = false
  }
}

async function confirmSend() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await smsSendApi.confirm({ group_selection: preview.value.group_selection, message: preview.value.message })
    sentResult.value = data.result
    balance.value = data.balance
    preview.value = null
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send this campaign.'
  } finally {
    busy.value = false
  }
}

function cancelPreview() {
  preview.value = null
}
function startOver() {
  sentResult.value = null
  groupSelection.value = ''
  messageText.value = ''
}
function fmt(n) {
  return currency.value + ' ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <h1 class="page-title">Bulk SMS</h1>
  <div class="sms-tabs">
    <router-link to="/sms/wallet">Wallet</router-link>
    <router-link to="/sms/contacts">Contacts</router-link>
    <router-link to="/sms/send" class="active">Send</router-link>
    <router-link to="/sms/history">History</router-link>
    <router-link to="/sms/whatsapp">WhatsApp</router-link>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="sms-balance-note">Wallet balance: <strong>{{ fmt(balance) }}</strong> — <router-link to="/sms/wallet">top up</router-link></div>

    <div v-if="error" class="sms-alert error">{{ error }}</div>

    <div v-if="sentResult" class="sms-section">
      <div class="sms-alert success">
        Sent to {{ sentResult.delivered }} recipient(s){{ sentResult.failed > 0 ? `, ${sentResult.failed} failed` : '' }}.
        Debited {{ sentResult.currency }} {{ Number(sentResult.total_cost).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}.
      </div>
      <div v-if="sentResult.simulated" class="sms-alert warn">Delivery is currently simulated — no real SMS was sent to any carrier yet. This will start sending for real once a live provider is connected.</div>
      <router-link to="/sms/history" style="color:var(--cyan);">View in History &rarr;</router-link>
      <button type="button" class="ghost" style="display:block;margin-top:12px;" @click="startOver">Send Another</button>
    </div>

    <div v-else-if="preview" class="sms-section">
      <h3>Confirm Send</h3>
      <div class="sms-preview-row"><span class="label">To</span><span>{{ preview.group_label }}</span></div>
      <div class="sms-preview-row"><span class="label">Recipients</span><span>{{ preview.recipient_count }}</span></div>
      <div class="sms-preview-row"><span class="label">Segments per message</span><span>{{ preview.segments }}</span></div>
      <div class="sms-preview-row"><span class="label">Estimated Cost</span><span>{{ fmt(preview.cost) }}</span></div>
      <div class="preview-message">{{ preview.message }}</div>

      <div v-if="!preview.can_afford" class="sms-alert warn" style="margin-top:16px;">Not enough wallet balance for this send. <router-link to="/sms/wallet" style="color:inherit;text-decoration:underline;">Top up first</router-link>.</div>
      <template v-else>
        <button type="button" :disabled="busy" @click="confirmSend">Confirm &amp; Send</button>
        <button type="button" class="ghost" @click="cancelPreview">Cancel</button>
      </template>
    </div>

    <div v-else class="sms-section">
      <form @submit.prevent="submitPreview">
        <label>Send To</label>
        <select v-model="groupSelection" required>
          <option value="">-- Select --</option>
          <option value="whole_school">Whole School</option>
          <option v-for="g in groups" :key="g.id" :value="String(g.id)">{{ g.name }} ({{ g.group_type === 'class' ? 'Class' : 'Imported' }})</option>
        </select>
        <label>Message</label>
        <textarea v-model="messageText" rows="5" required placeholder="Type your message..."></textarea>
        <button type="submit" :disabled="busy">Preview &amp; Estimate Cost</button>
      </form>
    </div>
  </template>
</template>

<style>
.page-title{margin:0 0 16px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;max-width:600px;}
.sms-section h3{margin:0 0 16px;font-size:1rem;}
.sms-balance-note{color:var(--muted);font-size:0.8rem;margin-bottom:16px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger);}
.sms-alert.warn{background:rgba(245,158,11,0.12);color:var(--amber);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section select,.sms-section textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;font-family:inherit;}
.sms-section textarea{resize:vertical;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button:disabled{opacity:0.6;cursor:default;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--text);margin-left:8px;}
.sms-preview-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:0.88rem;}
.sms-preview-row .label{color:var(--muted);}
.preview-message{margin-top:16px;padding:12px;background:var(--panel-raised);border-radius:6px;font-size:0.85rem;white-space:pre-wrap;}
</style>
