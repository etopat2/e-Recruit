<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import QRCode from 'qrcode'
import { useRoute, useRouter } from 'vue-router'
import FieldError from '../components/FieldError.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import { api, ApiError, jsonBody, setAuthToken } from '../lib/api'
import { useSessionStore, type EmailOtpChallengePayload } from '../stores/session'
import type { User } from '../types'

type MfaMethod = 'authenticator' | 'email'
type AccessPhase = 'access' | 'enrol' | 'confirm' | 'email-login' | 'password'

interface MfaEnrollmentResponse extends Partial<EmailOtpChallengePayload> {
  method: MfaMethod
  provisioning_uri?: string
  recovery_codes: string[]
}

const tab = ref<'login' | 'register'>('login')
const phase = ref<AccessPhase>('access')
const busy = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const provisioningUri = ref('')
const qrDataUrl = ref('')
const recoveryCodes = ref<string[]>([])
const login = reactive({ identity: '', password: '', totp: '', recoveryCode: '' })
const useRecoveryCode = ref(false)
const registration = reactive({ first_name: '', middle_names: '', last_name: '', nin: '', phone: '', email: '', date_of_birth: '', sex: '', nationality: 'Ugandan', password: '', password_confirmation: '' })
const passwordChange = reactive({ current_password: '', password: '', password_confirmation: '' })
const confirmCode = ref('')
const mfaMethod = ref<MfaMethod>('authenticator')
const emailChallengeId = ref('')
const emailChallengeToken = ref('')
const maskedEmail = ref('')
const totpSecondsRemaining = ref(30)
const resendSecondsRemaining = ref(0)
let resendAvailableAt = 0
let countdownTimer = 0
const session = useSessionStore()
const router = useRouter()
const route = useRoute()

onMounted(() => {
  if (session.user?.is_privileged && !session.user.mfa_confirmed) {
    mfaMethod.value = session.user.mfa_method || 'authenticator'
    phase.value = 'enrol'
  } else if (session.user?.must_change_password) {
    phase.value = 'password'
  }
  updateCountdowns()
  countdownTimer = window.setInterval(updateCountdowns, 1000)
})

onBeforeUnmount(() => window.clearInterval(countdownTimer))

function updateCountdowns(): void {
  totpSecondsRemaining.value = 30 - (Math.floor(Date.now() / 1000) % 30)
  resendSecondsRemaining.value = Math.max(0, Math.ceil((resendAvailableAt - Date.now()) / 1000))
}

watch(provisioningUri, async (uri) => {
  qrDataUrl.value = ''
  if (!uri) return
  try {
    qrDataUrl.value = await QRCode.toDataURL(uri, { width: 260, margin: 2, errorCorrectionLevel: 'M' })
  } catch {
    message.value = 'The QR image could not be generated. Use the text setup key below.'
  }
})

function fail(problem: unknown): void {
  const error = problem as ApiError
  message.value = error.message || 'The request could not be completed.'
  fieldErrors.value = error.errors || {}
}

async function destination(): Promise<void> {
  if (route.query.campaign && route.query.post) {
    const response = await api<{ data: { id: string } }>('/applications', {
      method: 'POST',
      ...jsonBody({ campaign_id: route.query.campaign, post_id: route.query.post }),
    })
    await router.push(`/applications/${response.data.id}`)
  } else {
    await router.push(String(route.query.redirect || session.homePath))
  }
}

function applyEmailChallenge(response: EmailOtpChallengePayload): void {
  emailChallengeId.value = response.challenge_id
  emailChallengeToken.value = response.challenge_token
  maskedEmail.value = response.masked_email
  resendAvailableAt = Date.now() + (response.resend_available_in * 1000)
  updateCountdowns()
}

async function submitLogin(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await session.login(login.identity, login.password, useRecoveryCode.value ? '' : login.totp, useRecoveryCode.value ? login.recoveryCode : '')
    if (response.requires_email_otp) {
      applyEmailChallenge(response as EmailOtpChallengePayload)
      confirmCode.value = ''
      phase.value = 'email-login'
      return
    }
    if (response.requires_mfa_enrolment) {
      mfaMethod.value = response.user?.mfa_method || 'authenticator'
      phase.value = 'enrol'
      return
    }
    if (response.requires_password_change) {
      passwordChange.current_password = login.password
      phase.value = 'password'
      return
    }
    await destination()
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

async function enrolMfa(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<MfaEnrollmentResponse>('/auth/mfa/enrol', {
      method: 'POST',
      ...jsonBody({ password: login.password, method: mfaMethod.value }),
    })
    applyMfaEnrollment(response)
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

function applyMfaEnrollment(response: MfaEnrollmentResponse): void {
  mfaMethod.value = response.method
  provisioningUri.value = response.provisioning_uri || ''
  recoveryCodes.value = response.recovery_codes
  confirmCode.value = ''
  if (response.method === 'email') applyEmailChallenge(response as EmailOtpChallengePayload)
  phase.value = 'confirm'
}

async function restartMfaEnrollment(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<MfaEnrollmentResponse>('/auth/mfa/restart', {
      method: 'POST',
      ...jsonBody({ password: login.password, method: mfaMethod.value }),
    })
    applyMfaEnrollment(response)
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

function chooseAnotherMethod(): void {
  confirmCode.value = ''
  message.value = ''
  fieldErrors.value = {}
  phase.value = 'enrol'
}

async function resendEmailCode(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<Omit<EmailOtpChallengePayload, 'challenge_token'> & { message: string }>('/auth/mfa/email/resend', {
      method: 'POST',
      ...jsonBody({ challenge_id: emailChallengeId.value, challenge_token: emailChallengeToken.value }),
    })
    emailChallengeId.value = response.challenge_id
    maskedEmail.value = response.masked_email
    resendAvailableAt = Date.now() + (response.resend_available_in * 1000)
    updateCountdowns()
    message.value = ''
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

async function verifyEmailLogin(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User; requires_password_change?: boolean }>('/auth/mfa/email/verify', {
      method: 'POST',
      ...jsonBody({
        challenge_id: emailChallengeId.value,
        challenge_token: emailChallengeToken.value,
        code: confirmCode.value,
        device_name: navigator.userAgent.slice(0, 90),
      }),
    })
    setAuthToken(response.token)
    session.user = response.user
    if (response.requires_password_change) {
      passwordChange.current_password = login.password
      phase.value = 'password'
      return
    }
    await destination()
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

async function confirmMfa(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const payload = mfaMethod.value === 'email'
      ? { code: confirmCode.value, challenge_id: emailChallengeId.value, challenge_token: emailChallengeToken.value }
      : { code: confirmCode.value }
    const response = await api<{ token: string; user: User; requires_password_change?: boolean }>('/auth/mfa/confirm', {
      method: 'POST',
      ...jsonBody(payload),
    })
    setAuthToken(response.token)
    session.user = response.user
    if (response.requires_password_change) {
      passwordChange.current_password = login.password
      phase.value = 'password'
      return
    }
    await destination()
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

async function changePassword(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User }>('/auth/password', { method: 'PUT', ...jsonBody(passwordChange) })
    setAuthToken(response.token)
    session.user = response.user
    await destination()
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}

async function submitRegistration(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User }>('/auth/register', { method: 'POST', ...jsonBody(registration) })
    setAuthToken(response.token)
    session.user = response.user
    await destination()
  } catch (problem) {
    fail(problem)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="access-layout">
    <aside>
      <p class="eyebrow">Official access</p>
      <h1>Welcome to UPS e-Recruit</h1>
      <p>Use your own contact details. UPS will never ask you to pay a recruitment fee through this portal.</p>
      <div class="security-note"><strong>Security reminder</strong><p>Keep your password, security codes, and recovery codes private.</p></div>
    </aside>
    <div class="form-panel">
      <div v-if="phase === 'access'" class="tabs" role="tablist">
        <button :aria-selected="tab === 'login'" @click="tab = 'login'">Sign in</button>
        <button :aria-selected="tab === 'register'" @click="tab = 'register'">Create account</button>
      </div>
      <FormAlert v-if="message" kind="error" :message="message" />

      <form v-if="phase === 'access' && tab === 'login'" @submit.prevent="submitLogin">
        <h2>Sign in securely</h2>
        <label>Email address or phone<input v-model="login.identity" autocomplete="username" required /><FieldError :error="fieldErrors.identity" /></label>
        <label>Password<input v-model="login.password" type="password" autocomplete="current-password" required /><FieldError :error="fieldErrors.password" /></label>
        <label class="checkbox recovery-toggle"><input v-model="useRecoveryCode" type="checkbox" /> <span>Use a recovery code instead of the enrolled second factor</span></label>
        <label v-if="useRecoveryCode">Recovery code<input v-model="login.recoveryCode" autocomplete="one-time-code" spellcheck="false" placeholder="XXXXX-XXXXX" /><FieldError :error="fieldErrors.recovery_code || fieldErrors.totp_code" /></label>
        <label v-else>Authenticator code <span>(only for authenticator-enrolled staff)</span><input v-model="login.totp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" /><FieldError :error="fieldErrors.totp_code" /></label>
        <button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Checking…" /><span v-else>Sign in</span></button>
      </form>

      <form v-else-if="phase === 'access'" @submit.prevent="submitRegistration">
        <h2>Create an applicant account</h2><p class="form-intro">Names should match your official identification.</p>
        <div class="field-grid">
          <label>First name<input v-model="registration.first_name" required /></label><label>Other names<input v-model="registration.middle_names" /></label><label>Last name<input v-model="registration.last_name" required /></label><label>National ID number<input v-model="registration.nin" autocomplete="off" required /></label><label>Phone<input v-model="registration.phone" type="tel" required /></label><label>Email<input v-model="registration.email" type="email" /></label><label>Date of birth<input v-model="registration.date_of_birth" type="date" required /></label><label>Sex<select v-model="registration.sex" required><option value="">Select</option><option>Female</option><option>Male</option><option>Other</option></select></label><label>Nationality<input v-model="registration.nationality" required /></label><label>Password<input v-model="registration.password" type="password" autocomplete="new-password" required /></label><label>Confirm password<input v-model="registration.password_confirmation" type="password" autocomplete="new-password" required /></label>
        </div>
        <button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Creating…" /><span v-else>Create secure account</span></button>
      </form>

      <form v-else-if="phase === 'enrol'" class="mfa-step" @submit.prevent="enrolMfa">
        <p class="eyebrow">Required for staff</p>
        <h2>Choose your MFA method</h2>
        <p>One method remains active until an authorised MFA reset and re-enrolment. Recovery codes are generated for either choice.</p>
        <fieldset class="mfa-method-choice">
          <legend>Second-factor method</legend>
          <label><input v-model="mfaMethod" type="radio" value="authenticator" /> <span><strong>Authenticator app (recommended)</strong><small>Use a time-based code from an authenticator application.</small></span></label>
          <label><input v-model="mfaMethod" type="radio" value="email" :disabled="!session.user?.email" /> <span><strong>Email code</strong><small>{{ session.user?.email ? `Send a single-use code to ${session.user.email}.` : 'Add an account email before choosing this method.' }}</small></span></label>
        </fieldset>
        <FieldError :error="fieldErrors.method" />
        <label>Current account password<input v-model="login.password" type="password" autocomplete="current-password" required /><FieldError :error="fieldErrors.password" /></label>
        <button class="button primary" :disabled="busy || !login.password"><LoadingIndicator v-if="busy" small label="Preparing…" /><span v-else>Begin MFA enrolment</span></button>
      </form>

      <form v-else-if="phase === 'email-login'" class="mfa-step" @submit.prevent="verifyEmailLogin">
        <p class="eyebrow">Email verification</p>
        <h2>Enter your security code</h2>
        <p>A six-digit, single-use code was sent to <strong>{{ maskedEmail }}</strong>. It expires in five minutes.</p>
        <label>Email security code<input v-model="confirmCode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required /><FieldError :error="fieldErrors.code" /></label>
        <div class="button-row">
          <button class="button primary" :disabled="busy || confirmCode.length !== 6"><LoadingIndicator v-if="busy" small label="Verifying…" /><span v-else>Verify and sign in</span></button>
          <button type="button" class="button secondary" :disabled="busy || resendSecondsRemaining > 0" @click="resendEmailCode">{{ resendSecondsRemaining ? `Resend in ${resendSecondsRemaining}s` : 'Resend email code' }}</button>
        </div>
      </form>

      <form v-else-if="phase === 'confirm'" class="mfa-step" @submit.prevent="confirmMfa">
        <h2>{{ mfaMethod === 'email' ? 'Confirm the email code' : 'Confirm the authenticator' }}</h2>
        <template v-if="mfaMethod === 'authenticator'">
          <p>Scan this QR code with an authenticator app, then enter the current six-digit code.</p>
          <div class="mfa-setup-grid">
            <div class="mfa-qr"><img v-if="qrDataUrl" :src="qrDataUrl" alt="QR code for setting up UPS e-Recruit multi-factor authentication" /><LoadingIndicator v-else label="Preparing QR code…" /></div>
            <div><details><summary>Cannot scan the QR code?</summary><p>Open this provisioning address in your authenticator or copy its setup details:</p><code class="break-all">{{ provisioningUri }}</code></details><p class="totp-countdown" aria-live="off">Authenticator codes refresh in <strong>{{ totpSecondsRemaining }} seconds</strong>.</p></div>
          </div>
        </template>
        <p v-else>A six-digit, single-use code was sent to <strong>{{ maskedEmail }}</strong>. It expires in five minutes.</p>
        <div class="recovery-box"><strong>Save these single-use recovery codes now</strong><span>Store them offline. Each code works once and they will not be shown again.</span><code v-for="code in recoveryCodes" :key="code">{{ code }}</code></div>
        <label>{{ mfaMethod === 'email' ? 'Email security code' : 'Authenticator code' }}<input v-model="confirmCode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required /><FieldError :error="fieldErrors.code" /></label>
        <div class="button-row">
          <button class="button primary" :disabled="busy || confirmCode.length !== 6"><LoadingIndicator v-if="busy" small label="Activating MFA…" /><span v-else>Activate MFA</span></button>
          <button v-if="mfaMethod === 'email'" type="button" class="button secondary" :disabled="busy || resendSecondsRemaining > 0" @click="resendEmailCode">{{ resendSecondsRemaining ? `Resend in ${resendSecondsRemaining}s` : 'Resend email code' }}</button>
          <button type="button" class="text-button" :disabled="busy" @click="chooseAnotherMethod">Choose another method</button>
          <button type="button" class="text-button" :disabled="busy" @click="restartMfaEnrollment">Restart this method</button>
        </div>
      </form>

      <form v-else class="mfa-step" @submit.prevent="changePassword">
        <p class="eyebrow">Required security step</p><h2>Replace the temporary password</h2><p>Choose a unique password with at least 12 characters, upper- and lower-case letters, and a number. Other application features remain locked until this is complete.</p><label>Current temporary password<input v-model="passwordChange.current_password" type="password" autocomplete="current-password" required /><FieldError :error="fieldErrors.current_password" /></label><label>New password<input v-model="passwordChange.password" type="password" autocomplete="new-password" minlength="12" required /><FieldError :error="fieldErrors.password" /></label><label>Confirm new password<input v-model="passwordChange.password_confirmation" type="password" autocomplete="new-password" minlength="12" required /></label><button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Changing…" /><span v-else>Change password and continue</span></button>
      </form>
    </div>
  </section>
</template>
