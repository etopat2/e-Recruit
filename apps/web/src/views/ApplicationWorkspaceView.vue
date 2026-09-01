<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StatusBadge from '../components/StatusBadge.vue'
import { api, ApiError, jsonBody } from '../lib/api'
import { getLocalDraft, offlineDb, putLocalDraft } from '../offline/database'
import type { ApplicationRecord } from '../types'

const route = useRoute(); const router = useRouter()
const application = ref<ApplicationRecord | null>(null)
type FormFields = Record<string, string | number | undefined>
interface EducationDraft extends FormFields { level: string; institution: string; completion_year: string; result: string }
interface DeclarationFields { [key: string]: string | boolean | undefined; accepted: boolean }
interface ApplicationDraft {
  [key: string]: FormFields | EducationDraft[] | DeclarationFields
  personal: FormFields
  address: FormFields
  education: EducationDraft[]
  declaration: DeclarationFields
}
const draft = reactive<ApplicationDraft>({
  personal: { full_name: '', nin: '', date_of_birth: '', nationality: '', phone: '', email: '' },
  address: { district: '', county: '', subcounty: '', parish: '', physical_address: '' },
  education: [],
  declaration: { accepted: false },
})
const activeSection = ref('personal'); const saveState = ref<'saved' | 'saving' | 'offline' | 'conflict'>('saved')
const error = ref(''); const uploadType = ref('national_id'); const uploadFile = ref<File | null>(null); const uploadProgress = ref(0); const submitting = ref(false)
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
    Object.assign(draft, response.data.draft_data || {})
    const local = await getLocalDraft(response.data.id)
    if (local && new Date(local.updatedAt) > new Date(response.data.submitted_at || 0) && local.entityVersion === response.data.entity_version) Object.assign(draft, local.data)
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Application could not be loaded.' }
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
    saveState.value = problem instanceof ApiError && problem.status === 409 ? 'conflict' : 'offline'
  }
}

function addEducation() {
  if (!Array.isArray(draft.education)) draft.education = []
  draft.education.push({ level: '', institution: '', completion_year: '', result: '' })
}

async function upload() {
  if (!application.value || !uploadFile.value) return
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
  } catch (problem) { error.value = `${problem instanceof Error ? problem.message : 'Upload failed.'} Choose Upload again to resume acknowledged chunks.` }
  finally { if (!uploadFile.value) uploadProgress.value = 0 }
}

async function sha256(bytes: Uint8Array): Promise<string> {
  const copy = new Uint8Array(bytes.byteLength); copy.set(bytes)
  const digest = await crypto.subtle.digest('SHA-256', copy.buffer)
  return [...new Uint8Array(digest)].map((value) => value.toString(16).padStart(2, '0')).join('')
}

async function submit() {
  if (!application.value) return
  submitting.value = true; error.value = ''
  await saveDraft()
  if (saveState.value !== 'saved') { error.value = 'Resolve the draft sync state before final submission.'; submitting.value = false; return }
  try {
    const response = await api<{ data: ApplicationRecord }>(`/applications/${application.value.id}/submit`, { method: 'POST', ...jsonBody({ entity_version: application.value.entity_version, privacy_accepted: true, declaration_accepted: Boolean(draft.declaration?.accepted), idempotency_key: crypto.randomUUID() }) })
    await offlineDb.drafts.delete(application.value.id)
    await router.push(`/applications/${response.data.id}/status`)
  } catch (problem) {
    const apiError = problem as ApiError
    error.value = apiError.errors ? Object.values(apiError.errors).flat().join(' ') : apiError.message
  } finally { submitting.value = false }
}
</script>

<template>
  <section v-if="application" class="workspace-heading"><div><p class="eyebrow">{{ application.campaign.name }}</p><h1>{{ application.post.name }}</h1><StatusBadge :status="application.status" /></div><div class="progress-card"><span>{{ completion }}% complete</span><div class="progress-track"><i :style="{ width: `${completion}%` }" /></div><small :class="`save-${saveState}`">{{ saveState === 'saved' ? 'Saved securely' : saveState === 'saving' ? 'Saving…' : saveState === 'conflict' ? 'Conflict — refresh required' : 'Saved on this device' }}</small></div></section>
  <div v-if="error" class="alert error page-alert" role="alert">{{ error }}</div>
  <section v-if="application" class="wizard-layout">
    <nav class="wizard-nav" aria-label="Application sections"><button v-for="(section, index) in sections" :key="section" :class="{ active: activeSection === section }" @click="activeSection = section"><span>{{ index + 1 }}</span>{{ section.replaceAll('_', ' ') }}</button><button :class="{ active: activeSection === 'documents' }" @click="activeSection = 'documents'"><span>{{ sections.length + 1 }}</span>Documents</button><button :class="{ active: activeSection === 'review' }" @click="activeSection = 'review'"><span>{{ sections.length + 2 }}</span>Review</button></nav>
    <div class="wizard-panel">
      <form v-if="activeSection === 'personal'" @submit.prevent><p class="eyebrow">Personal details</p><h2>Details matching your identification</h2><div class="field-grid"><label>Full legal name<input v-model="draft.personal.full_name" required /></label><label>National ID number<input v-model="draft.personal.nin" required /></label><label>Date of birth<input v-model="draft.personal.date_of_birth" type="date" required /></label><label>Nationality<input v-model="draft.personal.nationality" required /></label><label>Phone number<input v-model="draft.personal.phone" type="tel" /></label><label>Email address<input v-model="draft.personal.email" type="email" /></label></div></form>
      <form v-else-if="activeSection === 'address' || activeSection === 'origin' || activeSection === 'residence'" @submit.prevent><p class="eyebrow">Geography</p><h2>{{ activeSection }} details</h2><div class="field-grid"><label>District<input v-model="activeFields.district" required /></label><label>County<input v-model="activeFields.county" /></label><label>Sub-county<input v-model="activeFields.subcounty" /></label><label>Parish<input v-model="activeFields.parish" /></label><label class="wide">Village / physical address<textarea v-model="activeFields.physical_address" /></label></div></form>
      <div v-else-if="activeSection === 'education'"><p class="eyebrow">Qualifications</p><div class="section-heading"><h2>Education records</h2><button class="button secondary compact" @click="addEducation">Add qualification</button></div><article v-for="(record, index) in draft.education" :key="index" class="repeat-card"><div class="field-grid"><label>Level<input v-model="record.level" /></label><label>Institution<input v-model="record.institution" /></label><label>Completion year<input v-model="record.completion_year" inputmode="numeric" /></label><label>Result / class<input v-model="record.result" /></label></div><button class="text-button danger" @click="draft.education.splice(index, 1)">Remove</button></article><div v-if="!draft.education.length" class="empty-state compact"><p>Add each completed qualification.</p></div></div>
      <form v-else-if="activeSection === 'declaration' || activeSection === 'declarations'" @submit.prevent><p class="eyebrow">Declaration</p><h2>Confirm the information is yours</h2><label class="checkbox"><input v-model="draft.declaration.accepted" type="checkbox" /> <span>I declare that the information and documents I provide are complete and accurate. I understand that false information may disqualify my application.</span></label></form>
      <form v-else-if="activeSection === 'documents'" @submit.prevent="upload"><p class="eyebrow">Protected evidence</p><h2>Upload clear documents</h2><p class="form-intro">PDF, JPEG, or PNG. Files are uploaded in checksum-protected resumable chunks, signature-checked, malware-screened, versioned, and kept in protected storage.</p><div class="upload-row"><label>Document type<select v-model="uploadType"><option value="national_id">National identification</option><option value="academic_certificate">Academic certificate</option><option value="passport_photo">Passport photograph</option><option value="skill_certificate">Skill certificate</option></select></label><label>Choose file<input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="uploadFile = ($event.target as HTMLInputElement).files?.[0] || null" /></label><button class="button primary" :disabled="!uploadFile">{{ uploadProgress ? `Uploading ${uploadProgress}%` : 'Upload' }}</button></div><progress v-if="uploadProgress" :value="uploadProgress" max="100">{{ uploadProgress }}%</progress><ul class="document-list"><li v-for="document in application.documents" :key="String(document.id)"><span><strong>{{ document.document_type }}</strong><small>{{ document.original_filename }}</small></span><StatusBadge :status="String(document.processing_status)" /></li></ul></form>
      <div v-else-if="activeSection === 'review'"><p class="eyebrow">Final review</p><h2>Submit your application</h2><div class="review-summary"><div><span>Sections complete</span><strong>{{ completion }}%</strong></div><div><span>Documents uploaded</span><strong>{{ application.documents.length }}</strong></div><div><span>Hard copies</span><strong>{{ application.post.hard_copy_required ? 'Required after submission' : 'Not required' }}</strong></div></div><div class="notice"><strong>Submission locks this draft.</strong><p>You will receive a UPS reference and downloadable acknowledgement. A reference is assigned only after a successful final submission.</p></div><button class="button primary" :disabled="submitting || !draft.declaration?.accepted" @click="submit">{{ submitting ? 'Submitting securely…' : 'Submit final application' }}</button></div>
      <div v-else><p class="eyebrow">{{ activeSection }}</p><h2>{{ activeSection.replaceAll('_', ' ') }}</h2><label>Information<textarea v-model="activeFields.notes" rows="8" /></label></div>
    </div>
  </section>
  <p v-else-if="!error" class="content-section">Loading application…</p>
</template>
