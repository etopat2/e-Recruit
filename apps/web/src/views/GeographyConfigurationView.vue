<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError, authToken, jsonBody } from '../lib/api'

type Level = 'region' | 'subregion' | 'district' | 'county' | 'subcounty' | 'parish' | 'village'
interface Unit { id: string; code: string; source: string; source_id: string | null; name: string; level: Level; unit_type: string | null; parent_id: string | null; active: boolean; parent?: { id: string; code: string; name: string; level: Level } }
interface Region { id: string; code: string; name: string; centres: Array<{ id: string; code: string; name: string }> }
interface Paginator { data: Unit[]; current_page: number; last_page: number; total: number }
interface ImportRecord { status: string; administrative_rows: number; electoral_rows_skipped: number; source_sha256: string; completed_at: string | null }
interface GeographyResponse { units: Paginator; unit_counts: Record<Level, number>; latest_import: ImportRecord | null; regions: Region[]; mappings: Array<Record<string, unknown>> }
interface ImportError { row: number; errors: Record<string, string[]> }

const levels: Level[] = ['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village']
const geography = ref<GeographyResponse | null>(null)
const upload = ref<File | null>(null)
const importType = ref('administrative-units')
const importErrors = ref<ImportError[]>([])
const message = ref(''); const error = ref(''); const busy = ref(false)
const region = reactive({ code: '', name: '' })
const filters = reactive({ level: '' as '' | Level, search: '', page: 1 })
const unit = reactive({ id: '', code: '', name: '', level: 'district' as Level, unit_type: 'district', parent_id: '', active: true, source: 'manual' })
const parentSearch = ref('')
const parentOptions = ref<Unit[]>([])

const parentLevels = computed<Level[]>(() => ({
  region: [], subregion: ['region'], district: ['subregion'], county: ['district'],
  subcounty: ['county', 'district'], parish: ['subcounty'], village: ['parish'],
}[unit.level] as Level[]))
const typeOptions = computed(() => ({
  region: ['region'], subregion: ['subregion'], district: ['district', 'city', 'capital-city'],
  county: ['county', 'municipality'], subcounty: ['subcounty', 'town-council', 'division'],
  parish: ['parish', 'ward'], village: ['village', 'cell'],
}[unit.level]))

onMounted(load)
watch(() => unit.level, () => {
  if (unit.id) return
  unit.parent_id = ''; unit.unit_type = typeOptions.value[0]; parentOptions.value = []; parentSearch.value = ''
})

async function load() {
  error.value = ''
  const query = new URLSearchParams({ page: String(filters.page) })
  if (filters.level) query.set('level', filters.level)
  if (filters.search.trim()) query.set('search', filters.search.trim())
  try { geography.value = await api<GeographyResponse>(`/admin/geography?${query}`) }
  catch (problem) { error.value = problem instanceof Error ? problem.message : 'Reference data could not be loaded.' }
}
async function applyFilters() { filters.page = 1; await load() }
async function changePage(page: number) { filters.page = page; await load() }
async function createRegion() {
  try {
    await api('/admin/geography/regions', { method: 'POST', ...jsonBody({ ...region, active: true }) })
    region.code = ''; region.name = ''; message.value = 'Prison region created with an audit event.'; await load()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Region could not be created.' }
}
async function findParents() {
  parentOptions.value = []
  if (!parentLevels.value.length) return
  try {
    const responses = await Promise.all(parentLevels.value.map((level) => {
      const query = new URLSearchParams({ level, summary: '0' })
      if (parentSearch.value.trim()) query.set('search', parentSearch.value.trim())
      return api<GeographyResponse>(`/admin/geography?${query}`)
    }))
    parentOptions.value = responses.flatMap((response) => response.units.data)
    if (unit.parent_id && !parentOptions.value.some((item) => item.id === unit.parent_id)) {
      const current = geography.value?.units.data.find((item) => item.id === unit.id)?.parent
      if (current) parentOptions.value.unshift({ ...current, source: 'manual', source_id: null, unit_type: current.level, parent_id: null, active: true })
    }
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Parent units could not be loaded.' }
}
async function saveUnit() {
  busy.value = true; error.value = ''; message.value = ''
  const body = { code: unit.code, name: unit.name, level: unit.level, unit_type: unit.unit_type, parent_id: unit.parent_id || null, active: unit.active }
  try {
    if (unit.id) await api(`/admin/geography/units/${unit.id}`, { method: 'PUT', ...jsonBody(body) })
    else await api('/admin/geography/units', { method: 'POST', ...jsonBody(body) })
    message.value = `Administrative unit ${unit.id ? 'updated' : 'created'} and its searchable path rebuilt.`
    resetUnit(); await load()
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Administrative unit could not be saved.' }
  finally { busy.value = false }
}
async function editUnit(item: Unit) {
  Object.assign(unit, { id: item.id, code: item.code, name: item.name, level: item.level, unit_type: item.unit_type || item.level, parent_id: item.parent_id || '', active: item.active, source: item.source })
  parentOptions.value = item.parent ? [{ ...item.parent, source: 'manual', source_id: null, unit_type: item.parent.level, parent_id: null, active: true }] : []
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
function resetUnit() { Object.assign(unit, { id: '', code: '', name: '', level: 'district', unit_type: 'district', parent_id: '', active: true, source: 'manual' }); parentOptions.value = []; parentSearch.value = '' }
async function deleteUnit(item: Unit) {
  if (!window.confirm(`Delete ${item.name}? Units with children or application references cannot be deleted.`)) return
  try { await api(`/admin/geography/units/${item.id}`, { method: 'DELETE' }); message.value = `${item.name} deleted with an audit event.`; if (unit.id === item.id) resetUnit(); await load() }
  catch (problem) { error.value = problem instanceof Error ? problem.message : 'Administrative unit could not be deleted.' }
}
async function importReferenceData() {
  if (!upload.value) return
  busy.value = true; error.value = ''; importErrors.value = []
  const body = new FormData(); body.append('file', upload.value)
  try {
    const result = await api<{ imported: number }>(`/admin/geography/imports/${importType.value}`, { method: 'POST', body })
    message.value = `${result.imported} reference row(s) imported transactionally.`; upload.value = null; await load()
  } catch (problem) {
    const response = problem as ApiError; error.value = response.message
    const raw = (response.payload as { errors?: ImportError[] } | null)?.errors
    if (Array.isArray(raw)) importErrors.value = raw
  } finally { busy.value = false }
}
async function downloadTemplate(format: 'csv' | 'xlsx') {
  const response = await fetch(`/api/v1/admin/geography/templates/${importType.value}?format=${format}`, { headers: { Authorization: `Bearer ${authToken()}` } })
  if (!response.ok) { error.value = 'Template download failed.'; return }
  const link = document.createElement('a'); link.href = URL.createObjectURL(await response.blob()); link.download = `${importType.value}-template.${format}`; link.click(); URL.revokeObjectURL(link.href)
}
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Controlled reference data</p><h1>Geography and jurisdiction</h1><p>Maintain Uganda’s administrative hierarchy separately from electoral constituencies, with effective-dated recruitment-centre mappings.</p></section>
  <div v-if="message" class="alert success page-alert" role="status">{{ message }}</div><div v-if="error" class="alert error page-alert" role="alert">{{ error }}</div>
  <section class="configuration-layout">
    <form class="form-panel" @submit.prevent="saveUnit"><p class="eyebrow">Administrative CRUD</p><h2>{{ unit.id ? 'Edit unit' : 'Add unit' }}</h2><label>Level<select v-model="unit.level" required><option v-for="level in levels" :key="level" :value="level">{{ level }}</option></select></label><label>Detailed type<select v-model="unit.unit_type" required><option v-for="type in typeOptions" :key="type" :value="type">{{ type.replaceAll('-', ' ') }}</option></select></label><label>Canonical code<input v-model="unit.code" maxlength="120" required /></label><label>Name<input v-model="unit.name" required /></label><template v-if="parentLevels.length"><label>Find parent<input v-model="parentSearch" placeholder="Parent name or code" /></label><button type="button" class="button secondary compact" @click="findParents">Find {{ parentLevels.join(' / ') }}</button><label>Parent<select v-model="unit.parent_id" :required="unit.level !== 'district'"><option value="">{{ unit.level === 'district' ? 'Legacy root district (optional)' : 'Select parent' }}</option><option v-for="parent in parentOptions" :key="parent.id" :value="parent.id">{{ parent.name }} · {{ parent.level }}</option></select></label></template><label class="checkbox"><input v-model="unit.active" type="checkbox" /><span>Active and selectable</span></label><small v-if="unit.id && unit.source !== 'manual'">Managed from the authoritative Uganda dataset; a later full import may restore source values.</small><div class="button-row"><button class="button primary" :disabled="busy">{{ unit.id ? 'Save changes' : 'Create unit' }}</button><button v-if="unit.id" type="button" class="button secondary" @click="resetUnit">Cancel</button></div></form>
    <div class="form-panel"><h2>Transactional import</h2><label>Reference dataset<select v-model="importType"><option value="administrative-units">Administrative units</option><option value="district-centre-mappings">District-to-centre mappings</option></select></label><div class="button-row"><button class="button secondary compact" @click="downloadTemplate('csv')">CSV template</button><button class="button secondary compact" @click="downloadTemplate('xlsx')">XLSX template</button></div><label>Validated CSV or XLSX<input type="file" accept=".csv,.xlsx" @change="upload = ($event.target as HTMLInputElement).files?.[0] || null" /></label><button class="button primary full" :disabled="!upload || busy" @click="importReferenceData">{{ busy ? 'Validating…' : 'Validate and import' }}</button><p v-if="geography?.latest_import"><strong>Canonical import:</strong> {{ geography.latest_import.administrative_rows.toLocaleString() }} administrative rows; {{ geography.latest_import.electoral_rows_skipped.toLocaleString() }} electoral rows excluded.</p><table v-if="importErrors.length" class="evidence-table"><thead><tr><th>Row</th><th>Validation report</th></tr></thead><tbody><tr v-for="item in importErrors" :key="item.row"><td>{{ item.row }}</td><td>{{ Object.values(item.errors).flat().join(' ') }}</td></tr></tbody></table></div>
    <form class="form-panel" @submit.prevent="createRegion"><h2>Add prison region</h2><label>Code<input v-model="region.code" required /></label><label>Name<input v-model="region.name" required /></label><button class="button primary full">Create prison region</button><h3>Configured regions</h3><ul class="document-list"><li v-for="item in geography?.regions" :key="item.id"><span><strong>{{ item.name }}</strong><small>{{ item.code }} · {{ item.centres.length }} centre(s)</small></span></li></ul></form>
  </section>
  <section class="content-section"><div class="section-heading"><div><p class="eyebrow">Region → subregion → district/city → county → sub-county → parish → village</p><h2>Administrative hierarchy</h2></div><span>{{ geography?.units.total.toLocaleString() || 0 }} units</span></div><form class="account-filters" @submit.prevent="applyFilters"><label>Search<input v-model="filters.search" placeholder="Name or canonical code" /></label><label>Level<select v-model="filters.level"><option value="">All levels</option><option v-for="level in levels" :key="level" :value="level">{{ level }} ({{ geography?.unit_counts[level]?.toLocaleString() || 0 }})</option></select></label><button class="button secondary compact">Apply</button></form><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Parent</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="item in geography?.units.data" :key="item.id"><td><code>{{ item.code }}</code><small v-if="item.source_id">{{ item.source_id }}</small></td><td>{{ item.name }}</td><td>{{ (item.unit_type || item.level).replaceAll('-', ' ') }}</td><td>{{ item.parent?.name || '—' }}</td><td><span class="status-badge" :data-status="item.active ? 'active' : 'disabled'">{{ item.active ? 'active' : 'inactive' }}</span></td><td><div class="button-row"><button type="button" class="button secondary compact" @click="editUnit(item)">Edit</button><button type="button" class="text-button danger" @click="deleteUnit(item)">Delete</button></div></td></tr><tr v-if="!geography?.units.data.length"><td colspan="6" class="empty-state compact">No units match these filters.</td></tr></tbody></table></div><div v-if="geography && geography.units.last_page > 1" class="pagination"><button class="button secondary compact" :disabled="geography.units.current_page === 1" @click="changePage(geography.units.current_page - 1)">Previous</button><span>Page {{ geography.units.current_page }} of {{ geography.units.last_page }}</span><button class="button secondary compact" :disabled="geography.units.current_page === geography.units.last_page" @click="changePage(geography.units.current_page + 1)">Next</button></div></section>
</template>
