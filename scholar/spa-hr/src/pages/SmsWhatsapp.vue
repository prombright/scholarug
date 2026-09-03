<script setup>
import { ref, onMounted } from 'vue'
import { smsWhatsappApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref('')
const error = ref('')

const settings = ref(null)
const form = ref({ sender_name: '', whatsapp_number: '', phone_number_id: '', access_token: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await smsWhatsappApi.get()
    settings.value = data.settings
    if (settings.value) {
      form.value.sender_name = settings.value.sender_name
      form.value.whatsapp_number = settings.value.whatsapp_number
      form.value.phone_number_id = settings.value.phone_number_id
    }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await smsWhatsappApi.save(form.value)
    settings.value = data.settings
    message.value = data.message
    form.value.access_token = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not save WhatsApp settings.'
  } finally {
    busy.value = false
  }
}

async function disable() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await smsWhatsappApi.disable()
    settings.value = data.settings
    message.value = data.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <h1 class="page-title">Bulk SMS</h1>
  <div class="sms-tabs">
    <router-link to="/sms/wallet">Wallet</router-link>
    <router-link to="/sms/contacts">Contacts</router-link>
    <router-link to="/sms/send">Send</router-link>
    <router-link to="/sms/history">History</router-link>
    <router-link to="/sms/whatsapp" class="active">WhatsApp</router-link>
  </div>

  <div v-if="message" class="sms-alert success">{{ message }}</div>
  <div v-if="error" class="sms-alert error">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="sms-section">
      <h3><i class="bi bi-whatsapp"></i> Connect WhatsApp Business</h3>
      <p class="sub">Connect your school's own WhatsApp Business number (Meta Cloud API). Once connected, every Bulk SMS send tries WhatsApp first for each recipient and falls back to SMS automatically when it's not available.</p>

      <div v-if="settings" style="margin-bottom:6px;">
        <span class="sms-pill" :class="settings.status === 'active' ? 'ok' : 'muted'">{{ settings.status === 'active' ? 'Connected' : 'Disconnected' }}</span>
      </div>

      <form @submit.prevent="save">
        <label>Sender Name</label>
        <input type="text" v-model="form.sender_name" placeholder="e.g. Greenhill Academy" required>

        <label>WhatsApp Number</label>
        <input type="text" v-model="form.whatsapp_number" placeholder="+256772000000" required>

        <label>Phone Number ID</label>
        <input type="text" v-model="form.phone_number_id" placeholder="1029384756" required>
        <div class="hint">From your Meta WhatsApp Business API app dashboard.</div>

        <label>Access Token</label>
        <input type="password" v-model="form.access_token" :placeholder="settings ? 'Leave blank to keep the current token' : 'EAAG...'" autocomplete="off">
        <div class="hint">Stored encrypted. Only re-enter this if you're rotating the token.</div>

        <button type="submit" :disabled="busy">Save WhatsApp Settings</button>
      </form>

      <form v-if="settings && settings.status === 'active'" style="margin-top:0;" @submit.prevent="disable">
        <button type="submit" class="ghost" :disabled="busy">Disconnect WhatsApp</button>
      </form>
    </div>

    <div class="sms-alert info">
      Meta only allows free-form text outside approved templates within a 24-hour window after the recipient has messaged your WhatsApp number first. Outside that window, a recipient's message automatically falls back to SMS -- nothing is ever silently lost.
    </div>
  </template>
</template>

<style>
.page-title{margin:0 0 16px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;max-width:560px;}
.sms-section h3{margin:0 0 6px;font-size:1rem;}
.sub{color:var(--muted);font-size:0.8rem;margin:0 0 10px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;max-width:560px;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger);}
.sms-alert.info{background:rgba(0,168,168,0.1);color:var(--cyan);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.hint{color:var(--muted);font-size:0.75rem;margin-top:4px;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button:disabled{opacity:0.6;cursor:default;}
.sms-section button.ghost{background:transparent;border:1px solid var(--border);color:var(--muted);margin-top:10px;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.sms-pill.ok{background:rgba(16,185,129,0.12);color:var(--green);}
.sms-pill.muted{background:rgba(148,163,184,0.12);color:var(--muted);}
</style>
