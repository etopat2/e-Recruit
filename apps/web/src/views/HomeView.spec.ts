import { render, screen } from '@testing-library/vue'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import HomeView from './HomeView.vue'

describe('HomeView', () => {
  it('shows published posts and the supplied UPS brand', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: [{
      id: 'campaign-1', code: 'UPS-2026', name: 'Recruitment 2026', year: 2026, status: 'published',
      opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:59Z', hard_copy_deadline_at: null,
      privacy_notice: { summary: 'Official privacy notice.' }, posts: [{ id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: 'Serve Uganda.', sections: {}, hard_copy_required: true }],
    }] }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: HomeView }, { path: '/access', component: { template: '<div />' } }] })
    await router.push('/'); await router.isReady()
    render(HomeView, { global: { plugins: [createPinia(), router] } })

    expect(await screen.findByText('Recruit Warder')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /fair path/i })).toBeInTheDocument()
    expect(screen.getByText('Official privacy notice.')).toBeInTheDocument()
  })
})
