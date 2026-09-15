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

async function staffSession(page: import('@playwright/test').Page, userType = 'panel_member') {
  await page.addInitScript(() => localStorage.setItem('ups_auth_token', 'synthetic-staff-token'))
  await page.route('**/api/v1/auth/me', async (route) => route.fulfill({ json: { user: { id: 7, name: 'Synthetic Officer', email: null, phone: null, user_type: userType, is_privileged: true, mfa_confirmed: true, scopes: [] } } }))
}

async function operationsLookups(page: import('@playwright/test').Page) {
  await page.route('**/api/v1/operations/lookups', async (route) => route.fulfill({ json: { data: {
    posts: [{ id: 'post-1', label: 'UPS Recruitment 2026 — Recruit Warder', description: 'WARDER' }],
    regions: [{ id: 'region-1', label: 'Central Prison Region' }],
    hard_copy: { can_receive: true, receiving_point: 'Uganda Prisons Service Headquarters', transmission_notice: 'Units and regions are transmission channels only; final receipt is recorded at headquarters.' },
    centre_sessions: [{ id: 'session-1', post_id: 'post-1', label: 'Synthetic Centre — 15 Oct 2026 08:00', description: 'Recruit Warder' }],
    interview_assignments: [{ id: 'assignment-1', application_id: 'app-1', label: 'Synthetic Applicant — UPS/2026/WRD/000001', description: 'Synthetic Centre • Panel A • 2026-10-15 08:00' }],
    panels: [{ id: 'panel-1', label: 'Synthetic Centre — Panel A', description: '2026-10-15 • Head: Synthetic Head' }],
    medical_schedules: [{ id: 'medical-schedule-1', post_id: 'post-1', label: 'Synthetic Medical Centre — 2026-10-20 08:00', description: 'Recruit Warder' }],
    selection_outcomes: [{ id: 'outcome-1', application_id: 'reserve-app', label: 'Reserve Candidate — UPS/2026/WRD/000002', description: 'Certified selected outcome' }],
    medical_results: [{ id: 'medical-1', application_id: 'reserve-app', label: 'Reserve Candidate — UPS/2026/WRD/000002', description: 'Fit result' }],
    final_selections: [{ id: 'final-1', application_id: 'reserve-app', label: 'Reserve Candidate — UPS/2026/WRD/000002', description: 'Approved final selection' }],
    training_invites: [{ id: 'training-invite-1', application_id: 'reserve-app', label: 'Reserve Candidate — UPS/2026/WRD/000002', description: 'Synthetic Training School • 2026-10-25 08:00' }],
    selection_runs: [{ id: 'selection-1', label: 'Recruit Warder — certified run 4', description: '2026-10-01' }],
    replacement_recommendations: [{ id: 'recommendation-1', label: 'Selected Candidate (UPS/2026/WRD/000003)', description: 'Proposed replacement: Reserve Candidate (UPS/2026/WRD/000002)' }],
  } } }))
  await page.route('**/api/v1/operations/applications?*', async (route) => {
    const context = new URL(route.request().url()).searchParams.get('context')
    const candidate = context === 'replacement'
      ? { id: 'selected-app', post_id: 'post-1', label: 'Selected Candidate — UPS/2026/WRD/000003', description: 'Status: selected' }
      : context === 'medical'
        ? { id: 'reserve-app', post_id: 'post-1', label: 'Reserve Candidate — UPS/2026/WRD/000002', description: 'Status: selected' }
        : { id: 'app-1', post_id: 'post-1', label: 'Synthetic Applicant — UPS/2026/WRD/000001', description: 'Status: awaiting hard copies', document_requirements: [
            { document_type: 'national_id', label: 'National ID' },
            { document_type: 'application_letter', label: 'Application letter' },
            { document_type: 'lc1_letter', label: 'LC1 letter' },
            { document_type: 'academic_certificate', label: 'S.4 certificate / result slip' },
            { document_type: 'passport_photo', label: 'Passport photo' },
          ] }
    await route.fulfill({ json: { data: [candidate] } })
  })
}

async function technicalAdministratorSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => localStorage.setItem('ups_auth_token', 'synthetic-technical-admin-token'))
  await page.route('**/api/v1/auth/me', async (route) => route.fulfill({ json: { user: { id: 1, name: 'Synthetic Technical Administrator', email: 'system_administrator@example.test', phone: null, user_type: 'system_administrator', status: 'active', is_privileged: true, mfa_confirmed: true, must_change_password: false, scopes: [] } } }))
}

async function applicantSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => localStorage.setItem('ups_auth_token', 'synthetic-applicant-token'))
  await page.route('**/api/v1/auth/me', async (route) => route.fulfill({ json: { user: { id: 11, name: 'Synthetic Applicant', email: null, phone: '+256700000001', user_type: 'applicant', status: 'active', is_privileged: false, mfa_confirmed: false, must_change_password: false, scopes: [] } } }))
}

test('district combobox searches the official hierarchy without expanding the page', async ({ page }) => {
  await applicantSession(page)
  const district = { id: 'district-1', code: 'UG-KLA', name: 'Kampala', level: 'district', unit_type: 'city', parent_id: null, full_address: 'Kampala, Central Region', lineage: { region: { id: 'region-1', code: 'CENTRAL', name: 'Central Region', unit_type: 'region' }, subregion: null, district: { id: 'district-1', code: 'UG-KLA', name: 'Kampala', unit_type: 'city' }, county: null, subcounty: null, parish: null, village: null } }
  const application = { id: 'address-app', reference: null, status: 'draft', entity_version: 1, submitted_at: null, draft_data: {}, documents: [], timeline: [], campaign: { id: 'campaign-1', code: 'UPS-2026', name: 'UPS Recruitment 2026', year: 2026, status: 'published', opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:00Z', hard_copy_deadline_at: null, privacy_notice: {} }, post: { id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: '', sections: { address: { required: true } }, hard_copy_required: false } }
  await page.route('**/api/v1/applications/address-app', async (route) => route.fulfill({ json: { data: application } }))
  await page.route('**/api/v1/geography/units*', async (route) => {
    const url = new URL(route.request().url())
    const level = url.searchParams.get('level')
    await route.fulfill({ json: { data: level === 'district' ? [district] : [] } })
  })

  await page.goto('/applications/address-app')
  const districtInput = page.getByRole('combobox', { name: 'District / city' })
  await expect(districtInput).toBeEnabled()
  await districtInput.fill('Kamp')
  const option = page.getByRole('option', { name: /Kampala.*Central Region/i })
  await expect(option).toBeVisible()
  await option.click()
  await expect(districtInput).toHaveValue('Kampala')
  await expect(page.getByRole('combobox', { name: 'County / municipality' })).toBeEnabled()
})

test('applicant mobile navigation stays reachable behind a full-screen action dialog', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await applicantSession(page)
  await page.route('**/api/v1/helpdesk/tickets', async (route) => route.fulfill({ json: { tickets: { data: [] } } }))
  await page.route('**/api/v1/applications', async (route) => route.fulfill({ json: { data: [] } }))

  await page.goto('/help')
  const bottomNav = page.getByRole('navigation', { name: 'Applicant navigation' })
  await expect(bottomNav).toBeVisible()
  await expect(bottomNav.getByRole('link', { name: /Applications/ })).toBeVisible()
  await page.getByRole('button', { name: 'Open support request' }).click()
  const dialog = page.getByRole('dialog', { name: 'Open a support request' })
  await expect(dialog).toBeVisible()
  await expect(dialog).toHaveCSS('border-radius', '0px')
  const box = await dialog.boundingBox()
  const viewport = page.viewportSize()
  expect(box).not.toBeNull()
  expect(viewport).not.toBeNull()
  expect(box!.width / viewport!.width).toBeGreaterThan(0.98)
  expect(box!.height / viewport!.height).toBeGreaterThan(0.95)
})

test('privileged access supports QR enrolment and recovery-code login', async ({ page }) => {
  const pendingUser = { id: 8, name: 'Synthetic Administrator', email: 'admin@example.test', phone: null, user_type: 'panel_member', status: 'active', is_privileged: true, mfa_confirmed: false, must_change_password: false, scopes: [] }
  let recoveryPayload: Record<string, unknown> | null = null
  await page.route('**/api/v1/auth/login', async (route) => {
    const body = route.request().postDataJSON() as Record<string, unknown>
    if (body.recovery_code) {
      recoveryPayload = body
      await route.fulfill({ json: { token: 'recovery-token', user: { ...pendingUser, mfa_confirmed: true } } })
    } else {
      await route.fulfill({ json: { token: 'enrol-token', user: pendingUser, requires_mfa_enrolment: true } })
    }
  })
  await page.route('**/api/v1/auth/mfa/enrol', async (route) => route.fulfill({ json: { method: 'authenticator', provisioning_uri: 'otpauth://totp/UPS%20e-Recruit:admin%40example.test?secret=SYNTHETICSECRET&issuer=UPS%20e-Recruit&algorithm=SHA1&digits=6&period=30', recovery_codes: ['ABCDE-12345', 'FGHIJ-67890'] } }))
  await page.route('**/api/v1/applications', async (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/reports/dashboard', async (route) => route.fulfill({ status: 403, json: { message: 'Not available for this role.' } }))

  await page.goto('/access')
  await page.getByLabel('Email address or phone').fill('admin@example.test')
  await page.getByLabel('Password', { exact: true }).fill('SyntheticPassword2026')
  await page.locator('form').getByRole('button', { name: 'Sign in' }).click()
  await page.getByRole('button', { name: 'Begin MFA enrolment' }).click()
  await expect(page.getByRole('img', { name: /QR code for setting up/i })).toBeVisible()
  await expect(page.getByText(/Authenticator codes refresh in/)).toBeVisible()
  await expect(page.getByText('ABCDE-12345')).toBeVisible()

  await page.evaluate(() => localStorage.clear())
  await page.reload()
  await page.getByLabel('Email address or phone').fill('admin@example.test')
  await page.getByLabel('Password', { exact: true }).fill('SyntheticPassword2026')
  await page.getByRole('checkbox', { name: /Use a recovery code/i }).check()
  await page.getByLabel('Recovery code', { exact: true }).fill('ABCDE-12345')
  await page.locator('form').getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/dashboard$/)
  expect(recoveryPayload).toMatchObject({ recovery_code: 'ABCDE-12345' })
})

test('privileged access supports email-code MFA enrolment and confirmation', async ({ page }) => {
  const pendingUser = { id: 18, name: 'Synthetic Email Administrator', email: 'email-admin@example.test', phone: null, user_type: 'panel_member', status: 'active', is_privileged: true, mfa_method: null, mfa_confirmed: false, must_change_password: false, scopes: [] }
  let enrolmentPayload: Record<string, unknown> | null = null
  let confirmationPayload: Record<string, unknown> | null = null
  await page.route('**/api/v1/auth/login', async (route) => route.fulfill({ json: { token: 'email-enrol-token', user: pendingUser, requires_mfa_enrolment: true } }))
  await page.route('**/api/v1/auth/mfa/enrol', async (route) => {
    enrolmentPayload = route.request().postDataJSON() as Record<string, unknown>
    await route.fulfill({ json: { method: 'email', challenge_id: '01JEMAILENROLMENT000000000', challenge_token: 'e'.repeat(64), masked_email: 'em•••••••••@example.test', expires_in: 300, resend_available_in: 60, recovery_codes: ['EMAIL-12345'] } })
  })
  await page.route('**/api/v1/auth/mfa/confirm', async (route) => {
    confirmationPayload = route.request().postDataJSON() as Record<string, unknown>
    await route.fulfill({ json: { token: 'email-active-token', user: { ...pendingUser, mfa_method: 'email', mfa_confirmed: true } } })
  })
  await page.route('**/api/v1/applications', async (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/reports/dashboard', async (route) => route.fulfill({ status: 403, json: { message: 'Not available for this role.' } }))

  await page.goto('/access')
  await page.getByLabel('Email address or phone').fill('email-admin@example.test')
  await page.getByLabel('Password', { exact: true }).fill('SyntheticPassword2026')
  await page.locator('form').getByRole('button', { name: 'Sign in' }).click()
  await page.getByRole('radio', { name: /Email code/i }).check()
  await page.getByRole('button', { name: 'Begin MFA enrolment' }).click()
  await expect(page.getByRole('heading', { name: 'Confirm the email code' })).toBeVisible()
  await expect(page.getByText('EMAIL-12345')).toBeVisible()
  await expect(page.getByRole('button', { name: /Resend in 60s/ })).toBeDisabled()
  await page.getByLabel('Email security code').fill('246810')
  await page.getByRole('button', { name: 'Activate MFA' }).click()

  await expect(page).toHaveURL(/\/dashboard$/)
  expect(enrolmentPayload).toMatchObject({ method: 'email' })
  expect(confirmationPayload).toMatchObject({ challenge_id: '01JEMAILENROLMENT000000000', code: '246810' })
})

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

test('technical administrator lands on user administration after replacing a temporary password', async ({ page }) => {
  const administrator = (mustChangePassword: boolean) => ({
    id: 1,
    name: 'Synthetic Technical Administrator',
    email: 'system_administrator@example.test',
    phone: null,
    user_type: 'system_administrator',
    status: 'active',
    is_privileged: true,
    mfa_confirmed: true,
    must_change_password: mustChangePassword,
    scopes: [],
  })
  let recruitmentDashboardRequests = 0

  await page.route('**/api/v1/auth/login', async (route) => route.fulfill({
    json: { token: 'temporary-technical-admin-token', requires_password_change: true, user: administrator(true) },
  }))
  await page.route('**/api/v1/auth/password', async (route) => route.fulfill({
    json: { token: 'permanent-technical-admin-token', user: administrator(false) },
  }))
  await page.route('**/api/v1/admin/roles', async (route) => route.fulfill({ json: { data: [] } }))
  await page.route('**/api/v1/admin/scope-options', async (route) => route.fulfill({ json: { data: { tasks: [], references: {} } } }))
  await page.route('**/api/v1/admin/users*', async (route) => route.fulfill({
    json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } },
  }))
  await page.route('**/api/v1/reports/dashboard', async (route) => {
    recruitmentDashboardRequests += 1
    await route.fulfill({ status: 403, json: { message: 'Forbidden.' } })
  })

  await page.goto('/access')
  await page.getByLabel('Email address or phone').fill('system_administrator@example.test')
  await page.getByLabel('Password', { exact: true }).fill('ChangeMe!2026')
  await page.locator('button.button.primary.full').click()
  await expect(page.getByRole('heading', { name: 'Replace the temporary password' })).toBeVisible()
  await page.locator('input[autocomplete="new-password"]').nth(0).fill('PermanentTechnicalPass2026')
  await page.locator('input[autocomplete="new-password"]').nth(1).fill('PermanentTechnicalPass2026')
  await page.getByRole('button', { name: 'Change password and continue' }).click()

  await expect(page).toHaveURL('http://127.0.0.1:4173/staff/users')
  await expect(page.getByRole('heading', { name: 'User and administrator accounts' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Dashboard' })).toHaveCount(0)
  expect(recruitmentDashboardRequests).toBe(0)
})

test('technical administrator provisions and secures staff accounts', async ({ page }) => {
  await technicalAdministratorSession(page)
  const roles = [
    { code: 'applicant', name: 'Applicant', is_decision_role: false, is_privileged: false },
    { code: 'helpdesk_officer', name: 'Helpdesk Officer', is_decision_role: false, is_privileged: false },
    { code: 'system_administrator', name: 'System Administrator', is_decision_role: false, is_privileged: true },
  ]
  let created = false
  let savedScopePayload: Record<string, unknown> | null = null
  const managedUser = () => ({ id: 9, name: 'Synthetic Helpdesk Officer', email: 'helpdesk-new@example.test', phone: null, user_type: 'helpdesk_officer', status: 'active', is_privileged: false, must_change_password: true, mfa_enabled: false, mfa_confirmed: false, last_login_at: null, password_changed_at: null, entity_version: created ? 2 : 1, roles: [{ code: 'helpdesk_officer', name: 'Helpdesk Officer' }], scopes: savedScopePayload ? [{ scope_type: 'national', scope_id: null, allowed_tasks: ['view:operations'], expires_at: null }] : [] })
  await page.route('**/api/v1/admin/roles', async (route) => route.fulfill({ json: { data: roles } }))
  await page.route('**/api/v1/admin/scope-options', async (route) => route.fulfill({ json: { data: { tasks: [{ value: 'view:operations', label: 'View operational registers' }], references: {} } } }))
  await page.route('**/api/v1/admin/users*', async (route) => {
    if (route.request().method() === 'POST') { created = true; await route.fulfill({ status: 201, json: { user: managedUser(), temporary_password: 'Random-One-Time-2026', message: 'Account created. The temporary password is shown once.' } }) }
    else await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } } })
  })
  await page.route('**/api/v1/admin/users/9/password-reset', async (route) => route.fulfill({ json: { user: managedUser(), temporary_password: 'Replacement-One-Time-2026', message: 'Password reset. The temporary password is shown once and all sessions were revoked.' } }))
  await page.route('**/api/v1/admin/users/9/scopes', async (route) => { savedScopePayload = route.request().postDataJSON() as Record<string, unknown>; await route.fulfill({ json: { user: managedUser() } }) })

  await page.goto('/staff/users')
  await expect(page.getByRole('heading', { name: 'User and administrator accounts' })).toBeVisible()
  await page.getByRole('button', { name: 'Create staff account' }).click()
  await expect(page.getByRole('dialog', { name: 'Create a staff account' })).toBeVisible()
  await page.getByLabel('Full name').fill('Synthetic Helpdesk Officer')
  await page.getByLabel('Email address').fill('helpdesk-new@example.test')
  await page.getByLabel('Initial role').fill('Helpdesk Officer')
  await page.getByRole('option', { name: /Helpdesk Officer/ }).click()
  await page.getByRole('button', { name: 'Create secure account' }).click()
  await expect(page.getByText('Random-One-Time-2026')).toBeVisible()
  await page.getByRole('button', { name: 'Security operations' }).click()
  await page.getByLabel('Reason for security action').fill('Approved synthetic account recovery test.')
  await page.getByRole('button', { name: 'Issue temporary password' }).click()
  await expect(page.getByText('Replacement-One-Time-2026')).toBeVisible()
  await page.getByRole('button', { name: 'Authorisation scopes' }).click()
  await page.getByRole('button', { name: 'Add scope' }).click()
  await page.getByLabel('Reason for scope change').fill('Assign the approved whole-service operations view.')
  await page.getByRole('button', { name: 'Replace scopes' }).click()
  expect(savedScopePayload).toMatchObject({ scopes: [{ scope_type: 'national', scope_id: null, allowed_tasks: ['view:operations'], expires_at: null }] })
  expect(JSON.stringify(savedScopePayload)).not.toContain('scope_label')
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
  await page.route('**/api/v1/education-qualification-levels', async (route) => route.fulfill({ json: { data: [{
    label: 'School education',
    options: [{
      value: 'UCE',
      label: 'Uganda Certificate of Education (UCE / O-Level) — National Level 2',
      guidance: 'Choose the overall UCE result printed on the result slip or certificate.',
      directory_searchable: true,
      results: [{ value: 'Result 1 (Certificate awarded)', label: 'Result 1 — certificate awarded (current curriculum)' }],
    }],
  }] } }))
  await page.route('**/api/v1/education-institutions*', async (route) => route.fulfill({ json: { data: [{ id: 'institution-1', name: 'Synthetic Secondary School', institution_type: 'Secondary School', district: 'Kampala', registration_number: 'EMIS-1', registration_status: 'Registered', operational_status: 'Active', source: 'moes_emis', source_url: 'https://emis.go.ug/emis/public-search', last_verified_at: '2026-09-09T00:00:00Z' }] } }))

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
  await levelSelect.fill('Uganda Certificate of Education')
  await page.getByRole('option', { name: /Uganda Certificate of Education.*National Level 2/i }).click()
  await resultSelect.fill('Result 1')
  await page.getByRole('option', { name: /Result 1.*certificate awarded/i }).click()
  await page.getByRole('combobox', { name: 'Institution', exact: true }).fill('Synthetic Secondary School')
  await page.getByRole('option', { name: /Synthetic Secondary School/ }).click()
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
    application: { id: 'app-1', reference: 'UPS/2026/WRD/000001', applicant_name: 'Synthetic Applicant', entered_data: { personal: { full_name: 'Synthetic Applicant', date_of_birth: '2001-05-12' }, origin: { district: 'Kampala', county: 'Kampala City', subcounty: 'Central Division', parish: 'Old Kampala', village: 'Namirembe' }, education: [{ level: 'UCE', institution: 'Synthetic School', result: 'Division 1', completion_year: 2024 }], declaration: { accepted: true } } },
    documents: [{ id: 'doc-1', type: 'national_id', label: 'National ID', filename: 'synthetic-national-id.pdf', version: 1, preview_url: '/api/v1/documents/doc-1/download', quality: { status: 'review' }, fields: [{ field_key: 'name', raw_value: 'SYNTHETIC APPLICANT', confidence: 0.91, page_number: 1, bounding_polygon: [0, 0, 1, 1] }] }],
    comparisons: [], verified_values: [],
    evidence_matrix: { name: [{ document_id: 'doc-1', source_label: 'National ID — version 1', source_filename: 'synthetic-national-id.pdf', value: 'SYNTHETIC APPLICANT', confidence: 0.91, page: 1, bounding_polygon: { x: 0.1, y: 0.2, width: 0.4, height: 0.05, coordinate_space: 'normalised' } }] },
  }
  await page.route('**/api/v1/applications/app-1/verification-workbench', async (route) => route.fulfill({ json: workbench }))
  await page.route('**/api/v1/documents/doc-1/download', async (route) => route.fulfill({ contentType: 'application/pdf', body: '%PDF-1.4\n%%EOF' }))
  await page.route('**/api/v1/documents/doc-1/verification', async (route) => route.fulfill({ status: 201, json: { decision: { id: 'decision-1' } } }))

  await page.goto('/staff/verification/app-1')
  await expect(page.getByRole('heading', { name: 'Field-by-field comparison' })).toBeVisible()
  await expect(page.getByText('Kampala, Kampala City, Central Division, Old Kampala, Namirembe')).toBeVisible()
  await expect(page.getByText('Yes')).toBeVisible()
  await expect(page.locator('body')).not.toContainText('doc-1')
  const documentPane = await page.locator('.document-rail').boundingBox()
  const evidencePane = await page.locator('.evidence-panel').boundingBox()
  expect(Math.abs(documentPane!.width - evidencePane!.width)).toBeLessThanOrEqual(1)
  const sourceValue = page.getByRole('button', { name: /SYNTHETIC APPLICANT.*Focus original source/i })
  await expect(sourceValue).toBeVisible()
  await sourceValue.click()
  await expect(page.getByText(/Focused evidence source: page 1/)).toBeVisible()
  await page.getByLabel('Verified/corrected value').fill('Synthetic Applicant')
  await page.getByRole('button', { name: 'Record versioned decision' }).click()
  await expect(page.getByText('Versioned verification decision recorded.')).toBeVisible()
})

test('submitted applicant sees an auditable status timeline and secure inbox', async ({ page }) => {
  await applicantSession(page)
  await page.route('**/api/v1/applications/app-1', async (route) => route.fulfill({ json: { data: {
    id: 'app-1', reference: 'UPS/2026/WRD/000001', status: 'awaiting_hard_copies', entity_version: 3,
    submitted_at: '2026-09-02T08:00:00Z', documents: [],
    campaign: { id: 'campaign-1', code: 'UPS-2026', name: 'UPS Recruitment 2026', year: 2026, status: 'published', opens_at: '2026-09-01T00:00:00Z', closes_at: '2026-09-30T20:59:00Z', hard_copy_deadline_at: '2026-10-05T14:00:00Z', privacy_notice: {} },
    post: { id: 'post-1', code: 'WARDER', name: 'Recruit Warder', description: '', sections: {}, hard_copy_required: true },
    stages: ['application', 'hard_copy', 'verification', 'eligibility', 'interview', 'selection', 'medical', 'training'].map((stage_code, index) => ({ stage_code, name: stage_code, sequence: index + 1, required: true })),
    timeline: [{ status: 'submitted_online', reason: 'Internal submission notes must not appear.', at: '2026-09-02T08:00:00Z' }],
  } } }))
  await page.route('**/api/v1/notifications', async (route) => route.fulfill({ json: { notifications: { data: [{ id: 'notice-1', event_code: 'application.submitted', status: 'delivered', read_at: null, created_at: '2026-09-02T08:01:00Z' }] } } }))
  await page.route('**/api/v1/notifications/push/config', async (route) => route.fulfill({ json: { enabled: false, public_key: '' } }))
  await page.route('**/api/v1/notifications/notice-1/read', async (route) => route.fulfill({ json: { notification: { id: 'notice-1', read_at: '2026-09-02T08:02:00Z' } } }))

  await page.goto('/applications/app-1/status')
  await expect(page.getByRole('heading', { name: 'UPS/2026/WRD/000001' })).toBeVisible()
  await expect(page.getByText('Submit the required originals or certified copies')).toBeVisible()
  await expect(page.getByRole('list', { name: 'Application progress' })).toContainText('Application Submitted')
  await expect(page.getByRole('list', { name: 'Application progress' })).toContainText('Documents Received')
  await expect(page.getByText('Internal submission notes must not appear.')).toHaveCount(0)
  const inboxItem = page.getByRole('button', { name: /application submitted/i })
  await expect(inboxItem).toHaveClass(/unread/)
  await inboxItem.click()
  await expect(inboxItem).not.toHaveClass(/unread/)
  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''))).toEqual([])
})

test('headquarters hard-copy clerk records a traceable physical receipt', async ({ page }) => {
  await staffSession(page, 'hard_copy_receiving_officer')
  await operationsLookups(page)
  await page.route('**/api/v1/applications/app-1/hard-copy-receipts', async (route) => {
    expect(route.request().postDataJSON()).not.toHaveProperty('receiving_office')
    await route.fulfill({ status: 201, json: { receipt: { id: 'receipt-1', receipt_number: 'HC/20260902/SYNTHETIC', status: 'received' } } })
  })
  await page.goto('/staff/operations')
  await page.getByRole('button', { name: /Record headquarters hard-copy receipt/ }).click()
  await expect(page.getByRole('dialog', { name: 'Record hard-copy receipt' })).toBeVisible()
  await page.getByRole('combobox', { name: /^Application/ }).fill('Synthetic')
  await page.getByRole('option', { name: /Synthetic Applicant.*UPS\/2026\/WRD\/000001/ }).click()
  await expect(page.getByText(/Uganda Prisons Service Headquarters/)).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Receiving office', exact: true })).toHaveCount(0)
  await expect(page.getByRole('checkbox', { name: /National ID received and matches/ })).toBeVisible()
  await page.getByRole('button', { name: 'Record accountable receipt' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: 'Hard-copy receipt recorded with a traceable receipt number.' })).toBeVisible()
})

test('centre coordinator previews district-safe allocation and records interview check-in', async ({ page }) => {
  await staffSession(page)
  await operationsLookups(page)
  const allocation = { id: 'allocation-1', run_number: 1, status: 'preview', candidate_count: 13, post: { name: 'Recruit Warder' }, region: { name: 'Central Prison Region' }, centres: [{ centre_name: 'Synthetic Centre', candidate_count: 13, total_load: 13, capacity: 20 }], districts: [{ district_name: 'Kampala', centre_name: 'Synthetic Centre', candidate_count: 13 }] }
  await page.route('**/api/v1/interview-allocation-runs/preview', async (route) => route.fulfill({ status: 201, json: { data: allocation } }))
  await page.route('**/api/v1/interview-allocation-runs/allocation-1/commit', async (route) => route.fulfill({ json: { data: { ...allocation, status: 'committed' } } }))
  await page.route('**/api/v1/interview-assignments/assignment-1/attendance', async (route) => route.fulfill({ json: { attendance: { id: 'attendance-1', status: 'present', entity_version: 1 } } }))
  await page.goto('/staff/operations')
  await page.getByRole('button', { name: /Allocate interview candidates/ }).click()
  await page.getByRole('combobox', { name: 'Recruitment post', exact: true }).fill('Warder')
  await page.getByRole('option', { name: /UPS Recruitment 2026.*Recruit Warder/ }).click()
  await page.getByRole('combobox', { name: 'Prison region', exact: true }).fill('Central')
  await page.getByRole('option', { name: 'Central Prison Region', exact: true }).click()
  await page.getByRole('button', { name: 'Preview district-balanced allocation' }).click()
  await expect(page.getByRole('heading', { name: 'Allocation version 1' })).toBeVisible()
  await page.getByText('District allocation (1)').click()
  await expect(page.getByRole('cell', { name: 'Kampala' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Synthetic Centre' }).last()).toBeVisible()
  await page.getByRole('button', { name: 'Commit allocation and queue invitations' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: /invitation generation has been queued/ })).toBeVisible()
  await page.getByRole('button', { name: /Record attendance/ }).click()
  await page.getByRole('combobox', { name: 'Interview assignment', exact: true }).fill('Synthetic')
  await page.getByRole('option', { name: /Synthetic Applicant.*UPS\/2026\/WRD\/000001/ }).click()
  await page.getByLabel('Attendance status').selectOption('present')
  await page.getByRole('dialog', { name: 'Record interview attendance' }).getByRole('button', { name: 'Record attendance', exact: true }).click()
  await expect(page.locator('.page-alert').filter({ hasText: 'Attendance recorded and audited.' })).toBeVisible()
})

test('panel head closes a reconciled session and fingerprints scores', async ({ page }) => {
  await staffSession(page)
  await operationsLookups(page)
  await page.route('**/api/v1/panels/panel-1/close', async (route) => route.fulfill({ json: { closure: { id: 'closure-1', score_fingerprint: 'c'.repeat(64) } } }))
  await page.goto('/staff/operations')
  await page.getByRole('button', { name: /Close panel/ }).click()
  await page.getByRole('combobox', { name: 'Panel session', exact: true }).fill('Panel A')
  await page.getByRole('option', { name: /Synthetic Centre.*Panel A/ }).click()
  await page.getByRole('checkbox', { name: /panel data is complete/i }).check()
  await page.getByRole('button', { name: 'Close and fingerprint panel' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: /Panel closed; submitted scores are fingerprinted/ })).toBeVisible()
})

test('HQ runs a reproducible selection scenario and certifies the official draft', async ({ page }) => {
  await staffSession(page)
  const officialRun = { id: 'selection-1', run_number: 4, mode: 'official', status: 'draft', input_fingerprint: 'a'.repeat(64), output_fingerprint: 'b'.repeat(64), outcomes_count: 2 }
  await page.route('**/api/v1/selection-runs', async (route) => {
    if (route.request().method() === 'POST') await route.fulfill({ status: 201, json: { run: officialRun, outcomes: [{ id: 'outcome-1', applicant_name: 'Amina Nabirye', application_reference: 'UPS/2026/WRD/000001', position: 1, outcome: 'selected', score: 91 }] } })
    else await route.fulfill({ json: { data: [officialRun] } })
  })
  await page.route('**/api/v1/selection/lookups', async (route) => route.fulfill({ json: { data: { ranking_runs: [{ id: 'ranking-1', label: 'Recruit Warder — ranking run 3', description: '24 ranked candidates' }], buckets: [{ value: 'north', label: 'North' }], skills: [] } } }))
  await page.route('**/api/v1/selection-runs/selection-1/certify', async (route) => route.fulfill({ json: { run: { ...officialRun, status: 'certified' } } }))
  await page.goto('/staff/selection')
  await page.getByRole('button', { name: 'Run selection scenario' }).click()
  await page.getByRole('combobox', { name: 'Completed ranking run' }).fill('Warder')
  await page.getByRole('option', { name: /Recruit Warder.*ranking run 3/ }).click()
  await page.getByLabel('Mode').selectOption('official')
  await page.getByRole('button', { name: 'Add quota group' }).click()
  await page.getByRole('combobox', { name: 'Quota group' }).fill('North')
  await page.getByRole('option', { name: 'North', exact: true }).click()
  await page.getByLabel('Places', { exact: true }).first().fill('1')
  await page.getByRole('button', { name: 'Run reproducible scenario' }).click()
  await expect(page.getByText('Reproducible selection scenario created.')).toBeVisible()
  await page.getByRole('button', { name: 'Certify with council approval' }).click()
  await page.getByLabel('Council approval reference').fill('COUNCIL-SYNTHETIC-2026')
  await page.getByLabel('Type CERTIFY to confirm').fill('CERTIFY')
  await page.getByRole('button', { name: 'Certify official run' }).click()
  await expect(page.getByText('Run 4 certified.')).toBeVisible()
})

test('governance officer places a legal hold through a human record directory', async ({ page }) => {
  await staffSession(page)
  await page.route('**/api/v1/governance/retention', async (route) => route.fulfill({ json: { policies: [], legal_holds: { data: [] }, purge_requests: { data: [] }, supported_purge_categories: ['notifications', 'exports', 'expired_upload_sessions'] } }))
  await page.route('**/api/v1/governance/legal-hold-targets?*', async (route) => route.fulfill({ json: { options: [{ value: 'notification-1', label: 'Application Submitted · UPS/2026/WRD/000001 · Amina Nabirye', description: 'Portal · Delivered · 15 Sep 2026' }] } }))
  await page.route('**/api/v1/governance/legal-holds', async (route) => route.fulfill({ status: 201, json: { duplicate: false, legal_hold: { id: 'hold-1' } } }))

  await page.goto('/staff/governance')
  await page.getByRole('button', { name: 'Place legal hold' }).click()
  await page.getByRole('combobox', { name: 'Record to protect' }).fill('Amina')
  await page.getByRole('option', { name: /Application Submitted.*Amina Nabirye/ }).click()
  await page.getByLabel('Reason').fill('Preserve this notification for an authorised case review.')
  await page.getByRole('button', { name: 'Place hold', exact: true }).click()
  await expect(page.getByText('Legal hold placed; matching purge candidates are excluded.')).toBeVisible()
  await expect(page.getByText('notification-1', { exact: true })).toHaveCount(0)
})

test('medical outcome gates an independently approved strict-order reserve replacement', async ({ page }) => {
  await staffSession(page)
  await operationsLookups(page)
  await page.route('**/api/v1/medical/results', async (route) => route.fulfill({ status: 201, json: { result: { id: 'medical-1', application_id: 'reserve-app', outcome: 'Fit' } } }))
  await page.route('**/api/v1/training/replacement-recommendations', async (route) => route.fulfill({ status: 201, json: { recommendation: { id: 'recommendation-1', reserve_application_id: 'reserve-app', status: 'pending_approval' } } }))
  await page.route('**/api/v1/training/replacement-recommendations/recommendation-1/decision', async (route) => route.fulfill({ json: { recommendation: { id: 'recommendation-1', status: 'approved' } } }))
  await page.goto('/staff/operations')
  await page.getByRole('button', { name: /Record restricted result/ }).click()
  await page.getByRole('combobox', { name: 'Candidate', exact: true }).fill('Reserve')
  await page.getByRole('option', { name: /Reserve Candidate.*UPS\/2026\/WRD\/000002/ }).click()
  await page.getByRole('combobox', { name: 'Medical schedule', exact: true }).fill('Synthetic Medical')
  await page.getByRole('option', { name: /Synthetic Medical Centre/ }).click()
  await page.getByRole('combobox', { name: 'Outcome', exact: true }).selectOption('Fit')
  await page.getByRole('dialog', { name: 'Record restricted medical result' }).getByRole('button', { name: 'Record restricted result', exact: true }).click()
  await expect(page.locator('.page-alert').filter({ hasText: /Restricted medical result recorded/ })).toBeVisible()
  await page.getByRole('button', { name: /Recommend next reserve/ }).click()
  await page.getByRole('combobox', { name: 'Candidate being replaced', exact: true }).fill('Selected')
  await page.getByRole('option', { name: /Selected Candidate.*UPS\/2026\/WRD\/000003/ }).click()
  await page.getByRole('combobox', { name: 'Certified selection run', exact: true }).fill('Warder')
  await page.getByRole('option', { name: /Recruit Warder.*certified run 4/ }).click()
  await page.getByLabel('Reason', { exact: true }).fill('Synthetic candidate did not report for training intake.')
  await page.getByRole('button', { name: 'Recommend strict-order reserve' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: /recommended for independent approval/ })).toBeVisible()
  await page.getByRole('button', { name: /Decide reserve replacement/ }).click()
  await page.getByRole('combobox', { name: 'Pending recommendation', exact: true }).fill('Selected')
  await page.getByRole('option', { name: /Selected Candidate.*UPS\/2026\/WRD\/000003/ }).click()
  await page.getByLabel('Decision reason').fill('Independent synthetic review confirms the next reserve candidate.')
  await page.getByLabel('Approval reference', { exact: true }).fill('COUNCIL-SYNTHETIC-2026')
  await page.getByRole('button', { name: 'Record independent decision' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: 'Reserve replacement approved by an independent authority.' })).toBeVisible()
})

test('PATS issues a training invitation and records candidate reporting', async ({ page }) => {
  await staffSession(page)
  await operationsLookups(page)
  await page.route('**/api/v1/training/invitations', async (route) => route.fulfill({ status: 201, json: { invite: { id: 'training-invite-1', final_selection_id: 'final-1' } } }))
  await page.route('**/api/v1/training/reporting', async (route) => route.fulfill({ status: 201, json: { reporting: { id: 'report-1', status: 'admitted' } } }))
  await page.goto('/staff/operations')
  await page.getByRole('button', { name: /Issue training invitation/ }).click()
  await page.getByRole('combobox', { name: 'Approved candidate', exact: true }).fill('Reserve')
  await page.getByRole('option', { name: /Reserve Candidate.*UPS\/2026\/WRD\/000002/ }).click()
  await page.getByLabel('Date', { exact: true }).last().fill('2026-10-15')
  await page.getByLabel('Training location').fill('Synthetic Training School')
  await page.getByRole('button', { name: 'Issue protected invitation' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: /Training invitation issued/ })).toBeVisible()
  await page.getByRole('button', { name: /Record training reporting/ }).click()
  await page.getByRole('combobox', { name: 'Training invitation', exact: true }).fill('Reserve')
  await page.getByRole('option', { name: /Reserve Candidate.*UPS\/2026\/WRD\/000002/ }).click()
  await page.getByRole('combobox', { name: 'Status', exact: true }).fill('admitted')
  await page.getByRole('option', { name: 'Admitted', exact: true }).click()
  await page.getByRole('button', { name: 'Record reporting status' }).click()
  await expect(page.locator('.page-alert').filter({ hasText: 'Training reporting status recorded and audited.' })).toBeVisible()
})

test('panel user checks in and scores offline, reloads locked, then reconciles once', async ({ page, context }) => {
  await staffSession(page)
  await page.route('**/api/v1/offline/devices', async (route) => route.fulfill({ status: 201, json: { device: { id: 'device-1' } } }))
  await page.route('**/api/v1/offline/reference-options?*', async (route) => {
    const packType = new URL(route.request().url()).searchParams.get('pack_type')
    const score = packType === 'score_capture'
    await route.fulfill({ json: { options: [{ value: score ? 'score-1' : 'assignment-1', label: 'Synthetic Applicant · UPS/SYNTHETIC', description: score ? 'Assessment score' : 'Interview attendance' }], medical_schedules: [] } })
  })
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
  await page.getByRole('combobox', { name: 'Records to include' }).fill('Synthetic')
  await page.getByRole('option', { name: /Synthetic Applicant.*UPS\/SYNTHETIC/ }).click()
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
  await page.getByRole('combobox', { name: 'Records to include' }).fill('Synthetic')
  await page.getByRole('option', { name: /Synthetic Applicant.*UPS\/SYNTHETIC/ }).click()
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
  test.setTimeout(60_000)
  const secondContext = await browser.newContext()
  const secondPage = await secondContext.newPage()
  let secondResolved = false

  async function prepareDevice(devicePage: import('@playwright/test').Page, suffix: 'a' | 'b') {
    await staffSession(devicePage)
    await devicePage.route('**/api/v1/offline/devices', async (route) => route.fulfill({ status: 201, json: { device: { id: `device-${suffix}` } } }))
    await devicePage.route('**/api/v1/offline/reference-options?*', async (route) => route.fulfill({ json: { options: [{ value: 'shared-score', label: 'Shared Candidate · UPS/SYNTHETIC/SHARED', description: 'Assessment score' }], medical_schedules: [] } }))
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
    await devicePage.getByRole('combobox', { name: 'Records to include' }).fill('Shared')
    await devicePage.getByRole('option', { name: /Shared Candidate.*UPS\/SYNTHETIC\/SHARED/ }).click()
    await devicePage.getByRole('button', { name: 'Issue scoped pack' }).click()
    await devicePage.getByRole('spinbutton', { name: 'Score', exact: true }).fill(suffix === 'a' ? '81' : '79')
    await devicePage.getByRole('button', { name: 'Queue encrypted event' }).click()
  }

  try {
    await prepareDevice(page, 'a')
    await prepareDevice(secondPage, 'b')
    await page.getByRole('button', { name: 'Synchronise now' }).click()
    await secondPage.getByRole('button', { name: 'Synchronise now' }).click()
    await expect(secondPage.getByText(/Local value: 79.*Server value: 81/)).toBeVisible()
    await secondPage.getByLabel('Resolution reason').fill('Retain the first device score after supervisor evidence review.')
    await secondPage.getByRole('button', { name: 'Keep server value' }).click()
    await expect(secondPage.getByText(/Conflict resolved by retaining the current server value/)).toBeVisible()
  } finally { await secondContext.close() }
})
