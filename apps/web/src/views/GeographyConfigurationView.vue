<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError, authToken, jsonBody } from '../lib/api'

interface Unit { id: string; code: string; name: string; level: string; parent?: { name: string } }
interface Region { id: string; code: string; name: string; centres: Array<{ id: string; code: string; name: string }> }
interface GeographyResponse { units: { data: Unit[] }; regions: Region[]; mappings: Array<Record<string, unknown>> }
interface ImportError { row: number; errors: Record<string, string[]> }

const geography = ref<GeographyResponse | null>(null)
const upload = ref<File | null>(null)
const importType = ref('administrative-units')
const importErrors = ref<ImportError[]>([])
const message = ref(''); const error = ref(''); const busy = ref(false)
const region = reactive({ code: '', name: '' })

onMounted(load)
async function load() {
  try { geography.value = await api<GeographyResponse>('/admin/geography') }
  catch (problem) { error.value = problem instanceof Error ? problem.message : 'Reference data could not be loaded.' }
}
async function createRegion() {
  try {
    await api('/admin/geography/regions', { method: 'POST', ...jsonBody({ ...region, active: true }) })
    region.code = ''; region.name = ''; message.value = 'Prison region created with an audit event.'; await load()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Region could not be created.' }
}
async function importReferenceData() {
  if (!upload.value) return
  busy.value = true; error.value = ''; importErrors.value = []
  const body = new FormData(); body.append('file', upload.value)
  try {
    const result = await api<{ imported: number }>(`/admin/geography/imports/${importType.value}`, { method: 'POST', body })
    message.value = `${result.imported} reference row(s) imported transactionally.`; upload.value = null; await load()
  } catch (problem) {
    const response = problem as ApiError
    error.value = response.message
    const raw = (response.payload as { errors?: ImportError[] } | null)?.errors
    if (Array.isArray(raw)) importErrors.value = raw as ImportError[]
  } finally { busy.value = false }
}
async function downloadTemplate(format: 'csv' | 'xlsx') {
  const response = await fetch(`/api/v1/admin/geography/templates/${importType.value}?format=${format}`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Template download failed.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `${importType.value}-template.${format}`; link.click(); URL.revokeObjectURL(link.href)
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled reference data</p><h1>Geography and jurisdiction</h1><p>Maintain the official hierarchy and effective-dated centre mappings. Unmapped districts remain in a resolution queue; this screen never guesses a centre.</p></section>
  <div v-if="message" class="alert success page-alert">{{ message }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="configuration-layout">
    <div class="form-panel"><h2>Transactional import</h2><label>Reference dataset<select v-model="importType"><option value="administrative-units">Administrative units</option><option value="district-centre-mappings">District-to-centre mappings</option></select></label><div class="button-row"><button class="button secondary compact" @click="downloadTemplate('csv')">CSV template</button><button class="button secondary compact" @click="downloadTemplate('xlsx')">XLSX template</button></div><label>Validated CSV or XLSX<input type="file" accept=".csv,.xlsx" @change="upload = ($event.target as HTMLInputElement).files?.[0] || null" /></label><button class="button primary full" :disabled="!upload || busy" @click="importReferenceData">{{ busy ? 'Validating…' : 'Validate and import' }}</button><table v-if="importErrors.length" class="evidence-table"><thead><tr><th>Row</th><th>Validation report</th></tr></thead><tbody><tr v-for="item in importErrors" :key="item.row"><td>{{ item.row }}</td><td>{{ Object.values(item.errors).flat().join(' ') }}</td></tr></tbody></table></div>
    <form class="form-panel" @submit.prevent="createRegion"><h2>Add prison region</h2><label>Code<input v-model="region.code" required /></label><label>Name<input v-model="region.name" required /></label><button class="button primary full">Create region</button><h3>Configured regions</h3><ul class="document-list"><li v-for="item in geography?.regions" :key="item.id"><span><strong>{{ item.name }}</strong><small>{{ item.code }} · {{ item.centres.length }} centre(s)</small></span></li></ul></form>
  </section>
  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">District → county → subcounty → parish → village</p><h2>Administrative hierarchy</h2></div><span>{{ geography?.units.data.length || 0 }} loaded</span></div><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Level</th><th>Parent</th></tr></thead><tbody><tr v-for="unit in geography?.units.data" :key="unit.id"><td><code>{{ unit.code }}</code></td><td>{{ unit.name }}</td><td>{{ unit.level }}</td><td>{{ unit.parent?.name || '—' }}</td></tr></tbody></table></div></section>
</template>
