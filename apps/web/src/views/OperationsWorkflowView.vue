<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, jsonBody } from '../lib/api'

const notice = ref('')
const error = ref('')
const busy = ref('')

const hardCopy = reactive({ application_id: '', receiving_office: '', received_at: new Date().toISOString().slice(0, 16), notes: '', items: '[{"document_type":"national_id","status":"Match"}]' })
const scheduling = reactive({ post_id: '', centre_session_id: '', application_ids: '', panel_ids: '', algorithm_version: 'round-robin-v1' })
const attendance = reactive({ assignment_id: '', status: 'present', exception_reason: '' })
const panel = reactive({ panel_id: '', confirmation: false })
const medicalSchedule = reactive({ recruitment_post_id: '', facility: '', scheduled_date: '', reporting_time: '08:00', capacity: 100 })
const medicalResult = reactive({ application_id: '', medical_schedule_id: '', outcome: 'Fit', clinical_reference: '', restricted_notes: '' })
const finalSelection = reactive({ selection_outcome_id: '', medical_result_id: '', approval_reference: '', confirmation: false })
const trainingInvite = reactive({ final_selection_id: '', reporting_date: '', reporting_time: '08:00', location: '', instructions: '["Bring the official invitation and required originals."]' })
const trainingReport = reactive({ training_invite_id: '', status: 'reported', notes: '' })
const replacement = reactive({ replaced_application_id: '', selection_run_id: '', trigger: 'training_vacancy', reason: '' })
const replacementDecision = reactive({ recommendation_id: '', decision: 'approve', reason: '', approval_reference: '' })

function ids(value: string): string[] {
  return value.split(',').map((item) => item.trim()).filter(Boolean)
}

function jsonArray(value: string, label: string): unknown[] | null {
  try {
    const parsed: unknown = JSON.parse(value)
    if (Array.isArray(parsed)) return parsed
  } catch {
    // The actionable validation message is set below.
  }
  notice.value = ''
  error.value = `${label} must be a valid JSON array.`
  return null
}

async function act(name: string, path: string, method: 'POST' | 'PUT', payload: unknown, success: string) {
  busy.value = name; notice.value = ''; error.value = ''
  try {
    await api(path, { method, ...jsonBody(payload) })
    notice.value = success
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'The operational action failed.'
  } finally { busy.value = '' }
}

function recordHardCopy() {
  const items = jsonArray(hardCopy.items, 'Document checks')
  if (!items) return
  return act('hard-copy', `/applications/${hardCopy.application_id}/hard-copy-receipts`, 'POST', {
    receiving_office: hardCopy.receiving_office, received_at: new Date(hardCopy.received_at).toISOString(), notes: hardCopy.notes || undefined, items,
  }, 'Hard-copy receipt recorded with a traceable receipt number.')
}

function scheduleCandidates() {
  return act('schedule', `/posts/${scheduling.post_id}/interview-assignments`, 'POST', {
    centre_session_id: scheduling.centre_session_id, application_ids: ids(scheduling.application_ids), panel_ids: ids(scheduling.panel_ids), algorithm_version: scheduling.algorithm_version,
  }, 'Candidates assigned deterministically; the input fingerprint is retained by the server.')
}

function recordAttendance() {
  return act('attendance', `/interview-assignments/${attendance.assignment_id}/attendance`, 'PUT', { status: attendance.status, exception_reason: attendance.exception_reason || undefined }, 'Attendance recorded and audited.')
}

function closePanel() {
  return act('panel', `/panels/${panel.panel_id}/close`, 'POST', { confirmation: panel.confirmation }, 'Panel closed; submitted scores are now fingerprinted and immutable.')
}

function createMedicalSchedule() {
  return act('medical-schedule', '/medical/schedules', 'POST', medicalSchedule, 'Medical schedule created inside the authorised post scope.')
}

function recordMedicalResult() {
  return act('medical-result', '/medical/results', 'POST', medicalResult, 'Restricted medical result recorded. Only the outcome is visible outside the medical role.')
}

function approveFinalSelection() {
  return act('final-selection', '/final-selections', 'POST', finalSelection, 'Fit candidate approved against the certified selection outcome.')
}

function issueTrainingInvite() {
  const instructions = jsonArray(trainingInvite.instructions, 'Instructions')
  if (!instructions) return
  return act('training-invite', '/training/invitations', 'POST', { ...trainingInvite, instructions }, 'Training invitation issued with a protected verifiable PDF.')
}

function recordTrainingReport() {
  return act('training-report', '/training/reporting', 'POST', trainingReport, 'Training reporting status recorded and audited.')
}

function recommendReplacement() {
  return act('replacement', '/training/replacement-recommendations', 'POST', replacement, 'Strict-order reserve replacement recommended for independent approval.')
}

function decideReplacement() {
  const id = replacementDecision.recommendation_id
  return act('replacement-decision', `/training/replacement-recommendations/${id}/decision`, 'POST', {
    decision: replacementDecision.decision, reason: replacementDecision.reason, approval_reference: replacementDecision.approval_reference,
  }, `Reserve replacement ${replacementDecision.decision === 'approve' ? 'approved' : 'rejected'} by an independent authority.`)
}
</script>

<template>
  <section class="page-heading">
    <p class="eyebrow">Controlled recruitment operations</p>
    <h1>Centre, medical, and training workflows</h1>
    <p>Each action is authorised and scoped again by the server. Identifiers come from approved campaign registers and operational reports.</p>
  </section>
  <div v-if="notice" class="alert success page-alert" role="status">{{ notice }}</div>
  <div v-if="error" class="alert error page-alert" role="alert">{{ error }}</div>

  <section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">Before interview</p><h2>Hard copies and scheduling</h2></div></div>
    <div class="configuration-layout">
      <form class="form-panel" @submit.prevent="recordHardCopy"><h3>Record hard-copy receipt</h3><label>Application ID<input v-model="hardCopy.application_id" required /></label><label>Receiving office<input v-model="hardCopy.receiving_office" required /></label><label>Received at<input v-model="hardCopy.received_at" type="datetime-local" required /></label><label>Document checks (JSON)<textarea v-model="hardCopy.items" rows="4" required /></label><label>Receipt notes<textarea v-model="hardCopy.notes" /></label><button class="button primary full" :disabled="busy === 'hard-copy'">Record accountable receipt</button></form>
      <form class="form-panel" @submit.prevent="scheduleCandidates"><h3>Schedule candidates</h3><label>Recruitment post ID<input v-model="scheduling.post_id" required /></label><label>Centre session ID<input v-model="scheduling.centre_session_id" required /></label><label>Application IDs (comma-separated)<textarea v-model="scheduling.application_ids" required /></label><label>Panel IDs (comma-separated)<textarea v-model="scheduling.panel_ids" required /></label><label>Algorithm version<input v-model="scheduling.algorithm_version" required /></label><button class="button primary full" :disabled="busy === 'schedule'">Generate deterministic assignments</button></form>
    </div>
  </section>

  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Interview centre</p><h2>Attendance and panel closure</h2></div></div>
    <div class="configuration-layout">
      <form class="form-panel" @submit.prevent="recordAttendance"><h3>Record attendance</h3><label>Interview assignment ID<input v-model="attendance.assignment_id" required /></label><label>Attendance status<select v-model="attendance.status"><option v-for="status in ['present','late','absent','referred','disqualified','excused','no_show']" :key="status">{{ status }}</option></select></label><label>Exception reason<textarea v-model="attendance.exception_reason" /></label><button class="button primary full" :disabled="busy === 'attendance'">Record attendance</button></form>
      <form class="form-panel" @submit.prevent="closePanel"><h3>Close panel session</h3><label>Panel ID<input v-model="panel.panel_id" required /></label><label class="checkbox"><input v-model="panel.confirmation" type="checkbox" required /><span>I confirm the panel data is complete and all field packs are reconciled.</span></label><div class="notice"><strong>Immutability gate</strong><p>Open conflicts or active packs block closure. The server fingerprints all submitted assessment data.</p></div><button class="button primary full" :disabled="busy === 'panel'">Close and fingerprint panel</button></form>
    </div>
  </section>

  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Restricted stage</p><h2>Medical and final approval</h2></div></div>
    <div class="configuration-layout">
      <form class="form-panel" @submit.prevent="createMedicalSchedule"><h3>Create medical schedule</h3><label>Recruitment post ID<input v-model="medicalSchedule.recruitment_post_id" required /></label><label>Approved facility<input v-model="medicalSchedule.facility" required /></label><div class="field-grid"><label>Date<input v-model="medicalSchedule.scheduled_date" type="date" required /></label><label>Reporting time<input v-model="medicalSchedule.reporting_time" type="time" required /></label><label>Capacity<input v-model="medicalSchedule.capacity" type="number" min="1" /></label></div><button class="button primary full" :disabled="busy === 'medical-schedule'">Create schedule</button></form>
      <form class="form-panel" @submit.prevent="recordMedicalResult"><h3>Record restricted result</h3><label>Application ID<input v-model="medicalResult.application_id" required /></label><label>Medical schedule ID<input v-model="medicalResult.medical_schedule_id" required /></label><label>Outcome<select v-model="medicalResult.outcome"><option v-for="outcome in ['Fit','Not Fit','Deferred','Further Assessment Required','No Show']" :key="outcome">{{ outcome }}</option></select></label><label>Clinical reference<input v-model="medicalResult.clinical_reference" /></label><label>Restricted medical notes<textarea v-model="medicalResult.restricted_notes" /></label><button class="button primary full" :disabled="busy === 'medical-result'">Record restricted result</button></form>
      <form class="form-panel" @submit.prevent="approveFinalSelection"><h3>Approve final selection</h3><label>Certified selection outcome ID<input v-model="finalSelection.selection_outcome_id" required /></label><label>Fit medical result ID<input v-model="finalSelection.medical_result_id" required /></label><label>Council approval reference<input v-model="finalSelection.approval_reference" required /></label><label class="checkbox"><input v-model="finalSelection.confirmation" type="checkbox" required /><span>I confirm the certified selection and Fit medical gates.</span></label><button class="button primary full" :disabled="busy === 'final-selection'">Approve final selection</button></form>
    </div>
  </section>

  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Training intake</p><h2>Invitation, reporting, and reserve control</h2></div></div>
    <div class="configuration-layout">
      <form class="form-panel" @submit.prevent="issueTrainingInvite"><h3>Issue training invitation</h3><label>Final selection ID<input v-model="trainingInvite.final_selection_id" required /></label><div class="field-grid"><label>Date<input v-model="trainingInvite.reporting_date" type="date" required /></label><label>Time<input v-model="trainingInvite.reporting_time" type="time" required /></label></div><label>Training location<input v-model="trainingInvite.location" required /></label><label>Instructions (JSON array)<textarea v-model="trainingInvite.instructions" required /></label><button class="button primary full" :disabled="busy === 'training-invite'">Issue protected invitation</button></form>
      <form class="form-panel" @submit.prevent="recordTrainingReport"><h3>Record training reporting</h3><label>Training invitation ID<input v-model="trainingReport.training_invite_id" required /></label><label>Status<select v-model="trainingReport.status"><option v-for="status in ['expected','reported','verified','admitted','late','documentation_incomplete','no_show','withdrawn','replacement','not_reported','declined']" :key="status">{{ status }}</option></select></label><label>Reporting notes<textarea v-model="trainingReport.notes" /></label><button class="button primary full" :disabled="busy === 'training-report'">Record reporting status</button></form>
      <form class="form-panel" @submit.prevent="recommendReplacement"><h3>Recommend next reserve</h3><label>Candidate being replaced - application ID<input v-model="replacement.replaced_application_id" required /></label><label>Certified selection run ID<input v-model="replacement.selection_run_id" required /></label><label>Trigger<select v-model="replacement.trigger"><option value="not_fit">Not fit</option><option value="no_show">No show</option><option value="withdrawal">Withdrawal</option><option value="training_vacancy">Training vacancy</option></select></label><label>Reason<textarea v-model="replacement.reason" minlength="20" required /></label><button class="button primary full" :disabled="busy === 'replacement'">Recommend strict-order reserve</button></form>
      <form class="form-panel" @submit.prevent="decideReplacement"><h3>Independent replacement decision</h3><label>Recommendation ID<input v-model="replacementDecision.recommendation_id" required /></label><label>Decision<select v-model="replacementDecision.decision"><option value="approve">Approve</option><option value="reject">Reject</option></select></label><label>Decision reason<textarea v-model="replacementDecision.reason" minlength="20" required /></label><label>Approval reference<input v-model="replacementDecision.approval_reference" required /></label><button class="button primary full" :disabled="busy === 'replacement-decision'">Record independent decision</button></form>
    </div>
  </section>
</template>
