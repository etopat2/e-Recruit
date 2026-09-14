import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../lib/api'
import AccessView from './AccessView.vue'

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,cXItY29kZQ==') },
}))

afterEach(() => {
  cleanup()
  clearApiCache()
  localStorage.clear()
  vi.unstubAllGlobals()
})

function staffUser() {
  return { id: 8, name: 'Synthetic Administrator', email: 'admin@example.test', phone: null, user_type: 'system_administrator', status: 'active', is_privileged: true, mfa_confirmed: false, must_change_password: true, scopes: [] }
}

async function renderAccess() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/access', component: AccessView },
      { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/staff/users', component: { template: '<div>Users</div>' } },
    ],
  })
  await router.push('/access')
  await router.isReady()
  render(AccessView, { global: { plugins: [createPinia(), router] } })
  return router
}

describe('AccessView MFA', () => {
  it('submits a recovery code instead of a TOTP code when requested', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ token: 'token', user: { ...staffUser(), mfa_confirmed: true, must_change_password: false } }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await renderAccess()

    await fireEvent.update(screen.getByLabelText('Email address or phone'), 'admin@example.test')
    await fireEvent.update(screen.getByLabelText('Password'), 'SyntheticPassword2026')
    await fireEvent.click(screen.getByRole('checkbox', { name: /Use a recovery code/i }))
    await fireEvent.update(screen.getByLabelText('Recovery code'), 'ABCDE-12345')
    await fireEvent.click(screen.getAllByRole('button', { name: 'Sign in' }).at(-1)!)

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    const options = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect(JSON.parse(String(options.body))).toMatchObject({ recovery_code: 'ABCDE-12345' })
    expect(JSON.parse(String(options.body)).totp_code).toBeUndefined()
  })

  it('renders a QR code and can rotate an incomplete enrolment secret', async () => {
    const responses = [
      { token: 'enrol-token', user: staffUser(), requires_mfa_enrolment: true },
      { method: 'authenticator', provisioning_uri: 'otpauth://totp/UPS:test?secret=FIRST', recovery_codes: ['FIRST-CODE1'] },
      { method: 'authenticator', provisioning_uri: 'otpauth://totp/UPS:test?secret=SECOND', recovery_codes: ['SECOND-CODE'] },
    ]
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify(responses.shift()), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    vi.stubGlobal('fetch', fetchMock)
    await renderAccess()

    await fireEvent.update(screen.getByLabelText('Email address or phone'), 'admin@example.test')
    await fireEvent.update(screen.getByLabelText('Password'), 'SyntheticPassword2026')
    await fireEvent.click(screen.getAllByRole('button', { name: 'Sign in' }).at(-1)!)
    await fireEvent.click(await screen.findByRole('button', { name: 'Begin MFA enrolment' }))

    expect(await screen.findByRole('img', { name: /QR code for setting up/i })).toBeInTheDocument()
    expect(screen.getByText('FIRST-CODE1')).toBeInTheDocument()
    await fireEvent.click(screen.getByRole('button', { name: 'Restart this method' }))
    expect(await screen.findByText('SECOND-CODE')).toBeInTheDocument()
    expect(fetchMock.mock.calls[2]?.[0]).toContain('/auth/mfa/restart')
  })

  it('enrols email as the fixed second factor and confirms its purpose-bound code', async () => {
    const responses = [
      { token: 'enrol-token', user: staffUser(), requires_mfa_enrolment: true },
      { method: 'email', challenge_id: '01JEMAILENROLMENT000000000', challenge_token: 't'.repeat(64), masked_email: 'ad•••@example.test', expires_in: 300, resend_available_in: 60, recovery_codes: ['EMAIL-CODE1'] },
      { token: 'active-token', user: { ...staffUser(), mfa_method: 'email', mfa_confirmed: true }, requires_password_change: false },
    ]
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify(responses.shift()), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    vi.stubGlobal('fetch', fetchMock)
    await renderAccess()

    await fireEvent.update(screen.getByLabelText('Email address or phone'), 'admin@example.test')
    await fireEvent.update(screen.getByLabelText('Password'), 'SyntheticPassword2026')
    await fireEvent.click(screen.getAllByRole('button', { name: 'Sign in' }).at(-1)!)
    await fireEvent.click(await screen.findByRole('radio', { name: /Email code/i }))
    await fireEvent.click(screen.getByRole('button', { name: 'Begin MFA enrolment' }))

    expect(await screen.findByRole('heading', { name: 'Confirm the email code' })).toBeInTheDocument()
    expect(screen.getByText('EMAIL-CODE1')).toBeInTheDocument()
    expect(screen.queryByRole('img', { name: /QR code/i })).not.toBeInTheDocument()
    await fireEvent.update(screen.getByLabelText('Email security code'), '123456')
    await fireEvent.click(screen.getByRole('button', { name: 'Activate MFA' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
    expect(JSON.parse(String((fetchMock.mock.calls[1]?.[1] as RequestInit).body))).toMatchObject({ method: 'email' })
    expect(JSON.parse(String((fetchMock.mock.calls[2]?.[1] as RequestInit).body))).toMatchObject({
      challenge_id: '01JEMAILENROLMENT000000000',
      challenge_token: 't'.repeat(64),
      code: '123456',
    })
  })

  it('completes an enrolled email-code login without creating a session before verification', async () => {
    const responses = [
      { requires_email_otp: true, challenge_id: '01JEMAILLOGIN0000000000000', challenge_token: 'b'.repeat(64), masked_email: 'ad•••@example.test', expires_in: 300, resend_available_in: 60 },
      { token: 'verified-token', user: { ...staffUser(), mfa_method: 'email', mfa_confirmed: true, must_change_password: false } },
    ]
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify(responses.shift()), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    vi.stubGlobal('fetch', fetchMock)
    await renderAccess()

    await fireEvent.update(screen.getByLabelText('Email address or phone'), 'admin@example.test')
    await fireEvent.update(screen.getByLabelText('Password'), 'SyntheticPassword2026')
    await fireEvent.click(screen.getAllByRole('button', { name: 'Sign in' }).at(-1)!)

    expect(await screen.findByRole('heading', { name: 'Enter your security code' })).toBeInTheDocument()
    expect(localStorage.getItem('ups_auth_token')).toBeNull()
    await fireEvent.update(screen.getByLabelText('Email security code'), '654321')
    await fireEvent.click(screen.getByRole('button', { name: 'Verify and sign in' }))
    await waitFor(() => expect(localStorage.getItem('ups_auth_token')).toBe('verified-token'))
    expect(fetchMock.mock.calls[1]?.[0]).toContain('/auth/mfa/email/verify')
  })
})
