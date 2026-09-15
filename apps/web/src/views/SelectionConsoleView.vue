<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Dialog from '../components/Dialog.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'

interface SelectionRun { id: string; run_number: number; mode: string; status: string; outcomes_count?: number }
interface SelectionOutcome { id: string; application_reference: string; applicant_name: string; position: number; outcome: string; score: number }
interface SelectionResult { run?: SelectionRun; outcomes?: SelectionOutcome[] }
interface LookupItem { id?: string; value?: string; label: string; description?: string }
interface SelectionLookups { ranking_runs: LookupItem[]; buckets: LookupItem[]; skills: LookupItem[] }

const runs = ref<SelectionRun[]>([])
const result = ref<SelectionResult | null>(null)
const lookups = reactive<SelectionLookups>({ ranking_runs: [], buckets: [], skills: [] })
const error = ref('')
const notice = ref('')
const busy = ref(false)
const form = reactive({ ranking_run_id: '', ranking_run_label: '', total_slots: 10, reserve_size: 3, mode: 'scenario', unfilled_quota_rule: 'general_merit' })
const quotas = ref<Array<{ bucket: string; bucket_label: string; slots: number }>>([])
const skillReservations = ref<Array<{ skill_code: string; skill_label: string; slots: number; minimum_score: number }>>([])
const tieBreakers = ref<Array<{ field: string; direction: 'asc' | 'desc' }>>([{ field: 'submitted_at', direction: 'asc' }])
const activeDialog = ref<'run' | 'certify' | ''>('')
const selectedRun = ref<SelectionRun | null>(null)
const approvalReference = ref('')
const certifyConfirmation = ref('')

const rankingOptions = computed<ComboboxOption[]>(() => lookups.ranking_runs.map((item) => ({ value: item.id || '', label: item.label, description: item.description })))
const bucketOptions = computed<ComboboxOption[]>(() => lookups.buckets.map((item) => ({ value: item.value || '', label: item.label, description: item.description })))
const skillOptions = computed<ComboboxOption[]>(() => lookups.skills.map((item) => ({ value: item.value || '', label: item.label, description: item.description })))

onMounted(load)

async function load(): Promise<void> {
  try {
    const [runResponse, lookupResponse] = await Promise.all([
      api<{ data: SelectionRun[] }>('/selection-runs'),
      api<{ data: SelectionLookups }>('/selection/lookups', { cacheTtlMs: 30_000 }),
    ])
    runs.value = runResponse.data
    Object.assign(lookups, lookupResponse.data)
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Selection registers are unavailable.'
  }
}

async function runSelection(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    const quotaPolicy = Object.fromEntries(quotas.value.filter((item) => item.bucket).map((item) => [item.bucket, Number(item.slots)]))
    const reservationPolicy = skillReservations.value.filter((item) => item.skill_code && item.slots > 0).map((item) => ({ skill_code: item.skill_code, slots: Number(item.slots), minimum_score: Number(item.minimum_score) }))
    result.value = await api<SelectionResult>('/selection-runs', { method: 'POST', ...jsonBody({
      ranking_run_id: form.ranking_run_id,
      mode: form.mode,
      policy: {
        total_slots: Number(form.total_slots), reserve_size: Number(form.reserve_size), bucket_field: 'bucket', quotas: quotaPolicy,
        skill_reservations: reservationPolicy, tie_breakers: tieBreakers.value, unfilled_quota_rule: form.unfilled_quota_rule,
      },
    }) })
    notice.value = 'Reproducible selection scenario created. Certification remains a separate authorised action.'
    activeDialog.value = ''
    await load()
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Selection run failed.'
  } finally {
    busy.value = false
  }
}

function addQuota(): void { quotas.value.push({ bucket: '', bucket_label: '', slots: 0 }) }
function addSkillReservation(): void { skillReservations.value.push({ skill_code: '', skill_label: '', slots: 0, minimum_score: 0 }) }
function addTieBreaker(): void { tieBreakers.value.push({ field: 'submitted_at', direction: 'asc' }) }
function beginCertification(run: SelectionRun): void { selectedRun.value = run; approvalReference.value = ''; certifyConfirmation.value = ''; activeDialog.value = 'certify' }

async function certify(): Promise<void> {
  if (!selectedRun.value || certifyConfirmation.value !== 'CERTIFY') return
  busy.value = true
  error.value = ''
  try {
    await api(`/selection-runs/${selectedRun.value.id}/certify`, { method: 'POST', ...jsonBody({ confirmation: true, approval_reference: approvalReference.value }) })
    notice.value = `Run ${selectedRun.value.run_number} certified.`
    activeDialog.value = ''
    await load()
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Certification blocked.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Deterministic decision support</p><h1>Ranking and selection</h1><p>Every scenario stores its input, policy, outcome trace, and integrity seal. Skill reservations accept verified evidence only; certification is blocked by unresolved offline work.</p></section>
  <FormAlert v-if="notice" kind="success" :message="notice" page /><FormAlert v-if="error" kind="error" :message="error" page />
  <section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">Immutable run register</p><h2>{{ runs.length }} selection run(s)</h2></div><button type="button" class="button primary" @click="activeDialog = 'run'">Run selection scenario</button></div><article v-for="run in runs" :key="run.id" class="run-card"><div><strong>Run {{ run.run_number }}</strong><StatusBadge :status="run.status" /></div><p>{{ run.mode }} · {{ run.outcomes_count || 0 }} outcomes · integrity seal recorded</p><button v-if="run.status === 'draft' && run.mode === 'official'" class="button compact" @click="beginCertification(run)">Certify with council approval</button></article><div v-if="!runs.length" class="empty-state"><p>No selection runs have been created.</p></div><div v-if="result" class="result-box"><h3>Latest output</h3><p>Integrity seal and full decision trace stored in the audit register.</p><ol><li v-for="outcome in result.outcomes?.slice(0, 10)" :key="outcome.id">{{ outcome.position }}. {{ outcome.applicant_name }} — {{ outcome.application_reference }} — {{ outcome.outcome.replaceAll('_', ' ') }} ({{ outcome.score }})</li></ol></div></section>

  <Dialog :open="activeDialog === 'run'" title="Run a selection scenario" description="Build the policy with structured controls. The server stores the exact versioned policy and reproducible integrity seal." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><form @submit.prevent="runSelection"><FloatingCombobox label="Completed ranking run" :model-value="form.ranking_run_label" :options="rankingOptions" required placeholder="Search post or ranking run" @update:model-value="form.ranking_run_id = ''; form.ranking_run_label = $event" @select="form.ranking_run_id = $event.value; form.ranking_run_label = $event.label" /><div class="field-grid"><label>Total authorised slots<input v-model="form.total_slots" type="number" min="0" required /></label><label>Reserve list size<input v-model="form.reserve_size" type="number" min="0" required /></label><label>Mode<select v-model="form.mode"><option value="scenario">Scenario</option><option value="official">Official draft</option></select></label><label>Unfilled places<select v-model="form.unfilled_quota_rule"><option value="general_merit">Fill by general merit</option><option value="leave_unfilled">Leave unfilled</option></select></label></div>
    <fieldset class="repeatable-fields"><legend>Quota buckets</legend><div v-for="(quota, index) in quotas" :key="index" class="repeatable-row"><FloatingCombobox label="Quota group" :model-value="quota.bucket_label" :options="bucketOptions" placeholder="Select a ranked group" @update:model-value="quota.bucket = ''; quota.bucket_label = $event" @select="quota.bucket = $event.value; quota.bucket_label = $event.label" /><label>Places<input v-model="quota.slots" type="number" min="0" /></label><button type="button" class="button text" @click="quotas.splice(index, 1)">Remove</button></div><button type="button" class="button secondary" @click="addQuota">Add quota group</button></fieldset>
    <fieldset class="repeatable-fields"><legend>Verified-skill reservations</legend><div v-for="(reservation, index) in skillReservations" :key="index" class="repeatable-row"><FloatingCombobox label="Verified skill" :model-value="reservation.skill_label" :options="skillOptions" placeholder="Select a skill" @update:model-value="reservation.skill_code = ''; reservation.skill_label = $event" @select="reservation.skill_code = $event.value; reservation.skill_label = $event.label" /><label>Places<input v-model="reservation.slots" type="number" min="0" /></label><label>Minimum score<input v-model="reservation.minimum_score" type="number" min="0" step="0.01" /></label><button type="button" class="button text" @click="skillReservations.splice(index, 1)">Remove</button></div><button type="button" class="button secondary" @click="addSkillReservation">Add skill reservation</button></fieldset>
    <fieldset class="repeatable-fields"><legend>Tie-break order</legend><div v-for="(tieBreaker, index) in tieBreakers" :key="index" class="repeatable-row"><label>Field<select v-model="tieBreaker.field"><option value="submitted_at">Submission time</option></select></label><label>Direction<select v-model="tieBreaker.direction"><option value="asc">Earlier first</option><option value="desc">Later first</option></select></label><button type="button" class="button text" :disabled="tieBreakers.length === 1" @click="tieBreakers.splice(index, 1)">Remove</button></div><button type="button" class="button secondary" @click="addTieBreaker">Add tie-break rule</button></fieldset>
    <div class="notice"><strong>Readiness gate</strong><p>Open sync conflicts or outstanding offline events will stop this operation.</p></div><button class="button primary full" :disabled="busy || !form.ranking_run_id"><LoadingIndicator v-if="busy" small label="Running…" /><span v-else>Run reproducible scenario</span></button></form></Dialog>
  <Dialog :open="activeDialog === 'certify'" :title="selectedRun ? `Certify official run ${selectedRun.run_number}` : 'Certify official run'" description="Certification is irreversible and must reference the recorded council approval." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><form @submit.prevent="certify"><label>Council approval reference<input v-model="approvalReference" required data-dialog-initial-focus /></label><label>Type CERTIFY to confirm<input v-model="certifyConfirmation" required autocomplete="off" /></label><button class="button danger full" :disabled="busy || certifyConfirmation !== 'CERTIFY' || !approvalReference">Certify official run</button></form></Dialog>
</template>
