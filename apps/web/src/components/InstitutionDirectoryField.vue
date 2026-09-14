<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue'
import { api } from '../lib/api'
import type { EducationRecordDraft } from './EducationRecordsForm.vue'
import FloatingCombobox, { type ComboboxOption } from './FloatingCombobox.vue'

export interface EducationInstitutionMatch {
  id: string
  name: string
  institution_type: string | null
  district: string | null
  registration_number: string | null
  registration_status: string | null
  operational_status: string | null
  source: string
  source_url: string
  last_verified_at: string
}

const record = defineModel<EducationRecordDraft>({ required: true })
const props = defineProps<{ directorySearchable?: boolean }>()
const fieldId = `institution-directory-${useId()}`
const query = ref(record.value.institution || '')

const manualEntry = computed(() => props.directorySearchable === false || Boolean(record.value.institution_not_listed))
const selectedInstitution = computed(() => record.value.institution_id ? {
  name: record.value.institution,
  source: record.value.institution_source,
  registrationStatus: record.value.institution_registration_status,
} : null)

onMounted(() => {
  if (record.value.institution && !record.value.institution_id && record.value.institution_not_listed === undefined) {
    record.value = { ...record.value, institution_not_listed: true }
  }
})

watch([() => record.value.level, () => props.directorySearchable], () => {
  query.value = ''
  if (record.value.level && props.directorySearchable === false && !record.value.institution_not_listed) {
    record.value = {
      ...record.value,
      institution: '',
      institution_id: null,
      institution_not_listed: true,
      institution_source: undefined,
      institution_registration_status: undefined,
    }
  }
}, { immediate: true })

function updateQuery(value: string): void {
  query.value = value
  if (record.value.institution_id && query.value !== record.value.institution) {
    record.value = {
      ...record.value,
      institution: '',
      institution_id: null,
      institution_source: undefined,
      institution_registration_status: undefined,
    }
  }
}

async function loadInstitutions(search: string, signal: AbortSignal): Promise<ComboboxOption[]> {
  if (props.directorySearchable === false) return []
  const response = await api<{ data: EducationInstitutionMatch[] }>(
    `/education-institutions?level=${encodeURIComponent(record.value.level)}&search=${encodeURIComponent(search)}&limit=7`,
    { cacheTtlMs: 60_000, signal },
  )
  return response.data.slice(0, 7).map((institution) => ({
    value: institution.id,
    label: institution.name,
    description: [institution.institution_type, institution.district, `${sourceLabel(institution.source)} · ${institution.registration_status || institution.operational_status || 'Official record'}`].filter(Boolean).join(' · '),
    data: institution,
  }))
}

function selectInstitution(option: ComboboxOption): void {
  const institution = option.data as EducationInstitutionMatch
  record.value = {
    ...record.value,
    institution: institution.name,
    institution_id: institution.id,
    institution_not_listed: false,
    institution_source: institution.source,
    institution_registration_status: institution.registration_status || undefined,
  }
  query.value = institution.name
}

function toggleManualEntry(event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  query.value = ''
  record.value = {
    ...record.value,
    institution: '',
    institution_id: null,
    institution_not_listed: checked,
    institution_source: undefined,
    institution_registration_status: undefined,
  }
}

function updateManualName(event: Event): void {
  record.value = { ...record.value, institution: (event.target as HTMLInputElement).value }
}

function sourceLabel(source?: string): string {
  return source === 'nche' ? 'NCHE' : source === 'moes_tvet' ? 'MoES TVET' : source === 'moes_emis' ? 'MoES EMIS' : 'Official directory'
}
</script>

<template>
  <div class="institution-directory-field">
    <template v-if="!manualEntry && props.directorySearchable !== false">
      <FloatingCombobox
        :id="`${fieldId}-search`"
        label="Institution"
        :model-value="query"
        :load-options="loadInstitutions"
        :disabled="!record.level"
        :placeholder="record.level ? 'Type at least 2 characters' : 'Select a level first'"
        :min-chars="2"
        :debounce-ms="150"
        :max-visible="7"
        required
        loading-text="Searching official directories…"
        no-results-text="No official match found. Try another spelling or use the option below."
        @update:model-value="updateQuery"
        @select="selectInstitution"
      />
      <div v-if="selectedInstitution" class="selected-institution">
        <strong>{{ selectedInstitution.name }}</strong>
        <span>{{ sourceLabel(selectedInstitution.source) }} match</span>
        <small>{{ selectedInstitution.registrationStatus || 'Official directory record' }}</small>
      </div>
    </template>

    <p v-if="record.level && props.directorySearchable === false" class="field-help">An official searchable directory is not available for this qualification level. Enter the institution shown on your document.</p>

    <label v-if="props.directorySearchable !== false" class="checkbox institution-not-listed">
      <input :checked="manualEntry" type="checkbox" @change="toggleManualEntry" />
      <span>My institution is not listed</span>
    </label>

    <label v-if="manualEntry" :for="`${fieldId}-manual`">Institution name (as shown on the certificate)
      <input
        :id="`${fieldId}-manual`"
        :value="record.institution"
        required
        autocomplete="organization"
        @input="updateManualName"
      />
      <small class="field-help">Enter the official or historical name exactly as it appears on your document.</small>
    </label>
  </div>
</template>
