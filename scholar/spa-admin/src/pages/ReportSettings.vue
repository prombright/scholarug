<script setup>
import { ref, onMounted } from 'vue'
import { reportSettingsApi, scholarBase } from '../services/api'

const SB = scholarBase()
const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const showPhotos = ref(false)
const allowDownload = ref(false)
const useNoDataColor = ref(false)
const noDataColor = ref('#f1f5f9')

async function load() {
  loading.value = true
  try {
    const { data } = await reportSettingsApi.get()
    showPhotos.value = !!data.settings.show_student_photos
    allowDownload.value = !!data.settings.allow_student_download
    useNoDataColor.value = !!data.settings.no_data_color
    noDataColor.value = data.settings.no_data_color || '#f1f5f9'
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load report settings.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save() {
  busy.value = true
  message.value = null
  try {
    await reportSettingsApi.save({
      show_student_photos: showPhotos.value,
      allow_student_download: allowDownload.value,
      use_no_data_color: useNoDataColor.value,
      no_data_color: noDataColor.value
    })
    message.value = { type: 'success', text: 'Report card settings saved.' }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not save settings.' }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <h1 class="page-title">Report Cards</h1>

  <div class="report-tabs">
    <router-link to="/grading">Grading &amp; Bands</router-link>
    <router-link to="/report-settings" class="active">Display Settings</router-link>
    <a :href="`${SB}school_admin/bulk_report_print.php`">Print Reports</a>
    <a :href="`${SB}school_admin/remarks.php`">Remarks</a>
  </div>

  <p class="sub">How report cards look and behave for this school.</p>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <form v-else class="section" @submit.prevent="save">
    <label class="toggle-row">
      <input type="checkbox" v-model="showPhotos">
      <div>
        Show student photos on report cards
        <div class="hint">Turn off if your students don't have photos uploaded yet — reports render cleanly either way.</div>
      </div>
    </label>

    <label class="toggle-row">
      <input type="checkbox" v-model="allowDownload">
      <div>
        Allow students to print/download their own report card
        <div class="hint">Off by default — students can view their report, but the Print button is hidden until you turn this on.</div>
      </div>
    </label>

    <label class="toggle-row" style="align-items:flex-start;">
      <input type="checkbox" v-model="useNoDataColor" style="margin-top:2px;">
      <div>
        Background color for subjects with no marks recorded
        <div class="hint">Applied to a subject row when a student has no score for it yet, so an incomplete report still looks intentional.</div>
        <input type="color" v-model="noDataColor" style="width:60px;height:38px;padding:2px;margin-top:8px;">
      </div>
    </label>

    <button type="submit" :disabled="busy">Save Settings</button>
  </form>
</template>

<style>
.page-title{font-size:1.4rem;margin:0 0 20px;}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 20px;max-width:600px;}
.empty{color:var(--muted);font-size:0.85rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;max-width:600px;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;max-width:600px;}
.toggle-row{display:flex;align-items:center;gap:12px;font-size:0.9rem;color:var(--text);padding:12px 0;border-bottom:1px solid var(--border);}
.toggle-row:last-of-type{border-bottom:none;}
.toggle-row input[type=checkbox]{width:auto;transform:scale(1.2);}
.hint{color:var(--muted);font-size:0.78rem;margin:2px 0 0;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button:disabled{opacity:0.6;cursor:default;}
</style>
