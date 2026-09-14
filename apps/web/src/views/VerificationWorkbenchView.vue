<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import FormAlert from '../components/FormAlert.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import SkeletonBlock from '../components/SkeletonBlock.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, authToken, jsonBody } from '../lib/api'

interface EvidenceField { field_key: string; raw_value: string; confidence: number; page_number: number; bounding_polygon: unknown }
interface WorkbenchDocument { id: string; type: string; version: number; preview_url: string; quality: Record<string, unknown>; fields: EvidenceField[] }
interface EvidenceSource { source_id: string; value?: unknown; confidence?: number; page?: number; bounding_polygon?: { x?: number; y?: number; width?: number; height?: number; coordinate_space?: string } | null }
interface Workbench { application: { id: string; reference: string; entered_data: Record<string, unknown> }; documents: WorkbenchDocument[]; comparisons: Array<Record<string, unknown>>; verified_values: Array<Record<string, unknown>>; evidence_matrix: Record<string, EvidenceSource[]> }

const route = useRoute()
const workbench = ref<Workbench | null>(null)
const previews = ref<Record<string, string>>({})
const selectedDocument = ref('')
const selectedField = ref('name')
const selectedSource = ref<EvidenceSource | null>(null)
const error = ref('')
const notice = ref('')
const comparing = ref(false)
const initialLoading = ref(true)
const decision = reactive({ action: 'verify', outcome: 'VERIFIED/CONSISTENT', verified_value: '', reason: '' })
const fieldKeys = computed(() => Object.keys(workbench.value?.evidence_matrix || {}))
const fieldOptions = computed<ComboboxOption[]>(() => fieldKeys.value.map((key) => ({ value: key, label: key.replaceAll('_', ' ') })))
const selectedFieldLabel = computed(() => fieldOptions.value.find((option) => option.value === selectedField.value)?.label || selectedField.value)

onMounted(load)

async function load() {
  try {
    workbench.value = await api<Workbench>(`/applications/${route.params.id}/verification-workbench`)
    selectedDocument.value = workbench.value.documents[0]?.id || ''
    selectedField.value = fieldKeys.value[0] || 'name'
    await Promise.all(workbench.value.documents.map(async (document) => {
      const response = await fetch(`/api/v1/documents/${document.id}/download`, { headers: { Authorization: `Bearer ${authToken()}` } })
      if (response.ok) previews.value[document.id] = URL.createObjectURL(await response.blob())
    }))
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Workbench unavailable.' } finally { initialLoading.value = false }
}

async function compare() {
  comparing.value = true
  try {
    await api(`/applications/${route.params.id}/compare-evidence`, { method: 'POST' })
    await load()
    notice.value = 'Evidence matrix refreshed using pairwise comparisons.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Comparison failed.' }
  finally { comparing.value = false }
}

async function record() {
  if (!selectedDocument.value) return
  try {
    await api(`/documents/${selectedDocument.value}/verification`, { method: 'POST', ...jsonBody({ field_key: selectedField.value, ...decision, evidence_references: [selectedDocument.value], review_state: { viewport: 'same-screen-v1', focused_source: selectedSource.value } }) })
    notice.value = 'Versioned verification decision recorded.'
    await load()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Decision could not be recorded.' }
}

function focusSource(source: EvidenceSource) {
  selectedSource.value = source
  selectedDocument.value = source.source_id
}

function selectField(option: ComboboxOption): void {
  selectedField.value = option.value
  selectedSource.value = null
}

function previewUrl(document: WorkbenchDocument): string {
  const url = previews.value[document.id] || ''
  const page = selectedSource.value?.source_id === document.id ? selectedSource.value.page : undefined
  return page ? `${url}#page=${page}&zoom=page-fit` : url
}

function markerStyle(document: WorkbenchDocument): Record<string, string> | undefined {
  const source = selectedSource.value
  const box = source?.bounding_polygon
  if (!source || source.source_id !== document.id || !box || box.coordinate_space !== 'normalised') return undefined
  const clamp = (value: number | undefined) => `${Math.max(0, Math.min(1, Number(value || 0))) * 100}%`
  return { left: clamp(box.x), top: clamp(box.y), width: clamp(box.width), height: clamp(box.height) }
}
</script>

<template>
  <section class="workspace-heading">
    <div><p class="eyebrow">Verification workbench</p><h1>{{ workbench?.application.reference || 'Application evidence' }}</h1><p>Original documents, OCR output, entered data, and decisions remain visible together.</p></div>
    <button class="button secondary" :disabled="comparing" @click="compare"><LoadingIndicator v-if="comparing" small label="Comparing…" /><span v-else>Run pairwise comparison</span></button>
  </section>
  <FormAlert v-if="error" kind="error" :message="error" page /><FormAlert v-if="notice" kind="success" :message="notice" page />
  <section v-if="workbench" class="verification-layout">
    <div class="document-rail">
      <article v-for="document in workbench.documents" :key="document.id" :class="['document-card', { selected: selectedDocument === document.id }]" @click="selectedDocument = document.id">
        <div class="card-topline"><span>{{ document.type }}</span><span>v{{ document.version }}</span></div>
        <div v-if="previews[document.id]" class="source-preview"><iframe :src="previewUrl(document)" :title="`${document.type} original`" /><i v-if="markerStyle(document)" class="source-highlight" :style="markerStyle(document)" aria-hidden="true" /></div>
        <div v-else class="preview-loading"><LoadingIndicator label="Loading protected preview…" /></div>
        <p v-if="selectedSource?.source_id === document.id" class="source-focus" role="status">Focused evidence source: page {{ selectedSource.page || 1 }}<span v-if="selectedSource.bounding_polygon">, highlighted at its recorded OCR coordinates</span>.</p>
        <div class="quality-row"><StatusBadge :status="String(document.quality?.status || 'review')" /><small>Original file · proxy access</small></div>
      </article>
    </div>
    <div class="evidence-panel">
      <div class="section-heading"><div><p class="eyebrow">Evidence matrix</p><h2>Field-by-field comparison</h2></div><FloatingCombobox label="Field to verify" :model-value="selectedFieldLabel" :options="fieldOptions" placeholder="Search evidence fields" @update:model-value="selectedField = ''" @select="selectField" /></div>
      <table class="evidence-table"><thead><tr><th>Source</th><th>Value</th><th>Confidence</th></tr></thead><tbody><tr><td>Applicant entry</td><td>{{ workbench.application.entered_data }}</td><td>Declared</td></tr><tr v-for="source in workbench.evidence_matrix[selectedField]" :key="String(source.source_id)" :class="{ 'focused-source': selectedSource === source }"><td>{{ source.source_id }}<small v-if="source.page">Page {{ source.page }}</small></td><td><button class="evidence-source" type="button" @click="focusSource(source)">{{ source.value || 'Not available' }}<span>Focus original source</span></button></td><td>{{ source.confidence ? `${Math.round(Number(source.confidence) * 100)}%` : '—' }}</td></tr></tbody></table>
      <div class="decision-panel"><h3>Record an accountable decision</h3><div class="field-grid"><label>Action<select v-model="decision.action"><option value="verify">Verify</option><option value="correct">Correct OCR/value</option><option value="flag_discrepancy">Flag discrepancy</option><option value="mark_ocr_incorrect">Mark OCR incorrect</option><option value="request_replacement">Request replacement</option><option value="mark_unreadable">Mark unreadable</option><option value="mark_not_present">Mark not present</option></select></label><label>Outcome<select v-model="decision.outcome"><option>VERIFIED/CONSISTENT</option><option>PROBABLE MATCH</option><option>DISCREPANCY</option><option>UNREADABLE/LOW CONFIDENCE</option><option>NOT AVAILABLE</option></select></label><label class="wide">Verified/corrected value<input v-model="decision.verified_value" /></label><label class="wide">Reason<textarea v-model="decision.reason" placeholder="Required for discrepancies and corrections" /></label></div><button class="button primary" @click="record">Record versioned decision</button></div>
    </div>
  </section>
  <section v-else-if="initialLoading" class="content-section"><SkeletonBlock :lines="7" label="Loading protected evidence" /></section>
</template>
