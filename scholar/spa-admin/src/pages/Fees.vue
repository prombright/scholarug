<script setup>
import { ref, watch, onMounted } from 'vue'
import { feesApi } from '../services/api'

const loading = ref(true)
const busy = ref(false)
const message = ref(null)

const feeStructures = ref([])
const ledger = ref([])
const metrics = ref({ fully_paid: 0, partial_paid: 0, unpaid: 0, total_collected: 0 })

const search = ref('')
const classFilter = ref('')

const showStructureModal = ref(false)
const showPaymentModal = ref(false)

const structureForm = ref({ class_id: '', day_tuition: '', boarding_tuition: '', entry_fee: '' })
const paymentForm = ref({ student_id: '', residence_type: 'Day', is_new_student: false, amount_paid: '', bursary_amount: '', payment_notes: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await feesApi.get(search.value, classFilter.value)
    feeStructures.value = data.fee_structures
    ledger.value = data.ledger
    metrics.value = data.metrics
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'Could not load fee accounts.' }
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch([search, classFilter], load)

async function runAction(payload) {
  busy.value = true
  message.value = null
  try {
    const { data } = await feesApi.action(payload)
    message.value = { type: 'success', text: data.message }
    await load()
    showStructureModal.value = false
    showPaymentModal.value = false
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || 'That action failed.' }
  } finally {
    busy.value = false
  }
}

function saveStructure() {
  runAction({ action: 'save_fee_structure', ...structureForm.value })
}
function recordPayment() {
  runAction({ action: 'record_payment', ...paymentForm.value })
}

const fmt = (n) => 'UGX ' + Math.round(n).toLocaleString()
</script>

<template>
  <div class="header-banner">
    <div>
      <h2><i class="bi bi-wallet2"></i> Bursar &amp; Financial Portal</h2>
      <p>ScholarUg &bull; School Fee Ledger &amp; Collections</p>
    </div>
    <div class="header-actions">
      <button type="button" class="btn-light" @click="showStructureModal = true"><i class="bi bi-gear-fill"></i> Fee Structures</button>
      <button type="button" class="btn-primary" @click="showPaymentModal = true"><i class="bi bi-plus-circle-fill"></i> Record Payment</button>
    </div>
  </div>

  <div v-if="message" class="alert" :class="message.type">{{ message.text }}</div>

  <p v-if="loading" class="empty">Loading…</p>
  <template v-else>
    <div class="stat-grid">
      <div class="stat-card border-green"><div class="label">Total Revenue Collected</div><div class="n green">{{ fmt(metrics.total_collected) }}</div></div>
      <div class="stat-card border-cyan"><div class="label">Fully Paid Students</div><div class="n">{{ metrics.fully_paid.toLocaleString() }}</div></div>
      <div class="stat-card border-amber"><div class="label">Partial Payments</div><div class="n">{{ metrics.partial_paid.toLocaleString() }}</div></div>
      <div class="stat-card border-danger"><div class="label">Unpaid / Balances Due</div><div class="n">{{ metrics.unpaid.toLocaleString() }}</div></div>
    </div>

    <div class="dev-banner">
      <i class="bi bi-tools"></i>
      <div>
        <strong>Advanced Audit Reports &amp; Export Center</strong> <span class="badge-soon">Under Development</span>
        <p>Automated PDF statements, bank reconciliations, and termly comparison reports will be unlocked in the upcoming release.</p>
      </div>
    </div>

    <div class="filter-bar">
      <input type="text" v-model="search" placeholder="Search student by name...">
      <select v-model="classFilter">
        <option value="">All Classes</option>
        <option v-for="fs in feeStructures" :key="fs.class_id" :value="String(fs.class_id)">{{ fs.class_name }}</option>
      </select>
    </div>

    <div class="section">
      <div class="section-header"><i class="bi bi-receipt"></i> Student Fee Accounts</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Student Name</th><th>Class</th><th>Type</th><th class="right">Base Fee</th><th class="right">Bursary</th><th class="right">Paid Amount</th><th class="right">Balance</th><th class="center">Status</th></tr>
          </thead>
          <tbody>
            <tr v-if="!ledger.length"><td colspan="8" class="empty-row">No fee accounts found matching parameters.</td></tr>
            <tr v-for="s in ledger" :key="s.student_id">
              <td><strong>{{ s.full_name }}</strong></td>
              <td>{{ s.class_name || 'Unassigned' }}</td>
              <td><span class="pill muted">{{ s.active_residence || 'Day' }}{{ s.is_new == 1 ? ' (Entry)' : '' }}</span></td>
              <td class="right">{{ fmt(s.gross_due) }}</td>
              <td class="right green">{{ s.total_bursary > 0 ? '- ' + fmt(s.total_bursary) : '—' }}</td>
              <td class="right bold cyan">{{ fmt(s.total_paid) }}</td>
              <td class="right bold" :class="s.balance > 0 ? 'danger' : 'muted'">{{ fmt(s.balance) }}</td>
              <td class="center"><span class="pill" :class="'status-' + s.status">{{ s.status === 'cleared' ? 'Cleared' : s.status === 'partial' ? 'Partial' : 'Unpaid' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </template>

  <!-- Configure Fee Structure modal -->
  <div v-if="showStructureModal" class="modal-backdrop" @click.self="showStructureModal = false">
    <form class="modal-card" @submit.prevent="saveStructure">
      <div class="modal-header">Configure Class Fee Structure</div>
      <div class="modal-body">
        <label>Select Class</label>
        <select v-model="structureForm.class_id" required>
          <option value="">Choose Class...</option>
          <option v-for="fs in feeStructures" :key="fs.class_id" :value="fs.class_id">{{ fs.class_name }}</option>
        </select>
        <label>Day Student Tuition (UGX)</label>
        <input type="number" step="1000" v-model="structureForm.day_tuition" placeholder="e.g. 450000" required>
        <label>Boarding Student Tuition (UGX)</label>
        <input type="number" step="1000" v-model="structureForm.boarding_tuition" placeholder="e.g. 850000" required>
        <label>Entry/Beginner Fee (Uniform, Admission, etc.)</label>
        <input type="number" step="1000" v-model="structureForm.entry_fee" placeholder="e.g. 150000" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" @click="showStructureModal = false">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy">Save Structure</button>
      </div>
    </form>
  </div>

  <!-- Record Payment modal -->
  <div v-if="showPaymentModal" class="modal-backdrop" @click.self="showPaymentModal = false">
    <form class="modal-card" @submit.prevent="recordPayment">
      <div class="modal-header">Record Payment / Bursary Discount</div>
      <div class="modal-body">
        <label>Student</label>
        <select v-model="paymentForm.student_id" required>
          <option value="">Select Student...</option>
          <option v-for="s in ledger" :key="s.student_id" :value="s.student_id">{{ s.full_name }} ({{ s.class_name || 'N/A' }})</option>
        </select>
        <div class="row">
          <div>
            <label>Residence Status</label>
            <select v-model="paymentForm.residence_type">
              <option value="Day">Day Scholar</option>
              <option value="Boarding">Boarding</option>
            </select>
          </div>
          <div class="checkbox-row">
            <label class="checkbox-label"><input type="checkbox" v-model="paymentForm.is_new_student"> Include Entry Fee</label>
          </div>
        </div>
        <label>Amount Paid (UGX)</label>
        <input type="number" step="500" v-model="paymentForm.amount_paid" placeholder="0" required>
        <label>Bursary / Fee Reduction (UGX)</label>
        <input type="number" step="500" v-model="paymentForm.bursary_amount" placeholder="0">
        <div class="hint">Subtracts directly from total tuition obligation.</div>
        <label>Notes / Receipt Reference</label>
        <input type="text" v-model="paymentForm.payment_notes" placeholder="e.g. Bank Slip #48201">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" @click="showPaymentModal = false">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy">Save Payment</button>
      </div>
    </form>
  </div>
</template>

<style>
.header-banner{background:var(--panel-raised);border:1px solid var(--border);border-radius:10px;padding:24px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px;}
.header-banner h2{margin:0 0 4px;font-size:1.2rem;color:var(--text);}
.header-banner p{margin:0;color:var(--muted);}
.header-actions{display:flex;gap:10px;}
.header-actions button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:0.85rem;}
.btn-light{background:var(--panel);color:var(--text);}
.btn-primary{background:var(--cyan);color:#04222a;border-color:transparent;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:var(--panel);border-radius:10px;padding:18px;border-left:4px solid var(--border);}
.stat-card.border-green{border-left-color:var(--green);}
.stat-card.border-cyan{border-left-color:var(--cyan);}
.stat-card.border-amber{border-left-color:var(--amber);}
.stat-card.border-danger{border-left-color:var(--danger);}
.stat-card .label{color:var(--muted);font-size:0.72rem;font-weight:700;text-transform:uppercase;}
.stat-card .n{font-size:1.5rem;font-weight:700;margin-top:6px;}
.stat-card .n.green{color:var(--green);}
.dev-banner{background:var(--panel);border:2px dashed var(--border);border-radius:10px;padding:16px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;}
.dev-banner i{font-size:1.2rem;color:var(--muted);margin-top:2px;}
.badge-soon{background:var(--border);color:var(--muted);padding:2px 8px;border-radius:20px;font-size:0.7rem;margin-left:8px;}
.dev-banner p{margin:6px 0 0;color:var(--muted);font-size:0.8rem;}
.filter-bar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;}
.filter-bar input{flex:2;min-width:220px;}
.filter-bar select{flex:1;min-width:160px;}
input,select{padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;box-sizing:border-box;width:100%;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:0.86rem;min-width:760px;}
th,td{text-align:left;padding:12px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;}
.right{text-align:right;}
.center{text-align:center;}
.bold{font-weight:700;}
.cyan{color:var(--cyan);}
.green{color:var(--green);}
.danger{color:var(--danger);}
.muted{color:var(--muted);}
.pill{display:inline-block;font-size:0.72rem;padding:3px 10px;border-radius:20px;background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.status-cleared{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.status-partial{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.status-unpaid{background:rgba(239,68,68,0.15);color:var(--danger);}
.empty-row{text-align:center;color:var(--muted);padding:30px !important;}
.table-wrap{overflow-x:auto;}
.modal-backdrop{position:fixed;inset:0;background:rgba(4,5,7,0.6);display:flex;align-items:center;justify-content:center;z-index:50;padding:20px;}
.modal-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;}
.modal-header{padding:18px 22px;font-weight:700;border-bottom:1px solid var(--border);}
.modal-body{padding:20px 22px;}
.modal-body label{display:block;font-size:0.78rem;color:var(--muted);margin:14px 0 6px;font-weight:600;}
.modal-body label:first-child{margin-top:0;}
.modal-footer{padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);padding:10px 16px;border-radius:8px;cursor:pointer;}
.modal-footer button:disabled{opacity:0.6;cursor:default;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.checkbox-row{display:flex;align-items:flex-end;padding-bottom:10px;}
.checkbox-label{display:flex;align-items:center;gap:8px;font-weight:600;font-size:0.85rem;color:var(--text) !important;margin:0 !important;}
.checkbox-label input{width:auto;}
.hint{color:var(--muted);font-size:0.75rem;margin-top:4px;}
@media(max-width:480px){.row{grid-template-columns:1fr;}}
</style>
