<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, jsonBody, setAuthToken } from '../lib/api'
import { useSessionStore } from '../stores/session'
import type { User } from '../types'

const tab = ref<'login' | 'register'>('login')
const phase = ref<'access' | 'enrol' | 'confirm'>('access')
const busy = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const provisioningUri = ref('')
const recoveryCodes = ref<string[]>([])
const login = reactive({ identity: '', password: '', totp: '' })
const registration = reactive({ first_name: '', middle_names: '', last_name: '', nin: '', phone: '', email: '', date_of_birth: '', sex: '', nationality: 'Ugandan', password: '', password_confirmation: '' })
const confirmCode = ref('')
const session = useSessionStore()
const router = useRouter()
const route = useRoute()

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
    await router.push(String(route.query.redirect || '/dashboard'))
  }
}

async function submitLogin() {
  busy.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await session.login(login.identity, login.password, login.totp)
    if (response.requires_mfa_enrolment) { phase.value = 'enrol'; return }
    await destination()
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function enrolMfa() {
  busy.value = true
  try {
    const response = await api<{ provisioning_uri: string; recovery_codes: string[] }>('/auth/mfa/enrol', { method: 'POST', ...jsonBody({ password: login.password }) })
    provisioningUri.value = response.provisioning_uri; recoveryCodes.value = response.recovery_codes; phase.value = 'confirm'
  } catch (problem) { fail(problem) } finally { busy.value = false }
}

async function confirmMfa() {
  busy.value = true
  try {
    const response = await api<{ token: string }>('/auth/mfa/confirm', { method: 'POST', ...jsonBody({ code: confirmCode.value }) })
    setAuthToken(response.token)
    if (session.user) session.user.mfa_confirmed = true
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
      <div v-if="message" class="alert error" role="alert">{{ message }}</div>
      <form v-if="phase === 'access' && tab === 'login'" @submit.prevent="submitLogin">
        <h2>Sign in securely</h2>
        <label>Email address or phone<input v-model="login.identity" autocomplete="username" required /><small v-if="fieldErrors.identity">{{ fieldErrors.identity[0] }}</small></label>
        <label>Password<input v-model="login.password" type="password" autocomplete="current-password" required /></label>
        <label>Authenticator code <span>(staff accounts)</span><input v-model="login.totp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" /></label>
        <button class="button primary full" :disabled="busy">{{ busy ? 'Checking…' : 'Sign in' }}</button>
      </form>
      <form v-else-if="phase === 'access'" @submit.prevent="submitRegistration">
        <h2>Create an applicant account</h2><p class="form-intro">Names should match your official identification.</p>
        <div class="field-grid"><label>First name<input v-model="registration.first_name" required /></label><label>Other names<input v-model="registration.middle_names" /></label><label>Last name<input v-model="registration.last_name" required /></label><label>National ID number<input v-model="registration.nin" autocomplete="off" required /></label><label>Phone<input v-model="registration.phone" type="tel" required /></label><label>Email<input v-model="registration.email" type="email" /></label><label>Date of birth<input v-model="registration.date_of_birth" type="date" required /></label><label>Sex<select v-model="registration.sex" required><option value="">Select</option><option>Female</option><option>Male</option><option>Other</option></select></label><label>Nationality<input v-model="registration.nationality" required /></label><label>Password<input v-model="registration.password" type="password" autocomplete="new-password" required /></label><label>Confirm password<input v-model="registration.password_confirmation" type="password" autocomplete="new-password" required /></label></div>
        <button class="button primary full" :disabled="busy">{{ busy ? 'Creating…' : 'Create secure account' }}</button>
      </form>
      <div v-else-if="phase === 'enrol'" class="mfa-step"><p class="eyebrow">Required for staff</p><h2>Protect this account with MFA</h2><p>Continue to generate an authenticator secret and one-time recovery codes.</p><button class="button primary" :disabled="busy" @click="enrolMfa">Begin MFA enrolment</button></div>
      <form v-else class="mfa-step" @submit.prevent="confirmMfa"><h2>Confirm the authenticator</h2><p class="break-all">Open this provisioning URI in your authenticator: <code>{{ provisioningUri }}</code></p><div class="recovery-box"><strong>Save these recovery codes once</strong><code v-for="code in recoveryCodes" :key="code">{{ code }}</code></div><label>Six-digit code<input v-model="confirmCode" inputmode="numeric" maxlength="6" required /></label><button class="button primary full" :disabled="busy">Activate MFA</button></form>
    </div>
  </section>
</template>
