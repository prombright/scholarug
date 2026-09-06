<script setup>
import { ref, onMounted } from 'vue'
import { dashboardApi, scholarBase } from '../services/api'

const SB = scholarBase()

const loading = ref(true)
const error = ref(null)
const staffCount = ref(0)
const pendingLeave = ref(0)
const smsBalance = ref(0)
const teachingCount = ref(0)
const nonTeachingCount = ref(0)
const staffCategoryTotal = ref(0)
const teachingPct = ref(0)

onMounted(async () => {
  try {
    const { data } = await dashboardApi.get()
    staffCount.value = data.staff_count
    pendingLeave.value = data.pending_leave
    smsBalance.value = data.sms_balance
    teachingCount.value = data.teaching_count
    nonTeachingCount.value = data.non_teaching_count
    staffCategoryTotal.value = data.staff_category_total
    teachingPct.value = data.teaching_pct
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the HR dashboard.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="top-row">
    <h1>Human Resources</h1>
    <a class="btn-link" :href="`${SB}leave_requests.php`">My Leave</a>
  </div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <div class="hr-grid">
      <div class="hr-card"><div class="hr-card-icon"><i class="bi bi-people"></i></div><div><div class="n">{{ staffCount }}</div><div class="label">Active Staff</div></div></div>
      <div class="hr-card leave"><div class="hr-card-icon"><i class="bi bi-calendar2-week"></i></div><div><div class="n amber">{{ pendingLeave }}</div><div class="label">Pending Leave Requests</div></div></div>
      <div class="hr-card wallet"><div class="hr-card-icon"><i class="bi bi-wallet2"></i></div><div><div class="n">UGX {{ Number(smsBalance).toLocaleString() }}</div><div class="label">Bulk SMS Wallet</div></div></div>
    </div>

    <div v-if="staffCategoryTotal > 0" class="hr-chart-card">
      <h2>Staff Composition</h2>
      <div class="hr-donut" :style="{ background: `conic-gradient(var(--cyan) 0% ${teachingPct}%, var(--purple) ${teachingPct}% 100%)` }">
        <div class="hr-donut-center"><div class="n">{{ staffCategoryTotal }}</div><div class="label">Staff</div></div>
      </div>
      <div class="hr-donut-legend">
        <span><span class="dot" style="background:var(--cyan);"></span>Teaching {{ teachingCount }}</span>
        <span><span class="dot" style="background:var(--purple);"></span>Non-Teaching {{ nonTeachingCount }}</span>
      </div>
    </div>

    <div class="hr-links">
      <a class="hr-cta" :href="`${SB}staff_manager.php`">Staff</a>
      <router-link class="hr-cta ghost" to="/leave">Leave Requests</router-link>
      <router-link class="hr-cta ghost" to="/payroll">Payroll</router-link>
      <router-link class="hr-cta ghost" to="/sms/wallet">Bulk SMS</router-link>
    </div>
  </template>
</template>

<style scoped>
.top-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.top-row h1{margin:0;font-size:1.4rem;}
.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;}
.hr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
.hr-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;align-items:center;gap:14px;}
.hr-card-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;background:rgba(0,168,168,.15);color:var(--cyan);}
.hr-card.leave .hr-card-icon{background:rgba(245,158,11,.15);color:var(--amber);}
.hr-card.wallet .hr-card-icon{background:rgba(16,185,129,.15);color:var(--green);}
.hr-card .n{font-size:1.7rem;font-weight:700;color:var(--text);}
.hr-card .n.amber{color:var(--amber);}
.hr-card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.hr-links{display:flex;gap:12px;flex-wrap:wrap;}
.hr-cta{display:inline-block;background:var(--cyan);color:#04222a;font-weight:700;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:0.85rem;}
.hr-cta.ghost{background:transparent;border:1px solid var(--border);color:var(--text);}
.hr-chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;display:flex;flex-direction:column;align-items:center;}
.hr-chart-card h2{font-size:0.9rem;margin:0 0 18px;align-self:flex-start;}
.hr-donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;}
.hr-donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.hr-donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.hr-donut-center .n{font-size:1.4rem;font-weight:700;}
.hr-donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.hr-donut-legend{display:flex;gap:18px;font-size:0.8rem;color:var(--muted);}
.hr-donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
</style>
