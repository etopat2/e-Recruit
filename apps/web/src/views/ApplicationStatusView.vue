<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import StatusBadge from '../components/StatusBadge.vue'
import { api, authToken, jsonBody } from '../lib/api'
import type { ApplicationRecord } from '../types'

interface PortalNotification { id: string; event_code: string; status: string; read_at: string | null; created_at: string }
const route = useRoute(); const application = ref<ApplicationRecord | null>(null); const error = ref(''); const notice = ref('')
const notifications = ref<PortalNotification[]>([]); const pushConfig = ref({ enabled: false, public_key: '' }); const pushBusy = ref(false)

onMounted(async () => {
  try {
    const [record, inbox, config] = await Promise.all([
      api<{ data: ApplicationRecord }>(`/applications/${route.params.id}`),
      api<{ notifications: { data: PortalNotification[] } }>('/notifications'),
      api<{ enabled: boolean; public_key: string }>('/notifications/push/config'),
    ])
    application.value = record.data; notifications.value = inbox.notifications.data; pushConfig.value = config
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Record unavailable.' }
})

async function downloadAcknowledgement() {
  const response = await fetch(`/api/v1/applications/${route.params.id}/acknowledgement`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Acknowledgement is not available.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `${application.value?.reference || 'UPS'}-acknowledgement.pdf`; link.click(); URL.revokeObjectURL(link.href)
}

async function enablePush() {
  pushBusy.value = true; error.value = ''
  try {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error('This browser does not support secure push notifications.')
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') throw new Error('Notification permission was not granted.')
    const registration = await navigator.serviceWorker.ready
    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey(pushConfig.value.public_key) })
    const serialised = subscription.toJSON()
    await api('/notifications/push/subscriptions', { method: 'POST', ...jsonBody({ endpoint: subscription.endpoint, keys: serialised.keys, content_encoding: 'aes128gcm' }) })
    notice.value = 'Push notifications enabled for this browser. Sensitive details remain inside the secure portal.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Push enrolment failed.' }
  finally { pushBusy.value = false }
}

async function markRead(item: PortalNotification) {
  await api(`/notifications/${item.id}/read`, { method: 'POST' }); item.read_at = new Date().toISOString()
}

function vapidKey(value: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const raw = atob((value + padding).replaceAll('-', '+').replaceAll('_', '/'))
  return Uint8Array.from(raw, (character) => character.charCodeAt(0)) as Uint8Array<ArrayBuffer>
}
</script>

<template>
  <section v-if="application" class="status-page">
    <div v-if="notice" class="alert success page-alert">{{ notice }}</div>
    <div class="status-hero"><p class="eyebrow">Application record</p><h1>{{ application.reference || 'Draft application' }}</h1><StatusBadge :status="application.status" /><p>{{ application.campaign.name }} · {{ application.post.name }}</p><div class="button-row"><button v-if="application.submitted_at" class="button secondary" @click="downloadAcknowledgement">Download acknowledgement</button><button v-if="pushConfig.enabled" class="button secondary" :disabled="pushBusy" @click="enablePush">{{ pushBusy ? 'Enabling…' : 'Enable push updates' }}</button></div></div>
    <div class="status-grid"><article><h2>What happens next</h2><p v-if="application.status === 'awaiting_hard_copies'">Submit the required originals or certified copies by the campaign deadline. Keep the receipt.</p><p v-else>Your record is moving through the published recruitment stages. Any action required from you will appear here.</p><RouterLink to="/help">Ask for help or submit an appeal</RouterLink><h3>Secure inbox</h3><p v-if="!notifications.length">No portal updates yet.</p><button v-for="item in notifications" :key="item.id" class="notification-row" :class="{ unread: !item.read_at }" @click="markRead(item)"><span>{{ item.event_code.replaceAll('.', ' ') }}</span><small>{{ new Date(item.created_at).toLocaleString() }}</small></button></article><article><h2>Activity timeline</h2><ol class="timeline"><li v-for="event in application.timeline" :key="String(event.at)"><i /><div><strong>{{ String(event.status).replaceAll('_', ' ') }}</strong><p>{{ event.reason }}</p><small>{{ new Date(String(event.at)).toLocaleString() }}</small></div></li></ol></article></div>
  </section>
  <div v-else-if="error" class="alert error page-alert">{{ error }}</div><p v-else class="content-section">Loading status…</p>
</template>
