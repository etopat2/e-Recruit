<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, jsonBody } from '../lib/api'
import StatusBadge from '../components/StatusBadge.vue'

interface Policy { id: string; record_category: string; retention_days: number; disposition: string; legal_basis_reference: string }
interface PurgeRequest { id: string; record_category: string; eligible_record_count: number; status: string; reason: string; evidence_hash?: string }
interface Governance { policies: Policy[]; purge_requests: { data: PurgeRequest[] }; supported_purge_categories: string[] }

const governance = ref<Governance | null>(null); const message = ref(''); const error = ref('')
const policy = reactive({ record_category: 'notifications', retention_days: 365, disposition: 'review_for_purge', legal_basis_reference: '', approval_reference: '' })
const purge = reactive({ record_category: 'notifications', reason: '' })
const hold = reactive({ entity_type: 'notifications', entity_id: '', reason: '' })
onMounted(load)
async function load() { try { governance.value = await api<Governance>('/governance/retention') } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Governance register unavailable.' } }
async function savePolicy() { await act('/governance/retention/policies', policy, 'Retention policy approved and audited.') }
async function requestPurge() { await act('/governance/purge-requests', purge, 'Purge request created for independent approval.') }
async function placeHold() { await act('/governance/legal-holds', hold, 'Legal hold placed; matching purge candidates are excluded.') }
async function decide(record: PurgeRequest, decision: 'approve' | 'reject') { await act(`/governance/purge-requests/${record.id}/decision`, { decision, reason: `${decision} after reviewing policy, scope, cutoff, and legal holds.`, approval_reference: 'Enter-approved-reference-in-production' }, `Purge request ${decision}d.`) }
async function execute(record: PurgeRequest) { await act(`/governance/purge-requests/${record.id}/execute`, { confirmation: 'PURGE', reason: 'Execute the separately approved retention request.' }, 'Approved purge executed with immutable evidence hash.') }
async function act(path: string, payload: unknown, success: string) {
  error.value = ''
  try { await api(path, { method: 'POST', ...jsonBody(payload) }); message.value = success; await load() }
  catch (problem) { error.value = problem instanceof Error ? problem.message : 'Governance action failed.' }
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Privacy and records governance</p><h1>Retention, legal holds, and controlled purge</h1><p>Policy approval, purge request, independent decision, and execution are separate audited steps. Active applicant deletion is not available here.</p></section>
  <div v-if="message" class="alert success page-alert">{{ message }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="configuration-layout"><form class="form-panel" @submit.prevent="savePolicy"><h2>Approve retention policy</h2><label>Record category<select v-model="policy.record_category"><option v-for="category in governance?.supported_purge_categories" :key="category">{{ category }}</option></select></label><label>Retention days<input v-model="policy.retention_days" type="number" min="1" /></label><label>Legal basis reference<input v-model="policy.legal_basis_reference" required /></label><label>Approval reference<input v-model="policy.approval_reference" required /></label><button class="button primary full">Approve policy</button></form><form class="form-panel" @submit.prevent="placeHold"><h2>Place legal hold</h2><label>Entity category<input v-model="hold.entity_type" required /></label><label>Entity ID<input v-model="hold.entity_id" required /></label><label>Reason<textarea v-model="hold.reason" minlength="10" required /></label><button class="button primary full">Place hold</button></form></section>
  <section class="content-section"><form class="form-panel" @submit.prevent="requestPurge"><h2>Request policy-controlled purge</h2><div class="field-grid"><label>Record category<select v-model="purge.record_category"><option v-for="category in governance?.supported_purge_categories" :key="category">{{ category }}</option></select></label><label class="wide">Reason<textarea v-model="purge.reason" minlength="10" required /></label></div><button class="button primary">Request independent approval</button></form><div class="table-wrap"><table><thead><tr><th>Category</th><th>Eligible</th><th>Status</th><th>Controlled action</th></tr></thead><tbody><tr v-for="record in governance?.purge_requests.data" :key="record.id"><td>{{ record.record_category }}</td><td>{{ record.eligible_record_count }}</td><td><StatusBadge :status="record.status" /></td><td><div class="button-row"><button v-if="record.status === 'pending_approval'" class="button secondary compact" @click="decide(record, 'approve')">Approve</button><button v-if="record.status === 'pending_approval'" class="text-button danger" @click="decide(record, 'reject')">Reject</button><button v-if="record.status === 'approved'" class="button compact" @click="execute(record)">Execute approved purge</button></div><code v-if="record.evidence_hash">{{ record.evidence_hash }}</code></td></tr></tbody></table></div></section>
</template>
