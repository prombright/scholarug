<script setup>
import { ref, onMounted } from 'vue'
import { smsContactsApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref('')
const error = ref('')

const groups = ref([])
const availableClasses = ref([])
const selectedClassId = ref('')
const importGroupName = ref('')

async function load() {
  loading.value = true
  try {
    const { data } = await smsContactsApi.get()
    groups.value = data.groups
    availableClasses.value = data.available_classes
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function addClassGroup() {
  if (!selectedClassId.value) return
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await smsContactsApi.addClassGroup(selectedClassId.value)
    groups.value = data.groups
    availableClasses.value = data.available_classes
    message.value = data.message
    selectedClassId.value = ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not add that class group.'
  } finally {
    busy.value = false
  }
}

async function deleteGroup(g) {
  if (!confirm('Delete this group?')) return
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const { data } = await smsContactsApi.deleteGroup(g.id)
    groups.value = data.groups
    availableClasses.value = data.available_classes
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
    <router-link to="/sms/contacts" class="active">Contacts</router-link>
    <router-link to="/sms/send">Send</router-link>
    <router-link to="/sms/history">History</router-link>
    <router-link to="/sms/whatsapp">WhatsApp</router-link>
  </div>

  <div v-if="message" class="sms-alert success">{{ message }}</div>
  <div v-if="error" class="sms-alert error">{{ error }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="sms-two-col">
      <div class="sms-section">
        <h3>Add a Class as a Group</h3>
        <p class="sub">Always reflects current registered students' parent/guardian numbers -- no re-import needed when a phone changes.</p>
        <div v-if="!availableClasses.length" class="sms-empty">Every class already has a group.</div>
        <form v-else @submit.prevent="addClassGroup">
          <label>Class</label>
          <select v-model="selectedClassId" required>
            <option v-for="c in availableClasses" :key="c.id" :value="String(c.id)">{{ c.class_name }}{{ c.stream_name ? ' - ' + c.stream_name : '' }}</option>
          </select>
          <button type="submit" :disabled="busy">Add Class Group</button>
        </form>
      </div>

      <div class="sms-section">
        <h3>Import Contacts</h3>
        <p class="sub">CSV or .xlsx, phone number in column A, name (optional) in column B.</p>
        <form method="POST" enctype="multipart/form-data" :action="smsContactsApi.importUrl()">
          <label>Group Name (optional)</label>
          <input type="text" name="group_name" v-model="importGroupName" placeholder="e.g. Alumni 2025">
          <label>File</label>
          <input type="file" name="import_file" accept=".csv,.xlsx" required>
          <button type="submit">Import</button>
        </form>
      </div>
    </div>

    <div class="sms-section">
      <h3>Your Groups</h3>
      <div v-if="!groups.length" class="sms-empty">No groups yet -- add a class or import a file above.</div>
      <table v-else>
        <tr><th>Name</th><th>Type</th><th>Recipients</th><th></th></tr>
        <tr v-for="g in groups" :key="g.id">
          <td>{{ g.name }}</td>
          <td><span class="sms-pill">{{ g.group_type === 'class' ? 'Class (live)' : 'Imported' }}</span></td>
          <td>{{ g.recipient_count }}</td>
          <td><button type="button" class="danger" :disabled="busy" @click="deleteGroup(g)">Delete</button></td>
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
.sms-section h3{margin:0 0 6px;font-size:1rem;}
.sub{color:var(--muted);font-size:0.8rem;margin:0 0 10px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section input,.sms-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button:disabled{opacity:0.6;cursor:default;}
.sms-section button.danger{background:transparent;border:1px solid rgba(239,68,68,0.4);color:var(--danger);padding:6px 12px;margin-top:0;font-size:0.75rem;}
.sms-section .table-wrap,.sms-section{overflow-x:auto;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;min-width:420px;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;background:rgba(0,168,168,0.1);color:var(--cyan);}
.sms-two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:700px){.sms-two-col{grid-template-columns:1fr;}}
</style>
