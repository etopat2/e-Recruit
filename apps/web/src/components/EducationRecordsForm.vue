<script setup lang="ts">
import { useId } from 'vue'
import InstitutionDirectoryField from './InstitutionDirectoryField.vue'
import {
  educationLevelFor,
  educationLevelGroups,
  isKnownEducationLevel,
  isKnownEducationResult,
  resultOptionsForEducationLevel,
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

function changeLevel(record: EducationRecordDraft, event: Event): void {
  record.level = (event.target as HTMLSelectElement).value
  record.result = ''
  record.institution = ''
  record.institution_id = null
  record.institution_not_listed = false
  record.institution_source = undefined
  record.institution_registration_status = undefined
}

function guidanceFor(level: string): string {
  return educationLevelFor(level)?.guidance ?? 'Choose the closest Ugandan equivalent and match the wording on the official award.'
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

  <article v-for="(record, index) in records" :key="index" class="repeat-card">
    <div class="education-record-heading">
      <strong>Qualification {{ index + 1 }}</strong>
      <button type="button" class="text-button danger" :aria-label="`Remove qualification ${index + 1}`" @click="removeRecord(index)">Remove</button>
    </div>
    <div class="field-grid">
      <label :for="`${formId}-level-${index}`">Level
        <select :id="`${formId}-level-${index}`" :value="record.level" required @change="changeLevel(record, $event)">
          <option value="">Select education level</option>
          <option v-if="record.level && !isKnownEducationLevel(record.level)" :value="record.level">{{ record.level }} (previously saved)</option>
          <optgroup v-for="group in educationLevelGroups" :key="group.label" :label="group.label">
            <option v-for="option in group.options" :key="option.value" :value="option.value">{{ option.label }}</option>
          </optgroup>
        </select>
      </label>
      <InstitutionDirectoryField v-model="records[index]" />
      <label :for="`${formId}-year-${index}`">Completion year
        <input :id="`${formId}-year-${index}`" v-model="record.completion_year" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="YYYY" />
      </label>
      <label :for="`${formId}-result-${index}`">Result / class
        <select :id="`${formId}-result-${index}`" v-model="record.result" required :disabled="!record.level">
          <option value="">{{ record.level ? 'Select result or class' : 'Select a level first' }}</option>
          <option v-if="record.result && !isKnownEducationResult(record.level, record.result)" :value="record.result">{{ record.result }} (previously saved)</option>
          <option v-for="option in resultOptionsForEducationLevel(record.level)" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <small class="field-help">{{ guidanceFor(record.level) }}</small>
      </label>
    </div>
  </article>

  <div v-if="!records.length" class="empty-state compact">
    <p>Add each completed qualification, starting with your most recent.</p>
  </div>
</template>
