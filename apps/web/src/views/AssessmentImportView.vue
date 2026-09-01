<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError, authToken } from '../lib/api'
import StatusBadge from '../components/StatusBadge.vue'

interface Definition { id: string; code: string; name: string; component_type: string; maximum_mark: string; post: { code: string; name: string } }
interface ImportRecord { id: string; source_filename: string; status: string; total_rows: number; accepted_rows: number; rejected_rows: number; error_report_path: string | null }

const definitions = ref<Definition[]>([]); const imports = ref<ImportRecord[]>([])
const form = reactive({ assessment_definition_id: '', centre_session_id: '', purpose: '' })
const file = ref<File | null>(null); const rowErrors = ref<Array<{ row_number: number; errors: Record<string, string[]> }>>([])
const message = ref(''); const error = ref(''); const busy = ref(false)
onMounted(load)
async function load() {
  try {
    definitions.value = (await api<{ data: Definition[] }>('/assessment-definitions')).data.filter((item) => item.component_type === 'written')
    imports.value = (await api<{ data: { data: ImportRecord[] } }>('/assessment-score-imports')).data.data
    if (!form.assessment_definition_id) form.assessment_definition_id = definitions.value[0]?.id || ''
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Assessment imports could not be loaded.' }
}
async function submit() {
  if (!file.value) return
  busy.value = true; error.value = ''; rowErrors.value = []
  const body = new FormData(); body.append('assessment_definition_id', form.assessment_definition_id); if (form.centre_session_id) body.append('centre_session_id', form.centre_session_id); body.append('purpose', form.purpose); body.append('file', file.value)
  try {
    const response = await api<{ import: ImportRecord }>('/assessment-score-imports', { method: 'POST', body })
    message.value = `${response.import.accepted_rows} written score row(s) imported atomically.`; file.value = null; await load()
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
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `written-score-import-${record.id}-errors.json`; link.click(); URL.revokeObjectURL(link.href)
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled assessment intake</p><h1>Written score imports</h1><p>Every source file is retained and hashed. Any invalid row rejects the whole file; locked scores require the correction workflow.</p></section>
  <div v-if="message" class="alert success page-alert">{{ message }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="configuration-layout"><form class="form-panel" @submit.prevent="submit"><h2>Validate and import</h2><label>Written assessment<select v-model="form.assessment_definition_id" required><option v-for="definition in definitions" :key="definition.id" :value="definition.id">{{ definition.post.code }} · {{ definition.name }} / {{ definition.maximum_mark }}</option></select></label><label>Centre session ID (optional scope check)<input v-model="form.centre_session_id" /></label><label>Purpose and authority<textarea v-model="form.purpose" minlength="10" required /></label><div class="button-row"><button type="button" class="button secondary compact" @click="downloadTemplate('csv')">CSV template</button><button type="button" class="button secondary compact" @click="downloadTemplate('xlsx')">XLSX template</button></div><label>Score file<input type="file" accept=".csv,.xlsx" required @change="file = ($event.target as HTMLInputElement).files?.[0] || null" /></label><button class="button primary full" :disabled="busy || !file || !form.assessment_definition_id">{{ busy ? 'Validating every row…' : 'Import atomically' }}</button><table v-if="rowErrors.length" class="evidence-table"><thead><tr><th>Row</th><th>Errors</th></tr></thead><tbody><tr v-for="item in rowErrors" :key="item.row_number"><td>{{ item.row_number }}</td><td>{{ Object.values(item.errors).flat().join(' ') }}</td></tr></tbody></table></form><div><h2>Import register</h2><article v-for="record in imports" :key="record.id" class="run-card"><div><strong>{{ record.source_filename }}</strong><StatusBadge :status="record.status" /></div><p>{{ record.accepted_rows }} accepted · {{ record.rejected_rows }} rejected</p><button v-if="record.error_report_path" class="button secondary compact" @click="downloadErrors(record)">Validation report</button></article></div></section>
</template>
