<script setup>
import { ref, onMounted } from 'vue'
import { parentFeedbackApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const messages = ref([])
const openId = ref(null)
const replyDrafts = ref({})

async function load() {
  loading.value = true
  try {
    const { data } = await parentFeedbackApi.get()
    messages.value = data.messages
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load parent messages.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function open(m) {
  openId.value = openId.value === m.id ? null : m.id
  if (m.status === 'new') {
    try {
      const { data } = await parentFeedbackApi.action({ action: 'mark_read', id: m.id })
      messages.value = data.messages
    } catch (e) {
      // Non-critical -- the message still opens even if the read-marker didn't save.
    }
  }
}

async function sendResponse(m) {
  const response = (replyDrafts.value[m.id] || '').trim()
  if (!response) return
  busy.value = true
  error.value = null
  try {
    const { data } = await parentFeedbackApi.action({ action: 'respond', id: m.id, response })
    messages.value = data.messages
    delete replyDrafts.value[m.id]
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send response.'
  } finally {
    busy.value = false
  }
}

function statusLabel(status) {
  return { new: 'New', read: 'Read', responded: 'Responded' }[status] || status
}
</script>

<template>
  <h1 class="page-title">Parent Messages</h1>
  <p class="sub">Feedback and questions sent in from the parent portal, per student.</p>

  <div v-if="error" class="alert error">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="!messages.length" class="empty">No parent messages yet.</p>

  <div v-else class="list">
    <div v-for="m in messages" :key="m.id" class="row" :class="{ new: m.status === 'new' }">
      <div class="row-head" @click="open(m)">
        <span class="pill" :class="m.status">{{ statusLabel(m.status) }}</span>
        <div class="row-head-text">
          <div class="subject">{{ m.subject }}</div>
          <div class="meta">{{ m.parent_username }} · {{ m.student_name || 'Unknown student' }} · {{ m.created_at }}</div>
        </div>
        <i class="bi" :class="openId === m.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
      </div>

      <div v-if="openId === m.id" class="row-body">
        <p class="message-text">{{ m.message }}</p>

        <div v-if="m.response" class="response-block">
          <div class="response-label">Your response · {{ m.responded_at }}</div>
          <p class="response-text">{{ m.response }}</p>
        </div>
        <div v-else class="reply-form">
          <textarea v-model="replyDrafts[m.id]" rows="3" placeholder="Write a response…"></textarea>
          <button type="button" :disabled="busy || !(replyDrafts[m.id] || '').trim()" @click="sendResponse(m)">Send Response</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-title{font-size:1.4rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 24px;}
.empty{color:var(--muted);font-size:0.85rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.list{display:flex;flex-direction:column;gap:10px;}
.row{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.row.new{border-color:rgba(0,168,168,0.4);}
.row-head{display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer;}
.row-head-text{flex:1;min-width:0;}
.subject{font-weight:700;font-size:0.9rem;}
.meta{color:var(--muted);font-size:0.75rem;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pill{flex-shrink:0;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;padding:4px 10px;border-radius:20px;}
.pill.new{background:rgba(0,168,168,0.15);color:var(--cyan);}
.pill.read{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.responded{background:rgba(16,185,129,0.15);color:var(--green);}
.row-body{padding:0 18px 18px;border-top:1px solid var(--border);}
.message-text{font-size:0.88rem;line-height:1.6;margin:16px 0;white-space:pre-wrap;}
.response-block{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;}
.response-label{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.4px;color:var(--muted);margin-bottom:6px;}
.response-text{font-size:0.85rem;line-height:1.5;margin:0;white-space:pre-wrap;}
.reply-form textarea{width:100%;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;font-size:0.85rem;box-sizing:border-box;resize:vertical;}
.reply-form button{margin-top:10px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-size:0.82rem;}
.reply-form button:disabled{opacity:0.5;cursor:default;}
</style>
