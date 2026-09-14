<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import Dialog from '../components/Dialog.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'

interface SelectionRun { id: string; run_number: number; mode: string; status: string; input_fingerprint: string; output_fingerprint: string; outcomes_count?: number }
interface SelectionOutcome { id: string; application_id: string; position: number; outcome: string; score: number }
interface SelectionResult { run?: SelectionRun; outcomes?: SelectionOutcome[] }
const runs = ref<SelectionRun[]>([]); const result = ref<SelectionResult | null>(null); const error = ref(''); const notice = ref(''); const busy = ref(false)
const form = reactive({ ranking_run_id: '', total_slots: 10, reserve_size: 3, mode: 'scenario', quotas: '{}', skill_reservations: '[]', tie_breakers: '[{"field":"submitted_at","direction":"asc"}]' })
const activeDialog = ref<'run' | 'certify' | ''>(''); const selectedRun = ref<SelectionRun | null>(null); const approvalReference = ref(''); const certifyConfirmation = ref('')
onMounted(load)
async function load() { try { const response = await api<{ data: SelectionRun[] }>('/selection-runs'); runs.value = response.data } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Selection runs unavailable.' } }
async function runSelection() {
  busy.value = true; error.value = ''
  try {
    result.value = await api<SelectionResult>('/selection-runs', { method: 'POST', ...jsonBody({ ranking_run_id: form.ranking_run_id, mode: form.mode, policy: { total_slots: form.total_slots, reserve_size: form.reserve_size, bucket_field: 'bucket', quotas: JSON.parse(form.quotas), skill_reservations: JSON.parse(form.skill_reservations), tie_breakers: JSON.parse(form.tie_breakers), unfilled_quota_rule: 'general_merit' } }) })
    notice.value = 'Reproducible selection scenario created. Certification remains a separate authorised action.'; activeDialog.value = ''; await load()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Selection run failed.' } finally { busy.value = false }
}
function beginCertification(run: SelectionRun) { selectedRun.value = run; approvalReference.value = ''; certifyConfirmation.value = ''; activeDialog.value = 'certify' }
async function certify() {
  if (!selectedRun.value || certifyConfirmation.value !== 'CERTIFY') return
  busy.value = true; error.value = ''
  try { await api(`/selection-runs/${selectedRun.value.id}/certify`, { method: 'POST', ...jsonBody({ confirmation: true, approval_reference: approvalReference.value }) }); notice.value = `Run ${selectedRun.value.run_number} certified.`; activeDialog.value = ''; await load() } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Certification blocked.' } finally { busy.value = false }
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Deterministic decision support</p><h1>Ranking and selection</h1><p>Every scenario stores its input, policy, outcome trace, and fingerprint. Skill reservations accept verified evidence only; certification is blocked by unresolved offline work.</p></section><FormAlert v-if="notice" kind="success" :message="notice" page /><FormAlert v-if="error" kind="error" :message="error" page />
  <section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">Immutable run register</p><h2>{{ runs.length }} selection run(s)</h2></div><button type="button" class="button primary" @click="activeDialog = 'run'">Run selection scenario</button></div><article v-for="run in runs" :key="run.id" class="run-card"><div><strong>Run {{ run.run_number }}</strong><StatusBadge :status="run.status" /></div><p>{{ run.mode }} · {{ run.outcomes_count || 0 }} outcomes</p><code>{{ run.output_fingerprint }}</code><button v-if="run.status === 'draft' && run.mode === 'official'" class="button compact" @click="beginCertification(run)">Certify with council approval</button></article><div v-if="!runs.length" class="empty-state"><p>No selection runs have been created.</p></div><div v-if="result" class="result-box"><h3>Latest output</h3><p>Fingerprint</p><code>{{ result.run?.output_fingerprint }}</code><ol><li v-for="outcome in result.outcomes?.slice(0, 10)" :key="outcome.id">{{ outcome.position }}. {{ outcome.application_id }} — {{ outcome.outcome }} ({{ outcome.score }})</li></ol></div></section>
  <Dialog :open="activeDialog === 'run'" title="Run a selection scenario" description="The run stores its inputs, policy, outcome trace, and reproducible fingerprint." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><form @submit.prevent="runSelection"><label>Ranking run ID<input v-model="form.ranking_run_id" required data-dialog-initial-focus /></label><div class="field-grid"><label>Total authorised slots<input v-model="form.total_slots" type="number" min="0" /></label><label>Reserve list size<input v-model="form.reserve_size" type="number" min="0" /></label><label>Mode<select v-model="form.mode"><option value="scenario">Scenario</option><option value="official">Official draft</option></select></label><label class="wide">Quota buckets (JSON)<textarea v-model="form.quotas" rows="4" /></label><label class="wide">Verified-skill reservations (JSON)<textarea v-model="form.skill_reservations" rows="5" /></label><label class="wide">Tie-break order (JSON)<textarea v-model="form.tie_breakers" rows="4" /></label></div><div class="notice"><strong>Readiness gate</strong><p>Open sync conflicts or outstanding offline events will stop this operation.</p></div><button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Running…" /><span v-else>Run reproducible scenario</span></button></form></Dialog>
  <Dialog :open="activeDialog === 'certify'" :title="selectedRun ? `Certify official run ${selectedRun.run_number}` : 'Certify official run'" description="Certification is irreversible and must reference the recorded council approval." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><form @submit.prevent="certify"><label>Council approval reference<input v-model="approvalReference" required data-dialog-initial-focus /></label><label>Type CERTIFY to confirm<input v-model="certifyConfirmation" required autocomplete="off" /></label><button class="button danger full" :disabled="busy || certifyConfirmation !== 'CERTIFY' || !approvalReference">Certify official run</button></form></Dialog>
</template>
