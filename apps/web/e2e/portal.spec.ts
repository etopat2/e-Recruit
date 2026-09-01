import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/campaigns', async (route) => route.fulfill({
    json: { data: [{ id: 'campaign-1', code: 'UPS-2026', name: 'UPS Recruitment 2026', year: 2026, status: 'published', opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:00Z', privacy_notice: { summary: 'Your data is protected.' }, posts: [{ id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: 'Serve with integrity.', sections: {}, hard_copy_required: true }] }] },
  }))
})

test('public portal is responsive, branded, and has no serious accessibility violations', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: /fair path to serving uganda/i })).toBeVisible()
  await expect(page.getByAltText('Uganda Prisons Service crest')).toBeVisible()
  await expect(page.getByText('Recruit Warder')).toBeVisible()
  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''))).toEqual([])
})

test('applicant access form supports keyboard-sized mobile viewport', async ({ page }) => {
  await page.goto('/access')
  await expect(page.getByRole('heading', { name: 'Sign in securely' })).toBeVisible()
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByLabel('National ID number')).toBeVisible()
})

async function staffSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => localStorage.setItem('ups_auth_token', 'synthetic-staff-token'))
  await page.route('**/api/v1/auth/me', async (route) => route.fulfill({ json: { user: { id: 7, name: 'Synthetic Officer', email: null, phone: null, user_type: 'panel_member', is_privileged: true, mfa_confirmed: true, scopes: [] } } }))
}

test('verification workbench keeps source evidence and accountable decision together', async ({ page }) => {
  await staffSession(page)
  const workbench = {
    application: { id: 'app-1', reference: 'UPS/2026/WRD/000001', entered_data: { name: 'Synthetic Applicant' } },
    documents: [{ id: 'doc-1', type: 'national_id', version: 1, preview_url: '/api/v1/documents/doc-1/download', quality: { status: 'review' }, fields: [{ field_key: 'name', raw_value: 'SYNTHETIC APPLICANT', confidence: 0.91, page_number: 1, bounding_polygon: [0, 0, 1, 1] }] }],
    comparisons: [], verified_values: [],
    evidence_matrix: { name: [{ source_id: 'doc-1:p1', value: 'SYNTHETIC APPLICANT', confidence: 0.91 }] },
  }
  await page.route('**/api/v1/applications/app-1/verification-workbench', async (route) => route.fulfill({ json: workbench }))
  await page.route('**/api/v1/documents/doc-1/download', async (route) => route.fulfill({ contentType: 'application/pdf', body: '%PDF-1.4\n%%EOF' }))
  await page.route('**/api/v1/documents/doc-1/verification', async (route) => route.fulfill({ status: 201, json: { decision: { id: 'decision-1' } } }))

  await page.goto('/staff/verification/app-1')
  await expect(page.getByRole('heading', { name: 'Field-by-field comparison' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'SYNTHETIC APPLICANT', exact: true })).toBeVisible()
  await page.getByLabel('Verified/corrected value').fill('Synthetic Applicant')
  await page.getByRole('button', { name: 'Record versioned decision' }).click()
  await expect(page.getByText('Versioned verification decision recorded.')).toBeVisible()
})

test('offline score remains encrypted locally across reload and reconciles once', async ({ page, context }) => {
  await staffSession(page)
  await page.route('**/api/v1/offline/devices', async (route) => route.fulfill({ status: 201, json: { device: { id: 'device-1' } } }))
  await page.route('**/api/v1/offline/packages', async (route) => route.fulfill({ status: 201, json: {
    package: { id: 'pack-1', pack_type: 'score_capture', status: 'active', manifest: {}, manifest_fingerprint: 'a'.repeat(64), expires_at: '2099-01-01T00:00:00Z' },
    server_records: [{ entity_type: 'assessment_score', entity_id: 'score-1', server_version: 1, payload: { application_reference: 'UPS/SYNTHETIC', maximum_mark: 100 } }],
    server_time: '2026-09-01T00:00:00Z',
  } }))
  await page.route('**/api/v1/offline/packages/pack-1/sync', async (route) => {
    const body = route.request().postDataJSON() as { events: Array<{ id: string }> }
    await route.fulfill({ json: { acknowledgements: [{ event_id: body.events[0].id, state: 'accepted' }], package_status: 'reconciled' } })
  })

  await page.goto('/field/offline')
  await page.getByLabel('Offline PIN', { exact: true }).fill('246810')
  await page.getByLabel('Confirm offline PIN').fill('246810')
  await page.getByRole('button', { name: 'Configure and unlock' }).click()
  await page.getByRole('button', { name: 'Register this device' }).click()
  await page.getByLabel('Scoped entity IDs').fill('score-1')
  await page.getByRole('button', { name: 'Issue scoped pack' }).click()
  await expect(page.getByText(/Assessment scoring · 1 record/)).toBeVisible()
  await page.evaluate(() => navigator.serviceWorker.ready)
  await context.setOffline(true)
  await page.getByRole('spinbutton', { name: 'Score', exact: true }).fill('78')
  await page.getByRole('button', { name: 'Queue encrypted event' }).click()
  await expect(page.getByText('1 awaiting acknowledgement')).toBeVisible()
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Unlock this offline workspace' })).toBeVisible()
  await expect(page.getByText('1 awaiting acknowledgement')).toBeHidden()
  await page.getByLabel('Offline PIN', { exact: true }).fill('246810')
  await page.getByRole('button', { name: 'Unlock field mode' }).click()
  await expect(page.getByText('1 awaiting acknowledgement')).toBeVisible()
  await context.setOffline(false)
  await page.getByLabel('Final sync: reconcile and purge').check()
  await page.getByRole('button', { name: 'Synchronise now' }).click()
  await expect(page.getByText('Every event was acknowledged. The reconciled encrypted pack was purged from this browser.')).toBeVisible()
})
