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

async function technicalAdministratorSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => localStorage.setItem('ups_auth_token', 'synthetic-technical-admin-token'))
  await page.route('**/api/v1/auth/me', async (route) => route.fulfill({ json: { user: { id: 1, name: 'Synthetic Technical Administrator', email: 'system_administrator@example.test', phone: null, user_type: 'system_administrator', status: 'active', is_privileged: true, mfa_confirmed: true, must_change_password: false, scopes: [] } } }))
}

test('temporary credentials must be replaced before application access', async ({ page }) => {
  await page.route('**/api/v1/auth/login', async (route) => route.fulfill({ json: { token: 'temporary-token', requires_password_change: true, user: { id: 8, name: 'Synthetic New Officer', email: 'new-officer@example.test', phone: null, user_type: 'helpdesk_officer', status: 'active', is_privileged: false, mfa_confirmed: false, must_change_password: true, scopes: [] } } }))
  await page.route('**/api/v1/auth/password', async (route) => route.fulfill({ json: { token: 'permanent-token', user: { id: 8, name: 'Synthetic New Officer', email: 'new-officer@example.test', phone: null, user_type: 'helpdesk_officer', status: 'active', is_privileged: false, mfa_confirmed: false, must_change_password: false, scopes: [] } } }))

  await page.goto('/access?redirect=/')
  await page.getByLabel('Email address or phone').fill('new-officer@example.test')
  await page.getByLabel('Password', { exact: true }).fill('TemporaryPass2026')
  await page.locator('button.button.primary.full').click()
  await expect(page.getByRole('heading', { name: 'Replace the temporary password' })).toBeVisible()
  await page.locator('input[autocomplete="new-password"]').nth(0).fill('PermanentSecurePass2026')
  await page.locator('input[autocomplete="new-password"]').nth(1).fill('PermanentSecurePass2026')
  await page.getByRole('button', { name: 'Change password and continue' }).click()
  await expect(page).toHaveURL('http://127.0.0.1:4173/')
})

test('technical administrator provisions and secures staff accounts', async ({ page }) => {
  await technicalAdministratorSession(page)
  const roles = [
    { code: 'applicant', name: 'Applicant', is_decision_role: false, is_privileged: false },
    { code: 'helpdesk_officer', name: 'Helpdesk Officer', is_decision_role: false, is_privileged: false },
    { code: 'system_administrator', name: 'System Administrator', is_decision_role: false, is_privileged: true },
  ]
  let created = false
  const managedUser = () => ({ id: 9, name: 'Synthetic Helpdesk Officer', email: 'helpdesk-new@example.test', phone: null, user_type: 'helpdesk_officer', status: 'active', is_privileged: false, must_change_password: true, mfa_enabled: false, mfa_confirmed: false, last_login_at: null, password_changed_at: null, entity_version: created ? 2 : 1, roles: [{ code: 'helpdesk_officer', name: 'Helpdesk Officer' }], scopes: [] })
  await page.route('**/api/v1/admin/roles', async (route) => route.fulfill({ json: { data: roles } }))
  await page.route('**/api/v1/admin/users*', async (route) => {
    if (route.request().method() === 'POST') { created = true; await route.fulfill({ status: 201, json: { user: managedUser(), temporary_password: 'Random-One-Time-2026', message: 'Account created. The temporary password is shown once.' } }) }
    else await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } } })
  })
  await page.route('**/api/v1/admin/users/9/password-reset', async (route) => route.fulfill({ json: { user: managedUser(), temporary_password: 'Replacement-One-Time-2026', message: 'Password reset. The temporary password is shown once and all sessions were revoked.' } }))

  await page.goto('/staff/users')
  await expect(page.getByRole('heading', { name: 'User and administrator accounts' })).toBeVisible()
  await page.getByLabel('Full name').fill('Synthetic Helpdesk Officer')
  await page.getByLabel('Email address').fill('helpdesk-new@example.test')
  await page.getByLabel('Initial role').selectOption('helpdesk_officer')
  await page.getByRole('button', { name: 'Create secure account' }).click()
  await expect(page.getByText('Random-One-Time-2026')).toBeVisible()
  await page.getByLabel('Reason for security action').fill('Approved synthetic account recovery test.')
  await page.getByRole('button', { name: 'Issue temporary password' }).click()
  await expect(page.getByText('Replacement-One-Time-2026')).toBeVisible()
})

test('applicant registers, completes the dynamic form, uploads evidence, submits, and reaches acknowledgement', async ({ page }) => {
  let version = 1
  let submitted = false
  const application = () => ({
    id: 'app-1', reference: submitted ? 'UPS/2026/WRD/000001' : null, status: submitted ? 'awaiting_hard_copies' : 'draft', entity_version: version,
    submitted_at: submitted ? '2026-09-02T08:00:00Z' : null, draft_data: {}, documents: [], timeline: submitted ? [{ status: 'submitted', reason: 'Application submitted', at: '2026-09-02T08:00:00Z' }] : [],
    campaign: { id: 'campaign-1', code: 'UPS-2026', name: 'UPS Recruitment 2026', year: 2026, status: 'published', opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:00Z', hard_copy_deadline_at: '2026-10-05T14:00:00Z', privacy_notice: {} },
    post: { id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: '', sections: { personal: { required: true }, education: { required: true }, declaration: { required: true } }, hard_copy_required: true },
  })
  await page.route('**/api/v1/auth/register', async (route) => route.fulfill({ status: 201, json: { token: 'synthetic-applicant-token', user: { id: 11, name: 'Synthetic Applicant', email: null, phone: null, user_type: 'applicant', is_privileged: false, mfa_confirmed: true, scopes: [] } } }))
  await page.route('**/api/v1/applications', async (route) => route.fulfill({ status: 201, json: { data: application() } }))
  await page.route('**/api/v1/applications/app-1', async (route) => {
    if (route.request().method() === 'PUT') version += 1
    await route.fulfill({ json: { data: application() } })
  })
  await page.route('**/api/v1/applications/app-1/upload-sessions', async (route) => route.fulfill({ status: 201, json: { session: { id: 'upload-1', chunk_size: 1048576, expected_chunks: 1, received_chunks: [] } } }))
  await page.route('**/api/v1/upload-sessions/upload-1/chunks/0', async (route) => route.fulfill({ status: 201, json: { received: true } }))
  await page.route('**/api/v1/upload-sessions/upload-1/complete', async (route) => route.fulfill({ status: 201, json: { document: { id: 'doc-1', document_type: 'national_id', original_filename: 'synthetic-id.pdf', processing_status: 'pending' } } }))
  await page.route('**/api/v1/applications/app-1/submit', async (route) => { submitted = true; version += 1; await route.fulfill({ json: { data: application() } }) })
  await page.route('**/api/v1/notifications', async (route) => route.fulfill({ json: { notifications: { data: [] } } }))
  await page.route('**/api/v1/notifications/push/config', async (route) => route.fulfill({ json: { enabled: false, public_key: '' } }))

  await page.goto('/')
  await page.getByRole('button', { name: 'Apply' }).click()
  await page.getByRole('button', { name: 'Create account' }).click()
  await page.getByLabel('First name').fill('Synthetic')
  await page.getByLabel('Last name').fill('Applicant')
  await page.getByLabel('National ID number').fill('CM00000000000001')
  await page.getByLabel('Phone', { exact: true }).fill('+256700000001')
  await page.getByLabel('Date of birth').fill('2000-01-01')
  await page.getByLabel('Sex').selectOption('Female')
  await page.getByLabel('Password', { exact: true }).fill('Synthetic!Pass2026')
  await page.getByLabel('Confirm password').fill('Synthetic!Pass2026')
  await page.getByRole('button', { name: 'Create secure account' }).click()
  await expect(page.getByRole('heading', { name: 'Recruit Warder' })).toBeVisible()
  await page.getByLabel('Full legal name').fill('Synthetic Applicant')
  await page.getByLabel('National ID number').fill('CM00000000000001')
  await page.getByLabel('Date of birth').fill('2000-01-01')
  await page.getByLabel('Nationality').fill('Ugandan')
  await page.getByRole('button', { name: /education/i }).click()
  await page.getByRole('button', { name: 'Add qualification' }).click()
  const levelSelect = page.getByRole('combobox', { name: /^Level\b/ })
  const resultSelect = page.getByRole('combobox', { name: /^Result \/ class\b/ })
  await levelSelect.selectOption('UCE')
  await expect(resultSelect).toContainText('Result 1 — certificate awarded')
  await resultSelect.selectOption('Result 1 (Certificate awarded)')
  await page.getByRole('textbox', { name: 'Institution', exact: true }).fill('Synthetic Secondary School')
  await page.getByRole('textbox', { name: 'Completion year', exact: true }).fill('2024')
  await page.getByRole('button', { name: /declaration/i }).click()
  await page.getByRole('checkbox', { name: /information and documents/i }).check()
  await page.getByRole('button', { name: /documents/i }).click()
  await page.getByLabel('Choose file').setInputFiles({ name: 'synthetic-id.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% synthetic fixture\n%%EOF') })
  await page.getByRole('button', { name: 'Upload' }).click()
  await expect(page.getByText('synthetic-id.pdf')).toBeVisible()
  await page.getByRole('button', { name: /review/i }).click()
  await page.getByRole('button', { name: 'Submit final application' }).click()
  await expect(page).toHaveURL(/\/applications\/app-1\/status/)
  await expect(page.getByRole('heading', { name: 'UPS/2026/WRD/000001' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Download acknowledgement' })).toBeVisible()
})

test('verification workbench keeps source evidence and accountable decision together', async ({ page }) => {
  await staffSession(page)
  const workbench = {
    application: { id: 'app-1', reference: 'UPS/2026/WRD/000001', entered_data: { name: 'Synthetic Applicant' } },
    documents: [{ id: 'doc-1', type: 'national_id', version: 1, preview_url: '/api/v1/documents/doc-1/download', quality: { status: 'review' }, fields: [{ field_key: 'name', raw_value: 'SYNTHETIC APPLICANT', confidence: 0.91, page_number: 1, bounding_polygon: [0, 0, 1, 1] }] }],
    comparisons: [], verified_values: [],
    evidence_matrix: { name: [{ source_id: 'doc-1', value: 'SYNTHETIC APPLICANT', confidence: 0.91, page: 1, bounding_polygon: { x: 0.1, y: 0.2, width: 0.4, height: 0.05, coordinate_space: 'normalised' } }] },
  }
  await page.route('**/api/v1/applications/app-1/verification-workbench', async (route) => route.fulfill({ json: workbench }))
  await page.route('**/api/v1/documents/doc-1/download', async (route) => route.fulfill({ contentType: 'application/pdf', body: '%PDF-1.4\n%%EOF' }))
  await page.route('**/api/v1/documents/doc-1/verification', async (route) => route.fulfill({ status: 201, json: { decision: { id: 'decision-1' } } }))

  await page.goto('/staff/verification/app-1')
  await expect(page.getByRole('heading', { name: 'Field-by-field comparison' })).toBeVisible()
  const sourceValue = page.getByRole('button', { name: /SYNTHETIC APPLICANT.*Focus original source/i })
  await expect(sourceValue).toBeVisible()
  await sourceValue.click()
  await expect(page.getByText(/Focused evidence source: page 1/)).toBeVisible()
  await page.getByLabel('Verified/corrected value').fill('Synthetic Applicant')
  await page.getByRole('button', { name: 'Record versioned decision' }).click()
  await expect(page.getByText('Versioned verification decision recorded.')).toBeVisible()
})

test('submitted applicant sees an auditable status timeline and secure inbox', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/applications/app-1', async (route) => route.fulfill({ json: { data: {
    id: 'app-1', reference: 'UPS/2026/WRD/000001', status: 'awaiting_hard_copies', entity_version: 3,
    submitted_at: '2026-09-02T08:00:00Z', documents: [],
    campaign: { id: 'campaign-1', code: 'UPS-2026', name: 'UPS Recruitment 2026', year: 2026, status: 'published', opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:00Z', hard_copy_deadline_at: '2026-10-05T14:00:00Z', privacy_notice: {} },
    post: { id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: '', sections: {}, hard_copy_required: true },
    timeline: [{ status: 'submitted', reason: 'Application submitted', at: '2026-09-02T08:00:00Z' }],
  } } }))
  await page.route('**/api/v1/notifications', async (route) => route.fulfill({ json: { notifications: { data: [{ id: 'notice-1', event_code: 'application.submitted', status: 'delivered', read_at: null, created_at: '2026-09-02T08:01:00Z' }] } } }))
  await page.route('**/api/v1/notifications/push/config', async (route) => route.fulfill({ json: { enabled: false, public_key: '' } }))
  await page.route('**/api/v1/notifications/notice-1/read', async (route) => route.fulfill({ json: { notification: { id: 'notice-1', read_at: '2026-09-02T08:02:00Z' } } }))

  await page.goto('/applications/app-1/status')
  await expect(page.getByRole('heading', { name: 'UPS/2026/WRD/000001' })).toBeVisible()
  await expect(page.getByText('Submit the required originals or certified copies')).toBeVisible()
  await expect(page.getByText('Application submitted', { exact: true })).toBeVisible()
  const inboxItem = page.getByRole('button', { name: /application submitted/i })
  await expect(inboxItem).toHaveClass(/unread/)
  await inboxItem.click()
  await expect(inboxItem).not.toHaveClass(/unread/)
  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''))).toEqual([])
})

test('hard-copy receiving officer records a traceable physical receipt', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/applications/app-1/hard-copy-receipts', async (route) => route.fulfill({ status: 201, json: { receipt: { id: 'receipt-1', receipt_number: 'HC/20260902/SYNTHETIC', status: 'received' } } }))
  await page.goto('/staff/operations')
  await page.getByLabel('Application ID', { exact: true }).first().fill('app-1')
  await page.getByLabel('Receiving office').fill('Synthetic Centre Registry')
  await page.getByRole('button', { name: 'Record accountable receipt' }).click()
  await expect(page.getByText('Hard-copy receipt recorded with a traceable receipt number.')).toBeVisible()
})

test('centre coordinator schedules candidates and records interview check-in', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/posts/post-1/interview-assignments', async (route) => route.fulfill({ status: 201, json: { input_fingerprint: 'f'.repeat(64), assignments: [{ id: 'assignment-1' }] } }))
  await page.route('**/api/v1/interview-assignments/assignment-1/attendance', async (route) => route.fulfill({ json: { attendance: { id: 'attendance-1', status: 'present', entity_version: 1 } } }))
  await page.goto('/staff/operations')
  await page.getByLabel('Recruitment post ID', { exact: true }).first().fill('post-1')
  await page.getByLabel('Centre session ID').fill('session-1')
  await page.getByLabel('Application IDs (comma-separated)').fill('app-1, app-2')
  await page.getByLabel('Panel IDs (comma-separated)').fill('panel-1')
  await page.getByRole('button', { name: 'Generate deterministic assignments' }).click()
  await expect(page.getByText(/Candidates assigned deterministically/)).toBeVisible()
  await page.getByLabel('Interview assignment ID').fill('assignment-1')
  await page.getByLabel('Attendance status').selectOption('present')
  await page.getByRole('button', { name: 'Record attendance' }).click()
  await expect(page.getByText('Attendance recorded and audited.')).toBeVisible()
})

test('panel head closes a reconciled session and fingerprints scores', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/panels/panel-1/close', async (route) => route.fulfill({ json: { closure: { id: 'closure-1', score_fingerprint: 'c'.repeat(64) } } }))
  await page.goto('/staff/operations')
  await page.getByLabel('Panel ID', { exact: true }).fill('panel-1')
  await page.getByRole('checkbox', { name: /panel data is complete/i }).check()
  await page.getByRole('button', { name: 'Close and fingerprint panel' }).click()
  await expect(page.getByText(/Panel closed; submitted scores are now fingerprinted/)).toBeVisible()
})

test('HQ runs a reproducible selection scenario and certifies the official draft', async ({ page }) => {
  await staffSession(page)
  const officialRun = { id: 'selection-1', run_number: 4, mode: 'official', status: 'draft', input_fingerprint: 'a'.repeat(64), output_fingerprint: 'b'.repeat(64), outcomes_count: 2 }
  await page.route('**/api/v1/selection-runs', async (route) => {
    if (route.request().method() === 'POST') await route.fulfill({ status: 201, json: { run: officialRun, outcomes: [{ id: 'outcome-1', application_id: 'app-1', position: 1, outcome: 'selected', score: 91 }] } })
    else await route.fulfill({ json: { data: [officialRun] } })
  })
  await page.route('**/api/v1/selection-runs/selection-1/certify', async (route) => route.fulfill({ json: { run: { ...officialRun, status: 'certified' } } }))
  await page.goto('/staff/selection')
  await page.getByLabel('Ranking run ID').fill('ranking-1')
  await page.getByLabel('Mode').selectOption('official')
  await page.getByLabel('Quota buckets (JSON)').fill('{"north":1}')
  await page.getByRole('button', { name: 'Run reproducible scenario' }).click()
  await expect(page.getByText('Reproducible selection scenario created.')).toBeVisible()
  await page.getByRole('button', { name: 'Certify with council approval' }).click()
  await expect(page.getByText('Run 4 certified.')).toBeVisible()
})

test('medical outcome gates an independently approved strict-order reserve replacement', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/medical/results', async (route) => route.fulfill({ status: 201, json: { result: { id: 'medical-1', application_id: 'reserve-app', outcome: 'Fit' } } }))
  await page.route('**/api/v1/training/replacement-recommendations', async (route) => route.fulfill({ status: 201, json: { recommendation: { id: 'recommendation-1', reserve_application_id: 'reserve-app', status: 'pending_approval' } } }))
  await page.route('**/api/v1/training/replacement-recommendations/recommendation-1/decision', async (route) => route.fulfill({ json: { recommendation: { id: 'recommendation-1', status: 'approved' } } }))
  await page.goto('/staff/operations')
  await page.getByLabel('Application ID', { exact: true }).nth(1).fill('reserve-app')
  await page.getByLabel('Medical schedule ID', { exact: true }).fill('medical-schedule-1')
  await page.getByRole('combobox', { name: 'Outcome', exact: true }).selectOption('Fit')
  await page.getByRole('button', { name: 'Record restricted result' }).click()
  await expect(page.getByText(/Restricted medical result recorded/)).toBeVisible()
  await page.getByLabel('Candidate being replaced - application ID').fill('selected-app')
  await page.getByLabel('Certified selection run ID').fill('selection-1')
  await page.getByLabel('Reason', { exact: true }).fill('Synthetic candidate did not report for training intake.')
  await page.getByRole('button', { name: 'Recommend strict-order reserve' }).click()
  await expect(page.getByText(/recommended for independent approval/)).toBeVisible()
  await page.getByLabel('Recommendation ID').fill('recommendation-1')
  await page.getByLabel('Decision reason').fill('Independent synthetic review confirms the next reserve candidate.')
  await page.getByLabel('Approval reference', { exact: true }).fill('COUNCIL-SYNTHETIC-2026')
  await page.getByRole('button', { name: 'Record independent decision' }).click()
  await expect(page.getByText('Reserve replacement approved by an independent authority.')).toBeVisible()
})

test('PATS issues a training invitation and records candidate reporting', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/training/invitations', async (route) => route.fulfill({ status: 201, json: { invite: { id: 'training-invite-1', final_selection_id: 'final-1' } } }))
  await page.route('**/api/v1/training/reporting', async (route) => route.fulfill({ status: 201, json: { reporting: { id: 'report-1', status: 'admitted' } } }))
  await page.goto('/staff/operations')
  await page.getByLabel('Final selection ID').fill('final-1')
  await page.getByLabel('Date', { exact: true }).last().fill('2026-10-15')
  await page.getByLabel('Training location').fill('Synthetic Training School')
  await page.getByRole('button', { name: 'Issue protected invitation' }).click()
  await expect(page.getByText(/Training invitation issued/)).toBeVisible()
  await page.getByLabel('Training invitation ID').fill('training-invite-1')
  await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('admitted')
  await page.getByRole('button', { name: 'Record reporting status' }).click()
  await expect(page.getByText('Training reporting status recorded and audited.')).toBeVisible()
})

test('panel user checks in and scores offline, reloads locked, then reconciles once', async ({ page, context }) => {
  await staffSession(page)
  await page.route('**/api/v1/offline/devices', async (route) => route.fulfill({ status: 201, json: { device: { id: 'device-1' } } }))
  await page.route('**/api/v1/offline/packages', async (route) => {
    const body = route.request().postDataJSON() as { pack_type: 'attendance' | 'score_capture' }
    const attendancePack = body.pack_type === 'attendance'
    await route.fulfill({ status: 201, json: {
      package: { id: attendancePack ? 'pack-attendance' : 'pack-score', pack_type: body.pack_type, status: 'active', manifest: {}, manifest_fingerprint: (attendancePack ? 'a' : 'b').repeat(64), expires_at: '2099-01-01T00:00:00Z' },
      server_records: [{ entity_type: attendancePack ? 'interview_assignment' : 'assessment_score', entity_id: attendancePack ? 'assignment-1' : 'score-1', server_version: 1, payload: { application_reference: 'UPS/SYNTHETIC', maximum_mark: 100 } }],
      server_time: '2026-09-01T00:00:00Z',
    } })
  })
  await page.route('**/api/v1/offline/packages/*/sync', async (route) => {
    const body = route.request().postDataJSON() as { events: Array<{ id: string }> }
    await route.fulfill({ json: { acknowledgements: [{ event_id: body.events[0].id, state: 'accepted' }], package_status: 'reconciled' } })
  })

  await page.goto('/field/offline')
  await page.getByLabel('Offline PIN', { exact: true }).fill('246810')
  await page.getByLabel('Confirm offline PIN').fill('246810')
  await page.getByRole('button', { name: 'Configure and unlock' }).click()
  await page.getByRole('button', { name: 'Register this device' }).click()
  await page.getByLabel('Pack purpose').selectOption('attendance')
  await page.getByLabel('Scoped entity IDs').fill('assignment-1')
  await page.getByRole('button', { name: 'Issue scoped pack' }).click()
  await expect(page.getByText(/Interview attendance.*1 record/)).toBeVisible()
  await page.evaluate(() => navigator.serviceWorker.ready)
  await context.setOffline(true)
  await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('present')
  await page.getByRole('button', { name: 'Queue encrypted event' }).click()
  await expect(page.getByText('1 awaiting acknowledgement')).toBeVisible()
  await context.setOffline(false)
  await page.getByLabel('Final sync: reconcile and purge').check()
  await page.getByRole('button', { name: 'Synchronise now' }).click()
  await expect(page.getByText(/reconciled encrypted pack was purged/)).toBeVisible()

  await page.getByLabel('Pack purpose').selectOption('score_capture')
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

test('two field devices surface a protected conflict for authorised resolution', async ({ page, browser }) => {
  const secondContext = await browser.newContext()
  const secondPage = await secondContext.newPage()
  let secondResolved = false

  async function prepareDevice(devicePage: import('@playwright/test').Page, suffix: 'a' | 'b') {
    await staffSession(devicePage)
    await devicePage.route('**/api/v1/offline/devices', async (route) => route.fulfill({ status: 201, json: { device: { id: `device-${suffix}` } } }))
    await devicePage.route('**/api/v1/offline/packages', async (route) => route.fulfill({ status: 201, json: {
      package: { id: `pack-${suffix}`, pack_type: 'score_capture', status: 'active', manifest: {}, manifest_fingerprint: suffix.repeat(64), expires_at: '2099-01-01T00:00:00Z' },
      server_records: [{ entity_type: 'assessment_score', entity_id: 'shared-score', server_version: 1, payload: { application_reference: 'UPS/SYNTHETIC/SHARED', maximum_mark: 100 } }], server_time: '2026-09-01T00:00:00Z',
    } }))
    await devicePage.route(`**/api/v1/offline/packages/pack-${suffix}/sync`, async (route) => {
      const body = route.request().postDataJSON() as { events: Array<{ id: string }> }
      await route.fulfill({ json: { acknowledgements: [{ event_id: body.events[0].id, state: suffix === 'a' ? 'accepted' : 'conflict' }], package_status: 'active' } })
    })
    await devicePage.route(`**/api/v1/offline/packages/pack-${suffix}/changes`, async (route) => route.fulfill({ json: {
      package_status: 'active', server_records: [{ entity_type: 'assessment_score', entity_id: 'shared-score', server_version: 2, payload: { application_reference: 'UPS/SYNTHETIC/SHARED', score: 81 } }], server_cursor: '2',
      conflicts: suffix === 'b' && !secondResolved ? [{ id: 'conflict-1', entity_id: 'shared-score', field_key: 'score', status: 'open', local_value: 79, server_value: 81 }] : [],
    } }))
    if (suffix === 'b') await devicePage.route('**/api/v1/offline/conflicts/conflict-1/resolve', async (route) => { secondResolved = true; await route.fulfill({ json: { conflict: { id: 'conflict-1', status: 'resolved' } } }) })

    await devicePage.goto('http://127.0.0.1:4173/field/offline')
    await devicePage.getByLabel('Offline PIN', { exact: true }).fill(suffix === 'a' ? '111111' : '222222')
    await devicePage.getByLabel('Confirm offline PIN').fill(suffix === 'a' ? '111111' : '222222')
    await devicePage.getByRole('button', { name: 'Configure and unlock' }).click()
    await devicePage.getByRole('button', { name: 'Register this device' }).click()
    await devicePage.getByLabel('Scoped entity IDs').fill('shared-score')
    await devicePage.getByRole('button', { name: 'Issue scoped pack' }).click()
    await devicePage.getByRole('spinbutton', { name: 'Score', exact: true }).fill(suffix === 'a' ? '81' : '79')
    await devicePage.getByRole('button', { name: 'Queue encrypted event' }).click()
  }

  try {
    await prepareDevice(page, 'a')
    await prepareDevice(secondPage, 'b')
    await page.getByRole('button', { name: 'Synchronise now' }).click()
    await secondPage.getByRole('button', { name: 'Synchronise now' }).click()
    await expect(secondPage.getByText(/local 79, server 81/)).toBeVisible()
    await secondPage.getByLabel('Resolution reason').fill('Retain the first device score after supervisor evidence review.')
    await secondPage.getByRole('button', { name: 'Keep server value' }).click()
    await expect(secondPage.getByText(/Conflict resolved by retaining the current server value/)).toBeVisible()
  } finally { await secondContext.close() }
})
