<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, jsonBody } from '../lib/api'
import Dialog from '../components/Dialog.vue'
import StatusBadge from '../components/StatusBadge.vue'

interface Policy { id: string; record_category: string; retention_days: number; disposition: string; legal_basis_reference: string }
interface PurgeRequest { id: string; record_category: string; eligible_record_count: number; status: string; reason: string; evidence_hash?: string }
interface Governance { policies: Policy[]; purge_requests: { data: PurgeRequest[] }; supported_purge_categories: string[] }

const governance = ref<Governance | null>(null); const message = ref(''); const error = ref('')
const policy = reactive({ record_category: 'notifications', retention_days: 365, disposition: 'review_for_purge', legal_basis_reference: '', approval_reference: '' })
const purge = reactive({ record_category: 'notifications', reason: '' })
const hold = reactive({ entity_type: 'notifications', entity_id: '', reason: '' })
type GovernanceAction = 'policy' | 'hold' | 'purge' | 'decision' | 'execute'
const activeAction = ref<GovernanceAction | ''>('')
const selectedPurge = ref<PurgeRequest | null>(null)
const purgeDecision = reactive({ decision: 'approve' as 'approve' | 'reject', reason: '', approval_reference: '' })
const purgeExecution = reactive({ confirmation: '', reason: '' })
onMounted(load)
async function load() { try { governance.value = await api<Governance>('/governance/retention') } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Governance register unavailable.' } }
async function savePolicy() { await act('/governance/retention/policies', policy, 'Retention policy approved and audited.') }
async function requestPurge() { await act('/governance/purge-requests', purge, 'Purge request created for independent approval.') }
async function placeHold() { await act('/governance/legal-holds', hold, 'Legal hold placed; matching purge candidates are excluded.') }
function openDecision(record: PurgeRequest, decision: 'approve' | 'reject'): void {
  selectedPurge.value = record
  Object.assign(purgeDecision, { decision, reason: '', approval_reference: '' })
  activeAction.value = 'decision'
}
function openExecution(record: PurgeRequest): void {
  selectedPurge.value = record
  Object.assign(purgeExecution, { confirmation: '', reason: '' })
  activeAction.value = 'execute'
}
async function decide() {
  if (!selectedPurge.value) return
  await act(`/governance/purge-requests/${selectedPurge.value.id}/decision`, purgeDecision, `Purge request ${purgeDecision.decision}d.`)
}
async function execute() {
  if (!selectedPurge.value) return
  await act(`/governance/purge-requests/${selectedPurge.value.id}/execute`, purgeExecution, 'Approved purge executed with immutable evidence hash.')
}
async function act(path: string, payload: unknown, success: string) {
  error.value = ''
  try { await api(path, { method: 'POST', ...jsonBody(payload) }); message.value = success; activeAction.value = ''; selectedPurge.value = null; await load() }
  catch (problem) { error.value = problem instanceof Error ? problem.message : 'Governance action failed.' }
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Privacy and records governance</p><h1>Retention, legal holds, and controlled purge</h1><p>Policy approval, purge request, independent decision, and execution are separate audited steps. Active applicant deletion is not available here.</p></section>
  <div v-if="message" class="alert success page-alert">{{ message }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="content-section compact-top"><div class="action-launcher-grid"><button type="button" class="action-launcher" @click="activeAction = 'policy'"><strong>Approve retention policy</strong><span>Set the reviewed legal basis, retention window, and approval reference.</span></button><button type="button" class="action-launcher" @click="activeAction = 'hold'"><strong>Place legal hold</strong><span>Exclude a specific entity from policy-controlled purge.</span></button><button type="button" class="action-launcher danger-zone" @click="activeAction = 'purge'"><strong>Request controlled purge</strong><span>Create a request for independent review; this does not delete records.</span></button></div></section>
  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Independent control</p><h2>Purge request register</h2></div></div><div class="table-wrap"><table><thead><tr><th>Category</th><th>Eligible</th><th>Status</th><th>Controlled action</th></tr></thead><tbody><tr v-for="record in governance?.purge_requests.data" :key="record.id"><td>{{ record.record_category }}</td><td>{{ record.eligible_record_count }}</td><td><StatusBadge :status="record.status" /></td><td><div class="button-row"><button v-if="record.status === 'pending_approval'" class="button secondary compact" @click="openDecision(record, 'approve')">Approve</button><button v-if="record.status === 'pending_approval'" class="text-button danger" @click="openDecision(record, 'reject')">Reject</button><button v-if="record.status === 'approved'" class="button compact" @click="openExecution(record)">Execute approved purge</button></div><code v-if="record.evidence_hash">{{ record.evidence_hash }}</code></td></tr></tbody></table></div></section>

  <Dialog :open="activeAction === 'policy'" title="Approve retention policy" :close-on-backdrop="false" mobile-sheet @close="activeAction = ''"><form @submit.prevent="savePolicy"><label>Record category<select v-model="policy.record_category"><option v-for="category in governance?.supported_purge_categories" :key="category">{{ category }}</option></select></label><label>Retention days<input v-model="policy.retention_days" type="number" min="1" required data-dialog-initial-focus /></label><label>Legal basis reference<input v-model="policy.legal_basis_reference" required /></label><label>Approval reference<input v-model="policy.approval_reference" required /></label><button class="button primary full">Approve policy</button></form></Dialog>
  <Dialog :open="activeAction === 'hold'" title="Place legal hold" :close-on-backdrop="false" mobile-sheet @close="activeAction = ''"><form @submit.prevent="placeHold"><label>Entity category<input v-model="hold.entity_type" required data-dialog-initial-focus /></label><label>Entity ID<input v-model="hold.entity_id" required /></label><label>Reason<textarea v-model="hold.reason" minlength="10" required /></label><button class="button primary full">Place hold</button></form></Dialog>
  <Dialog :open="activeAction === 'purge'" title="Request policy-controlled purge" description="This creates a request only. An independent approver must review it before execution." :close-on-backdrop="false" mobile-sheet @close="activeAction = ''"><form @submit.prevent="requestPurge"><label>Record category<select v-model="purge.record_category"><option v-for="category in governance?.supported_purge_categories" :key="category">{{ category }}</option></select></label><label>Reason<textarea v-model="purge.reason" minlength="10" required data-dialog-initial-focus /></label><button class="button primary full">Request independent approval</button></form></Dialog>
  <Dialog :open="activeAction === 'decision'" :title="`${purgeDecision.decision === 'approve' ? 'Approve' : 'Reject'} purge request`" :description="selectedPurge ? `${selectedPurge.record_category} · ${selectedPurge.eligible_record_count} eligible records` : ''" :close-on-backdrop="false" mobile-sheet @close="activeAction = ''"><form @submit.prevent="decide"><label>Decision<select v-model="purgeDecision.decision"><option value="approve">Approve</option><option value="reject">Reject</option></select></label><label>Reason<textarea v-model="purgeDecision.reason" minlength="10" required data-dialog-initial-focus /></label><label>Independent approval reference<input v-model="purgeDecision.approval_reference" required /></label><button class="button primary full">Record independent decision</button></form></Dialog>
  <Dialog :open="activeAction === 'execute'" title="Execute approved purge" :description="selectedPurge ? `${selectedPurge.record_category} · ${selectedPurge.eligible_record_count} eligible records` : ''" :close-on-backdrop="false" mobile-sheet @close="activeAction = ''"><form @submit.prevent="execute"><div class="notice"><strong>Irreversible controlled action</strong><p>Only records inside the separately approved request and outside legal holds are eligible. Evidence is retained.</p></div><label>Type PURGE to confirm<input v-model="purgeExecution.confirmation" pattern="PURGE" required data-dialog-initial-focus /></label><label>Execution reason<textarea v-model="purgeExecution.reason" minlength="10" required /></label><button class="button primary full" :disabled="purgeExecution.confirmation !== 'PURGE'">Execute approved purge</button></form></Dialog>
</template>
