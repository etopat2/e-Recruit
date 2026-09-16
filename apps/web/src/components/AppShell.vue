<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '../stores/session'
import OfflineBanner from './OfflineBanner.vue'
import PwaInstallButton from './PwaInstallButton.vue'

const session = useSessionStore()
const router = useRouter()
const route = useRoute()
const open = ref(false)
const staffNav = computed(() => session.isStaff)
const technicalAdmin = computed(() => session.user?.user_type === 'system_administrator')
const recruitmentStaff = computed(() => staffNav.value && !technicalAdmin.value)
const officialDocumentStaff = computed(() => ['hq_recruitment_administrator', 'prisons_council_secretariat', 'auditor'].includes(session.user?.user_type || ''))
const lastApplicationId = ref(sessionStorage.getItem('ups_last_application_id') || '')
watch(() => route.params.id, (id) => {
  if (typeof id === 'string' && route.path.startsWith('/applications/')) {
    lastApplicationId.value = id
    sessionStorage.setItem('ups_last_application_id', id)
  }
}, { immediate: true })
const statusPath = computed(() => lastApplicationId.value ? `/applications/${lastApplicationId.value}/status` : '/dashboard')
async function signOut() {
  await session.logout()
  await router.push('/')
}
</script>

<template>
  <a class="skip-link" href="#main-content">Skip to main content</a>
  <OfflineBanner />
  <header class="site-header">
    <RouterLink class="brand" to="/" aria-label="UPS e-Recruit home">
      <img :src="'/brand/ups-logo.png'" alt="Uganda Prisons Service crest" />
      <span><strong>Uganda Prisons Service</strong><small>e-Recruit</small></span>
    </RouterLink>
    <button class="menu-button" type="button" :aria-expanded="open" aria-controls="primary-navigation" @click="open = !open">Menu</button>
    <nav id="primary-navigation" :class="{ open }" aria-label="Primary navigation" @click="open = false">
      <RouterLink to="/">Opportunities</RouterLink>
      <PwaInstallButton />
      <template v-if="session.authenticated">
        <RouterLink v-if="!technicalAdmin" to="/dashboard">Dashboard</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/campaigns">Campaigns</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/geography">Geography</RouterLink>
        <RouterLink v-if="recruitmentStaff" to="/staff/assessments">Assessments</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/governance">Governance</RouterLink>
        <RouterLink v-if="recruitmentStaff" to="/staff/selection">Selection</RouterLink>
        <RouterLink v-if="recruitmentStaff" to="/staff/operations">Operations</RouterLink>
        <RouterLink v-if="officialDocumentStaff" to="/staff/recruitment-documents">Official lists</RouterLink>
        <RouterLink v-if="technicalAdmin" to="/staff/users">Users</RouterLink>
        <RouterLink v-if="recruitmentStaff" to="/field/offline">Field mode</RouterLink>
        <RouterLink to="/help">Help</RouterLink>
        <button class="nav-action" type="button" @click="signOut">Sign out</button>
      </template>
      <RouterLink v-else class="nav-action" to="/access">Sign in / register</RouterLink>
    </nav>
  </header>
  <main id="main-content" :class="{ 'applicant-tab-content': session.isApplicant }" tabindex="-1"><slot /></main>
  <nav v-if="session.isApplicant" class="applicant-bottom-nav" aria-label="Applicant navigation">
    <RouterLink to="/"><span aria-hidden="true">⌂</span>Home</RouterLink>
    <RouterLink to="/dashboard"><span aria-hidden="true">▤</span>Applications</RouterLink>
    <RouterLink :to="statusPath"><span aria-hidden="true">✓</span>Status</RouterLink>
    <RouterLink to="/help"><span aria-hidden="true">?</span>Help</RouterLink>
  </nav>
  <footer class="site-footer" :class="{ 'with-applicant-tabs': session.isApplicant }">
    <div><strong>Uganda Prisons Service</strong><br />Secure, accountable recruitment.</div>
    <div><RouterLink to="/help">Support and appeals</RouterLink><br /><span>Official portal · Africa/Kampala</span></div>
  </footer>
</template>
