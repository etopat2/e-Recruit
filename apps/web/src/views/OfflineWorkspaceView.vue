<script setup lang="ts">
import { liveQuery } from 'dexie'
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'
import { getOfflinePackage, offlineDb, openValue, putOfflinePackage, sealValue, type OfflineEvent } from '../offline/database'

interface PackRecord { entity_type: string; entity_id: string; server_version: number; payload: Record<string, unknown> }
interface PackPayload { package: { id: string; manifest_fingerprint: string; expires_at: string }; server_records: PackRecord[]; server_time: string }

const deviceId = ref(localStorage.getItem('ups_device_id') || crypto.randomUUID())
const deviceRecordId = ref(localStorage.getItem('ups_device_record_id') || '')
const packageId = ref(localStorage.getItem('ups_package_id') || '')
const events = ref<OfflineEvent[]>([])
const notice = ref(''); const error = ref(''); const syncBusy = ref(false); const completePack = ref(false)
const packPayload = ref<PackPayload | null>(null)
const online = ref(window.navigator.onLine)
const capture = reactive({ score_id: '', score: 0, base_version: 1, notes: '' })
const subscription = liveQuery(() => offlineDb.events.orderBy('local_sequence').toArray()).subscribe((items) => { events.value = items })
const updateOnline = () => { online.value = window.navigator.onLine }

onBeforeUnmount(() => { subscription.unsubscribe(); window.removeEventListener('online', updateOnline); window.removeEventListener('offline', updateOnline) })
onMounted(async () => {
  localStorage.setItem('ups_device_id', deviceId.value)
  window.addEventListener('online', updateOnline); window.addEventListener('offline', updateOnline)
  if (!packageId.value) return
  try {
    const cached = await getOfflinePackage<PackPayload>(packageId.value)
    if (cached && new Date(cached.expiresAt) > new Date()) packPayload.value = cached.payload
    else if (cached) {
      await offlineDb.packages.delete(cached.id); packageId.value = ''; localStorage.removeItem('ups_package_id')
      error.value = 'The offline pack expired and was removed from this device.'
    }
  } catch { error.value = 'The encrypted offline pack could not be opened on this device.' }
})

async function register() {
  try {
    const response = await api<{ device: { id: string } }>('/offline/devices', { method: 'POST', ...jsonBody({ public_identifier: deviceId.value, label: 'Browser field device', platform: navigator.platform }) })
    deviceRecordId.value = response.device.id; localStorage.setItem('ups_device_record_id', response.device.id)
    notice.value = 'Device registered and bound to this account.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Device registration failed.' }
}

async function issue() {
  try {
    const ids = capture.score_id.split(',').map((item) => item.trim()).filter(Boolean)
    const response = await api<PackPayload>('/offline/packages', { method: 'POST', ...jsonBody({ registered_device_id: deviceRecordId.value, pack_type: 'score_capture', scope: { device: deviceId.value }, permitted_actions: ['ASSESSMENT_SCORE_RECORDED'], entity_ids: ids, expiry_hours: 24 }) })
    packageId.value = response.package.id; packPayload.value = response
    await putOfflinePackage({ id: response.package.id, manifestFingerprint: response.package.manifest_fingerprint, expiresAt: response.package.expires_at }, response)
    localStorage.setItem('ups_package_id', packageId.value)
    notice.value = 'Scoped pack encrypted for this browser with a 24-hour expiry.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Pack issue failed.' }
}

async function queueScore() {
  if (!packageId.value || !capture.score_id) return
  const source = packPayload.value?.server_records.find((record) => record.entity_id === capture.score_id)
  if (!source) { error.value = 'That score record is outside this encrypted pack.'; return }
  const sequence = (await offlineDb.events.count()) + 1
  await offlineDb.events.add({
    id: crypto.randomUUID(), packageId: packageId.value, entity_type: 'assessment_score', entity_id: capture.score_id,
    action_type: 'ASSESSMENT_SCORE_RECORDED', payload_schema_version: 1,
    sealedPayload: await sealValue({ score: capture.score, notes: capture.notes }), base_entity_version: source.server_version,
    local_sequence: sequence, local_timestamp: new Date().toISOString(), state: 'pending',
  })
  notice.value = 'Score encrypted locally. It is not final until synchronised and acknowledged.'
}

async function sync() {
  syncBusy.value = true; error.value = ''
  try {
    const pending = await offlineDb.events.where('state').equals('pending').toArray()
    const eventsToSend = await Promise.all(pending.map(async (event) => ({
      id: event.id,
      entity_type: event.entity_type,
      entity_id: event.entity_id,
      action_type: event.action_type,
      payload_schema_version: event.payload_schema_version,
      payload: event.payload || (event.sealedPayload ? await openValue<Record<string, unknown>>(event.sealedPayload) : {}),
      base_entity_version: event.base_entity_version,
      local_sequence: event.local_sequence,
      local_timestamp: event.local_timestamp,
    })))
    const response = await api<{ acknowledgements: Array<{ event_id: string; state: OfflineEvent['state']; error?: string }>; package_status: string }>(`/offline/packages/${packageId.value}/sync`, {
      method: 'POST',
      ...jsonBody({ events: eventsToSend, client_pending_count: 0, last_local_sequence: Math.max(0, ...pending.map((event) => event.local_sequence)), complete: completePack.value }),
    })
    for (const acknowledgement of response.acknowledgements) await offlineDb.events.update(acknowledgement.event_id, { state: acknowledgement.state, error: acknowledgement.error })
    notice.value = response.package_status === 'reconciled'
      ? 'Every event was acknowledged and this pack is reconciled.'
      : 'Server acknowledgements applied. Conflicts remain visibly blocked for supervisor resolution.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Sync failed; local events remain queued.' }
  finally { syncBusy.value = false }
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled field operation</p><h1>Offline workspace</h1><p>Offline packs are user-, device-, role-, scope-, action-, and time-bound. A browser cache is never a general replica.</p></section>
  <div v-if="notice" class="alert success page-alert">{{ notice }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="offline-grid">
    <article class="form-panel"><h2>1. Bind and provision</h2><label>Device UUID<input v-model="deviceId" readonly /></label><button v-if="!deviceRecordId" class="button secondary" @click="register">Register this device</button><template v-else><label>Assessment score record ID(s)<textarea v-model="capture.score_id" placeholder="One or more IDs, comma-separated" /></label><button v-if="!packageId" class="button secondary" @click="issue">Issue scoped pack</button><div v-else><p class="mono-note">Pack {{ packageId }}</p><p>{{ packPayload?.server_records.length || 0 }} scoped record(s) available offline.</p></div></template></article>
    <article class="form-panel"><h2>2. Capture score locally</h2><label>Score record ID<input v-model="capture.score_id" /></label><div class="field-grid"><label>Score<input v-model="capture.score" type="number" /></label><label>Base version<input v-model="capture.base_version" type="number" min="1" readonly /></label><label class="wide">Notes<textarea v-model="capture.notes" /></label></div><button class="button primary" :disabled="!packageId" @click="queueScore">Queue offline event</button></article>
  </section>
  <section class="content-section compact-top"><div class="section-heading"><div><h2>Event outbox</h2><p>{{ events.filter((event) => event.state === 'pending').length }} awaiting acknowledgement</p></div><div><label class="checkbox compact"><input v-model="completePack" type="checkbox" /> <span>Final sync: reconcile this pack</span></label><button class="button primary" :disabled="syncBusy || !online || !events.some((event) => event.state === 'pending')" @click="sync">{{ syncBusy ? 'Synchronising…' : 'Synchronise now' }}</button></div></div><div class="table-wrap"><table><thead><tr><th>Sequence</th><th>Event UUID</th><th>Entity</th><th>State</th></tr></thead><tbody><tr v-for="event in events" :key="event.id"><td>{{ event.local_sequence }}</td><td><code>{{ event.id }}</code></td><td>{{ event.entity_id }}</td><td><StatusBadge :status="event.state" /><small v-if="event.error">{{ event.error }}</small></td></tr></tbody></table></div></section>
</template>
