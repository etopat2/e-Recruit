<script setup lang="ts">
import { onMounted, ref, useId } from 'vue'
import InstitutionDirectoryField from './InstitutionDirectoryField.vue'
import FloatingCombobox, { type ComboboxOption } from './FloatingCombobox.vue'
import FormAlert from './FormAlert.vue'
import {
  educationLevelFor,
  isKnownEducationLevel,
  isKnownEducationResult,
  loadEducationLevelGroups,
  resultOptionsForEducationLevel,
  type EducationLevelGroup,
} from '../lib/educationQualifications'

export interface EducationRecordDraft {
  level: string
  institution: string
  institution_id?: string | null
  institution_not_listed?: boolean
  institution_source?: string
  institution_registration_status?: string
  completion_year: string
  result: string
}

const records = defineModel<EducationRecordDraft[]>({ required: true })
const formId = `education-records-${useId()}`
const educationLevelGroups = ref<readonly EducationLevelGroup[]>([])
const catalogueError = ref('')

onMounted(async () => {
  try {
    educationLevelGroups.value = await loadEducationLevelGroups()
  } catch (problem) {
    catalogueError.value = problem instanceof Error ? problem.message : 'The qualification catalogue could not be loaded.'
  }
})

function addRecord(): void {
  records.value.push({
    level: '',
    institution: '',
    institution_id: null,
    institution_not_listed: false,
    completion_year: '',
    result: '',
  })
}

function removeRecord(index: number): void {
  records.value.splice(index, 1)
}

function changeLevel(record: EducationRecordDraft, option: ComboboxOption): void {
  record.level = option.value
  record.result = ''
  record.institution = ''
  record.institution_id = null
  record.institution_not_listed = false
  record.institution_source = undefined
  record.institution_registration_status = undefined
}

function levelOptions(record: EducationRecordDraft): ComboboxOption[] {
  const options = educationLevelGroups.value.flatMap((group) => group.options.map((option) => ({
    value: option.value,
    label: option.value,
    description: `${option.label} · ${group.label}`,
  })))
  if (record.level && !isKnownEducationLevel(educationLevelGroups.value, record.level)) {
    options.unshift({ value: record.level, label: record.level, description: 'Previously saved qualification level' })
  }
  return options
}

function resultOptions(record: EducationRecordDraft): ComboboxOption[] {
  const options = resultOptionsForEducationLevel(educationLevelGroups.value, record.level).map((option) => ({
    value: option.value,
    label: option.value,
    description: option.label === option.value ? undefined : option.label,
  }))
  if (record.result && !isKnownEducationResult(educationLevelGroups.value, record.level, record.result)) {
    options.unshift({ value: record.result, label: record.result, description: 'Previously saved result' })
  }
  return options
}

function clearChangedLevel(record: EducationRecordDraft, query: string): void {
  if (record.level && query !== record.level) changeLevel(record, { value: '', label: '' })
}

function clearChangedResult(record: EducationRecordDraft, query: string): void {
  if (record.result && query !== record.result) record.result = ''
}

function guidanceFor(level: string): string {
  return educationLevelFor(educationLevelGroups.value, level)?.guidance ?? 'Choose the closest Ugandan equivalent and match the wording on the official award.'
}

function directorySearchable(level: string): boolean | undefined {
  return educationLevelFor(educationLevelGroups.value, level)?.directory_searchable
}
</script>

<template>
  <p class="eyebrow">Qualifications</p>
  <div class="section-heading">
    <div>
      <h2>Education records</h2>
      <p class="form-intro">Add completed school, university, tertiary, technical, vocational, and institute qualifications.</p>
    </div>
    <button type="button" class="button secondary compact" @click="addRecord">Add qualification</button>
  </div>
  <FormAlert v-if="catalogueError" kind="error" :message="catalogueError" />

  <article v-for="(record, index) in records" :key="index" class="repeat-card">
    <div class="education-record-heading">
      <strong>Qualification {{ index + 1 }}</strong>
      <button type="button" class="text-button danger" :aria-label="`Remove qualification ${index + 1}`" @click="removeRecord(index)">Remove</button>
    </div>
    <div class="field-grid">
      <FloatingCombobox :id="`${formId}-level-${index}`" label="Level" :model-value="record.level" :options="levelOptions(record)" required placeholder="Search or select education level" @update:model-value="clearChangedLevel(record, $event)" @select="changeLevel(record, $event)" />
      <InstitutionDirectoryField v-model="records[index]" :directory-searchable="directorySearchable(record.level)" />
      <label :for="`${formId}-year-${index}`">Completion year
        <input :id="`${formId}-year-${index}`" v-model="record.completion_year" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="YYYY" />
      </label>
      <FloatingCombobox :id="`${formId}-result-${index}`" label="Result / class" :model-value="record.result" :options="resultOptions(record)" required :disabled="!record.level" :placeholder="record.level ? 'Search or select result / class' : 'Select a level first'" :hint="guidanceFor(record.level)" @update:model-value="clearChangedResult(record, $event)" @select="record.result = $event.value" />
    </div>
  </article>

  <div v-if="!records.length" class="empty-state compact">
    <p>Add each completed qualification, starting with your most recent.</p>
  </div>
</template>
