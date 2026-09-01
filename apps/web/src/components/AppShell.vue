<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '../stores/session'
import OfflineBanner from './OfflineBanner.vue'

const session = useSessionStore()
const router = useRouter()
const open = ref(false)
const staffNav = computed(() => session.isStaff)
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
      <template v-if="session.authenticated">
        <RouterLink to="/dashboard">Dashboard</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/campaigns">Campaigns</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/geography">Geography</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/assessments">Assessments</RouterLink>
        <RouterLink v-if="staffNav" to="/staff/selection">Selection</RouterLink>
        <RouterLink v-if="staffNav" to="/field/offline">Field mode</RouterLink>
        <RouterLink to="/help">Help</RouterLink>
        <button class="nav-action" type="button" @click="signOut">Sign out</button>
      </template>
      <RouterLink v-else class="nav-action" to="/access">Sign in / register</RouterLink>
    </nav>
  </header>
  <main id="main-content" tabindex="-1"><slot /></main>
  <footer class="site-footer">
    <div><strong>Uganda Prisons Service</strong><br />Secure, accountable recruitment.</div>
    <div><RouterLink to="/help">Support and appeals</RouterLink><br /><span>Official portal · Africa/Kampala</span></div>
  </footer>
</template>
