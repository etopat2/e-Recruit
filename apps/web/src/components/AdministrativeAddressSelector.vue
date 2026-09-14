<script setup lang="ts">
import { onMounted, ref, useId } from 'vue'
import { api } from '../lib/api'
import FloatingCombobox, { type ComboboxOption } from './FloatingCombobox.vue'

type Level = 'region' | 'subregion' | 'district' | 'county' | 'subcounty' | 'parish' | 'village'
interface LineageUnit { id: string; code: string; name: string; unit_type: string | null }
interface AdministrativeUnit {
  id: string
  code: string
  name: string
  level: Level
  unit_type: string | null
  parent_id: string | null
  full_address: string
  lineage: Record<Level, LineageUnit | null>
}
type AddressValue = Record<string, string | number | null | undefined>

const props = defineProps<{ modelValue: AddressValue }>()
const emit = defineEmits<{ 'update:modelValue': [value: AddressValue] }>()
const villageControlId = `administrative-village-${useId()}`

const districts = ref<AdministrativeUnit[]>([])
const counties = ref<AdministrativeUnit[]>([])
const subcounties = ref<AdministrativeUnit[]>([])
const parishes = ref<AdministrativeUnit[]>([])
const villages = ref<AdministrativeUnit[]>([])
const villageQuery = ref('')
const loading = ref<Record<string, boolean>>({})
const error = ref('')

onMounted(async () => {
  const districtId = value('district_id')
  const subcountyId = value('subcounty_id')
  const parishId = value('parish_id')
  villageQuery.value = value('village')
  await Promise.all([
    loadUnits('district', {}, districts),
    ...(districtId ? [
      loadUnits('county', { district_id: districtId }, counties),
      loadUnits('subcounty', { district_id: districtId }, subcounties),
    ] : []),
    ...(subcountyId ? [loadUnits('parish', { subcounty_id: subcountyId }, parishes)] : []),
    ...(parishId ? [loadUnits('village', { parish_id: parishId }, villages)] : []),
  ])
})

function value(key: string): string {
  return String(props.modelValue[key] || '')
}

async function loadUnits(level: Level, filters: Record<string, string>, target: typeof districts) {
  loading.value[level] = true
  error.value = ''
  const query = new URLSearchParams({ level, limit: '250', ...filters })
  try {
    const response = await api<{ data: AdministrativeUnit[] }>(`/geography/units?${query}`, { cacheTtlMs: 300_000 })
    target.value = response.data
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Administrative units could not be loaded.'
    target.value = []
  } finally {
    loading.value[level] = false
  }
}

function mergedSelection(unit: AdministrativeUnit): AddressValue {
  const next: AddressValue = { ...props.modelValue, full_address: unit.full_address }
  for (const level of ['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village'] as Level[]) {
    next[`${level}_id`] = unit.lineage[level]?.id ?? null
    next[level] = unit.lineage[level]?.name ?? null
  }
  return next
}

function clearBelow(level: Level): AddressValue {
  const ordered: Level[] = ['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village']
  const next = { ...props.modelValue }
  for (const child of ordered.slice(ordered.indexOf(level) + 1)) {
    next[`${child}_id`] = null
    next[child] = null
  }
  next.full_address = null
  return next
}

async function chooseDistrict(unit: AdministrativeUnit) {
  const id = unit.id
  counties.value = []; subcounties.value = []; parishes.value = []; villages.value = []
  villageQuery.value = ''
  emit('update:modelValue', clearBelowSelection(unit, 'district'))
  await Promise.all([
    loadUnits('county', { district_id: id }, counties),
    loadUnits('subcounty', { district_id: id }, subcounties),
  ])
}

async function chooseCounty(unit: AdministrativeUnit) {
  const id = unit.id
  parishes.value = []; villages.value = []
  villageQuery.value = ''
  emit('update:modelValue', clearBelowSelection(unit, 'county'))
  await loadUnits('subcounty', { county_id: id }, subcounties)
}

async function chooseSubcounty(unit: AdministrativeUnit) {
  const id = unit.id
  parishes.value = []; villages.value = []
  villageQuery.value = ''
  emit('update:modelValue', clearBelowSelection(unit, 'subcounty'))
  await loadUnits('parish', { subcounty_id: id }, parishes)
}

async function chooseParish(unit: AdministrativeUnit) {
  const id = unit.id
  villages.value = []
  villageQuery.value = ''
  emit('update:modelValue', clearBelowSelection(unit, 'parish'))
  await loadUnits('village', { parish_id: id }, villages)
}

function clearBelowSelection(unit: AdministrativeUnit, level: Level): AddressValue {
  const next = mergedSelection(unit)
  const ordered: Level[] = ['region', 'subregion', 'district', 'county', 'subcounty', 'parish', 'village']
  for (const child of ordered.slice(ordered.indexOf(level) + 1)) {
    next[`${child}_id`] = null
    next[child] = null
  }
  next.full_address = unit.full_address
  return next
}

async function chooseSearchResult(unit: AdministrativeUnit) {
  emit('update:modelValue', mergedSelection(unit))
  villageQuery.value = unit.name
  const districtId = unit.lineage.district?.id
  const countyId = unit.lineage.county?.id
  const subcountyId = unit.lineage.subcounty?.id
  const parishId = unit.lineage.parish?.id
  await Promise.all([
    ...(districtId ? [loadUnits('county', { district_id: districtId }, counties)] : []),
    ...(districtId ? [loadUnits('subcounty', countyId ? { county_id: countyId } : { district_id: districtId }, subcounties)] : []),
    ...(subcountyId ? [loadUnits('parish', { subcounty_id: subcountyId }, parishes)] : []),
    ...(parishId ? [loadUnits('village', { parish_id: parishId }, villages)] : []),
  ])
}

function typeLabel(unit: AdministrativeUnit): string {
  return (unit.unit_type || unit.level).replaceAll('-', ' ')
}

function unitOptions(units: AdministrativeUnit[]): ComboboxOption[] {
  return units.map((unit) => ({
    value: unit.id,
    label: unit.name,
    description: `${typeLabel(unit)} · ${unit.full_address}`,
    data: unit,
  }))
}

function unitFromOption(option: ComboboxOption): AdministrativeUnit {
  return option.data as AdministrativeUnit
}

function updateUnitQuery(level: Exclude<Level, 'region' | 'subregion' | 'village'>, query: string): void {
  if (!value(`${level}_id`) || query === value(level)) return
  const next = level === 'district' ? clearBelow('region') : clearBelow({ county: 'district', subcounty: 'county', parish: 'subcounty' }[level] as Level)
  next[`${level}_id`] = null
  next[level] = null
  if (level === 'district') {
    next.region_id = null; next.region = null; next.subregion_id = null; next.subregion = null
    counties.value = []; subcounties.value = []; parishes.value = []; villages.value = []
  } else if (level === 'county') {
    parishes.value = []; villages.value = []
  } else if (level === 'subcounty') {
    parishes.value = []; villages.value = []
  } else {
    villages.value = []
  }
  emit('update:modelValue', next)
}

function updateVillageQuery(query: string): void {
  villageQuery.value = query
  if (value('village_id') && query !== value('village')) emit('update:modelValue', clearBelow('parish'))
}

async function loadVillageOptions(search: string, signal: AbortSignal): Promise<ComboboxOption[]> {
  if (!search.trim() && value('parish_id')) return unitOptions(villages.value.slice(0, 25))
  const query = new URLSearchParams({ level: 'village', search: search.trim(), limit: '25' })
  const response = await api<{ data: AdministrativeUnit[] }>(`/geography/units?${query}`, { cacheTtlMs: 60_000, signal })
  return unitOptions(response.data)
}
</script>

<template>
  <div class="administrative-address">
    <div class="field-grid">
      <FloatingCombobox label="District / city" :model-value="value('district')" :options="unitOptions(districts)" required :disabled="loading.district" :placeholder="loading.district ? 'Loading districts…' : 'Search or select district / city'" @update:model-value="updateUnitQuery('district', $event)" @select="chooseDistrict(unitFromOption($event))" />
      <FloatingCombobox label="County / municipality" :model-value="value('county')" :options="unitOptions(counties)" :disabled="!value('district_id') || loading.county" :placeholder="loading.county ? 'Loading counties…' : 'Search or select county / municipality'" @update:model-value="updateUnitQuery('county', $event)" @select="chooseCounty(unitFromOption($event))" />
      <FloatingCombobox label="Sub-county / town / division" :model-value="value('subcounty')" :options="unitOptions(subcounties)" :disabled="!value('district_id') || loading.subcounty" :placeholder="loading.subcounty ? 'Loading sub-counties…' : 'Search or select sub-county / town / division'" @update:model-value="updateUnitQuery('subcounty', $event)" @select="chooseSubcounty(unitFromOption($event))" />
      <FloatingCombobox label="Parish / ward" :model-value="value('parish')" :options="unitOptions(parishes)" :disabled="!value('subcounty_id') || loading.parish" :placeholder="loading.parish ? 'Loading parishes…' : 'Search or select parish / ward'" @update:model-value="updateUnitQuery('parish', $event)" @select="chooseParish(unitFromOption($event))" />
      <div class="wide village-combobox">
        <FloatingCombobox
          :id="villageControlId"
          label="Village / cell"
          :model-value="villageQuery"
          :load-options="loadVillageOptions"
          :min-chars="value('parish_id') ? 0 : 2"
          :max-visible="7"
          :debounce-ms="300"
          :placeholder="value('parish_id') ? 'Type or select a village / cell' : 'Type 2+ letters to search all Uganda'"
          hint="Type two or more letters to search nationally. Selecting a result fills every higher administrative unit."
          loading-text="Searching villages…"
          no-results-text="No village or cell matches that search."
          @update:model-value="updateVillageQuery"
          @select="chooseSearchResult(unitFromOption($event))"
        />
      </div>
    </div>
    <p v-if="value('full_address')" class="selected-address"><strong>Selected administrative address</strong><span>{{ value('full_address') }}</span><small v-if="value('subregion')">{{ value('subregion') }} subregion · {{ value('region') }} region</small></p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>
