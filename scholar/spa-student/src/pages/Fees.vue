<script setup>
import { ref, onMounted } from 'vue'
import { feesApi } from '../services/api'

const loading = ref(true)
const error = ref(null)
const expected = ref(0)
const paid = ref(0)
const balance = ref(0)

onMounted(async () => {
  try {
    const { data } = await feesApi.get()
    expected.value = data.expected
    paid.value = data.paid
    balance.value = data.balance
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your fees.'
  } finally {
    loading.value = false
  }
})

const fmt = (n) => 'UGX ' + Math.round(n).toLocaleString()
</script>

<template>
  <div class="section-title">Fees</div>

  <p v-if="loading" class="empty">Loading…</p>
  <p v-else-if="error" class="empty">{{ error }}</p>

  <template v-else>
    <div class="grid">
      <div class="card">
        <div class="n" :class="balance > 0 ? 'danger' : 'green'">{{ fmt(Math.abs(balance)) }}</div>
        <div class="label">{{ balance > 0 ? 'Balance Due' : 'Fully Paid' }}</div>
      </div>
    </div>
    <table>
      <tbody>
        <tr><td>Expected (tuition + entry fee)</td><td>{{ fmt(expected) }}</td></tr>
        <tr><td>Paid to date</td><td>{{ fmt(paid) }}</td></tr>
        <tr><td><strong>{{ balance > 0 ? 'Balance due' : 'Overpaid / Credit' }}</strong></td><td><strong>{{ fmt(Math.abs(balance)) }}</strong></td></tr>
      </tbody>
    </table>
  </template>
</template>

<style>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.empty{color:var(--muted);font-size:0.9rem;padding:16px;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.card .n{font-size:1.6rem;font-weight:700;}
.card .n.green{color:var(--green);}
.card .n.danger{color:var(--danger);}
.card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:0.9rem;}
th,td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);}
tr:last-child td{border-bottom:none;}
</style>
