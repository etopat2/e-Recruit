<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import QRCode from 'qrcode'
import { useRoute, useRouter } from 'vue-router'
import FieldError from '../components/FieldError.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import { api, ApiError, jsonBody, setAuthToken } from '../lib/api'
import { useSessionStore } from '../stores/session'
import type { User } from '../types'

const tab = ref<'login' | 'register'>('login')
const phase = ref<'access' | 'enrol' | 'confirm' | 'password'>('access')
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
const totpSecondsRemaining = ref(30)
const session = useSessionStore()
const router = useRouter()
const route = useRoute()

onMounted(() => {
  if (session.user?.is_privileged && !session.user.mfa_confirmed) phase.value = 'enrol'
  else if (session.user?.must_change_password) phase.value = 'password'
  updateTotpCountdown()
  countdownTimer = window.setInterval(updateTotpCountdown, 1000)
})
let countdownTimer = 0
onBeforeUnmount(() => window.clearInterval(countdownTimer))

function updateTotpCountdown() { totpSecondsRemaining.value = 30 - (Math.floor(Date.now() / 1000) % 30) }

watch(provisioningUri, async (uri) => {
  qrDataUrl.value = ''
  if (!uri) return
  try { qrDataUrl.value = await QRCode.toDataURL(uri, { width: 260, margin: 2, errorCorrectionLevel: 'M' }) }
  catch { message.value = 'The QR image could not be generated. Use the text setup key below.' }
})

function fail(problem: unknown) {
  const error = problem as ApiError
  message.value = error.message || 'The request could not be completed.'
  fieldErrors.value = error.errors || {}
}

async function destination() {
  if (route.query.campaign && route.query.post) {
    const response = await api<{ data: { id: string } }>('/applications', { method: 'POST', ...jsonBody({ campaign_id: route.query.campaign, post_id: route.query.post }) })
    await router.push(`/applications/${response.data.id}`)
  } else {
    await router.push(String(route.query.redirect || session.homePath))
  }
}

async function submitLogin() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await session.login(login.identity, login.password, useRecoveryCode.value ? '' : login.totp, useRecoveryCode.value ? login.recoveryCode : '')
    if (response.requires_mfa_enrolment) { phase.value = 'enrol'; return }
    if (response.requires_password_change) { passwordChange.current_password = login.password; phase.value = 'password'; return }
    await destination()
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function enrolMfa() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await api<{ provisioning_uri: string; recovery_codes: string[] }>('/auth/mfa/enrol', { method: 'POST', ...jsonBody({ password: login.password }) })
    applyMfaEnrollment(response)
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

function applyMfaEnrollment(response: { provisioning_uri: string; recovery_codes: string[] }) {
  provisioningUri.value = response.provisioning_uri; recoveryCodes.value = response.recovery_codes; confirmCode.value = ''; phase.value = 'confirm'
}

async function restartMfaEnrollment() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await api<{ provisioning_uri: string; recovery_codes: string[] }>('/auth/mfa/restart', { method: 'POST', ...jsonBody({ password: login.password }) })
    applyMfaEnrollment(response)
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function confirmMfa() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User; requires_password_change?: boolean }>('/auth/mfa/confirm', { method: 'POST', ...jsonBody({ code: confirmCode.value }) })
    setAuthToken(response.token)
    session.user = response.user
    if (response.requires_password_change) { passwordChange.current_password = login.password; phase.value = 'password'; return }
    await destination()
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function changePassword() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User }>('/auth/password', { method: 'PUT', ...jsonBody(passwordChange) })
    setAuthToken(response.token); session.user = response.user
    await destination()
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function submitRegistration() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await api<{ token: string; user: User }>('/auth/register', { method: 'POST', ...jsonBody(registration) })
    setAuthToken(response.token); session.user = response.user
    await destination()
  } catch (problem) { fail(problem) } finally { busy.value = false }
}
</script>

<template>
  <section class="access-layout">
    <aside><p class="eyebrow">Official access</p><h1>Welcome to UPS e-Recruit</h1><p>Use your own contact details. UPS will never ask you to pay a recruitment fee through this portal.</p><div class="security-note"><strong>Security reminder</strong><p>Keep your password, authenticator code, and recovery codes private.</p></div></aside>
    <div class="form-panel">
      <div v-if="phase === 'access'" class="tabs" role="tablist"><button :aria-selected="tab === 'login'" @click="tab = 'login'">Sign in</button><button :aria-selected="tab === 'register'" @click="tab = 'register'">Create account</button></div>
      <FormAlert v-if="message" kind="error" :message="message" />
      <form v-if="phase === 'access' && tab === 'login'" @submit.prevent="submitLogin">
        <h2>Sign in securely</h2>
        <label>Email address or phone<input v-model="login.identity" autocomplete="username" required /><FieldError :error="fieldErrors.identity" /></label>
        <label>Password<input v-model="login.password" type="password" autocomplete="current-password" required /><FieldError :error="fieldErrors.password" /></label>
        <label class="checkbox recovery-toggle"><input v-model="useRecoveryCode" type="checkbox" /> <span>Use a recovery code instead of an authenticator code</span></label>
        <label v-if="useRecoveryCode">Recovery code<input v-model="login.recoveryCode" autocomplete="one-time-code" spellcheck="false" placeholder="XXXXX-XXXXX" /><FieldError :error="fieldErrors.recovery_code || fieldErrors.totp_code" /></label>
        <label v-else>Authenticator code <span>(staff accounts)</span><input v-model="login.totp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" /><FieldError :error="fieldErrors.totp_code" /></label>
        <button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Checking…" /><span v-else>Sign in</span></button>
      </form>
      <form v-else-if="phase === 'access'" @submit.prevent="submitRegistration">
        <h2>Create an applicant account</h2><p class="form-intro">Names should match your official identification.</p>
        <div class="field-grid"><label>First name<input v-model="registration.first_name" required /></label><label>Other names<input v-model="registration.middle_names" /></label><label>Last name<input v-model="registration.last_name" required /></label><label>National ID number<input v-model="registration.nin" autocomplete="off" required /></label><label>Phone<input v-model="registration.phone" type="tel" required /></label><label>Email<input v-model="registration.email" type="email" /></label><label>Date of birth<input v-model="registration.date_of_birth" type="date" required /></label><label>Sex<select v-model="registration.sex" required><option value="">Select</option><option>Female</option><option>Male</option><option>Other</option></select></label><label>Nationality<input v-model="registration.nationality" required /></label><label>Password<input v-model="registration.password" type="password" autocomplete="new-password" required /></label><label>Confirm password<input v-model="registration.password_confirmation" type="password" autocomplete="new-password" required /></label></div>
        <button class="button primary full" :disabled="busy">{{ busy ? 'Creating…' : 'Create secure account' }}</button>
      </form>
      <div v-else-if="phase === 'enrol'" class="mfa-step"><p class="eyebrow">Required for staff</p><h2>Protect this account with MFA</h2><p>Enter the current account password to generate an authenticator secret and one-time recovery codes. Starting again safely replaces any incomplete setup.</p><label>Current account password<input v-model="login.password" type="password" autocomplete="current-password" required /><FieldError :error="fieldErrors.password" /></label><button class="button primary" :disabled="busy || !login.password" @click="enrolMfa"><LoadingIndicator v-if="busy" small label="Preparing…" /><span v-else>Begin MFA enrolment</span></button></div>
      <form v-else-if="phase === 'confirm'" class="mfa-step" @submit.prevent="confirmMfa"><h2>Confirm the authenticator</h2><p>Scan this QR code with an authenticator app, then enter the current six-digit code.</p><div class="mfa-setup-grid"><div class="mfa-qr"><img v-if="qrDataUrl" :src="qrDataUrl" alt="QR code for setting up UPS e-Recruit multi-factor authentication" /><LoadingIndicator v-else label="Preparing QR code…" /></div><div><details><summary>Cannot scan the QR code?</summary><p>Open this provisioning address in your authenticator or copy its setup details:</p><code class="break-all">{{ provisioningUri }}</code></details><p class="totp-countdown" aria-live="off">Authenticator codes refresh in <strong>{{ totpSecondsRemaining }} seconds</strong>.</p><button type="button" class="text-button" :disabled="busy" @click="restartMfaEnrollment">Restart with a new secret</button></div></div><div class="recovery-box"><strong>Save these single-use recovery codes now</strong><span>Store them offline. Each code works once and they will not be shown again.</span><code v-for="code in recoveryCodes" :key="code">{{ code }}</code></div><label>Six-digit code<input v-model="confirmCode" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required /><FieldError :error="fieldErrors.code" /></label><button class="button primary full" :disabled="busy"><LoadingIndicator v-if="busy" small label="Activating MFA…" /><span v-else>Activate MFA</span></button></form>
      <form v-else class="mfa-step" @submit.prevent="changePassword"><p class="eyebrow">Required security step</p><h2>Replace the temporary password</h2><p>Choose a unique password with at least 12 characters, upper- and lower-case letters, and a number. Other application features remain locked until this is complete.</p><label>Current temporary password<input v-model="passwordChange.current_password" type="password" autocomplete="current-password" required /><small v-if="fieldErrors.current_password">{{ fieldErrors.current_password[0] }}</small></label><label>New password<input v-model="passwordChange.password" type="password" autocomplete="new-password" minlength="12" required /><small v-if="fieldErrors.password">{{ fieldErrors.password[0] }}</small></label><label>Confirm new password<input v-model="passwordChange.password_confirmation" type="password" autocomplete="new-password" minlength="12" required /></label><button class="button primary full" :disabled="busy">{{ busy ? 'Changing…' : 'Change password and continue' }}</button></form>
    </div>
  </section>
</template>
