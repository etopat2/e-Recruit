<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import SkeletonBlock from '../components/SkeletonBlock.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, authToken, jsonBody } from '../lib/api'

interface EvidenceField { field_key: string; raw_value: string; confidence: number; page_number: number; bounding_polygon: unknown }
interface WorkbenchDocument { id: string; type: string; label: string; filename: string; version: number; preview_url: string; quality: Record<string, unknown>; fields: EvidenceField[] }
interface EvidenceSource { document_id: string; source_label: string; source_filename?: string; value?: unknown; confidence?: number; page?: number; bounding_polygon?: { x?: number; y?: number; width?: number; height?: number; coordinate_space?: string } | null }
interface Workbench { application: { id: string; reference: string; applicant_name: string; entered_data: Record<string, unknown> }; documents: WorkbenchDocument[]; comparisons: Array<Record<string, unknown>>; verified_values: Array<Record<string, unknown>>; evidence_matrix: Record<string, EvidenceSource[]> }

const route = useRoute()
const workbench = ref<Workbench | null>(null)
const previews = ref<Record<string, string>>({})
const selectedDocument = ref('')
const selectedField = ref('name')
const selectedSource = ref<EvidenceSource | null>(null)
const error = ref('')
const notice = ref('')
const comparing = ref(false)
const recording = ref(false)
const initialLoading = ref(true)
const decision = reactive({ action: 'verify', outcome: 'VERIFIED/CONSISTENT', verified_value: '', reason: '' })
const fieldKeys = computed(() => Object.keys(workbench.value?.evidence_matrix || {}))
const fieldOptions = computed<ComboboxOption[]>(() => fieldKeys.value.map((key) => ({ value: key, label: humanise(key) })))
const selectedFieldLabel = computed(() => fieldOptions.value.find((option) => option.value === selectedField.value)?.label || humanise(selectedField.value))
const entered = computed(() => workbench.value?.application.entered_data || {})
const personal = computed(() => objectAt(entered.value, 'personal'))
const addresses = computed(() => ['origin', 'residence', 'address'].map((key) => ({ key, value: objectAt(entered.value, key) })).filter((item) => Object.keys(item.value).length > 0))
const education = computed(() => Array.isArray(entered.value.education) ? entered.value.education.filter(isRecord) : [])
const declarations = computed(() => {
  const values = { ...objectAt(entered.value, 'declaration'), ...objectAt(entered.value, 'declarations') }
  return visibleEntries(values)
})

onMounted(load)
onBeforeUnmount(() => Object.values(previews.value).forEach((url) => URL.revokeObjectURL(url)))

async function load(): Promise<void> {
  try {
    workbench.value = await api<Workbench>(`/applications/${route.params.id}/verification-workbench`)
    selectedDocument.value = workbench.value.documents[0]?.id || ''
    selectedField.value = fieldKeys.value.includes(selectedField.value) ? selectedField.value : fieldKeys.value[0] || 'name'
    await Promise.all(workbench.value.documents.map(async (document) => {
      if (previews.value[document.id]) return
      const response = await fetch(document.preview_url, { headers: { Authorization: `Bearer ${authToken()}` } })
      if (response.ok) previews.value[document.id] = URL.createObjectURL(await response.blob())
    }))
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Workbench unavailable.'
  } finally {
    initialLoading.value = false
  }
}

async function compare(): Promise<void> {
  comparing.value = true
  error.value = ''
  try {
    await api(`/applications/${route.params.id}/compare-evidence`, { method: 'POST' })
    await load()
    notice.value = 'Evidence matrix refreshed using pairwise comparisons.'
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Comparison failed.'
  } finally {
    comparing.value = false
  }
}

async function record(): Promise<void> {
  if (!selectedDocument.value) {
    error.value = 'Select a supporting document before recording a verification decision.'
    return
  }
  recording.value = true
  error.value = ''
  try {
    await api(`/documents/${selectedDocument.value}/verification`, { method: 'POST', ...jsonBody({ field_key: selectedField.value, ...decision, evidence_references: [selectedDocument.value], review_state: { viewport: 'same-screen-v2', source_label: selectedSource.value?.source_label, page: selectedSource.value?.page } }) })
    notice.value = 'Versioned verification decision recorded.'
    await load()
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Decision could not be recorded.'
  } finally {
    recording.value = false
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function objectAt(value: Record<string, unknown>, key: string): Record<string, unknown> {
  return isRecord(value[key]) ? value[key] : {}
}

function humanise(value: string): string {
  const labels: Record<string, string> = { nin: 'National ID number', dob: 'Date of birth', lc1: 'LC1', s4: 'S.4' }
  if (labels[value.toLowerCase()]) return labels[value.toLowerCase()]
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function visibleEntries(value: Record<string, unknown>): Array<[string, unknown]> {
  return Object.entries(value).filter(([key, item]) => {
    if (key === 'id' || key.endsWith('_id') || /hash|path|fingerprint|secret/i.test(key)) return false
    return item !== null && item !== undefined && item !== '' && !isRecord(item) && !Array.isArray(item)
  })
}

function formatValue(value: unknown, key = ''): string {
  if (value === true) return 'Yes'
  if (value === false) return 'No'
  if (value === null || value === undefined || value === '') return 'Not provided'
  const text = String(value)
  if (/date|dob|_at$|year/i.test(key)) {
    const parsed = new Date(/^\d{4}$/.test(text) ? `${text}-01-01` : text)
    if (!Number.isNaN(parsed.valueOf())) return /^\d{4}$/.test(text) ? text : new Intl.DateTimeFormat('en-UG', { day: 'numeric', month: 'short', year: 'numeric' }).format(parsed)
  }
  return text.replaceAll('_', ' ')
}

function addressText(address: Record<string, unknown>): string {
  const hierarchy = ['district', 'county', 'subcounty', 'parish', 'village'].map((key) => address[key]).filter(Boolean)
  if (address.physical_address) hierarchy.push(address.physical_address)
  if (hierarchy.length) return hierarchy.join(', ')
  return address.full_address ? String(address.full_address) : 'Not provided'
}

function educationValue(record: Record<string, unknown>, keys: string[]): string {
  const key = keys.find((candidate) => record[candidate] !== null && record[candidate] !== undefined && record[candidate] !== '')
  return key ? formatValue(record[key], key) : 'Not provided'
}

function enteredValue(field: string): string {
  const mapping: Record<string, unknown> = {
    name: personal.value.full_name,
    full_name: personal.value.full_name,
    nin: personal.value.nin,
    dob: personal.value.date_of_birth,
    date_of_birth: personal.value.date_of_birth,
    grade: education.value[0]?.result,
    result: education.value[0]?.result,
    index_number: education.value[0]?.index_number,
  }
  return formatValue(mapping[field] ?? personal.value[field] ?? entered.value[field], field)
}

function focusSource(source: EvidenceSource): void {
  selectedSource.value = source
  selectedDocument.value = source.document_id
}

function selectField(option: ComboboxOption): void {
  selectedField.value = option.value
  selectedSource.value = null
  decision.verified_value = enteredValue(option.value) === 'Not provided' ? '' : enteredValue(option.value)
}

function previewUrl(document: WorkbenchDocument): string {
  const url = previews.value[document.id] || ''
  const page = selectedSource.value?.document_id === document.id ? selectedSource.value.page : undefined
  return page ? `${url}#page=${page}&zoom=page-fit` : url
}

function markerStyle(document: WorkbenchDocument): Record<string, string> | undefined {
  const source = selectedSource.value
  const box = source?.bounding_polygon
  if (!source || source.document_id !== document.id || !box || box.coordinate_space !== 'normalised') return undefined
  const clamp = (value: number | undefined) => `${Math.max(0, Math.min(1, Number(value || 0))) * 100}%`
  return { left: clamp(box.x), top: clamp(box.y), width: clamp(box.width), height: clamp(box.height) }
}
</script>

<template>
  <section class="workspace-heading"><div><p class="eyebrow">Verification workbench</p><h1>{{ workbench?.application.reference || 'Application evidence' }}</h1><p v-if="workbench">{{ workbench.application.applicant_name }} · Original documents, applicant declarations, extracted evidence, and decisions together.</p></div><button class="button secondary" :disabled="comparing || !workbench" @click="compare"><LoadingIndicator v-if="comparing" small label="Comparing…" /><span v-else>Run pairwise comparison</span></button></section>
  <FormAlert v-if="error" kind="error" :message="error" page /><FormAlert v-if="notice" kind="success" :message="notice" page />
  <section v-if="workbench" class="verification-layout">
    <div class="document-rail">
      <article v-for="document in workbench.documents" :key="document.id" :class="['document-card', { selected: selectedDocument === document.id }]" @click="selectedDocument = document.id">
        <div class="card-topline"><span>{{ document.label }}</span><span>Version {{ document.version }}</span></div><p class="document-filename">{{ document.filename }}</p>
        <div v-if="previews[document.id]" class="source-preview"><iframe :src="previewUrl(document)" :title="`${document.label} original`" /><i v-if="markerStyle(document)" class="source-highlight" :style="markerStyle(document)" aria-hidden="true" /></div><div v-else class="preview-loading"><LoadingIndicator label="Loading protected preview…" /></div>
        <p v-if="selectedSource?.document_id === document.id" class="source-focus" role="status">Focused evidence source: page {{ selectedSource.page || 1 }}<span v-if="selectedSource.bounding_polygon">, highlighted at its recorded OCR coordinates</span>.</p>
        <div class="quality-row"><StatusBadge :status="String(document.quality?.status || 'review')" /><a v-if="previews[document.id]" :href="previews[document.id]" target="_blank" rel="noopener">Open original file</a></div>
      </article>
      <p v-if="!workbench.documents.length" class="empty-state">No supporting documents have been uploaded.</p>
    </div>
    <div class="evidence-panel">
      <section class="declared-evidence" aria-labelledby="declared-heading"><div class="section-heading"><div><p class="eyebrow">Applicant entry</p><h2 id="declared-heading">Structured declared information</h2></div></div>
        <article><h3>Personal details</h3><dl class="evidence-details"><template v-for="([key, value]) in visibleEntries(personal)" :key="key"><dt>{{ humanise(key) }}</dt><dd>{{ formatValue(value, key) }}</dd></template></dl></article>
        <article v-for="address in addresses" :key="address.key"><h3>{{ address.key === 'address' ? 'Address' : humanise(address.key) }}</h3><p>{{ addressText(address.value) }}</p></article>
        <article><h3>Education records</h3><div class="table-scroll"><table><thead><tr><th>Level</th><th>Institution</th><th>Result / class</th><th>Completed</th></tr></thead><tbody><tr v-for="(record, index) in education" :key="index"><td>{{ educationValue(record, ['level', 'qualification_level']) }}</td><td>{{ educationValue(record, ['institution', 'institution_name', 'manual_institution_name']) }}</td><td>{{ educationValue(record, ['result', 'result_class']) }}</td><td>{{ educationValue(record, ['completion_year', 'year']) }}</td></tr><tr v-if="!education.length"><td colspan="4">No education record provided.</td></tr></tbody></table></div></article>
        <article><h3>Declarations</h3><dl class="evidence-details"><template v-for="([key, value]) in declarations" :key="key"><dt>{{ humanise(key) }}</dt><dd>{{ formatValue(value, key) }}</dd></template><template v-if="!declarations.length"><dt>Declaration</dt><dd>Not provided</dd></template></dl></article>
      </section>

      <section class="comparison-evidence"><div class="section-heading"><div><p class="eyebrow">Evidence matrix</p><h2>Field-by-field comparison</h2></div><FloatingCombobox label="Field to verify" :model-value="selectedFieldLabel" :options="fieldOptions" placeholder="Search evidence fields" @update:model-value="selectedField = ''" @select="selectField" /></div>
        <table class="evidence-table"><thead><tr><th>Source</th><th>Value</th><th>Confidence</th></tr></thead><tbody><tr><td>Applicant entry</td><td>{{ enteredValue(selectedField) }}</td><td>Declared</td></tr><tr v-for="source in workbench.evidence_matrix[selectedField] || []" :key="`${source.document_id}-${source.page}`" :class="{ 'focused-source': selectedSource === source }"><td>{{ source.source_label }}<small v-if="source.source_filename">{{ source.source_filename }}</small><small v-if="source.page">Page {{ source.page }}</small></td><td><button class="evidence-source" type="button" @click="focusSource(source)">{{ formatValue(source.value, selectedField) }}<span>Focus original source</span></button></td><td>{{ source.confidence !== undefined && source.confidence !== null ? `${Math.round(Number(source.confidence) * 100)}%` : '—' }}</td></tr><tr v-if="!(workbench.evidence_matrix[selectedField] || []).length"><td colspan="3">No extracted document evidence is available for this field.</td></tr></tbody></table>
      </section>
      <div class="decision-panel"><h3>Record an accountable decision</h3><div class="field-grid"><label>Action<select v-model="decision.action"><option value="verify">Verify</option><option value="correct">Correct OCR/value</option><option value="flag_discrepancy">Flag discrepancy</option><option value="mark_ocr_incorrect">Mark OCR incorrect</option><option value="request_replacement">Request replacement</option><option value="mark_unreadable">Mark unreadable</option><option value="mark_not_present">Mark not present</option></select></label><label>Outcome<select v-model="decision.outcome"><option>VERIFIED/CONSISTENT</option><option>PROBABLE MATCH</option><option>DISCREPANCY</option><option>UNREADABLE/LOW CONFIDENCE</option><option>NOT AVAILABLE</option></select></label><label class="wide">Verified/corrected value<input v-model="decision.verified_value" /></label><label class="wide">Reason<textarea v-model="decision.reason" placeholder="Required for discrepancies and corrections" /></label></div><button class="button primary" :disabled="recording || !selectedDocument" @click="record"><LoadingIndicator v-if="recording" small label="Recording…" /><span v-else>Record versioned decision</span></button></div>
    </div>
  </section>
  <section v-else-if="initialLoading" class="content-section"><SkeletonBlock :lines="7" label="Loading protected evidence" /></section>
</template>
