<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { messagesApi } from '../services/api'

function chatInitials(name) {
  const n = (name || '').trim()
  if (!n) return '?'
  const parts = n.split(/\s+/)
  let initials = parts[0][0].toUpperCase()
  if (parts.length > 1) initials += parts[parts.length - 1][0].toUpperCase()
  return initials
}

function relativeTime(datetime) {
  if (!datetime) return ''
  const ts = new Date(datetime.replace(' ', 'T')).getTime()
  const diff = (Date.now() - ts) / 1000
  if (diff < 60) return 'now'
  if (diff < 3600) return Math.floor(diff / 60) + 'm'
  if (diff < 86400) return Math.floor(diff / 3600) + 'h'
  if (diff < 604800) return Math.floor(diff / 86400) + 'd'
  return new Date(ts).toLocaleDateString([], { day: '2-digit', month: 'short' })
}

const loading = ref(true)
const error = ref(null)
const teachers = ref([])

const openTeacherId = ref(null)
const openTeacherName = ref('')
const openMessages = ref([])
const chatInput = ref(null)
const chatBody = ref('')
const chatContainer = ref(null)

async function loadTeachers() {
  try {
    const { data } = await messagesApi.get({})
    teachers.value = data.teachers
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your messages.'
  } finally {
    loading.value = false
  }
}

function scrollToBottom() {
  if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight
}

async function openThread(teacherId) {
  error.value = null
  try {
    const { data } = await messagesApi.get({ teacher_id: teacherId })
    openTeacherId.value = teacherId
    openTeacherName.value = data.open.teacher_name
    openMessages.value = data.open.messages
    await nextTick()
    scrollToBottom()
    await nextTick()
    chatInput.value?.focus()
  } catch (e) {
    error.value = e.response?.data?.message || "Could not open that teacher's conversation."
  }
}

async function sendReply() {
  const body = chatBody.value.trim()
  if (!body || !openTeacherId.value) return
  chatBody.value = ''
  try {
    const { data } = await messagesApi.send({ teacher_id: openTeacherId.value, body })
    openMessages.value.push(data.message)
    await nextTick()
    scrollToBottom()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send that message.'
  }
}

let threadsPoll = null
let openPoll = null

onMounted(() => {
  loadTeachers()
  threadsPoll = setInterval(async () => {
    if (document.hidden) return
    try {
      const { data } = await messagesApi.get({ poll_threads: 1 })
      teachers.value = data.threads
    } catch (e) { /* silent -- next poll tries again */ }
  }, 6000)
})

watch(openTeacherId, (id) => {
  clearInterval(openPoll)
  if (!id) return
  openPoll = setInterval(async () => {
    if (document.hidden) return
    try {
      const afterId = openMessages.value.length ? openMessages.value[openMessages.value.length - 1].id : 0
      const { data } = await messagesApi.get({ teacher_id: id, poll: 1, after_id: afterId })
      if (data.messages.length) {
        openMessages.value.push(...data.messages)
        await nextTick()
        scrollToBottom()
      }
    } catch (e) { /* silent */ }
  }, 4000)
})

onUnmounted(() => { clearInterval(threadsPoll); clearInterval(openPoll) })
</script>

<template>
  <div class="page-title">Messages</div>
  <div v-if="error" class="alert">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>

  <div v-else class="chat-layout">
    <div class="chat-sidebar">
      <div v-if="!teachers.length" class="empty">No teachers assigned to your class yet.</div>
      <a v-for="t in teachers" :key="t.teacher_id" href="#" class="thread-row" :class="{ active: openTeacherId === t.teacher_id }" @click.prevent="openThread(t.teacher_id)">
        <span class="avatar">{{ chatInitials(t.teacher_name) }}</span>
        <span class="thread-row-body">
          <span class="thread-row-top">
            <span class="thread-row-name">{{ t.teacher_name }}</span>
            <span v-if="t.last_at" class="thread-row-time">{{ relativeTime(t.last_at) }}</span>
          </span>
        </span>
        <span v-if="t.unread > 0" class="unread">{{ t.unread }}</span>
      </a>
    </div>

    <div class="chat-main">
      <div v-if="openTeacherId === null" class="chat-placeholder">
        <i class="bi bi-chat-dots"></i>
        <p>Pick a teacher on the left to start or continue a conversation.</p>
      </div>
      <template v-else>
        <div class="chat-header">
          <span class="avatar">{{ chatInitials(openTeacherName) }}</span>
          <span class="chat-header-name">{{ openTeacherName }}</span>
        </div>
        <div class="chat-messages" ref="chatContainer">
          <div v-if="!openMessages.length" class="empty">No messages yet — say hello to {{ openTeacherName }}.</div>
          <div v-for="m in openMessages" :key="m.id" class="msg-row" :class="m.sender_role === 'student' ? 'out' : 'in'">
            <div class="msg">
              {{ m.body }}
              <span class="meta">{{ new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
            </div>
          </div>
        </div>
        <form class="chat-composer" @submit.prevent="sendReply">
          <textarea ref="chatInput" v-model="chatBody" rows="1" required placeholder="Type a message..."
            @keydown.enter.exact.prevent="sendReply"></textarea>
          <button type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
        </form>
      </template>
    </div>
  </div>
</template>

<style scoped>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;}
.avatar{flex-shrink:0;width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),#0a7d7d);color:#04222a;font-weight:700;font-size:0.85rem;display:flex;align-items:center;justify-content:center;}
.chat-layout{display:grid;grid-template-columns:280px 1fr;gap:0;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;height:min(640px,75vh);}
@media(max-width:700px){.chat-layout{grid-template-columns:1fr;height:auto;}}
.chat-sidebar{border-right:1px solid var(--border);overflow-y:auto;}
.thread-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);color:var(--text);text-decoration:none;}
.thread-row:last-child{border-bottom:none;}
.thread-row.active,.thread-row:hover{background:rgba(0,168,168,0.08);}
.thread-row-body{flex:1;min-width:0;}
.thread-row-top{display:flex;align-items:baseline;justify-content:space-between;gap:8px;}
.thread-row-name{font-size:0.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.thread-row-time{font-size:0.7rem;color:var(--muted);flex-shrink:0;}
.thread-row .unread{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;background:var(--cyan);color:#04222a;font-size:0.7rem;font-weight:700;border-radius:20px;}
.chat-main{display:flex;flex-direction:column;min-height:0;}
.chat-placeholder{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:10px;}
.chat-placeholder i{font-size:2.5rem;opacity:0.4;}
.chat-placeholder p{font-size:0.85rem;margin:0;}
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
