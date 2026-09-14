<script setup lang="ts">
import { onMounted, ref } from 'vue'
import FormAlert from '../components/FormAlert.vue'
import SkeletonBlock from '../components/SkeletonBlock.vue'
import StatCard from '../components/StatCard.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, ApiError } from '../lib/api'
import { useSessionStore } from '../stores/session'
import type { ApplicationRecord } from '../types'

const session = useSessionStore()
const applications = ref<ApplicationRecord[]>([])
interface Aggregate { status?: string; sex?: string; processing_status?: string; total: number }
interface DashboardReport {
  generated_at: string
  total_applications: number
  funnel: Aggregate[]
  sex_distribution: Aggregate[]
  document_processing: Aggregate[]
  eligibility: Aggregate[]
  offline_readiness: { open_conflicts: number; unsynced_packages: number }
}
const report = ref<DashboardReport | null>(null)
const loading = ref(true)
const error = ref('')
const reportNotice = ref('')

function readable(value: string | undefined): string { return (value || 'Not recorded').replaceAll('_', ' ') }

onMounted(async () => {
  try {
    const applicationResponse = await api<{ data: ApplicationRecord[] }>('/applications')
    applications.value = applicationResponse.data
    if (session.isStaff) {
      try { report.value = await api<DashboardReport>('/reports/dashboard') }
      catch (problem) {
        reportNotice.value = problem instanceof ApiError && problem.status === 403
          ? 'Operational aggregates are not available to your assigned role. Your scoped application queue remains available.'
          : 'Operational aggregates could not be loaded. Your scoped application queue remains available.'
      }
    }
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Dashboard unavailable.' } finally { loading.value = false }
})
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Secure workspace</p><h1>{{ session.isApplicant ? 'My applications' : 'Recruitment operations' }}</h1><p>Welcome, {{ session.user?.name }}. Your access is limited to the role and scope assigned to you.</p></section>
  <section class="content-section compact-top">
    <SkeletonBlock v-if="loading" :lines="5" label="Preparing your workspace" /><FormAlert v-else-if="error" kind="error" :message="error" />
    <template v-else>
      <FormAlert v-if="reportNotice" kind="info" :message="reportNotice" />
      <div v-if="session.isStaff && report" class="metric-grid">
        <StatCard label="Total applications" :value="report.total_applications" detail="Within the selected report scope" />
        <StatCard label="Open sync conflicts" :value="report.offline_readiness.open_conflicts" detail="Requires reconciliation" :tone="report.offline_readiness.open_conflicts ? 'danger' : 'success'" />
        <StatCard label="Unsynced field packs" :value="report.offline_readiness.unsynced_packages" detail="Outstanding offline events" :tone="report.offline_readiness.unsynced_packages ? 'danger' : 'success'" />
      </div>
      <div v-if="session.isStaff && report" class="dashboard-breakdowns">
        <section><h2>Application funnel</h2><div class="metric-grid compact-metrics"><StatCard v-for="item in report.funnel" :key="item.status" :label="readable(item.status)" :value="item.total" tone="neutral" /></div></section>
        <section><h2>Applicant sex distribution</h2><div class="metric-grid compact-metrics"><StatCard v-for="item in report.sex_distribution" :key="item.sex" :label="readable(item.sex)" :value="item.total" tone="neutral" /></div></section>
        <section><h2>Document processing</h2><div class="metric-grid compact-metrics"><StatCard v-for="item in report.document_processing" :key="item.processing_status" :label="readable(item.processing_status)" :value="item.total" :tone="item.processing_status === 'failed' ? 'danger' : 'neutral'" /></div></section>
        <section><h2>Eligibility outcomes</h2><div class="metric-grid compact-metrics"><StatCard v-for="item in report.eligibility" :key="item.status" :label="readable(item.status)" :value="item.total" :tone="item.status === 'eligible' ? 'success' : 'neutral'" /></div></section>
      </div>
      <div class="section-heading"><div><h2>{{ session.isApplicant ? 'Application records' : 'Scoped application queue' }}</h2><p>{{ applications.length }} record(s)</p></div><RouterLink v-if="session.isApplicant" class="button secondary" to="/">Find an opportunity</RouterLink></div>
      <div v-if="applications.length === 0" class="empty-state"><h3>No records yet</h3><p>{{ session.isApplicant ? 'Choose an open opportunity to start.' : 'No applications fall within your active scope.' }}</p></div>
      <div v-else class="table-wrap"><table class="mobile-card-table"><thead><tr><th>Reference</th><th>Campaign / post</th><th>Status</th><th>Last action</th></tr></thead><tbody><tr v-for="application in applications" :key="application.id"><td data-label="Reference"><RouterLink :to="session.isApplicant && application.status === 'draft' ? `/applications/${application.id}` : session.isStaff ? `/staff/verification/${application.id}` : `/applications/${application.id}/status`">{{ application.reference || 'Draft' }}</RouterLink></td><td data-label="Campaign / post">{{ application.campaign.name }}<small>{{ application.post.name }}</small></td><td data-label="Status"><StatusBadge :status="application.status" /></td><td data-label="Action"><RouterLink :to="session.isStaff ? `/staff/verification/${application.id}` : `/applications/${application.id}/status`">Open record</RouterLink></td></tr></tbody></table></div>
    </template>
  </section>
</template>
