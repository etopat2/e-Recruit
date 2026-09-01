<script setup lang="ts">
import { onMounted, ref } from 'vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api } from '../lib/api'
import { useSessionStore } from '../stores/session'
import type { ApplicationRecord } from '../types'

const session = useSessionStore()
const applications = ref<ApplicationRecord[]>([])
const report = ref<Record<string, unknown> | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    const response = await api<{ data: ApplicationRecord[] }>('/applications')
    applications.value = response.data
    if (session.isStaff) report.value = await api<Record<string, unknown>>('/reports/dashboard')
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Dashboard unavailable.' } finally { loading.value = false }
})
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Secure workspace</p><h1>{{ session.isApplicant ? 'My applications' : 'Recruitment operations' }}</h1><p>Welcome, {{ session.user?.name }}. Your access is limited to the role and scope assigned to you.</p></section>
  <section class="content-section compact-top">
    <p v-if="loading">Preparing your workspace…</p><div v-else-if="error" class="alert error">{{ error }}</div>
    <template v-else>
      <div v-if="session.isStaff && report" class="metric-grid">
        <article><span>Total applications</span><strong>{{ report.total_applications }}</strong></article>
        <article><span>Open sync conflicts</span><strong>{{ (report.offline_readiness as Record<string, number>)?.open_conflicts }}</strong></article>
        <article><span>Unsynced packs</span><strong>{{ (report.offline_readiness as Record<string, number>)?.unsynced_packages }}</strong></article>
      </div>
      <div class="section-heading"><div><h2>{{ session.isApplicant ? 'Application records' : 'Scoped application queue' }}</h2><p>{{ applications.length }} record(s)</p></div><RouterLink v-if="session.isApplicant" class="button secondary" to="/">Find an opportunity</RouterLink></div>
      <div v-if="applications.length === 0" class="empty-state"><h3>No records yet</h3><p>{{ session.isApplicant ? 'Choose an open opportunity to start.' : 'No applications fall within your active scope.' }}</p></div>
      <div v-else class="table-wrap"><table><thead><tr><th>Reference</th><th>Campaign / post</th><th>Status</th><th>Last action</th></tr></thead><tbody><tr v-for="application in applications" :key="application.id"><td><RouterLink :to="session.isApplicant && application.status === 'draft' ? `/applications/${application.id}` : session.isStaff ? `/staff/verification/${application.id}` : `/applications/${application.id}/status`">{{ application.reference || 'Draft' }}</RouterLink></td><td>{{ application.campaign.name }}<small>{{ application.post.name }}</small></td><td><StatusBadge :status="application.status" /></td><td><RouterLink :to="session.isStaff ? `/staff/verification/${application.id}` : `/applications/${application.id}/status`">Open record</RouterLink></td></tr></tbody></table></div>
    </template>
  </section>
</template>
