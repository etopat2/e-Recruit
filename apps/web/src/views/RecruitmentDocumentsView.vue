<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, authToken, jsonBody } from '../lib/api'
import { useSessionStore } from '../stores/session'

interface LookupRecord { id: string; label: string; description?: string }
interface GeneratedDocument {
  id: string
  export_type: string
  title: string
  scope: { campaign_id: string; recruitment_post_id: string }
  filters: { row_count?: number }
  purpose: string
  status: string
  sha256: string | null
  failure_reason: string | null
  completed_at: string | null
  created_at: string
  requested_by_name: string
}

const posts = ref<LookupRecord[]>([])
const session = useSessionStore()
const documents = ref<GeneratedDocument[]>([])
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const form = reactive({ document_type: 'interview_shortlist', recruitment_post_id: '', post_label: '', purpose: '' })
let pollTimer: ReturnType<typeof setInterval> | null = null

const postOptions = computed<ComboboxOption[]>(() => posts.value.map((post) => ({ value: post.id, label: post.label, description: post.description })))
const pending = computed(() => documents.value.some((document) => ['pending', 'processing'].includes(document.status)))
const canGenerate = computed(() => ['hq_recruitment_administrator', 'prisons_council_secretariat'].includes(session.user?.user_type || ''))

onMounted(async () => {
  await load()
  pollTimer = setInterval(() => { if (pending.value) void loadDocuments(false) }, 5_000)
})
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer) })

async function load(): Promise<void> {
  loading.value = true
  try {
    if (canGenerate.value) {
      const lookups = await api<{ data: { posts: LookupRecord[] } }>('/operations/lookups', { cacheTtlMs: 30_000 })
      posts.value = lookups.data.posts
    }
    await loadDocuments(false)
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Official-list workspace could not be loaded.' }
  finally { loading.value = false }
}

async function loadDocuments(showError = true): Promise<void> {
  try {
    const response = await api<{ data: GeneratedDocument[] }>('/reports/recruitment-documents')
    documents.value = response.data
  } catch (problem) {
    if (showError) error.value = problem instanceof Error ? problem.message : 'Generated documents could not be refreshed.'
  }
}

async function generate(): Promise<void> {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await api('/reports/recruitment-documents', { method: 'POST', ...jsonBody({ document_type: form.document_type, recruitment_post_id: form.recruitment_post_id, purpose: form.purpose }) })
    notice.value = 'Official list queued. Status refreshes automatically while the Tahoma PDF worker runs.'
    form.purpose = ''
    await loadDocuments()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'The official list could not be queued.' }
  finally { busy.value = false }
}

async function download(document: GeneratedDocument): Promise<void> {
  error.value = ''
  try {
    const response = await fetch(`/api/v1/reports/recruitment-documents/${document.id}/download`, { headers: { Authorization: `Bearer ${authToken()}` } })
    if (!response.ok) throw new Error(`Download failed (${response.status}).`)
    const url = URL.createObjectURL(await response.blob())
    const link = window.document.createElement('a')
    link.href = url; link.download = `${document.export_type}-${document.id}.pdf`; link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1_000)
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'The generated document could not be downloaded.' }
}

function selectPost(option: ComboboxOption): void { form.recruitment_post_id = option.value; form.post_label = option.label }
function readable(value: string): string { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
function timestamp(value: string | null): string { return value ? new Intl.DateTimeFormat('en-UG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—' }
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Official publication worker</p><h1>Recruitment lists</h1><p>Generate protected interview, medical-examination, and final-successful-candidate documents from approved system records.</p></section>
  <FormAlert v-if="error" kind="error" :message="error" page /><FormAlert v-if="notice" kind="success" :message="notice" page />
  <section v-if="loading" class="content-section"><LoadingIndicator label="Loading official document register…" /></section>
  <template v-else>
    <section v-if="canGenerate" class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">New document</p><h2>Queue an official Tahoma PDF</h2></div></div><form class="field-grid" @submit.prevent="generate"><label>Document layout<select v-model="form.document_type"><option value="interview_shortlist">Interview shortlist</option><option value="medical_examination_shortlist">Medical examination shortlist</option><option value="final_successful_candidates">Final successful candidates</option></select></label><FloatingCombobox label="Recruitment post" :model-value="form.post_label" :options="postOptions" required placeholder="Search campaign and post" @update:model-value="form.recruitment_post_id = ''; form.post_label = $event" @select="selectPost" /><label class="wide">Generation purpose<textarea v-model="form.purpose" minlength="10" required placeholder="State why this official copy is required and who will use it." /></label><div class="wide notice"><strong>Controlled output</strong><p>The list is generated only from current approved workflow records. It uses the supplied UPS crest and licensed Tahoma fonts. Historical handwritten signatures are never copied.</p></div><button class="button primary" :disabled="busy || !form.recruitment_post_id || form.purpose.length < 10"><LoadingIndicator v-if="busy" small label="Queueing…" /><span v-else>Queue official list</span></button></form></section>
    <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Protected document register</p><h2>Generated copies</h2></div><button class="button secondary compact" @click="loadDocuments()">Refresh</button></div><div class="table-wrap"><table class="mobile-card-table"><thead><tr><th>Document</th><th>Status</th><th>Records</th><th>Requested</th><th>Integrity</th><th></th></tr></thead><tbody><tr v-for="item in documents" :key="item.id"><td data-label="Document"><strong>{{ item.title || readable(item.export_type) }}</strong><small>{{ item.purpose }}</small></td><td data-label="Status"><StatusBadge :status="item.status" /><small v-if="item.failure_reason" class="danger-text">{{ item.failure_reason }}</small></td><td data-label="Records">{{ item.filters.row_count ?? '—' }}</td><td data-label="Requested">{{ item.requested_by_name }}<small>{{ timestamp(item.created_at) }}</small></td><td data-label="Integrity"><code v-if="item.sha256" :title="item.sha256">{{ item.sha256.slice(0, 12) }}…</code><span v-else>Pending</span></td><td data-label="Action"><button v-if="item.status === 'ready'" type="button" class="button secondary compact" @click="download(item)">Download PDF</button><LoadingIndicator v-else-if="['pending','processing'].includes(item.status)" small label="Generating…" /></td></tr><tr v-if="!documents.length"><td colspan="6" class="empty-state compact">No official recruitment list has been generated yet.</td></tr></tbody></table></div></section>
  </template>
</template>
