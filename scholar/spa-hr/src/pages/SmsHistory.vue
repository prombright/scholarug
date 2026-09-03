<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { smsHistoryApi } from '../services/api'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const campaigns = ref([])
const openCampaign = ref(null)
const recipients = ref([])

async function load() {
  loading.value = true
  try {
    const { data } = await smsHistoryApi.get(route.query.campaign_id)
    campaigns.value = data.campaigns
    openCampaign.value = data.open_campaign
    recipients.value = data.recipients
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => route.query.campaign_id, load)

function openCampaignView(c) {
  router.push({ query: { campaign_id: c.id } })
}
function backToAll() {
  router.push({ query: {} })
}
function fmtDate(dt) {
  return new Date(dt.replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function statusPillClass(status) {
  return status === 'sent' ? 'delivered' : (status === 'failed' ? 'failed' : 'pending')
}
</script>

<template>
  <h1 class="page-title">Bulk SMS</h1>
  <div class="sms-tabs">
    <router-link to="/sms/wallet">Wallet</router-link>
    <router-link to="/sms/contacts">Contacts</router-link>
    <router-link to="/sms/send">Send</router-link>
    <router-link to="/sms/history" class="active">History</router-link>
    <router-link to="/sms/whatsapp">WhatsApp</router-link>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div v-if="openCampaign" class="sms-section">
      <a href="#" class="sms-link" @click.prevent="backToAll">&larr; Back to all campaigns</a>
      <h3 style="margin:16px 0 4px;">Campaign #{{ openCampaign.id }}</h3>
      <p style="color:var(--muted);font-size:0.85rem;white-space:pre-wrap;">{{ openCampaign.message }}</p>
      <div class="sms-note">SMS delivery status below is simulated -- no live SMS gateway is connected yet. WhatsApp status reflects the real Meta API response for schools that have connected a number.</div>
      <table>
        <tr><th>Phone</th><th>Name</th><th>Channel</th><th>Status</th></tr>
        <tr v-for="(r, i) in recipients" :key="i">
          <td>{{ r.phone }}</td>
          <td>{{ r.full_name || '—' }}</td>
          <td><span class="sms-pill" :class="r.channel === 'whatsapp' ? 'delivered' : 'pending'">{{ r.channel === 'whatsapp' ? 'WhatsApp' : 'SMS' }}</span></td>
          <td><span class="sms-pill" :class="r.delivery_status">{{ r.delivery_status.charAt(0).toUpperCase() + r.delivery_status.slice(1) }}{{ r.channel === 'whatsapp' ? '' : ' (simulated)' }}</span></td>
        </tr>
      </table>
    </div>

    <div v-else class="sms-section">
      <div class="sms-note">SMS delivery statuses shown here are simulated for now -- no live SMS gateway is connected yet. Recipients reached via a connected WhatsApp number get a real delivery status.</div>
      <div v-if="!campaigns.length" class="sms-empty">No campaigns sent yet.</div>
      <table v-else>
        <tr><th>Date</th><th>Message</th><th>Recipients</th><th>Cost</th><th>Status</th><th></th></tr>
        <tr v-for="c in campaigns" :key="c.id">
          <td>{{ fmtDate(c.created_at) }}</td>
          <td class="ellipsis">{{ c.message }}</td>
          <td>{{ c.recipient_count }}</td>
          <td>UGX {{ Number(c.total_cost).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
          <td><span class="sms-pill" :class="statusPillClass(c.status)">{{ c.status.charAt(0).toUpperCase() + c.status.slice(1) }}</span></td>
          <td><a href="#" class="sms-link" @click.prevent="openCampaignView(c)">View</a></td>
        </tr>
      </table>
    </div>
  </template>
</template>

<style>
.page-title{margin:0 0 16px;font-size:1.4rem;}
.empty{color:var(--muted);font-size:0.85rem;}
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.sms-section .table-wrap,.sms-section{overflow-x:auto;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:560px;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.sms-pill.delivered{background:rgba(16,185,129,0.12);color:var(--green);}
.sms-pill.failed{background:rgba(239,68,68,0.12);color:var(--danger);}
.sms-pill.pending{background:rgba(148,163,184,0.15);color:var(--muted);}
.sms-note{color:var(--amber);font-size:0.78rem;margin-bottom:12px;}
a.sms-link{color:var(--cyan);text-decoration:none;font-size:0.85rem;}
.ellipsis{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
</style>
