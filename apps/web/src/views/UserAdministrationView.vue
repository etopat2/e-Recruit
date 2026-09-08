<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError, jsonBody } from '../lib/api'

interface RoleRecord { code: string; name: string; is_decision_role: boolean; is_privileged: boolean }
interface ManagedScope { scope_type: string; scope_id: string | null; allowed_tasks: string[]; expires_at: string | null }
interface ManagedUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  user_type: string
  status: string
  is_privileged: boolean
  must_change_password: boolean
  mfa_enabled: boolean
  mfa_confirmed: boolean
  last_login_at: string | null
  password_changed_at: string | null
  entity_version: number
  roles: Array<{ code: string; name: string }>
  scopes: ManagedScope[]
}

const users = ref<ManagedUser[]>([])
const roles = ref<RoleRecord[]>([])
const selected = ref<ManagedUser | null>(null)
const message = ref('')
const error = ref('')
const temporaryPassword = ref('')
const busy = ref(false)
const total = ref(0)
const filters = reactive({ search: '', status: '', role: '' })
const creation = reactive({ name: '', email: '', phone: '', role_code: '' })
const edit = reactive({ name: '', email: '', phone: '', status: 'active', role_code: '' })
const reason = ref('')
const scopesText = ref('[]')
const staffRoles = computed(() => roles.value.filter((role) => role.code !== 'applicant'))

onMounted(async () => {
  await Promise.all([loadRoles(), loadUsers()])
})

function describe(problem: unknown): string {
  const apiError = problem as ApiError
  if (apiError.errors) return Object.values(apiError.errors).flat().join(' ')
  return apiError.message || 'The request could not be completed.'
}

function begin() {
  busy.value = true; message.value = ''; error.value = ''; temporaryPassword.value = ''
}

function finish(problem?: unknown) {
  busy.value = false
  if (problem) error.value = describe(problem)
}

async function loadRoles() {
  try {
    const response = await api<{ data: RoleRecord[] }>('/admin/roles')
    roles.value = response.data
    if (!creation.role_code) creation.role_code = staffRoles.value[0]?.code || ''
  } catch (problem) { error.value = describe(problem) }
}

async function loadUsers() {
  const query = new URLSearchParams({ per_page: '100' })
  if (filters.search.trim()) query.set('search', filters.search.trim())
  if (filters.status) query.set('status', filters.status)
  if (filters.role) query.set('role', filters.role)
  try {
    const response = await api<{ data: ManagedUser[]; meta: { total: number } }>(`/admin/users?${query}`)
    users.value = response.data; total.value = response.meta.total
    if (selected.value) {
      const refreshed = users.value.find((user) => user.id === selected.value?.id)
      if (refreshed) selectUser(refreshed)
    }
  } catch (problem) { error.value = describe(problem) }
}

function selectUser(user: ManagedUser) {
  selected.value = user
  Object.assign(edit, {
    name: user.name,
    email: user.email || '',
    phone: user.phone || '',
    status: user.status,
    role_code: user.user_type,
  })
  scopesText.value = JSON.stringify(user.scopes.map((scope) => ({
    scope_type: scope.scope_type,
    scope_id: scope.scope_id,
    allowed_tasks: scope.allowed_tasks,
    expires_at: scope.expires_at,
  })), null, 2)
  reason.value = ''; temporaryPassword.value = ''; message.value = ''; error.value = ''
}

function acceptUpdatedUser(user: ManagedUser) {
  const index = users.value.findIndex((candidate) => candidate.id === user.id)
  if (index >= 0) users.value[index] = user
  selectUser(user)
}

async function createUser() {
  begin()
  try {
    const response = await api<{ user: ManagedUser; temporary_password: string; message: string }>('/admin/users', {
      method: 'POST',
      ...jsonBody({ ...creation, phone: creation.phone || null }),
    })
    users.value.push(response.user); users.value.sort((a, b) => a.name.localeCompare(b.name)); total.value += 1
    temporaryPassword.value = response.temporary_password; message.value = response.message; selectUser(response.user)
    temporaryPassword.value = response.temporary_password; message.value = response.message
    creation.name = ''; creation.email = ''; creation.phone = ''
  } catch (problem) { finish(problem); return }
  finish()
}

async function saveAccount() {
  if (!selected.value) return
  begin()
  try {
    const body: Record<string, unknown> = {
      name: edit.name,
      phone: edit.phone || null,
      status: edit.status,
      entity_version: selected.value.entity_version,
      reason: reason.value,
    }
    if (edit.email) body.email = edit.email
    if (selected.value.user_type !== 'applicant') body.role_code = edit.role_code
    const response = await api<{ user: ManagedUser }>(`/admin/users/${selected.value.id}`, { method: 'PUT', ...jsonBody(body) })
    acceptUpdatedUser(response.user); message.value = 'Account details and access state were updated.'
  } catch (problem) { finish(problem); return }
  finish()
}

async function saveScopes() {
  if (!selected.value) return
  begin()
  try {
    const parsed = JSON.parse(scopesText.value) as unknown
    if (!Array.isArray(parsed)) throw new Error('Scopes must be a JSON array.')
    const response = await api<{ user: ManagedUser }>(`/admin/users/${selected.value.id}/scopes`, {
      method: 'PUT',
      ...jsonBody({ scopes: parsed, entity_version: selected.value.entity_version, reason: reason.value }),
    })
    acceptUpdatedUser(response.user); message.value = 'Account scopes were replaced and audited.'
  } catch (problem) { finish(problem); return }
  finish()
}

async function sensitiveAction(path: 'password-reset' | 'mfa-reset' | 'sessions/revoke') {
  if (!selected.value) return
  begin()
  try {
    const response = await api<{ user: ManagedUser; message: string; temporary_password?: string }>(`/admin/users/${selected.value.id}/${path}`, {
      method: 'POST',
      ...jsonBody({ entity_version: selected.value.entity_version, reason: reason.value }),
    })
    acceptUpdatedUser(response.user); message.value = response.message
    temporaryPassword.value = response.temporary_password || ''
  } catch (problem) { finish(problem); return }
  finish()
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Technical administration</p><h1>User and administrator accounts</h1><p>Create staff identities, control account status and role assignments, reset credentials, and revoke sessions. Every mutation requires a reason and is written to the audit trail. Recruitment decisions remain outside this role.</p></section>
  <div v-if="message" class="alert success page-alert" role="status">{{ message }}</div><div v-if="error" class="alert error page-alert" role="alert">{{ error }}</div>
  <div v-if="temporaryPassword" class="one-time-secret" role="status"><strong>Copy this temporary password now</strong><code>{{ temporaryPassword }}</code><span>It will not be shown again. Send it through an approved secure channel; the user must replace it at first sign-in.</span></div>

  <section class="account-admin-layout">
    <div class="form-panel">
      <h2>Create a staff account</h2><p class="form-intro">Applicant accounts are created through registration. Staff receive a one-time random password.</p>
      <form @submit.prevent="createUser"><label>Full name<input v-model="creation.name" required /></label><label>Email address<input v-model="creation.email" type="email" required /></label><label>Phone <span>(optional)</span><input v-model="creation.phone" type="tel" /></label><label>Initial role<select v-model="creation.role_code" required><option v-for="role in staffRoles" :key="role.code" :value="role.code">{{ role.name }}{{ role.is_privileged ? ' · MFA required' : '' }}</option></select></label><button class="button primary full" :disabled="busy">Create secure account</button></form>
    </div>

    <div class="form-panel account-directory">
      <div class="section-heading"><div><p class="eyebrow">Directory</p><h2>{{ total }} account(s)</h2></div></div>
      <form class="account-filters" @submit.prevent="loadUsers"><label>Search<input v-model="filters.search" placeholder="Name, email, or phone" /></label><label>Status<select v-model="filters.status"><option value="">All</option><option value="active">Active</option><option value="disabled">Disabled</option></select></label><label>Role<select v-model="filters.role"><option value="">All</option><option v-for="role in roles" :key="role.code" :value="role.code">{{ role.name }}</option></select></label><button class="button secondary compact">Apply</button></form>
      <div class="table-wrap"><table><thead><tr><th>Account</th><th>Role</th><th>Security</th><th></th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td><strong>{{ user.name }}</strong><small>{{ user.email || user.phone || 'No contact value' }}</small><span class="status-badge" :data-status="user.status">{{ user.status }}</span></td><td>{{ user.roles[0]?.name || user.user_type }}</td><td><small>{{ user.is_privileged ? (user.mfa_confirmed ? 'MFA active' : 'MFA pending') : 'Standard' }}</small><small v-if="user.must_change_password">Password change required</small></td><td><button class="button secondary compact" type="button" @click="selectUser(user)">Manage</button></td></tr><tr v-if="!users.length"><td colspan="4" class="empty-state compact">No accounts match these filters.</td></tr></tbody></table></div>
    </div>
  </section>

  <section v-if="selected" class="content-section compact-top">
    <div class="section-heading"><div><p class="eyebrow">Selected account #{{ selected.id }}</p><h2>{{ selected.name }}</h2><p>Version {{ selected.entity_version }} · last login {{ selected.last_login_at || 'never' }}</p></div><span class="status-badge" :data-status="selected.status">{{ selected.status }}</span></div>
    <div class="account-actions-layout">
      <form class="form-panel" @submit.prevent="saveAccount"><h3>Identity and access</h3><label>Name<input v-model="edit.name" required /></label><label>Email<input v-model="edit.email" type="email" :required="selected.user_type !== 'applicant'" /></label><label>Phone<input v-model="edit.phone" type="tel" /></label><label>Status<select v-model="edit.status"><option value="active">Active</option><option value="disabled">Disabled</option></select></label><label>Role<select v-model="edit.role_code" :disabled="selected.user_type === 'applicant'"><option v-for="role in (selected.user_type === 'applicant' ? roles : staffRoles)" :key="role.code" :value="role.code">{{ role.name }}</option></select></label><label>Reason for change<textarea v-model="reason" minlength="10" rows="3" required /></label><button class="button primary full" :disabled="busy">Save account</button></form>
      <div class="form-panel"><h3>Security operations</h3><p class="form-intro">All active browser sessions and API tokens are revoked by credential resets.</p><label>Reason for security action<textarea v-model="reason" minlength="10" rows="3" required /></label><div class="stacked-actions"><button class="button secondary" type="button" :disabled="busy || reason.length < 10" @click="sensitiveAction('password-reset')">Issue temporary password</button><button class="button secondary" type="button" :disabled="busy || reason.length < 10 || !selected.mfa_enabled" @click="sensitiveAction('mfa-reset')">Reset MFA</button><button class="button secondary" type="button" :disabled="busy || reason.length < 10" @click="sensitiveAction('sessions/revoke')">Revoke all sessions</button></div></div>
      <form v-if="selected.user_type !== 'applicant'" class="form-panel" @submit.prevent="saveScopes"><h3>Authorisation scopes</h3><p class="form-intro">Replace the complete scope set using reviewed JSON. Non-national scopes require a scope ID.</p><label>Scopes JSON<textarea v-model="scopesText" class="scope-editor" rows="14" spellcheck="false" required /></label><label>Reason for scope change<textarea v-model="reason" minlength="10" rows="3" required /></label><button class="button primary full" :disabled="busy">Replace scopes</button></form>
    </div>
  </section>
</template>
