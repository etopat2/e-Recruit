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

async function signIn(): Promise<void> {
  await fireEvent.update(screen.getByLabelText('Email address or phone'), 'admin@example.test')
  await fireEvent.update(screen.getByLabelText('Password'), 'SyntheticPassword2026')
  await fireEvent.click(screen.getAllByRole('button', { name: 'Sign in' }).at(-1)!)
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

  it.each([
    ['captured', 'The code was captured by the development mailbox; it was not delivered to an external inbox.'],
    ['submitted', 'The mail server accepted the security code. Inbox delivery is not confirmed.'],
  ])('shows the API delivery message for %s login mail without claiming delivery', async (delivery_status, delivery_message) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      requires_email_otp: true, challenge_id: 'login-mail', challenge_token: 'b'.repeat(64),
      masked_email: 'ad***@example.test', expires_in: 300, resend_available_in: 60,
      delivery_status, delivery_message,
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    await renderAccess()
    await signIn()

    expect(await screen.findByText(delivery_message)).toHaveAttribute('role', 'status')
    expect(screen.queryByText(/code was sent to/i)).not.toBeInTheDocument()
    expect(localStorage.getItem('ups_auth_token')).toBeNull()
  })

  it('updates delivery reporting on resend and shows SMTP failures without stale success text', async () => {
    const challenge = { challenge_id: 'resend-mail', challenge_token: 'b'.repeat(64), masked_email: 'ad***@example.test', expires_in: 300, resend_available_in: 0 }
    const responses = [
      { status: 200, body: { ...challenge, requires_email_otp: true, delivery_status: 'captured', delivery_message: 'Code captured in the local development mailbox.' } },
      { status: 200, body: { ...challenge, delivery_status: 'submitted', delivery_message: 'The mail server accepted the replacement code; delivery is not confirmed.' } },
      { status: 503, body: { message: 'The security email could not be submitted. Please try again.' } },
    ]
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => {
      const response = responses.shift()!
      return Promise.resolve(new Response(JSON.stringify(response.body), { status: response.status, headers: { 'Content-Type': 'application/json' } }))
    }))
    await renderAccess()
    await signIn()

    expect(await screen.findByText('Code captured in the local development mailbox.')).toHaveAttribute('role', 'status')
    await fireEvent.click(screen.getByRole('button', { name: 'Resend email code' }))
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('The mail server accepted the replacement code; delivery is not confirmed.'))
    await fireEvent.click(screen.getByRole('button', { name: 'Resend email code' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The security email could not be submitted. Please try again.')
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('does not advance to the code-entry screen when email submission fails at login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      message: 'The security email could not be submitted. Please try again.',
    }), { status: 503, headers: { 'Content-Type': 'application/json' } })))
    await renderAccess()
    await signIn()

    expect(await screen.findByRole('alert')).toHaveTextContent('The security email could not be submitted. Please try again.')
    expect(screen.queryByRole('heading', { name: 'Enter your security code' })).not.toBeInTheDocument()
    expect(localStorage.getItem('ups_auth_token')).toBeNull()
  })

  it.each([
    ['queued', 'Your recovery-code email is queued. Delivery is not yet confirmed.'],
    ['unavailable', 'Your recovery-code email could not be queued. Save the codes shown here.'],
    ['not_available', 'No pending recovery-code email is available. Save the codes shown here.'],
  ])('preserves recovery codes after MFA activation and displays %s mail status before continuing', async (recovery_email_status, recovery_email_message) => {
    const responses = [
      { token: 'enrol-token', user: staffUser(), requires_mfa_enrolment: true },
      { method: 'email', challenge_id: 'enrol-mail', challenge_token: 't'.repeat(64), masked_email: 'ad***@example.test', expires_in: 300, resend_available_in: 60, recovery_codes: ['EMAIL-CODE1'], delivery_status: 'captured', delivery_message: 'Code captured locally, not sent to an external inbox.' },
      { token: 'confirmed-token', user: { ...staffUser(), mfa_method: 'email', mfa_confirmed: true }, requires_password_change: true, recovery_email_status, recovery_email_message },
    ]
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify(responses.shift()), { status: 200, headers: { 'Content-Type': 'application/json' } }))))
    const router = await renderAccess()
    await signIn()
    await fireEvent.click(await screen.findByRole('radio', { name: /Email code/i }))
    await fireEvent.click(screen.getByRole('button', { name: 'Begin MFA enrolment' }))

    expect(await screen.findByText('Code captured locally, not sent to an external inbox.')).toHaveAttribute('role', 'status')
    await fireEvent.update(screen.getByLabelText('Email security code'), '123456')
    await fireEvent.click(screen.getByRole('button', { name: 'Activate MFA' }))

    expect(await screen.findByRole('heading', { name: 'MFA is active' })).toBeInTheDocument()
    expect(screen.getByText(recovery_email_message)).toBeInTheDocument()
    expect(screen.getByText('EMAIL-CODE1')).toBeInTheDocument()
    expect(router.currentRoute.value.path).toBe('/access')
    expect(localStorage.getItem('ups_auth_token')).toBe('confirmed-token')
    await fireEvent.click(screen.getByRole('button', { name: 'Continue to change password' }))
    expect(await screen.findByRole('heading', { name: 'Replace the temporary password' })).toBeInTheDocument()
  })
})
