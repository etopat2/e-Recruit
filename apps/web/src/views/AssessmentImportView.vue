<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError, authToken } from '../lib/api'
import Dialog from '../components/Dialog.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import StatusBadge from '../components/StatusBadge.vue'

interface Definition { id: string; recruitment_post_id: string; code: string; name: string; component_type: string; maximum_mark: string; post: { code: string; name: string } }
interface CentreSession { id: string; post_id?: string; label: string; description?: string }
interface ImportRecord { id: string; source_filename: string; status: string; total_rows: number; accepted_rows: number; rejected_rows: number; error_report_path: string | null }

const definitions = ref<Definition[]>([]); const imports = ref<ImportRecord[]>([]); const centreSessions = ref<CentreSession[]>([])
const form = reactive({ assessment_definition_id: '', centre_session_id: '', purpose: '' })
const file = ref<File | null>(null); const rowErrors = ref<Array<{ row_number: number; errors: Record<string, string[]> }>>([])
const message = ref(''); const error = ref(''); const busy = ref(false)
const importDialogOpen = ref(false)
function definitionOptions(): ComboboxOption[] {
  return definitions.value.map((definition) => ({ value: definition.id, label: definition.name, description: `${definition.post.code} · maximum mark ${definition.maximum_mark}` }))
}
function definitionName(): string {
  return definitions.value.find((definition) => definition.id === form.assessment_definition_id)?.name || ''
}
function sessionOptions(): ComboboxOption[] {
  const postId = definitions.value.find((definition) => definition.id === form.assessment_definition_id)?.recruitment_post_id
  return centreSessions.value.filter((session) => !postId || session.post_id === postId).map((session) => ({ value: session.id, label: session.label, description: session.description }))
}
function sessionName(): string { return centreSessions.value.find((session) => session.id === form.centre_session_id)?.label || '' }
onMounted(load)
async function load() {
  try {
    const [definitionResponse, importResponse, lookupResponse] = await Promise.all([
      api<{ data: Definition[] }>('/assessment-definitions'),
      api<{ data: { data: ImportRecord[] } }>('/assessment-score-imports'),
      api<{ data: { centre_sessions: CentreSession[] } }>('/operations/lookups', { cacheTtlMs: 30_000 }),
    ])
    definitions.value = definitionResponse.data.filter((item) => item.component_type === 'written')
    imports.value = importResponse.data.data
    centreSessions.value = lookupResponse.data.centre_sessions
    if (!form.assessment_definition_id) form.assessment_definition_id = definitions.value[0]?.id || ''
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Assessment imports could not be loaded.' }
}
async function submit() {
  if (!file.value) return
  busy.value = true; error.value = ''; rowErrors.value = []
  const body = new FormData(); body.append('assessment_definition_id', form.assessment_definition_id); if (form.centre_session_id) body.append('centre_session_id', form.centre_session_id); body.append('purpose', form.purpose); body.append('file', file.value)
  try {
    const response = await api<{ import: ImportRecord }>('/assessment-score-imports', { method: 'POST', body })
    message.value = `${response.import.accepted_rows} written score row(s) imported atomically.`; file.value = null; importDialogOpen.value = false; await load()
  } catch (problem) {
    const issue = problem as ApiError; error.value = issue.message
    rowErrors.value = ((issue.payload as { validation_errors?: Array<{ row_number: number; errors: Record<string, string[]> }> } | null)?.validation_errors || [])
    await load()
  } finally { busy.value = false }
}
async function downloadTemplate(format: 'csv' | 'xlsx') {
  const response = await fetch(`/api/v1/assessment-score-imports/template?format=${format}`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Template download failed.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `written-score-import-template.${format}`; link.click(); URL.revokeObjectURL(link.href)
}
async function downloadErrors(record: ImportRecord) {
  const response = await fetch(`/api/v1/assessment-score-imports/${record.id}/error-report`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Validation report download failed.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = 'written-score-import-validation-errors.json'; link.click(); URL.revokeObjectURL(link.href)
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled assessment intake</p><h1>Written score imports</h1><p>Every source file is retained and hashed. Any invalid row rejects the whole file; locked scores require the correction workflow.</p></section>
  <FormAlert v-if="message" kind="success" :message="message" page /><FormAlert v-if="error" kind="error" :message="error" page />
  <section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">Import register</p><h2>{{ imports.length }} import(s)</h2></div><button type="button" class="button primary" @click="importDialogOpen = true">Validate and import scores</button></div><article v-for="record in imports" :key="record.id" class="run-card"><div><strong>{{ record.source_filename }}</strong><StatusBadge :status="record.status" /></div><p>{{ record.accepted_rows }} accepted · {{ record.rejected_rows }} rejected</p><button v-if="record.error_report_path" class="button secondary compact" @click="downloadErrors(record)">Validation report</button></article><div v-if="!imports.length" class="empty-state"><p>No score files have been imported.</p></div></section>
  <Dialog :open="importDialogOpen" title="Validate and import written scores" description="Every row is validated before an atomic import; validation failures remain visible here." :close-on-backdrop="false" mobile-sheet @close="importDialogOpen = false"><form @submit.prevent="submit"><FloatingCombobox label="Written assessment" :model-value="definitionName()" :options="definitionOptions()" required placeholder="Search or select assessment" @update:model-value="form.assessment_definition_id = ''; form.centre_session_id = ''" @select="form.assessment_definition_id = $event.value; form.centre_session_id = ''" /><FloatingCombobox label="Centre session (optional scope check)" :model-value="sessionName()" :options="sessionOptions()" placeholder="Search centre, date, or post" @update:model-value="form.centre_session_id = ''" @select="form.centre_session_id = $event.value" /><label>Purpose and authority<textarea v-model="form.purpose" minlength="10" required /></label><div class="button-row"><button type="button" class="button secondary compact" @click="downloadTemplate('csv')">CSV template</button><button type="button" class="button secondary compact" @click="downloadTemplate('xlsx')">XLSX template</button></div><label>Score file<input type="file" accept=".csv,.xlsx" required data-dialog-initial-focus @change="file = ($event.target as HTMLInputElement).files?.[0] || null" /></label><button class="button primary full" :disabled="busy || !file || !form.assessment_definition_id"><LoadingIndicator v-if="busy" small label="Validating every row…" /><span v-else>Import atomically</span></button><div v-if="rowErrors.length" class="table-wrap"><table class="evidence-table"><thead><tr><th>Row</th><th>Errors</th></tr></thead><tbody><tr v-for="item in rowErrors" :key="item.row_number"><td>{{ item.row_number }}</td><td>{{ Object.values(item.errors).flat().join(' ') }}</td></tr></tbody></table></div></form></Dialog>
</template>
