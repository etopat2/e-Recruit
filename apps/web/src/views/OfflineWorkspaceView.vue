<script setup lang="ts">
import { liveQuery } from 'dexie'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'
import { configureOfflineUnlock, getOfflinePackage, lockOfflineData, offlineDb, offlineUnlockState, openOfflineValue, putOfflinePackage, sealOfflineValue, unlockOfflineData, type OfflineEvent } from '../offline/database'

type PackType = 'score_capture' | 'attendance' | 'hard_copy' | 'verification' | 'medical' | 'panel_closure'
interface PackRecord { entity_type: string; entity_id: string; server_version: number; payload: Record<string, unknown> }
interface PackInfo { id: string; pack_type: PackType; manifest_fingerprint: string; expires_at: string; status: string; manifest: Record<string, unknown> }
interface Conflict { id: string; entity_id: string; field_key: string; status: string; local_value: unknown; server_value: unknown }
interface PackPayload { package: PackInfo; server_records: PackRecord[]; server_time: string; conflicts?: Conflict[] }

const definitions: Record<PackType, { label: string; action: string; entityType: string }> = {
  score_capture: { label: 'Assessment scoring', action: 'ASSESSMENT_SCORE_RECORDED', entityType: 'assessment_score' },
  attendance: { label: 'Interview attendance', action: 'ATTENDANCE_RECORDED', entityType: 'interview_assignment' },
  hard_copy: { label: 'Hard-copy reception', action: 'HARDCOPY_RECEIPT_RECORDED', entityType: 'application' },
  verification: { label: 'Document verification', action: 'DOCUMENT_VERIFICATION_RECORDED', entityType: 'document' },
  medical: { label: 'Medical results', action: 'MEDICAL_RESULT_RECORDED', entityType: 'application' },
  panel_closure: { label: 'Panel closure', action: 'PANEL_CLOSED', entityType: 'panel' },
}

const deviceId = ref(localStorage.getItem('ups_device_id') || crypto.randomUUID())
const deviceRecordId = ref(localStorage.getItem('ups_device_record_id') || '')
const packageId = ref(localStorage.getItem('ups_package_id') || '')
const events = ref<OfflineEvent[]>([])
const conflicts = ref<Conflict[]>([])
const notice = ref(''); const error = ref(''); const syncBusy = ref(false); const completePack = ref(false)
const packPayload = ref<PackPayload | null>(null)
const online = ref(window.navigator.onLine)
const lastSync = ref(localStorage.getItem('ups_last_sync') || '')
const unlock = reactive({ configured: false, unlocked: false, pin: '', confirmation: '' })
const conflictReason = ref('')
const provision = reactive({ packType: 'score_capture' as PackType, entityIds: '', scopeJson: '{}' })
const capture = reactive({
  entityId: '', score: 0, notes: '', attendanceStatus: 'present', receivingOffice: '', receivedAt: new Date().toISOString().slice(0, 16),
  hardCopyItems: '[{"document_type":"national_id","status":"Match"}]', fieldKey: '', verificationAction: 'verify', verificationOutcome: 'VERIFIED/CONSISTENT',
  verifiedValue: '', evidenceReferences: '[]', medicalOutcome: 'Fit', clinicalReference: '', confirmation: false,
})
const activeEvents = computed(() => events.value.filter((event) => event.packageId === packageId.value))
const selectedRecord = computed(() => packPayload.value?.server_records.find((record) => record.entity_id === capture.entityId))
const definition = computed(() => definitions[packPayload.value?.package.pack_type || provision.packType])
const subscription = liveQuery(() => offlineDb.events.orderBy('local_sequence').toArray()).subscribe((items) => { events.value = items })
const updateOnline = () => { online.value = window.navigator.onLine }
let inactivityTimer: number | undefined
const activityEvents = ['pointerdown', 'keydown', 'touchstart'] as const

watch(() => provision.packType, (packType) => { provision.scopeJson = packType === 'medical' ? '{"medical_schedule_id":""}' : '{}' })
watch(packPayload, (payload) => {
  if (payload?.server_records.length && !payload.server_records.some((record) => record.entity_id === capture.entityId)) capture.entityId = payload.server_records[0].entity_id
})

onBeforeUnmount(() => {
  subscription.unsubscribe(); window.removeEventListener('online', updateOnline); window.removeEventListener('offline', updateOnline)
  for (const event of activityEvents) window.removeEventListener(event, resetInactivity)
  if (inactivityTimer) window.clearTimeout(inactivityTimer)
  lockOfflineData()
})
onMounted(async () => {
  localStorage.setItem('ups_device_id', deviceId.value)
  window.addEventListener('online', updateOnline); window.addEventListener('offline', updateOnline)
  const state = await offlineUnlockState(); unlock.configured = state.configured; unlock.unlocked = state.unlocked
  if (!unlock.unlocked) return
  await loadCachedPack()
  startInactivityLock()
})

async function loadCachedPack() {
  if (!packageId.value) return
  try {
    const cached = await getOfflinePackage<PackPayload>(packageId.value)
    if (cached && new Date(cached.expiresAt) > new Date()) packPayload.value = cached.payload
    else if (cached) await purgePack('The offline pack expired and its encrypted local records were removed.')
  } catch { error.value = 'The encrypted offline pack could not be opened on this device.' }
}

async function configureUnlock() {
  resetMessages()
  if (unlock.pin !== unlock.confirmation) { error.value = 'Offline PIN confirmation does not match.'; return }
  try {
    await configureOfflineUnlock(unlock.pin); unlock.configured = true; unlock.unlocked = true; unlock.pin = ''; unlock.confirmation = ''
    notice.value = 'Offline encryption configured. The PIN is never stored and cannot be recovered by the browser.'; startInactivityLock()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Offline encryption setup failed.' }
}

async function unlockWorkspace() {
  resetMessages()
  try {
    await unlockOfflineData(unlock.pin); unlock.unlocked = true; unlock.pin = ''; await loadCachedPack(); startInactivityLock()
    notice.value = 'Protected offline workspace unlocked for this session.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Offline unlock failed.' }
}

function startInactivityLock() {
  for (const event of activityEvents) { window.removeEventListener(event, resetInactivity); window.addEventListener(event, resetInactivity, { passive: true }) }
  resetInactivity()
}

function resetInactivity() {
  if (!unlock.unlocked) return
  if (inactivityTimer) window.clearTimeout(inactivityTimer)
  inactivityTimer = window.setTimeout(() => {
    lockOfflineData(); unlock.unlocked = false; packPayload.value = null; unlock.pin = ''
    notice.value = 'Field mode locked after 15 minutes of inactivity. Local ciphertext remains protected.'
  }, 15 * 60 * 1000)
}

function resetMessages() { notice.value = ''; error.value = '' }

async function register() {
  resetMessages()
  try {
    const response = await api<{ device: { id: string } }>('/offline/devices', { method: 'POST', ...jsonBody({ public_identifier: deviceId.value, label: 'Browser field device', platform: navigator.platform }) })
    deviceRecordId.value = response.device.id; localStorage.setItem('ups_device_record_id', response.device.id)
    notice.value = 'Device registered and bound to this account.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Device registration failed.' }
}

async function issue() {
  resetMessages()
  try {
    const ids = provision.entityIds.split(',').map((item) => item.trim()).filter(Boolean)
    const scope = JSON.parse(provision.scopeJson) as Record<string, unknown>
    if (!ids.length) throw new Error('Provide at least one entity ID for this controlled pack.')
    const selected = definitions[provision.packType]
    const response = await api<PackPayload>('/offline/packages', { method: 'POST', ...jsonBody({ registered_device_id: deviceRecordId.value, pack_type: provision.packType, scope, permitted_actions: [selected.action], entity_ids: ids, expiry_hours: 24 }) })
    packageId.value = response.package.id; packPayload.value = response; conflicts.value = []
    await putOfflinePackage({ id: response.package.id, manifestFingerprint: response.package.manifest_fingerprint, expiresAt: response.package.expires_at }, response)
    localStorage.setItem('ups_package_id', packageId.value)
    notice.value = `${selected.label} pack encrypted for this browser with a 24-hour expiry.`
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Pack issue failed.' }
}

function eventPayload(): Record<string, unknown> {
  switch (definition.value.action) {
    case 'ASSESSMENT_SCORE_RECORDED': return { score: capture.score, notes: capture.notes || undefined }
    case 'ATTENDANCE_RECORDED': return { status: capture.attendanceStatus, notes: capture.notes || undefined }
    case 'HARDCOPY_RECEIPT_RECORDED': return { receiving_office: capture.receivingOffice, received_at: new Date(capture.receivedAt).toISOString(), notes: capture.notes || undefined, items: JSON.parse(capture.hardCopyItems) }
    case 'DOCUMENT_VERIFICATION_RECORDED': return { field_key: capture.fieldKey, action: capture.verificationAction, outcome: capture.verificationOutcome, verified_value: capture.verifiedValue, evidence_references: JSON.parse(capture.evidenceReferences), reason: capture.notes || undefined }
    case 'MEDICAL_RESULT_RECORDED': return { outcome: capture.medicalOutcome, restricted_notes: capture.notes || undefined, clinical_reference: capture.clinicalReference || undefined }
    default: return { confirmation: capture.confirmation }
  }
}

async function queueEvent() {
  resetMessages()
  if (!packageId.value || !capture.entityId || !selectedRecord.value) { error.value = 'Select an entity contained in this pack.'; return }
  try {
    const sequence = Math.max(0, ...events.value.map((event) => event.local_sequence)) + 1
    await offlineDb.events.add({
      id: crypto.randomUUID(), packageId: packageId.value, entity_type: definition.value.entityType, entity_id: capture.entityId,
      action_type: definition.value.action, payload_schema_version: 1, sealedPayload: await sealOfflineValue(eventPayload()),
      base_entity_version: selectedRecord.value.server_version, local_sequence: sequence, local_timestamp: new Date().toISOString(), state: 'pending',
    })
    notice.value = 'Event encrypted locally. It is not final until the server acknowledges it.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'The event payload is invalid.' }
}

async function pullChanges() {
  resetMessages()
  if (!packageId.value) return
  try {
    const response = await api<{ package_status: string; server_records: PackRecord[]; conflicts: Conflict[]; server_cursor: string }>(`/offline/packages/${packageId.value}/changes`)
    conflicts.value = response.conflicts
    if (packPayload.value) {
      packPayload.value.server_records = response.server_records
      packPayload.value.package.status = response.package_status
      await putOfflinePackage({ id: packPayload.value.package.id, manifestFingerprint: packPayload.value.package.manifest_fingerprint, expiresAt: packPayload.value.package.expires_at }, packPayload.value)
    }
    notice.value = `Pulled ${response.server_records.length} current server record(s) and ${response.conflicts.length} conflict(s).`
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Server refresh failed.' }
}

async function resolveConflict(item: Conflict, resolution: 'keep_server' | 'accept_local') {
  resetMessages()
  if (conflictReason.value.trim().length < 10) { error.value = 'Provide a conflict-resolution reason of at least 10 characters.'; return }
  try {
    await api(`/offline/conflicts/${item.id}/resolve`, { method: 'POST', ...jsonBody({ resolution, reason: conflictReason.value.trim() }) })
    conflictReason.value = ''
    await pullChanges()
    notice.value = `Conflict resolved by ${resolution === 'keep_server' ? 'retaining the current server value' : 'accepting the reviewed local value'}.`
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Conflict resolution failed.' }
}

async function sync() {
  syncBusy.value = true; resetMessages()
  try {
    const pending = activeEvents.value.filter((event) => event.state === 'pending')
    const eventsToSend = await Promise.all(pending.map(async (event) => ({
      id: event.id, entity_type: event.entity_type, entity_id: event.entity_id, action_type: event.action_type,
      payload_schema_version: event.payload_schema_version, payload: event.payload || (event.sealedPayload ? await openOfflineValue<Record<string, unknown>>(event.sealedPayload) : {}),
      base_entity_version: event.base_entity_version, local_sequence: event.local_sequence, local_timestamp: event.local_timestamp,
    })))
    const response = await api<{ acknowledgements: Array<{ event_id: string; state: OfflineEvent['state']; error?: string }>; package_status: string }>(`/offline/packages/${packageId.value}/sync`, {
      method: 'POST', ...jsonBody({ events: eventsToSend, client_pending_count: 0, last_local_sequence: Math.max(0, ...pending.map((event) => event.local_sequence)), complete: completePack.value }),
    })
    for (const acknowledgement of response.acknowledgements) await offlineDb.events.update(acknowledgement.event_id, { state: acknowledgement.state, error: acknowledgement.error })
    lastSync.value = new Date().toISOString(); localStorage.setItem('ups_last_sync', lastSync.value)
    if (response.package_status === 'reconciled') await purgePack('Every event was acknowledged. The reconciled encrypted pack was purged from this browser.')
    else { notice.value = 'Server acknowledgements applied. Pull changes to inspect any blocked conflicts.'; await pullChanges() }
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Sync failed; local events remain queued.' }
  finally { syncBusy.value = false }
}

async function revokePack() {
  resetMessages()
  if (!packageId.value || activeEvents.value.some((event) => event.state === 'pending')) { error.value = 'Synchronise or resolve pending events before revoking this local pack.'; return }
  try {
    await api(`/offline/packages/${packageId.value}/revoke`, { method: 'POST', ...jsonBody({ reason: 'Field assignment completed; revoke and purge the device copy.' }) })
    await purgePack('Pack revoked on the server and purged from this browser.')
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Pack revocation failed.' }
}

async function purgePack(message: string) {
  const id = packageId.value
  if (id) { await offlineDb.events.where('packageId').equals(id).delete(); await offlineDb.packages.delete(id) }
  packageId.value = ''; packPayload.value = null; conflicts.value = []; completePack.value = false
  localStorage.removeItem('ups_package_id'); notice.value = message
}
</script>

<template>
  <section class="page-heading">
    <p class="eyebrow">Controlled field operation</p><h1>Offline workspace</h1>
    <p>Offline packs are user-, device-, role-, scope-, action-, and time-bound. A browser cache is never a general replica.</p>
  </section>
  <div v-if="notice" class="alert success page-alert">{{ notice }}</div>
  <div v-if="error" class="alert error page-alert">{{ error }}</div>

  <section v-if="!unlock.unlocked" class="configuration-layout compact-top">
    <form v-if="!unlock.configured" class="form-panel" @submit.prevent="configureUnlock">
      <p class="eyebrow">Local data protection</p><h2>Protect offline field data</h2>
      <p>Create a private 6–12 digit PIN. It wraps the field-pack encryption key and is never stored or sent to the server. If it is lost, the local pack cannot be recovered; revoke it from an authorised online workstation.</p>
      <label>Offline PIN<input v-model="unlock.pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" required /></label>
      <label>Confirm offline PIN<input v-model="unlock.confirmation" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" required /></label>
      <button class="button primary full">Configure and unlock</button>
    </form>
    <form v-else class="form-panel" @submit.prevent="unlockWorkspace">
      <p class="eyebrow">Protected field mode</p><h2>Unlock this offline workspace</h2>
      <p>The field-pack key is held only in memory. Reload, logout, or 15 minutes of inactivity locks protected records again.</p>
      <label>Offline PIN<input v-model="unlock.pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="current-password" required autofocus /></label>
      <button class="button primary full">Unlock field mode</button>
    </form>
    <aside class="notice"><strong>Recovery boundary</strong><p>Neither the browser nor the server stores this PIN. An authorised supervisor can revoke the server-side pack, but cannot decrypt an abandoned browser cache.</p></aside>
  </section>

  <template v-else>
    <section class="offline-grid">
      <article class="form-panel">
        <h2>1. Bind and provision</h2>
        <p><StatusBadge :status="online ? 'online' : 'offline'" /> {{ online ? 'Online' : 'Offline' }} · Last successful sync {{ lastSync ? new Date(lastSync).toLocaleString() : 'not yet completed' }}</p>
        <label>Device UUID<input v-model="deviceId" readonly /></label>
        <button v-if="!deviceRecordId" class="button secondary" @click="register">Register this device</button>
        <template v-else-if="!packageId">
          <label>Pack purpose<select v-model="provision.packType"><option v-for="(item, key) in definitions" :key="key" :value="key">{{ item.label }}</option></select></label>
          <label>Scoped entity IDs<textarea v-model="provision.entityIds" placeholder="One or more IDs, comma-separated" /></label>
          <label>Scope (JSON)<textarea v-model="provision.scopeJson" rows="3" /></label>
          <button class="button secondary" :disabled="!online" @click="issue">Issue scoped pack</button>
        </template>
        <template v-else>
          <p class="mono-note">Pack {{ packageId }}</p>
          <p><StatusBadge :status="packPayload?.package.status || 'active'" /> {{ definition.label }} · {{ packPayload?.server_records.length || 0 }} record(s)</p>
          <p>Expires {{ new Date(packPayload?.package.expires_at || '').toLocaleString() }}</p>
          <div class="button-row"><button class="button secondary compact" :disabled="!online" @click="pullChanges">Pull changes</button><button class="button danger compact" :disabled="!online" @click="revokePack">Revoke pack</button></div>
        </template>
      </article>

      <article class="form-panel">
        <h2>2. Capture {{ definition.label.toLowerCase() }}</h2>
        <label>Pack record<select v-model="capture.entityId" :disabled="!packageId"><option v-for="record in packPayload?.server_records" :key="record.entity_id" :value="record.entity_id">{{ record.payload.application_reference || record.payload.panel_code || record.entity_id }} · v{{ record.server_version }}</option></select></label>
        <div v-if="definition.action === 'ASSESSMENT_SCORE_RECORDED'" class="field-grid"><label>Score<input v-model="capture.score" type="number" min="0" /></label><label class="wide">Notes<textarea v-model="capture.notes" /></label></div>
        <div v-else-if="definition.action === 'ATTENDANCE_RECORDED'" class="field-grid"><label>Status<select v-model="capture.attendanceStatus"><option v-for="status in ['present','late','absent','referred','disqualified','excused','no_show']" :key="status">{{ status }}</option></select></label><label class="wide">Exception notes<textarea v-model="capture.notes" /></label></div>
        <div v-else-if="definition.action === 'HARDCOPY_RECEIPT_RECORDED'" class="field-grid"><label>Receiving office<input v-model="capture.receivingOffice" /></label><label>Received at<input v-model="capture.receivedAt" type="datetime-local" /></label><label class="wide">Document checks (JSON)<textarea v-model="capture.hardCopyItems" rows="5" /></label><label class="wide">Notes<textarea v-model="capture.notes" /></label></div>
        <div v-else-if="definition.action === 'DOCUMENT_VERIFICATION_RECORDED'" class="field-grid"><label>Field key<input v-model="capture.fieldKey" /></label><label>Action<select v-model="capture.verificationAction"><option v-for="action in ['verify','flag_discrepancy','correct','mark_ocr_incorrect','request_replacement','mark_unreadable','mark_not_present']" :key="action">{{ action }}</option></select></label><label>Outcome<select v-model="capture.verificationOutcome"><option v-for="outcome in ['VERIFIED/CONSISTENT','PROBABLE MATCH','DISCREPANCY','UNREADABLE/LOW CONFIDENCE','NOT AVAILABLE']" :key="outcome">{{ outcome }}</option></select></label><label>Verified value<input v-model="capture.verifiedValue" /></label><label class="wide">Evidence references (JSON)<textarea v-model="capture.evidenceReferences" /></label><label class="wide">Reason<textarea v-model="capture.notes" /></label></div>
        <div v-else-if="definition.action === 'MEDICAL_RESULT_RECORDED'" class="field-grid"><label>Outcome<select v-model="capture.medicalOutcome"><option v-for="outcome in ['Fit','Not Fit','Deferred','Further Assessment Required','No Show']" :key="outcome">{{ outcome }}</option></select></label><label>Clinical reference<input v-model="capture.clinicalReference" /></label><label class="wide">Restricted notes<textarea v-model="capture.notes" /></label></div>
        <label v-else class="checkbox"><input v-model="capture.confirmation" type="checkbox" /> <span>I confirm all panel assessment data is complete and ready to be made immutable.</span></label>
        <div v-if="selectedRecord" class="notice"><strong>Server snapshot v{{ selectedRecord.server_version }}</strong><pre>{{ JSON.stringify(selectedRecord.payload, null, 2) }}</pre></div>
        <button class="button primary" :disabled="!packageId" @click="queueEvent">Queue encrypted event</button>
      </article>
    </section>

    <section class="content-section compact-top">
      <div class="section-heading"><div><h2>Event outbox</h2><p>{{ activeEvents.filter((event) => event.state === 'pending').length }} awaiting acknowledgement · {{ activeEvents.filter((event) => event.state === 'rejected').length }} rejected · {{ conflicts.filter((item) => item.status === 'open').length }} open conflict(s)</p></div><div><label class="checkbox compact"><input v-model="completePack" type="checkbox" /> <span>Final sync: reconcile and purge</span></label><button class="button primary" :disabled="syncBusy || !online || !activeEvents.some((event) => event.state === 'pending')" @click="sync">{{ syncBusy ? 'Synchronising…' : 'Synchronise now' }}</button></div></div>
      <div class="table-wrap"><table><thead><tr><th>Sequence</th><th>Event UUID</th><th>Action</th><th>Entity</th><th>State</th></tr></thead><tbody><tr v-for="event in activeEvents" :key="event.id"><td>{{ event.local_sequence }}</td><td><code>{{ event.id }}</code></td><td>{{ event.action_type }}</td><td>{{ event.entity_id }}</td><td><StatusBadge :status="event.state" /><small v-if="event.error">{{ event.error }}</small></td></tr><tr v-if="!activeEvents.length"><td colspan="5">No local events for this pack.</td></tr></tbody></table></div>
      <div v-if="conflicts.length" class="notice"><strong>Supervisor resolution required</strong><label>Resolution reason<textarea v-model="conflictReason" minlength="10" placeholder="Document the evidence and authority for this decision." /></label><article v-for="item in conflicts" :key="item.id"><p>{{ item.field_key }} on {{ item.entity_id }}: local {{ JSON.stringify(item.local_value) }}, server {{ JSON.stringify(item.server_value) }}</p><div class="button-row"><button class="button secondary compact" :disabled="!online" @click="resolveConflict(item, 'keep_server')">Keep server value</button><button class="button compact" :disabled="!online" @click="resolveConflict(item, 'accept_local')">Accept reviewed local value</button></div></article></div>
    </section>
  </template>
</template>
