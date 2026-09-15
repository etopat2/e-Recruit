<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { api, ApiError, jsonBody } from '../lib/api'
import Dialog from '../components/Dialog.vue'
import FieldError from '../components/FieldError.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'

interface RoleRecord { code: string; name: string; is_decision_role: boolean; is_privileged: boolean }
interface ManagedScope { scope_type: string; scope_id: string | null; allowed_tasks: string[]; expires_at: string | null }
interface ScopeReference { value: string; label: string; description?: string }
interface EditableScope extends ManagedScope { scope_label: string }
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
const validationErrors = ref<Record<string, string[]>>({})
const temporaryPassword = ref('')
const busy = ref(false)
const total = ref(0)
const filters = reactive({ search: '', status: '', role: '' })
const creation = reactive({ name: '', email: '', phone: '', role_code: '' })
const edit = reactive({ name: '', email: '', phone: '', status: 'active', role_code: '' })
const reason = ref('')
const scopeRows = ref<EditableScope[]>([])
const scopeDirectory = reactive<{ tasks: ScopeReference[]; references: Record<string, ScopeReference[]> }>({ tasks: [], references: {} })
type AccountDialog = 'create' | 'edit' | 'scopes' | 'security'
const activeDialog = ref<AccountDialog | ''>('')
const staffRoles = computed(() => roles.value.filter((role) => role.code !== 'applicant'))
const scopeTypeOptions: ComboboxOption[] = [
  ['national', 'National'], ['region', 'Prison region'], ['centre', 'Recruitment centre'], ['panel', 'Interview panel'],
  ['campaign', 'Recruitment campaign'], ['post', 'Recruitment post'], ['stage', 'Campaign stage'], ['task', 'Specific task'],
].map(([value, label]) => ({ value, label }))

function roleOptions(availableRoles: RoleRecord[], includeAll = false): ComboboxOption[] {
  return [
    ...(includeAll ? [{ value: '', label: 'All roles' }] : []),
    ...availableRoles.map((role) => ({
      value: role.code,
      label: role.name,
      description: role.is_privileged ? 'Privileged · MFA required' : role.code,
      data: role,
    })),
  ]
}

function roleName(code: string, availableRoles: RoleRecord[] = roles.value): string {
  return availableRoles.find((role) => role.code === code)?.name || ''
}

onMounted(async () => { await Promise.all([loadRoles(), loadUsers(), loadScopeOptions()]) })

function describe(problem: unknown): string {
  const apiError = problem as ApiError
  if (apiError.errors) return Object.values(apiError.errors).flat().join(' ')
  return apiError.message || 'The request could not be completed.'
}

function begin() {
  busy.value = true; message.value = ''; error.value = ''; validationErrors.value = {}; temporaryPassword.value = ''
}

function finish(problem?: unknown) {
  busy.value = false
  if (problem) {
    error.value = problem instanceof ApiError ? problem.message : describe(problem)
    validationErrors.value = problem instanceof ApiError ? problem.errors : {}
    void nextTick(() => {
      const fieldError = document.querySelector('.dialog-panel .field-error')
      fieldError?.closest('label')?.querySelector<HTMLElement>('input, textarea, select, button')?.focus()
    })
  }
}

async function loadRoles() {
  try {
    const response = await api<{ data: RoleRecord[] }>('/admin/roles')
    roles.value = response.data
    if (!creation.role_code) creation.role_code = staffRoles.value[0]?.code || ''
  } catch (problem) { error.value = describe(problem) }
}

async function loadScopeOptions() {
  try {
    const response = await api<{ data: { tasks: ScopeReference[]; references: Record<string, ScopeReference[]> } }>('/admin/scope-options', { cacheTtlMs: 60_000 })
    Object.assign(scopeDirectory, response.data)
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
  scopeRows.value = user.scopes.map((scope) => ({ ...scope, scope_label: referenceLabel(scope.scope_type, scope.scope_id) }))
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
    activeDialog.value = ''
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
    acceptUpdatedUser(response.user); message.value = 'Account details and access state were updated.'; activeDialog.value = ''
  } catch (problem) { finish(problem); return }
  finish()
}

async function saveScopes() {
  if (!selected.value) return
  begin()
  try {
    const scopes = scopeRows.value.map((scope) => ({ scope_type: scope.scope_type, scope_id: scope.scope_type === 'national' ? null : scope.scope_id, allowed_tasks: scope.allowed_tasks, expires_at: scope.expires_at || null }))
    const response = await api<{ user: ManagedUser }>(`/admin/users/${selected.value.id}/scopes`, {
      method: 'PUT',
      ...jsonBody({ scopes, entity_version: selected.value.entity_version, reason: reason.value }),
    })
    acceptUpdatedUser(response.user); message.value = 'Account scopes were replaced and audited.'; activeDialog.value = ''
  } catch (problem) { finish(problem); return }
  finish()
}

function referenceLabel(scopeType: string, scopeId: string | null): string {
  if (scopeType === 'national') return 'Whole service'
  return scopeDirectory.references[scopeType]?.find((item) => item.value === scopeId)?.label || ''
}

function referenceOptions(scopeType: string): ComboboxOption[] {
  return (scopeDirectory.references[scopeType] || []).map((item) => ({ value: item.value, label: item.label, description: item.description }))
}

function addScope(): void {
  scopeRows.value.push({ scope_type: 'national', scope_id: null, scope_label: 'Whole service', allowed_tasks: ['view:operations'], expires_at: null })
}

function selectScopeType(scope: EditableScope, option: ComboboxOption): void {
  scope.scope_type = option.value
  scope.scope_id = null
  scope.scope_label = option.value === 'national' ? 'Whole service' : ''
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
    activeDialog.value = ''
  } catch (problem) { finish(problem); return }
  finish()
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Technical administration</p><h1>User and administrator accounts</h1><p>Create staff identities, control account status and role assignments, reset credentials, and revoke sessions. Every mutation requires a reason and is written to the audit trail. Recruitment decisions remain outside this role.</p></section>
  <FormAlert v-if="message" kind="success" :message="message" page /><FormAlert v-if="error && !activeDialog" kind="error" :message="error" page />
  <div v-if="temporaryPassword" class="one-time-secret" role="status"><strong>Copy this temporary password now</strong><code>{{ temporaryPassword }}</code><span>It will not be shown again. Send it through an approved secure channel; the user must replace it at first sign-in.</span></div>

  <section class="content-section compact-top">
    <div class="form-panel account-directory">
      <div class="section-heading"><div><p class="eyebrow">Directory</p><h2>{{ total }} account(s)</h2></div><button type="button" class="button primary" @click="activeDialog = 'create'">Create staff account</button></div>
      <form class="account-filters" @submit.prevent="loadUsers"><label>Search<input v-model="filters.search" placeholder="Name, email, or phone" /></label><label>Status<select v-model="filters.status"><option value="">All</option><option value="active">Active</option><option value="disabled">Disabled</option></select></label><FloatingCombobox label="Role" :model-value="roleName(filters.role)" :options="roleOptions(roles, true)" placeholder="All roles" @update:model-value="filters.role = ''" @select="filters.role = $event.value" /><button class="button secondary compact">Apply</button></form>
      <div class="table-wrap"><table class="mobile-card-table"><thead><tr><th>Account</th><th>Role</th><th>Security</th><th></th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td data-label="Account"><strong>{{ user.name }}</strong><small>{{ user.email || user.phone || 'No contact value' }}</small><span class="status-badge" :data-status="user.status">{{ user.status }}</span></td><td data-label="Role">{{ user.roles[0]?.name || user.user_type }}</td><td data-label="Security"><small>{{ user.is_privileged ? (user.mfa_confirmed ? 'MFA active' : 'MFA pending') : 'Standard' }}</small><small v-if="user.must_change_password">Password change required</small></td><td data-label="Action"><button class="button secondary compact" type="button" @click="selectUser(user)">Manage</button></td></tr><tr v-if="!users.length"><td colspan="4" class="empty-state compact">No accounts match these filters.</td></tr></tbody></table></div>
    </div>
  </section>

  <section v-if="selected" class="content-section compact-top">
    <div class="section-heading"><div><p class="eyebrow">Selected account</p><h2>{{ selected.name }}</h2><p>Version {{ selected.entity_version }} · last login {{ selected.last_login_at || 'never' }}</p></div><span class="status-badge" :data-status="selected.status">{{ selected.status }}</span></div>
    <div class="action-launcher-grid"><button type="button" class="action-launcher" @click="activeDialog = 'edit'"><strong>Identity and access</strong><span>Edit profile, status, and staff role with an audited reason.</span></button><button type="button" class="action-launcher danger-zone" @click="activeDialog = 'security'"><strong>Security operations</strong><span>Reset password/MFA or revoke all active sessions.</span></button><button v-if="selected.user_type !== 'applicant'" type="button" class="action-launcher" @click="activeDialog = 'scopes'"><strong>Authorisation scopes</strong><span>Replace this staff account’s complete reviewed scope set.</span></button></div>
  </section>

  <Dialog :open="activeDialog === 'create'" title="Create a staff account" description="Applicant accounts are created through registration. Staff receive a one-time random password." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><FormAlert v-if="error" kind="error" :message="error" /><form @submit.prevent="createUser"><label>Full name<input v-model="creation.name" required data-dialog-initial-focus /><FieldError :error="validationErrors.name" /></label><label>Email address<input v-model="creation.email" type="email" required /><FieldError :error="validationErrors.email" /></label><label>Phone <span>(optional)</span><input v-model="creation.phone" type="tel" /><FieldError :error="validationErrors.phone" /></label><FloatingCombobox label="Initial role" :model-value="roleName(creation.role_code, staffRoles)" :options="roleOptions(staffRoles)" required placeholder="Search or select role" @update:model-value="creation.role_code = ''" @select="creation.role_code = $event.value" /><FieldError :error="validationErrors.role_code" /><button class="button primary full" :disabled="busy">Create secure account</button></form></Dialog>
  <Dialog :open="activeDialog === 'edit'" :title="selected ? `Edit ${selected.name}` : 'Edit account'" :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><FormAlert v-if="error" kind="error" :message="error" /><form v-if="selected" @submit.prevent="saveAccount"><label>Name<input v-model="edit.name" required data-dialog-initial-focus /><FieldError :error="validationErrors.name" /></label><label>Email<input v-model="edit.email" type="email" :required="selected.user_type !== 'applicant'" /><FieldError :error="validationErrors.email" /></label><label>Phone<input v-model="edit.phone" type="tel" /><FieldError :error="validationErrors.phone" /></label><label>Status<select v-model="edit.status"><option value="active">Active</option><option value="disabled">Disabled</option></select><FieldError :error="validationErrors.status" /></label><FloatingCombobox label="Role" :model-value="roleName(edit.role_code, selected.user_type === 'applicant' ? roles : staffRoles)" :options="roleOptions(selected.user_type === 'applicant' ? roles : staffRoles)" :disabled="selected.user_type === 'applicant'" placeholder="Search or select role" @update:model-value="edit.role_code = ''" @select="edit.role_code = $event.value" /><FieldError :error="validationErrors.role_code" /><label>Reason for change<textarea v-model="reason" minlength="10" rows="3" required /><FieldError :error="validationErrors.reason" /></label><button class="button primary full" :disabled="busy">Save account</button></form></Dialog>
  <Dialog :open="activeDialog === 'security'" :title="selected ? `Security operations for ${selected.name}` : 'Security operations'" description="Credential resets revoke all active browser sessions and API tokens." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><FormAlert v-if="error" kind="error" :message="error" /><div v-if="selected"><label>Reason for security action<textarea v-model="reason" minlength="10" rows="3" required data-dialog-initial-focus /><FieldError :error="validationErrors.reason" /></label><div class="stacked-actions"><button class="button secondary" type="button" :disabled="busy || reason.length < 10" @click="sensitiveAction('password-reset')">Issue temporary password</button><button class="button secondary" type="button" :disabled="busy || reason.length < 10 || !selected.mfa_enabled" @click="sensitiveAction('mfa-reset')">Reset MFA</button><button class="button secondary" type="button" :disabled="busy || reason.length < 10" @click="sensitiveAction('sessions/revoke')">Revoke all sessions</button></div></div></Dialog>
  <Dialog :open="activeDialog === 'scopes'" :title="selected ? `Authorisation scopes for ${selected.name}` : 'Authorisation scopes'" description="Choose reviewed jurisdictions and permitted tasks. The internal references remain hidden." :close-on-backdrop="false" mobile-sheet @close="activeDialog = ''"><FormAlert v-if="error" kind="error" :message="error" /><form v-if="selected && selected.user_type !== 'applicant'" @submit.prevent="saveScopes"><fieldset class="repeatable-fields"><legend>Complete scope set</legend><article v-for="(scope, index) in scopeRows" :key="index" class="scope-card"><FloatingCombobox label="Scope type" :model-value="scopeTypeOptions.find((item) => item.value === scope.scope_type)?.label || ''" :options="scopeTypeOptions" required placeholder="Select scope type" @update:model-value="scope.scope_type = ''; scope.scope_id = null; scope.scope_label = ''" @select="selectScopeType(scope, $event)" /><FloatingCombobox v-if="scope.scope_type !== 'national'" label="Jurisdiction or workflow" :model-value="scope.scope_label" :options="referenceOptions(scope.scope_type)" required placeholder="Search by human-readable name" @update:model-value="scope.scope_id = null; scope.scope_label = $event" @select="scope.scope_id = $event.value; scope.scope_label = $event.label" /><p v-else class="field-help">Applies to the whole service.</p><fieldset class="checkbox-option-list"><legend>Allowed tasks</legend><label v-for="task in scopeDirectory.tasks" :key="task.value" class="checkbox"><input v-model="scope.allowed_tasks" type="checkbox" :value="task.value" /><span>{{ task.label }}</span></label></fieldset><label>Expires at (optional)<input v-model="scope.expires_at" type="datetime-local" /></label><button type="button" class="button text" @click="scopeRows.splice(index, 1)">Remove scope</button></article><button type="button" class="button secondary" @click="addScope">Add scope</button></fieldset><FieldError :error="validationErrors.scopes" /><label>Reason for scope change<textarea v-model="reason" minlength="10" rows="3" required /><FieldError :error="validationErrors.reason" /></label><button class="button primary full" :disabled="busy || scopeRows.some((scope) => !scope.scope_type || (scope.scope_type !== 'national' && !scope.scope_id) || !scope.allowed_tasks.length)">Replace scopes</button></form></Dialog>
</template>
