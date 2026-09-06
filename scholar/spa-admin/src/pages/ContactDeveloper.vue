<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { contactDeveloperApi } from '../services/api'

const loading = ref(true)
const messages = ref([])
const chatInput = ref(null)
const chatBody = ref('')
const chatContainer = ref(null)
let poll = null

function scrollToBottom() {
  if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight
}

async function load() {
  try {
    const { data } = await contactDeveloperApi.get()
    messages.value = data.messages
    await nextTick()
    scrollToBottom()
  } finally {
    loading.value = false
  }
}

async function send() {
  const body = chatBody.value.trim()
  if (!body) return
  chatBody.value = ''
  try {
    const { data } = await contactDeveloperApi.send(body)
    messages.value.push(data.message)
    await nextTick()
    scrollToBottom()
  } catch (e) { /* keep quiet, matches classic page's ajax fallback behavior */ }
}

onMounted(() => {
  load()
  poll = setInterval(async () => {
    if (document.hidden) return
    const afterId = messages.value.length ? messages.value[messages.value.length - 1].id : 0
    try {
      const { data } = await contactDeveloperApi.get({ poll: 1, after_id: afterId })
      if (data.messages.length) {
        messages.value.push(...data.messages)
        await nextTick()
        scrollToBottom()
      }
    } catch (e) { /* silent -- next poll tries again */ }
  }, 4000)
  nextTick(() => chatInput.value?.focus())
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="page-title">Contact Developer</div>

  <div class="chat-layout">
    <div class="chat-main">
      <div class="chat-header">
        <span class="avatar">SU</span>
        <span class="chat-header-name">ScholarUg Developer</span>
      </div>
      <div class="chat-messages" ref="chatContainer">
        <p v-if="loading" class="empty">Loading…</p>
        <div v-else-if="!messages.length" class="empty">No messages yet — describe your question or issue below and the developer will get back to you here.</div>
        <div v-for="m in messages" :key="m.id" class="msg-row" :class="m.sender_role === 'school_admin' ? 'out' : 'in'">
          <div class="msg">
            {{ m.body }}
            <span class="meta">{{ new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
          </div>
        </div>
      </div>
      <form class="chat-composer" @submit.prevent="send">
        <textarea ref="chatInput" v-model="chatBody" rows="1" required placeholder="Type a message..." @keydown.enter.exact.prevent="send"></textarea>
        <button type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;}
.avatar{flex-shrink:0;width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),#0a7d7d);color:#04222a;font-weight:700;font-size:0.85rem;display:flex;align-items:center;justify-content:center;}
.chat-layout{display:grid;grid-template-columns:1fr;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;height:min(640px,75vh);}
.chat-main{display:flex;flex-direction:column;min-height:0;}
.chat-header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);}
.chat-header-name{font-weight:600;font-size:0.92rem;}
.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:2px;background:radial-gradient(circle at 20% 20%, rgba(0,168,168,0.03) 0, transparent 40%),radial-gradient(circle at 80% 80%, rgba(0,168,168,0.03) 0, transparent 40%);}
.msg-row{display:flex;margin:4px 0;}
.msg-row.out{justify-content:flex-end;}
.msg-row.in{justify-content:flex-start;}
.msg{position:relative;max-width:72%;padding:8px 12px 18px;border-radius:12px;font-size:0.88rem;line-height:1.4;word-wrap:break-word;white-space:pre-wrap;}
.msg-row.out .msg{background:var(--cyan);color:#04222a;border-bottom-right-radius:3px;}
.msg-row.in .msg{background:#1c2536;color:var(--text);border-bottom-left-radius:3px;}
.msg .meta{position:absolute;right:10px;bottom:4px;font-size:0.65rem;opacity:0.65;white-space:nowrap;}
.chat-composer{display:flex;align-items:flex-end;gap:10px;padding:12px 14px;border-top:1px solid var(--border);}
.chat-composer textarea{flex:1;resize:none;max-height:120px;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:22px;padding:11px 18px;font-family:inherit;font-size:0.88rem;line-height:1.3;}
.chat-composer textarea:focus{outline:none;border-color:var(--cyan);}
.chat-composer button{flex-shrink:0;width:42px;height:42px;border-radius:50%;border:none;background:var(--cyan);color:#04121a;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.chat-composer button:hover{background:#0a7d7d;color:#fff;}
</style>
