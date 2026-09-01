import { createRouter, createWebHistory } from 'vue-router'
import { useSessionStore } from './stores/session'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'home', component: () => import('./views/HomeView.vue') },
    { path: '/access', name: 'access', component: () => import('./views/AccessView.vue') },
    { path: '/dashboard', name: 'dashboard', component: () => import('./views/DashboardView.vue'), meta: { auth: true } },
    { path: '/applications/:id', name: 'application', component: () => import('./views/ApplicationWorkspaceView.vue'), meta: { auth: true, applicant: true } },
    { path: '/applications/:id/status', name: 'application-status', component: () => import('./views/ApplicationStatusView.vue'), meta: { auth: true } },
    { path: '/staff/verification/:id', name: 'verification', component: () => import('./views/VerificationWorkbenchView.vue'), meta: { auth: true, staff: true } },
    { path: '/staff/campaigns', name: 'campaigns', component: () => import('./views/CampaignConfigurationView.vue'), meta: { auth: true, staff: true } },
    { path: '/staff/selection', name: 'selection', component: () => import('./views/SelectionConsoleView.vue'), meta: { auth: true, staff: true } },
    { path: '/field/offline', name: 'offline', component: () => import('./views/OfflineWorkspaceView.vue'), meta: { auth: true, staff: true } },
    { path: '/help', name: 'help', component: () => import('./views/HelpdeskView.vue'), meta: { auth: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

let restored = false
router.beforeEach(async (to) => {
  const session = useSessionStore()
  if (!restored) {
    await session.restore()
    restored = true
  }
  if (to.meta.auth && !session.authenticated) return { name: 'access', query: { redirect: to.fullPath } }
  if (to.meta.staff && !session.isStaff) return { name: 'dashboard' }
  if (to.meta.applicant && !session.isApplicant) return { name: 'dashboard' }
  if (to.name === 'access' && session.authenticated) return { name: 'dashboard' }
  return true
})

export default router
