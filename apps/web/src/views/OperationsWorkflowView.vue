<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Dialog from '../components/Dialog.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import { api, jsonBody } from '../lib/api'
import { pushToast } from '../lib/toast'

interface LookupRecord {
  id: string
  label: string
  description?: string
  application_id?: string
  post_id?: string
  document_requirements?: Array<{ document_type: string; label: string }>
}

interface OperationsLookups {
  posts: LookupRecord[]
  regions: LookupRecord[]
  receiving_offices: LookupRecord[]
  centre_sessions: LookupRecord[]
  interview_assignments: LookupRecord[]
  panels: LookupRecord[]
  medical_schedules: LookupRecord[]
  selection_outcomes: LookupRecord[]
  medical_results: LookupRecord[]
  final_selections: LookupRecord[]
  training_invites: LookupRecord[]
  selection_runs: LookupRecord[]
  replacement_recommendations: LookupRecord[]
}

interface AllocationRun {
  id: string
  run_number: number
  status: 'preview' | 'committed'
  candidate_count: number
  post: { name: string }
  region: { name: string }
  centres: Array<{ centre_name: string; candidate_count: number; total_load: number; capacity: number }>
  districts: Array<{ district_name: string; centre_name: string; candidate_count: number }>
}

type OperationalAction = 'hard-copy' | 'schedule' | 'attendance' | 'panel' | 'medical-schedule' | 'medical-result' | 'final-selection' | 'training-invite' | 'training-report' | 'replacement' | 'replacement-decision'

const emptyLookups = (): OperationsLookups => ({
  posts: [], regions: [], receiving_offices: [], centre_sessions: [], interview_assignments: [], panels: [],
  medical_schedules: [], selection_outcomes: [], medical_results: [], final_selections: [], training_invites: [],
  selection_runs: [], replacement_recommendations: [],
})
const lookups = reactive<OperationsLookups>(emptyLookups())
const notice = ref('')
const error = ref('')
const busy = ref('')
const loadingLookups = ref(true)
const activeAction = ref<OperationalAction | ''>('')
const allocationPreview = ref<AllocationRun | null>(null)
const actionTitles: Record<OperationalAction, string> = {
  'hard-copy': 'Record hard-copy receipt', schedule: 'Allocate candidates to interview centres', attendance: 'Record interview attendance',
  panel: 'Close panel session', 'medical-schedule': 'Create medical schedule', 'medical-result': 'Record restricted medical result',
  'final-selection': 'Approve final selection', 'training-invite': 'Issue training invitation', 'training-report': 'Record training reporting',
  replacement: 'Recommend next reserve', 'replacement-decision': 'Decide reserve replacement',
}
const activeActionTitle = computed(() => activeAction.value ? actionTitles[activeAction.value] : 'Operational action')

const hardCopy = reactive({ application_id: '', application_label: '', receiving_office: '', receiving_office_label: '', received_at: new Date().toISOString().slice(0, 16), notes: '', items: [] as Array<{ document_type: string; label: string; present: boolean }> })
const scheduling = reactive({ post_id: '', post_label: '', region_id: '', region_label: '' })
const attendance = reactive({ assignment_id: '', assignment_label: '', status: 'present', exception_reason: '' })
const panel = reactive({ panel_id: '', panel_label: '', confirmation: false })
const medicalSchedule = reactive({ recruitment_post_id: '', post_label: '', facility: '', scheduled_date: '', reporting_time: '08:00', capacity: 100 })
const medicalResult = reactive({ application_id: '', application_label: '', medical_schedule_id: '', schedule_label: '', outcome: 'Fit', clinical_reference: '', restricted_notes: '' })
const finalSelection = reactive({ selection_outcome_id: '', outcome_label: '', medical_result_id: '', medical_label: '', approval_reference: '', confirmation: false })
const trainingInvite = reactive({ final_selection_id: '', selection_label: '', reporting_date: '', reporting_time: '08:00', location: '', instructions: ['Bring the official invitation and required originals.'] })
const trainingReport = reactive({ training_invite_id: '', invite_label: '', status: 'reported', notes: '' })
const replacement = reactive({ replaced_application_id: '', application_label: '', selection_run_id: '', run_label: '', trigger: 'training_vacancy', reason: '' })
const replacementDecision = reactive({ recommendation_id: '', recommendation_label: '', decision: 'approve', reason: '', approval_reference: '' })
const selectedMedicalApplicationPost = ref('')
const selectedOutcomeApplication = ref('')

const trainingStatuses = ['expected', 'reported', 'verified', 'admitted', 'late', 'documentation_incomplete', 'no_show', 'withdrawn', 'replacement', 'not_reported', 'declined']
const trainingStatusOptions: ComboboxOption[] = trainingStatuses.map((status) => ({ value: status, label: humanise(status) }))
const options = (items: LookupRecord[]): ComboboxOption[] => items.map((item) => ({ value: item.id, label: item.label, description: item.description, data: item }))
const postOptions = computed(() => options(lookups.posts))
const regionOptions = computed(() => options(lookups.regions))
const officeOptions = computed(() => options(lookups.receiving_offices))
const assignmentOptions = computed(() => options(lookups.interview_assignments))
const panelOptions = computed(() => options(lookups.panels))
const medicalScheduleOptions = computed(() => options(lookups.medical_schedules.filter((item) => !selectedMedicalApplicationPost.value || !item.post_id || item.post_id === selectedMedicalApplicationPost.value)))
const outcomeOptions = computed(() => options(lookups.selection_outcomes))
const fitMedicalOptions = computed(() => options(lookups.medical_results.filter((item) => !selectedOutcomeApplication.value || item.application_id === selectedOutcomeApplication.value)))
const finalSelectionOptions = computed(() => options(lookups.final_selections))
const trainingInviteOptions = computed(() => options(lookups.training_invites))
const selectionRunOptions = computed(() => options(lookups.selection_runs))
const recommendationOptions = computed(() => options(lookups.replacement_recommendations))

onMounted(loadLookups)

async function loadLookups(): Promise<void> {
  loadingLookups.value = true
  try {
    const response = await api<{ data: OperationsLookups }>('/operations/lookups', { cacheTtlMs: 30_000 })
    Object.assign(lookups, emptyLookups(), response.data)
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Operational registers could not be loaded.'
  } finally {
    loadingLookups.value = false
  }
}

function openAction(action: OperationalAction): void {
  activeAction.value = action
  notice.value = ''
  error.value = ''
  if (action === 'schedule') allocationPreview.value = null
}

function humanise(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function recordSelection(target: Record<string, unknown>, idField: string, labelField: string, option: ComboboxOption): LookupRecord {
  target[idField] = option.value
  target[labelField] = option.label
  return option.data as LookupRecord
}

function clearSelection(target: Record<string, unknown>, idField: string, labelField: string, value: string): void {
  target[idField] = ''
  target[labelField] = value
}

async function loadApplications(query: string, context: 'hard_copy' | 'medical' | 'replacement', signal: AbortSignal): Promise<ComboboxOption[]> {
  const response = await api<{ data: LookupRecord[] }>(`/operations/applications?search=${encodeURIComponent(query)}&context=${context}`, { signal, cacheTtlMs: 30_000 })
  return options(response.data)
}

async function act(name: string, path: string, method: 'POST' | 'PUT', payload: unknown, success: string): Promise<void> {
  busy.value = name
  notice.value = ''
  error.value = ''
  try {
    await api(path, { method, ...jsonBody(payload) })
    notice.value = success
    pushToast(success, 'success')
    activeAction.value = ''
    await loadLookups()
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'The operational action failed.'
    pushToast(error.value, 'error')
  } finally {
    busy.value = ''
  }
}

function chooseHardCopyApplication(option: ComboboxOption): void {
  const item = recordSelection(hardCopy, 'application_id', 'application_label', option)
  hardCopy.items = (item.document_requirements || []).map((requirement) => ({ ...requirement, present: false }))
}

function recordHardCopy(): Promise<void> {
  return act('hard-copy', `/applications/${hardCopy.application_id}/hard-copy-receipts`, 'POST', {
    receiving_office: hardCopy.receiving_office_label,
    received_at: new Date(hardCopy.received_at).toISOString(),
    notes: hardCopy.notes || undefined,
    items: hardCopy.items.map((item) => ({ document_type: item.document_type, status: item.present ? 'Match' : 'Missing' })),
  }, 'Hard-copy receipt recorded with a traceable receipt number.')
}

async function previewAllocation(): Promise<void> {
  busy.value = 'schedule-preview'
  error.value = ''
  try {
    const response = await api<{ data: AllocationRun }>('/interview-allocation-runs/preview', { method: 'POST', ...jsonBody({ recruitment_post_id: scheduling.post_id, prison_region_id: scheduling.region_id }) })
    allocationPreview.value = response.data
    notice.value = `Allocation version ${response.data.run_number} is ready for review.`
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'The allocation preview could not be generated.'
    pushToast(error.value, 'error')
  } finally {
    busy.value = ''
  }
}

async function commitAllocation(): Promise<void> {
  if (!allocationPreview.value) return
  await act('schedule-commit', `/interview-allocation-runs/${allocationPreview.value.id}/commit`, 'POST', {}, 'Interview allocation committed; invitation generation has been queued.')
  allocationPreview.value = null
}

function addInstruction(): void { trainingInvite.instructions.push('') }
function removeInstruction(index: number): void { if (trainingInvite.instructions.length > 1) trainingInvite.instructions.splice(index, 1) }
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled recruitment operations</p><h1>Centre, medical, and training workflows</h1><p>Search approved registers by human-readable candidate, campaign, centre, and panel details. Internal record keys are retained by the system.</p></section>
  <FormAlert v-if="notice" kind="success" :message="notice" page /><FormAlert v-if="error && !activeAction" kind="error" :message="error" page />
  <section v-if="loadingLookups" class="content-section"><LoadingIndicator label="Loading authorised operational registers…" /></section>
  <template v-else>
    <section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">Before interview</p><h2>Hard copies and scheduling</h2></div></div><div class="action-launcher-grid"><button type="button" class="action-launcher" @click="openAction('hard-copy')"><strong>Record hard-copy receipt</strong><span>Capture accountable document reception.</span></button><button type="button" class="action-launcher" @click="openAction('schedule')"><strong>Allocate interview candidates</strong><span>Preview a district-preserving, load-balanced allocation.</span></button></div></section>
    <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Interview centre</p><h2>Attendance and panel closure</h2></div></div><div class="action-launcher-grid"><button type="button" class="action-launcher" @click="openAction('attendance')"><strong>Record attendance</strong><span>Update one approved interview assignment.</span></button><button type="button" class="action-launcher danger-zone" @click="openAction('panel')"><strong>Close panel session</strong><span>Reconcile, fingerprint, and make submitted scores immutable.</span></button></div></section>
    <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Restricted stage</p><h2>Medical and final approval</h2></div></div><div class="action-launcher-grid"><button type="button" class="action-launcher" @click="openAction('medical-schedule')"><strong>Create medical schedule</strong><span>Configure an authorised facility and capacity.</span></button><button type="button" class="action-launcher" @click="openAction('medical-result')"><strong>Record restricted result</strong><span>Capture role-restricted medical evidence.</span></button><button type="button" class="action-launcher danger-zone" @click="openAction('final-selection')"><strong>Approve final selection</strong><span>Confirm certified selection and Fit medical gates.</span></button></div></section>
    <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Training intake</p><h2>Invitation, reporting, and reserve control</h2></div></div><div class="action-launcher-grid"><button type="button" class="action-launcher" @click="openAction('training-invite')"><strong>Issue training invitation</strong><span>Create the protected reporting artifact.</span></button><button type="button" class="action-launcher" @click="openAction('training-report')"><strong>Record training reporting</strong><span>Update the invitation’s intake status.</span></button><button type="button" class="action-launcher" @click="openAction('replacement')"><strong>Recommend next reserve</strong><span>Follow the certified reserve order.</span></button><button type="button" class="action-launcher danger-zone" @click="openAction('replacement-decision')"><strong>Decide reserve replacement</strong><span>Record independent approval or rejection.</span></button></div></section>
  </template>

  <Dialog :open="Boolean(activeAction)" :title="activeActionTitle" :close-on-backdrop="false" initial-focus="input, select, textarea" mobile-sheet @close="activeAction = ''">
    <FormAlert v-if="error" kind="error" :message="error" />
    <form v-if="activeAction === 'hard-copy'" @submit.prevent="recordHardCopy">
      <FloatingCombobox label="Application" :model-value="hardCopy.application_label" :load-options="(query, signal) => loadApplications(query, 'hard_copy', signal)" :min-chars="2" required placeholder="Search name, NIN, or application reference" hint="Enter at least two characters." @update:model-value="clearSelection(hardCopy, 'application_id', 'application_label', $event)" @select="chooseHardCopyApplication" />
      <FloatingCombobox label="Receiving office" :model-value="hardCopy.receiving_office_label" :options="officeOptions" required placeholder="Search active recruitment centres" @update:model-value="clearSelection(hardCopy, 'receiving_office', 'receiving_office_label', $event)" @select="recordSelection(hardCopy, 'receiving_office', 'receiving_office_label', $event)" />
      <label>Received at<input v-model="hardCopy.received_at" type="datetime-local" required /></label>
      <fieldset v-if="hardCopy.items.length" class="checklist"><legend>Required hard-copy documents</legend><label v-for="item in hardCopy.items" :key="item.document_type" class="checkbox"><input v-model="item.present" type="checkbox" /><span>{{ item.label }} received and matches</span></label><small>Unchecked documents are recorded as missing for follow-up.</small></fieldset>
      <p v-else-if="hardCopy.application_id" class="field-help">No hard-copy requirements were configured for this application.</p>
      <label>Receipt notes<textarea v-model="hardCopy.notes" /></label><button class="button primary full" :disabled="busy === 'hard-copy' || !hardCopy.application_id || !hardCopy.receiving_office"><LoadingIndicator v-if="busy === 'hard-copy'" small label="Recording…" /><span v-else>Record accountable receipt</span></button>
    </form>
    <form v-else-if="activeAction === 'schedule'" @submit.prevent="allocationPreview ? commitAllocation() : previewAllocation()">
      <FloatingCombobox label="Recruitment post" :model-value="scheduling.post_label" :options="postOptions" required placeholder="Search active campaign posts" @update:model-value="clearSelection(scheduling, 'post_id', 'post_label', $event); allocationPreview = null" @select="recordSelection(scheduling, 'post_id', 'post_label', $event); allocationPreview = null" />
      <FloatingCombobox label="Prison region" :model-value="scheduling.region_label" :options="regionOptions" required placeholder="Search active regions" @update:model-value="clearSelection(scheduling, 'region_id', 'region_label', $event); allocationPreview = null" @select="recordSelection(scheduling, 'region_id', 'region_label', $event); allocationPreview = null" />
      <section v-if="allocationPreview" class="allocation-preview" aria-live="polite"><h3>Allocation version {{ allocationPreview.run_number }}</h3><p>{{ allocationPreview.candidate_count }} candidates; each routing district remains at one centre.</p><table><thead><tr><th>Centre</th><th>Allocated</th><th>Total load</th><th>Capacity</th></tr></thead><tbody><tr v-for="centre in allocationPreview.centres" :key="centre.centre_name"><td>{{ centre.centre_name }}</td><td>{{ centre.candidate_count }}</td><td>{{ centre.total_load }}</td><td>{{ centre.capacity }}</td></tr></tbody></table><details><summary>District allocation ({{ allocationPreview.districts.length }})</summary><table><thead><tr><th>District</th><th>Centre</th><th>Candidates</th></tr></thead><tbody><tr v-for="district in allocationPreview.districts" :key="district.district_name"><td>{{ district.district_name }}</td><td>{{ district.centre_name }}</td><td>{{ district.candidate_count }}</td></tr></tbody></table></details></section>
      <button class="button primary full" :disabled="busy.startsWith('schedule') || !scheduling.post_id || !scheduling.region_id"><LoadingIndicator v-if="busy.startsWith('schedule')" small label="Processing…" /><span v-else>{{ allocationPreview ? 'Commit allocation and queue invitations' : 'Preview district-balanced allocation' }}</span></button>
    </form>
    <form v-else-if="activeAction === 'attendance'" @submit.prevent="act('attendance', `/interview-assignments/${attendance.assignment_id}/attendance`, 'PUT', { status: attendance.status, exception_reason: attendance.exception_reason || undefined }, 'Attendance recorded and audited.')"><FloatingCombobox label="Interview assignment" :model-value="attendance.assignment_label" :options="assignmentOptions" required placeholder="Search candidate, centre, or panel" @update:model-value="clearSelection(attendance, 'assignment_id', 'assignment_label', $event)" @select="recordSelection(attendance, 'assignment_id', 'assignment_label', $event)" /><label>Attendance status<select v-model="attendance.status"><option v-for="status in ['present','late','absent','referred','disqualified','excused','no_show']" :key="status" :value="status">{{ humanise(status) }}</option></select></label><label>Exception reason<textarea v-model="attendance.exception_reason" /></label><button class="button primary full" :disabled="busy === 'attendance' || !attendance.assignment_id">Record attendance</button></form>
    <form v-else-if="activeAction === 'panel'" @submit.prevent="act('panel', `/panels/${panel.panel_id}/close`, 'POST', { confirmation: panel.confirmation }, 'Panel closed; submitted scores are fingerprinted and immutable.')"><FloatingCombobox label="Panel session" :model-value="panel.panel_label" :options="panelOptions" required placeholder="Search centre, panel, or date" @update:model-value="clearSelection(panel, 'panel_id', 'panel_label', $event)" @select="recordSelection(panel, 'panel_id', 'panel_label', $event)" /><label class="checkbox"><input v-model="panel.confirmation" type="checkbox" required /><span>I confirm the panel data is complete and all field packs are reconciled.</span></label><div class="notice"><strong>Immutability gate</strong><p>Open conflicts or active packs block closure. The server fingerprints all submitted assessment data.</p></div><button class="button primary full" :disabled="busy === 'panel' || !panel.panel_id">Close and fingerprint panel</button></form>
    <form v-else-if="activeAction === 'medical-schedule'" @submit.prevent="act('medical-schedule', '/medical/schedules', 'POST', { recruitment_post_id: medicalSchedule.recruitment_post_id, facility: medicalSchedule.facility, scheduled_date: medicalSchedule.scheduled_date, reporting_time: medicalSchedule.reporting_time, capacity: medicalSchedule.capacity }, 'Medical schedule created inside the authorised post scope.')"><FloatingCombobox label="Recruitment post" :model-value="medicalSchedule.post_label" :options="postOptions" required placeholder="Search active campaign posts" @update:model-value="clearSelection(medicalSchedule, 'recruitment_post_id', 'post_label', $event)" @select="recordSelection(medicalSchedule, 'recruitment_post_id', 'post_label', $event)" /><label>Approved facility<input v-model="medicalSchedule.facility" required /></label><div class="field-grid"><label>Date<input v-model="medicalSchedule.scheduled_date" type="date" required /></label><label>Reporting time<input v-model="medicalSchedule.reporting_time" type="time" required /></label><label>Capacity<input v-model="medicalSchedule.capacity" type="number" min="1" /></label></div><button class="button primary full" :disabled="busy === 'medical-schedule' || !medicalSchedule.recruitment_post_id">Create schedule</button></form>
    <form v-else-if="activeAction === 'medical-result'" @submit.prevent="act('medical-result', '/medical/results', 'POST', { application_id: medicalResult.application_id, medical_schedule_id: medicalResult.medical_schedule_id, outcome: medicalResult.outcome, clinical_reference: medicalResult.clinical_reference || undefined, restricted_notes: medicalResult.restricted_notes || undefined }, 'Restricted medical result recorded.')"><FloatingCombobox label="Candidate" :model-value="medicalResult.application_label" :load-options="(query, signal) => loadApplications(query, 'medical', signal)" :min-chars="2" required placeholder="Search name, NIN, or application reference" @update:model-value="clearSelection(medicalResult, 'application_id', 'application_label', $event); selectedMedicalApplicationPost = ''" @select="selectedMedicalApplicationPost = recordSelection(medicalResult, 'application_id', 'application_label', $event).post_id || ''; medicalResult.medical_schedule_id = ''; medicalResult.schedule_label = ''" /><FloatingCombobox label="Medical schedule" :model-value="medicalResult.schedule_label" :options="medicalScheduleOptions" required placeholder="Search matching facility or date" @update:model-value="clearSelection(medicalResult, 'medical_schedule_id', 'schedule_label', $event)" @select="recordSelection(medicalResult, 'medical_schedule_id', 'schedule_label', $event)" /><label>Outcome<select v-model="medicalResult.outcome"><option v-for="outcome in ['Fit','Not Fit','Deferred','Further Assessment Required','No Show']" :key="outcome">{{ outcome }}</option></select></label><label>Clinical reference<input v-model="medicalResult.clinical_reference" /></label><label>Restricted medical notes<textarea v-model="medicalResult.restricted_notes" /></label><button class="button primary full" :disabled="busy === 'medical-result' || !medicalResult.application_id || !medicalResult.medical_schedule_id">Record restricted result</button></form>
    <form v-else-if="activeAction === 'final-selection'" @submit.prevent="act('final-selection', '/final-selections', 'POST', { selection_outcome_id: finalSelection.selection_outcome_id, medical_result_id: finalSelection.medical_result_id, approval_reference: finalSelection.approval_reference, confirmation: finalSelection.confirmation }, 'Fit candidate approved against the certified selection outcome.')"><FloatingCombobox label="Certified selected candidate" :model-value="finalSelection.outcome_label" :options="outcomeOptions" required placeholder="Search candidate or application reference" @update:model-value="clearSelection(finalSelection, 'selection_outcome_id', 'outcome_label', $event); selectedOutcomeApplication = ''" @select="selectedOutcomeApplication = recordSelection(finalSelection, 'selection_outcome_id', 'outcome_label', $event).application_id || ''; finalSelection.medical_result_id = ''; finalSelection.medical_label = ''" /><FloatingCombobox label="Fit medical result" :model-value="finalSelection.medical_label" :options="fitMedicalOptions" required placeholder="Select the matching Fit result" @update:model-value="clearSelection(finalSelection, 'medical_result_id', 'medical_label', $event)" @select="recordSelection(finalSelection, 'medical_result_id', 'medical_label', $event)" /><label>Council approval reference<input v-model="finalSelection.approval_reference" required /></label><label class="checkbox"><input v-model="finalSelection.confirmation" type="checkbox" required /><span>I confirm the certified selection and Fit medical gates.</span></label><button class="button primary full" :disabled="busy === 'final-selection' || !finalSelection.selection_outcome_id || !finalSelection.medical_result_id">Approve final selection</button></form>
    <form v-else-if="activeAction === 'training-invite'" @submit.prevent="act('training-invite', '/training/invitations', 'POST', { final_selection_id: trainingInvite.final_selection_id, reporting_date: trainingInvite.reporting_date, reporting_time: trainingInvite.reporting_time, location: trainingInvite.location, instructions: trainingInvite.instructions.filter(Boolean) }, 'Training invitation issued with a protected verifiable PDF.')"><FloatingCombobox label="Approved candidate" :model-value="trainingInvite.selection_label" :options="finalSelectionOptions" required placeholder="Search candidate or application reference" @update:model-value="clearSelection(trainingInvite, 'final_selection_id', 'selection_label', $event)" @select="recordSelection(trainingInvite, 'final_selection_id', 'selection_label', $event)" /><div class="field-grid"><label>Date<input v-model="trainingInvite.reporting_date" type="date" required /></label><label>Time<input v-model="trainingInvite.reporting_time" type="time" required /></label></div><label>Training location<input v-model="trainingInvite.location" required /></label><fieldset class="repeatable-fields"><legend>Reporting instructions</legend><div v-for="(_, index) in trainingInvite.instructions" :key="index" class="repeatable-row"><label :for="`training-instruction-${index}`">Instruction {{ index + 1 }}<input :id="`training-instruction-${index}`" v-model="trainingInvite.instructions[index]" required /></label><button type="button" class="button text" :disabled="trainingInvite.instructions.length === 1" @click="removeInstruction(index)">Remove</button></div><button type="button" class="button secondary" @click="addInstruction">Add instruction</button></fieldset><button class="button primary full" :disabled="busy === 'training-invite' || !trainingInvite.final_selection_id">Issue protected invitation</button></form>
    <form v-else-if="activeAction === 'training-report'" @submit.prevent="act('training-report', '/training/reporting', 'POST', { training_invite_id: trainingReport.training_invite_id, status: trainingReport.status, notes: trainingReport.notes || undefined }, 'Training reporting status recorded and audited.')"><FloatingCombobox label="Training invitation" :model-value="trainingReport.invite_label" :options="trainingInviteOptions" required placeholder="Search candidate, location, or date" @update:model-value="clearSelection(trainingReport, 'training_invite_id', 'invite_label', $event)" @select="recordSelection(trainingReport, 'training_invite_id', 'invite_label', $event)" /><FloatingCombobox label="Status" :model-value="humanise(trainingReport.status)" :options="trainingStatusOptions" required placeholder="Search or select status" @update:model-value="trainingReport.status = ''" @select="trainingReport.status = $event.value" /><label>Reporting notes<textarea v-model="trainingReport.notes" /></label><button class="button primary full" :disabled="busy === 'training-report' || !trainingReport.training_invite_id">Record reporting status</button></form>
    <form v-else-if="activeAction === 'replacement'" @submit.prevent="act('replacement', '/training/replacement-recommendations', 'POST', { replaced_application_id: replacement.replaced_application_id, selection_run_id: replacement.selection_run_id, trigger: replacement.trigger, reason: replacement.reason }, 'Strict-order reserve replacement recommended for independent approval.')"><FloatingCombobox label="Candidate being replaced" :model-value="replacement.application_label" :load-options="(query, signal) => loadApplications(query, 'replacement', signal)" :min-chars="2" required placeholder="Search name or application reference" @update:model-value="clearSelection(replacement, 'replaced_application_id', 'application_label', $event)" @select="recordSelection(replacement, 'replaced_application_id', 'application_label', $event)" /><FloatingCombobox label="Certified selection run" :model-value="replacement.run_label" :options="selectionRunOptions" required placeholder="Search post or certified run" @update:model-value="clearSelection(replacement, 'selection_run_id', 'run_label', $event)" @select="recordSelection(replacement, 'selection_run_id', 'run_label', $event)" /><label>Trigger<select v-model="replacement.trigger"><option value="not_fit">Not fit</option><option value="no_show">No show</option><option value="withdrawal">Withdrawal</option><option value="training_vacancy">Training vacancy</option></select></label><label>Reason<textarea v-model="replacement.reason" minlength="20" required /></label><button class="button primary full" :disabled="busy === 'replacement' || !replacement.replaced_application_id || !replacement.selection_run_id">Recommend strict-order reserve</button></form>
    <form v-else-if="activeAction === 'replacement-decision'" @submit.prevent="act('replacement-decision', `/training/replacement-recommendations/${replacementDecision.recommendation_id}/decision`, 'POST', { decision: replacementDecision.decision, reason: replacementDecision.reason, approval_reference: replacementDecision.approval_reference }, `Reserve replacement ${replacementDecision.decision === 'approve' ? 'approved' : 'rejected'} by an independent authority.`)"><FloatingCombobox label="Pending recommendation" :model-value="replacementDecision.recommendation_label" :options="recommendationOptions" required placeholder="Search replaced or reserve candidate" @update:model-value="clearSelection(replacementDecision, 'recommendation_id', 'recommendation_label', $event)" @select="recordSelection(replacementDecision, 'recommendation_id', 'recommendation_label', $event)" /><label>Decision<select v-model="replacementDecision.decision"><option value="approve">Approve</option><option value="reject">Reject</option></select></label><label>Decision reason<textarea v-model="replacementDecision.reason" minlength="20" required /></label><label>Approval reference<input v-model="replacementDecision.approval_reference" required /></label><button class="button primary full" :disabled="busy === 'replacement-decision' || !replacementDecision.recommendation_id">Record independent decision</button></form>
  </Dialog>
</template>
