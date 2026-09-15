<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FieldError from '../components/FieldError.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import SkeletonBlock from '../components/SkeletonBlock.vue'
import StatusBadge from '../components/StatusBadge.vue'
import AdministrativeAddressSelector from '../components/AdministrativeAddressSelector.vue'
import EducationRecordsForm from '../components/EducationRecordsForm.vue'
import { api, ApiError, jsonBody } from '../lib/api'
import { getLocalDraft, offlineDb, putLocalDraft } from '../offline/database'
import type { ApplicationRecord } from '../types'

const route = useRoute(); const router = useRouter()
const application = ref<ApplicationRecord | null>(null)
type FormFields = Record<string, string | number | null | undefined>
interface EducationDraft extends FormFields { level: string; institution: string; completion_year: string; result: string }
interface DeclarationFields { [key: string]: string | boolean | undefined; accepted: boolean }
interface Lc1LetterFields { [key: string]: string | undefined; address_type: string }
interface ApplicationDraft {
  [key: string]: FormFields | EducationDraft[] | DeclarationFields | Lc1LetterFields
  personal: FormFields
  address: FormFields
  education: EducationDraft[]
  declaration: DeclarationFields
  lc1_letter: Lc1LetterFields
}
const draft = reactive<ApplicationDraft>({
  personal: { full_name: '', nin: '', date_of_birth: '', nationality: '', phone: '', email: '' },
  address: { district_id: null, county_id: null, subcounty_id: null, parish_id: null, village_id: null, physical_address: '' },
  education: [],
  declaration: { accepted: false },
  lc1_letter: { address_type: '' },
})
const activeSection = ref('personal'); const saveState = ref<'saved' | 'saving' | 'offline' | 'conflict'>('saved')
const error = ref(''); const uploadType = ref('national_id'); const uploadFile = ref<File | null>(null); const uploadProgress = ref(0); const submitting = ref(false)
const validationErrors = ref<Record<string, string[]>>({}); const loading = ref(true)
const sections = computed(() => Object.keys(application.value?.post.sections || { personal: true, address: true, education: true, declaration: true }))
const activeFields = computed<FormFields>(() => {
  const section = draft[activeSection.value]
  return Array.isArray(section) ? {} : section as FormFields
})
const completion = computed(() => {
  if (!sections.value.length) return 0
  return Math.round(sections.value.filter((section) => {
    const value = draft[section]
    return Array.isArray(value) ? value.length > 0 : value && Object.keys(value).length > 0 && Object.values(value).some(Boolean)
  }).length / sections.value.length * 100)
})
let saveTimer = 0

onMounted(async () => {
  try {
    const response = await api<{ data: ApplicationRecord }>(`/applications/${route.params.id}`)
    application.value = response.data
    for (const section of Object.keys(response.data.post.sections || {})) {
      if (draft[section] === undefined) draft[section] = {}
    }
    if (!Object.keys(response.data.post.sections || {}).includes(activeSection.value)) {
      activeSection.value = Object.keys(response.data.post.sections || {})[0] || 'personal'
    }
    Object.assign(draft, response.data.draft_data || {})
    const local = await getLocalDraft(response.data.id)
    if (local && new Date(local.updatedAt) > new Date(response.data.submitted_at || 0) && local.entityVersion === response.data.entity_version) Object.assign(draft, local.data)
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Application could not be loaded.' } finally { loading.value = false }
})

watch(draft, () => {
  window.clearTimeout(saveTimer)
  saveTimer = window.setTimeout(saveDraft, 900)
}, { deep: true })

async function saveDraft() {
  if (!application.value || application.value.status !== 'draft') return
  saveState.value = 'saving'
  await putLocalDraft({ id: application.value.id, entityVersion: application.value.entity_version, data: JSON.parse(JSON.stringify(draft)), updatedAt: new Date().toISOString(), syncState: 'pending' })
  if (!navigator.onLine) { saveState.value = 'offline'; return }
  try {
    const response = await api<{ data: ApplicationRecord }>(`/applications/${application.value.id}`, { method: 'PUT', ...jsonBody({ draft_data: draft, entity_version: application.value.entity_version }) })
    application.value.entity_version = response.data.entity_version
    saveState.value = 'saved'
    await putLocalDraft({ id: application.value.id, entityVersion: response.data.entity_version, data: JSON.parse(JSON.stringify(draft)), updatedAt: new Date().toISOString(), syncState: 'clean' })
  } catch (problem) {
    if (problem instanceof ApiError) validationErrors.value = problem.errors
    saveState.value = problem instanceof ApiError && problem.status === 409 ? 'conflict' : 'offline'
  }
}

function updateAddress(value: FormFields) {
  Object.assign(activeFields.value, value)
}

async function upload() {
  if (!application.value || !uploadFile.value) return
  validationErrors.value = {}; error.value = ''
  const file = uploadFile.value; const chunkSize = 1024 * 1024
  try {
    const idempotencyKey = await sha256(new TextEncoder().encode(`${application.value.id}:${uploadType.value}:${file.name}:${file.size}:${file.lastModified}`))
    const initiated = await api<{ session: { id: string; chunk_size: number; expected_chunks: number; received_chunks: number[] } }>(`/applications/${application.value.id}/upload-sessions`, { method: 'POST', ...jsonBody({ document_type: uploadType.value, original_filename: file.name, expected_bytes: file.size, chunk_size: chunkSize, idempotency_key: idempotencyKey }) })
    const received = new Set(initiated.session.received_chunks)
    for (let index = 0; index < initiated.session.expected_chunks; index++) {
      if (!received.has(index)) {
        const part = file.slice(index * initiated.session.chunk_size, Math.min(file.size, (index + 1) * initiated.session.chunk_size))
        const body = new FormData(); body.append('chunk', part, `${index}.part`); body.append('sha256', await sha256(new Uint8Array(await part.arrayBuffer())))
        await api(`/upload-sessions/${initiated.session.id}/chunks/${index}`, { method: 'PUT', body })
      }
      uploadProgress.value = Math.round(((index + 1) / initiated.session.expected_chunks) * 100)
    }
    const response = await api<{ document: Record<string, unknown> }>(`/upload-sessions/${initiated.session.id}/complete`, { method: 'POST', ...jsonBody({ sha256: await sha256(new Uint8Array(await file.arrayBuffer())), client_mime_type: file.type }) })
    application.value.documents.push(response.document); uploadFile.value = null; error.value = ''
  } catch (problem) { if (problem instanceof ApiError) validationErrors.value = problem.errors; error.value = `${problem instanceof Error ? problem.message : 'Upload failed.'} Choose Upload again to resume acknowledged chunks.` }
  finally { if (!uploadFile.value) uploadProgress.value = 0 }
}

async function sha256(bytes: Uint8Array): Promise<string> {
  const copy = new Uint8Array(bytes.byteLength); copy.set(bytes)
  const digest = await crypto.subtle.digest('SHA-256', copy.buffer)
  return [...new Uint8Array(digest)].map((value) => value.toString(16).padStart(2, '0')).join('')
}

async function submit() {
  if (!application.value) return
  submitting.value = true; error.value = ''; validationErrors.value = {}
  await saveDraft()
  if (saveState.value !== 'saved') { error.value = 'Resolve the draft sync state before final submission.'; submitting.value = false; return }
  try {
    const response = await api<{ data: ApplicationRecord }>(`/applications/${application.value.id}/submit`, { method: 'POST', ...jsonBody({ entity_version: application.value.entity_version, privacy_accepted: true, declaration_accepted: Boolean(draft.declaration?.accepted), idempotency_key: crypto.randomUUID() }) })
    await offlineDb.drafts.delete(application.value.id)
    await router.push(`/applications/${response.data.id}/status`)
  } catch (problem) {
    const apiError = problem as ApiError
    error.value = apiError.message
    validationErrors.value = apiError.errors || {}
  } finally { submitting.value = false }
}
</script>

<template>
  <section v-if="application" class="workspace-heading"><div><p class="eyebrow">{{ application.campaign.name }}</p><h1>{{ application.post.name }}</h1><StatusBadge :status="application.status" /></div><div class="progress-card"><span>{{ completion }}% complete</span><div class="progress-track"><i :style="{ width: `${completion}%` }" /></div><small :class="`save-${saveState}`">{{ saveState === 'saved' ? 'Saved securely' : saveState === 'saving' ? 'Saving…' : saveState === 'conflict' ? 'Conflict — refresh required' : 'Saved on this device' }}</small></div></section>
  <FormAlert v-if="error" kind="error" page><strong>{{ error }}</strong><ul v-if="Object.keys(validationErrors).length" class="validation-summary"><li v-for="(messages, field) in validationErrors" :key="field"><span>{{ field.replaceAll('_', ' ').replaceAll('.', ' ') }}:</span> {{ messages.join(' ') }}</li></ul></FormAlert>
  <section v-if="application" class="wizard-layout">
    <nav class="wizard-nav" aria-label="Application sections"><button v-for="(section, index) in sections" :key="section" :class="{ active: activeSection === section }" @click="activeSection = section"><span>{{ index + 1 }}</span>{{ section.replaceAll('_', ' ') }}</button><button :class="{ active: activeSection === 'documents' }" @click="activeSection = 'documents'"><span>{{ sections.length + 1 }}</span>Documents</button><button :class="{ active: activeSection === 'review' }" @click="activeSection = 'review'"><span>{{ sections.length + 2 }}</span>Review</button></nav>
    <div class="wizard-panel">
      <form v-if="activeSection === 'personal'" @submit.prevent><p class="eyebrow">Personal details</p><h2>Details matching your identification</h2><div class="field-grid"><label>Full legal name<input v-model="draft.personal.full_name" required /><FieldError :error="validationErrors['draft_data.personal.full_name']" /></label><label>National ID number<input v-model="draft.personal.nin" required /><FieldError :error="validationErrors['draft_data.personal.nin']" /></label><label>Date of birth<input v-model="draft.personal.date_of_birth" type="date" required /><FieldError :error="validationErrors['draft_data.personal.date_of_birth']" /></label><label>Nationality<input v-model="draft.personal.nationality" required /><FieldError :error="validationErrors['draft_data.personal.nationality']" /></label><label>Phone number<input v-model="draft.personal.phone" type="tel" /><FieldError :error="validationErrors['draft_data.personal.phone']" /></label><label>Email address<input v-model="draft.personal.email" type="email" /><FieldError :error="validationErrors['draft_data.personal.email']" /></label></div></form>
      <form v-else-if="activeSection === 'address' || activeSection === 'origin' || activeSection === 'residence'" @submit.prevent><p class="eyebrow">Geography</p><h2>{{ activeSection }} details</h2><AdministrativeAddressSelector :key="activeSection" :model-value="activeFields" @update:model-value="updateAddress" /><label class="wide">Street, landmark, or other physical directions <span>(optional)</span><textarea v-model="activeFields.physical_address" rows="3" /></label></form>
      <EducationRecordsForm v-else-if="activeSection === 'education'" v-model="draft.education" />
      <form v-else-if="activeSection === 'declaration' || activeSection === 'declarations'" @submit.prevent><p class="eyebrow">Declaration</p><h2>Confirm the information is yours</h2><label class="checkbox"><input v-model="draft.declaration.accepted" type="checkbox" /> <span>I declare that the information and documents I provide are complete and accurate. I understand that false information may disqualify my application.</span></label></form>
      <form v-else-if="activeSection === 'documents'" @submit.prevent="upload"><p class="eyebrow">Protected evidence</p><h2>Upload clear documents</h2><p class="form-intro">PDF, JPEG, or PNG. Files are uploaded in checksum-protected resumable chunks, signature-checked, malware-screened, versioned, and kept in protected storage.</p><label v-if="application.post.lc_source_policy === 'origin_or_residence'" class="wide">Address supported by the LC1 letter<select v-model="draft.lc1_letter.address_type" required><option value="">Select the address shown on the letter</option><option value="origin">Place of origin</option><option value="residence">Current residence</option></select><small class="field-help">This verified district determines the interview region; it does not change your application reference.</small><FieldError :error="validationErrors['draft_data.lc1_letter.address_type']" /></label><div class="upload-row"><label>Document type<select v-model="uploadType"><option value="national_id">National identification</option><option value="application_letter">Application letter</option><option value="lc1_letter">LC1 letter</option><option value="academic_certificate">Academic certificate / result slip</option><option value="passport_photo">Passport photograph</option><option value="skill_certificate">Skill certificate</option></select><FieldError :error="validationErrors.document_type" /></label><label>Choose file<input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="uploadFile = ($event.target as HTMLInputElement).files?.[0] || null" /><FieldError :error="validationErrors.file" /></label><button class="button primary" :disabled="!uploadFile || uploadProgress > 0"><LoadingIndicator v-if="uploadProgress" small :label="`Uploading ${uploadProgress}%`" /><span v-else>Upload</span></button></div><progress v-if="uploadProgress" :value="uploadProgress" max="100">{{ uploadProgress }}%</progress><ul class="document-list"><li v-for="document in application.documents" :key="String(document.id)"><span><strong>{{ String(document.document_type).replaceAll('_', ' ') }}</strong><small>{{ document.filename || document.original_filename }}</small></span><StatusBadge :status="String(document.processing_status)" /></li></ul></form>
      <div v-else-if="activeSection === 'review'"><p class="eyebrow">Final review</p><h2>Submit your application</h2><div class="review-summary"><div><span>Sections complete</span><strong>{{ completion }}%</strong></div><div><span>Documents uploaded</span><strong>{{ application.documents.length }}</strong></div><div><span>Hard copies</span><strong>{{ application.post.hard_copy_required ? 'Required after submission' : 'Not required' }}</strong></div></div><FieldError :error="validationErrors.application" /><FieldError :error="validationErrors.missing_sections" /><FieldError :error="validationErrors.missing_documents" /><div class="notice"><strong>Submission locks this draft.</strong><p>You will receive a UPS reference and downloadable acknowledgement. A reference is assigned only after a successful final submission.</p></div><button class="button primary" :disabled="submitting || !draft.declaration?.accepted" @click="submit"><LoadingIndicator v-if="submitting" small label="Submitting securely…" /><span v-else>Submit final application</span></button></div>
      <div v-else><p class="eyebrow">{{ activeSection }}</p><h2>{{ activeSection.replaceAll('_', ' ') }}</h2><label>Information<textarea v-model="activeFields.notes" rows="8" /></label></div>
    </div>
  </section>
  <section v-else-if="loading" class="content-section"><SkeletonBlock :lines="7" label="Loading application workspace" /></section>
</template>
