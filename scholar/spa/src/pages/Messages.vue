<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
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
const classes = ref([])
const threads = ref([])
const roster = ref([])
const selectedClassId = ref('')
const mode = ref('individual')
const selectedStudentId = ref('')
const composeBody = ref('')
const composeError = ref('')
const sentNotice = ref('')

const openStudentId = ref(null)
const openStudentName = ref('')
const openMessages = ref([])
const chatInput = ref(null)
const chatBody = ref('')
const chatContainer = ref(null)

const selectedClass = computed(() => classes.value.find((c) => c.id === selectedClassId.value) || null)

async function loadBase() {
  try {
    const { data } = await messagesApi.get({})
    classes.value = data.classes
    threads.value = data.threads
    if (classes.value.length === 1) selectedClassId.value = classes.value[0].id
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your messages.'
  } finally {
    loading.value = false
  }
}

watch(selectedClassId, async (id) => {
  roster.value = []
  if (!id) return
  const { data } = await messagesApi.get({ class_id: id })
  roster.value = data.roster || []
})

async function send() {
  composeError.value = ''
  sentNotice.value = ''
  const body = composeBody.value.trim()
  if (!body) return
  try {
    if (mode.value === 'broadcast') {
      const { data } = await messagesApi.send({ action: 'send_broadcast', class_id: selectedClassId.value, body })
      sentNotice.value = `Message sent to ${data.count} student(s).`
      composeBody.value = ''
      await refreshThreads()
    } else {
      if (!selectedStudentId.value) { composeError.value = 'Pick a student.'; return }
      await messagesApi.send({ action: 'send_individual', class_id: selectedClassId.value, student_id: selectedStudentId.value, body })
      composeBody.value = ''
      await refreshThreads()
      await openThread(selectedStudentId.value)
    }
  } catch (e) {
    composeError.value = e.response?.data?.message || 'Could not send message.'
  }
}

async function refreshThreads() {
  const { data } = await messagesApi.get({})
  threads.value = data.threads
}

async function openThread(studentId) {
  error.value = null
  try {
    const { data } = await messagesApi.get({ student_id: studentId })
    openStudentId.value = studentId
    openStudentName.value = data.open.student_name
    openMessages.value = data.open.messages
    await nextTick()
    scrollToBottom()
    await nextTick()
    chatInput.value?.focus()
  } catch (e) {
    error.value = e.response?.data?.message || "Could not open that student's conversation."
  }
}

function scrollToBottom() {
  if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight
}

async function sendReply() {
  const body = chatBody.value.trim()
  if (!body || !openStudentId.value) return
  chatBody.value = ''
  try {
    const { data } = await messagesApi.send({ action: 'send_message', student_id: openStudentId.value, body })
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
  loadBase()
  threadsPoll = setInterval(async () => {
    if (document.hidden) return
    try {
      const { data } = await messagesApi.get({ poll_threads: 1 })
      // Merge in the freshest unread/last_at without losing conversation ids.
      threads.value = threads.value.map((t) => {
        const fresh = data.threads.find((f) => f.student_id === t.student_id)
        return fresh ? { ...t, unread: fresh.unread, last_at: fresh.last_at } : t
      })
      // A brand-new incoming thread (student messaged first) won't be in
      // threads.value yet -- pick those up with a full reload.
      if (data.threads.some((f) => !threads.value.some((t) => t.student_id === f.student_id))) {
        const full = await messagesApi.get({})
        threads.value = full.data.threads
      }
    } catch (e) { /* silent -- next poll tries again */ }
  }, 6000)
})

watch(openStudentId, (id) => {
  clearInterval(openPoll)
  if (!id) return
  openPoll = setInterval(async () => {
    if (document.hidden) return
    try {
      const afterId = openMessages.value.length ? openMessages.value[openMessages.value.length - 1].id : 0
      const { data } = await messagesApi.get({ student_id: id, poll: 1, after_id: afterId })
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
  <div v-if="composeError" class="alert">{{ composeError }}</div>
  <div v-if="sentNotice" class="alert-success">{{ sentNotice }}</div>

  <div class="section new-message">
    <p v-if="loading" class="empty">Loading…</p>
    <template v-else>
      <div v-if="!classes.length" class="empty">You're not assigned to teach any class yet.</div>
      <template v-else>
        <div v-if="classes.length > 1" class="class-tabs">
          <button v-for="c in classes" :key="c.id" type="button" :class="{ active: selectedClassId === c.id }" @click="selectedClassId = c.id">
            {{ c.class_name }}{{ c.stream_name ? ' - ' + c.stream_name : '' }}
          </button>
        </div>

        <div v-if="!selectedClass" class="empty">Pick a class above to message its students.</div>
        <form v-else @submit.prevent="send">
          <div class="recipient-toggle">
            <button type="button" :class="{ active: mode === 'individual' }" @click="mode = 'individual'">Individual Student</button>
            <button type="button" :class="{ active: mode === 'broadcast' }" @click="mode = 'broadcast'">All Students In This Class</button>
          </div>
          <select v-if="mode === 'individual'" v-model="selectedStudentId">
            <option value="">-- Select Student --</option>
            <option v-for="r in roster" :key="r.id" :value="r.id">{{ r.full_name }}</option>
          </select>
          <div v-else class="hint">
            This sends the same message to all {{ roster.length }} student(s) in {{ selectedClass.class_name }}<span v-if="!selectedClass.is_class_teacher"> who take a subject you teach them</span>, as an individual message in each of their inboxes.
          </div>
          <div v-if="!roster.length" class="hint">No reachable students in this class yet.</div>
          <textarea v-model="composeBody" rows="3" required placeholder="Type a new message..."></textarea>
          <button type="submit" class="send-btn">Send</button>
        </form>
      </template>
    </template>
  </div>

  <div class="chat-layout">
    <div class="chat-sidebar">
      <div v-if="!threads.length" class="empty">No messages from students yet.</div>
      <a v-for="th in threads" :key="th.student_id" href="#" class="thread-row" :class="{ active: openStudentId === th.student_id }" @click.prevent="openThread(th.student_id)">
        <span class="avatar">{{ chatInitials(th.student_name) }}</span>
        <span class="thread-row-body">
          <span class="thread-row-top">
            <span class="thread-row-name">{{ th.student_name }}</span>
            <span v-if="th.last_at" class="thread-row-time">{{ relativeTime(th.last_at) }}</span>
          </span>
        </span>
        <span v-if="th.unread > 0" class="unread">{{ th.unread }}</span>
      </a>
    </div>

    <div class="chat-main">
      <div v-if="openStudentId === null" class="chat-placeholder">
        <i class="bi bi-chat-dots"></i>
        <p>Pick a student on the left to view the conversation.</p>
      </div>
      <template v-else>
        <div class="chat-header">
          <span class="avatar">{{ chatInitials(openStudentName) }}</span>
          <span class="chat-header-name">{{ openStudentName }}</span>
        </div>
        <div class="chat-messages" ref="chatContainer">
          <div v-if="!openMessages.length" class="empty">No messages yet.</div>
          <div v-for="m in openMessages" :key="m.id" class="msg-row" :class="m.sender_role === 'teacher' ? 'out' : 'in'">
            <div class="msg">
              {{ m.body }}
              <span class="meta">{{ new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
            </div>
          </div>
        </div>
        <form class="chat-composer" @submit.prevent="sendReply">
          <textarea ref="chatInput" v-model="chatBody" rows="1" required :placeholder="`Reply to ${openStudentName}...`"
            @keydown.enter.exact.prevent="sendReply"></textarea>
          <button type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
        </form>
      </template>
    </div>
  </div>
</template>

<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.alert-success{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(16,185,129,0.12);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;}
.new-message{padding:16px;margin-bottom:20px;}
.new-message .recipient-toggle{display:flex;gap:8px;margin-bottom:12px;}
.new-message .recipient-toggle button{background:transparent;border:1px solid var(--border);color:var(--muted);padding:7px 14px;border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer;}
.new-message .recipient-toggle button.active{background:var(--cyan);border-color:var(--cyan);color:#04121a;}
.new-message select,.new-message textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:9px 10px;font-family:inherit;font-size:0.85rem;box-sizing:border-box;margin-bottom:10px;}
.new-message textarea{resize:vertical;}
.new-message .send-btn{background:var(--cyan);color:#04121a;border:none;border-radius:6px;padding:9px 18px;font-weight:700;font-size:0.85rem;cursor:pointer;}
.new-message .hint{color:var(--muted);font-size:0.78rem;margin-bottom:10px;}
.class-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.class-tabs button{padding:8px 16px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:0.82rem;font-weight:600;cursor:pointer;}
.class-tabs button.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}

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
