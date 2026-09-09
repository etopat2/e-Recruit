<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { api } from '../lib/api'
import type { EducationRecordDraft } from './EducationRecordsForm.vue'

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
const fieldId = `institution-directory-${useId()}`
const query = ref(record.value.institution || '')
const matches = ref<EducationInstitutionMatch[]>([])
const loading = ref(false)
const searched = ref(false)
const searchFailed = ref(false)
const expanded = ref(false)
const activeIndex = ref(-1)
let searchTimer = 0
let searchSequence = 0

const manualEntry = computed(() => Boolean(record.value.institution_not_listed))
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

onBeforeUnmount(() => window.clearTimeout(searchTimer))

watch(() => record.value.level, () => {
  window.clearTimeout(searchTimer)
  query.value = ''
  matches.value = []
  searched.value = false
  searchFailed.value = false
  expanded.value = false
})

function updateQuery(event: Event): void {
  query.value = (event.target as HTMLInputElement).value
  if (record.value.institution_id && query.value !== record.value.institution) {
    record.value = {
      ...record.value,
      institution: '',
      institution_id: null,
      institution_source: undefined,
      institution_registration_status: undefined,
    }
  }
  scheduleSearch()
}

function scheduleSearch(): void {
  window.clearTimeout(searchTimer)
  searched.value = false
  searchFailed.value = false
  if (!record.value.level || manualEntry.value || query.value.trim().length < 2) {
    matches.value = []
    expanded.value = false
    loading.value = false
    return
  }

  const sequence = ++searchSequence
  loading.value = true
  searchTimer = window.setTimeout(() => void searchInstitutions(sequence), 300)
}

async function searchInstitutions(sequence: number): Promise<void> {
  const search = query.value.trim()
  try {
    const response = await api<{ data: EducationInstitutionMatch[] }>(
      `/education-institutions?level=${encodeURIComponent(record.value.level)}&search=${encodeURIComponent(search)}&limit=7`,
      { cacheTtlMs: 60_000 },
    )
    if (sequence !== searchSequence || search !== query.value.trim()) return
    matches.value = response.data.slice(0, 7)
    searched.value = true
    searchFailed.value = false
    expanded.value = true
    activeIndex.value = response.data.length ? 0 : -1
  } catch {
    if (sequence !== searchSequence) return
    matches.value = []
    searched.value = true
    searchFailed.value = true
    expanded.value = true
  } finally {
    if (sequence === searchSequence) loading.value = false
  }
}

function selectInstitution(institution: EducationInstitutionMatch): void {
  record.value = {
    ...record.value,
    institution: institution.name,
    institution_id: institution.id,
    institution_not_listed: false,
    institution_source: institution.source,
    institution_registration_status: institution.registration_status || undefined,
  }
  query.value = institution.name
  matches.value = []
  expanded.value = false
  searched.value = false
  searchFailed.value = false
}

function toggleManualEntry(event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  searchSequence++
  window.clearTimeout(searchTimer)
  query.value = ''
  matches.value = []
  expanded.value = false
  searched.value = false
  searchFailed.value = false
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

function moveActive(direction: 1 | -1): void {
  if (!matches.value.length) return
  expanded.value = true
  activeIndex.value = (activeIndex.value + direction + matches.value.length) % matches.value.length
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    moveActive(1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    moveActive(-1)
  } else if (event.key === 'Enter' && expanded.value && activeIndex.value >= 0) {
    event.preventDefault()
    selectInstitution(matches.value[activeIndex.value])
  } else if (event.key === 'Escape') {
    expanded.value = false
  }
}

function closeResultsSoon(): void {
  window.setTimeout(() => {
    expanded.value = false
  }, 150)
}

function sourceLabel(source?: string): string {
  return source === 'nche' ? 'NCHE' : source === 'moes_tvet' ? 'MoES TVET' : source === 'moes_emis' ? 'MoES EMIS' : 'Official directory'
}
</script>

<template>
  <div class="institution-directory-field">
    <template v-if="!manualEntry">
      <label :for="`${fieldId}-search`">Institution
        <input
          :id="`${fieldId}-search`"
          :value="query"
          role="combobox"
          autocomplete="off"
          aria-autocomplete="list"
          :disabled="!record.level"
          :placeholder="record.level ? 'Type at least 2 characters' : 'Select a level first'"
          :aria-expanded="expanded"
          :aria-controls="`${fieldId}-results`"
          :aria-activedescendant="activeIndex >= 0 ? `${fieldId}-option-${activeIndex}` : undefined"
          required
          @input="updateQuery"
          @focus="expanded = matches.length > 0 || searched"
          @blur="closeResultsSoon"
          @keydown="handleKeydown"
        />
      </label>
      <div v-if="expanded" :id="`${fieldId}-results`" class="institution-search-results" role="listbox" aria-label="Official institution matches">
        <button
          v-for="(institution, matchIndex) in matches"
          :id="`${fieldId}-option-${matchIndex}`"
          :key="institution.id"
          type="button"
          role="option"
          :aria-selected="matchIndex === activeIndex"
          @mouseenter="activeIndex = matchIndex"
          @mousedown.prevent="selectInstitution(institution)"
        >
          <strong>{{ institution.name }}</strong>
          <span>{{ [institution.institution_type, institution.district].filter(Boolean).join(' · ') }}</span>
          <small>{{ sourceLabel(institution.source) }} · {{ institution.registration_status || institution.operational_status || 'Official record' }}</small>
        </button>
        <p v-if="loading" class="institution-search-message">Searching official directories…</p>
        <p v-else-if="searchFailed" class="institution-search-message" role="status">The official directory is temporarily unavailable. Try again or use the option below.</p>
        <p v-else-if="searched && !matches.length" class="institution-search-message" role="status">No official match found. Try another spelling or use the option below.</p>
      </div>
      <div v-if="selectedInstitution" class="selected-institution">
        <strong>{{ selectedInstitution.name }}</strong>
        <span>{{ sourceLabel(selectedInstitution.source) }} match</span>
        <small>{{ selectedInstitution.registrationStatus || 'Official directory record' }}</small>
      </div>
    </template>

    <label class="checkbox institution-not-listed">
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
