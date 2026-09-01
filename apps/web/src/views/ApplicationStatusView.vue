<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import StatusBadge from '../components/StatusBadge.vue'
import { api, authToken } from '../lib/api'
import type { ApplicationRecord } from '../types'

const route = useRoute(); const application = ref<ApplicationRecord | null>(null); const error = ref('')
onMounted(async () => { try { application.value = (await api<{ data: ApplicationRecord }>(`/applications/${route.params.id}`)).data } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Record unavailable.' } })
async function downloadAcknowledgement() {
  const response = await fetch(`/api/v1/applications/${route.params.id}/acknowledgement`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Acknowledgement is not available.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `${application.value?.reference || 'UPS'}-acknowledgement.pdf`; link.click(); URL.revokeObjectURL(link.href)
}
</script>

<template>
  <section v-if="application" class="status-page"><div class="status-hero"><p class="eyebrow">Application record</p><h1>{{ application.reference || 'Draft application' }}</h1><StatusBadge :status="application.status" /><p>{{ application.campaign.name }} · {{ application.post.name }}</p><button v-if="application.submitted_at" class="button secondary" @click="downloadAcknowledgement">Download acknowledgement</button></div><div class="status-grid"><article><h2>What happens next</h2><p v-if="application.status === 'awaiting_hard_copies'">Submit the required originals or certified copies by the campaign deadline. Keep the receipt.</p><p v-else>Your record is moving through the published recruitment stages. Any action required from you will appear here.</p><RouterLink to="/help">Ask for help or submit an appeal</RouterLink></article><article><h2>Activity timeline</h2><ol class="timeline"><li v-for="event in application.timeline" :key="String(event.at)"><i /><div><strong>{{ String(event.status).replaceAll('_', ' ') }}</strong><p>{{ event.reason }}</p><small>{{ new Date(String(event.at)).toLocaleString() }}</small></div></li></ol></article></div></section>
  <div v-else-if="error" class="alert error page-alert">{{ error }}</div><p v-else class="content-section">Loading status…</p>
</template>
