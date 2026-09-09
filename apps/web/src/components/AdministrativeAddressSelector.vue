<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { api } from '../lib/api'

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
const villageResultsId = `${villageControlId}-results`

const districts = ref<AdministrativeUnit[]>([])
const counties = ref<AdministrativeUnit[]>([])
const subcounties = ref<AdministrativeUnit[]>([])
const parishes = ref<AdministrativeUnit[]>([])
const villages = ref<AdministrativeUnit[]>([])
const villageQuery = ref('')
const villageResults = ref<AdministrativeUnit[]>([])
const activeVillageIndex = ref(-1)
const loading = ref<Record<string, boolean>>({})
const error = ref('')
let searchTimer = 0
let searchSequence = 0

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

onBeforeUnmount(() => window.clearTimeout(searchTimer))

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

async function chooseDistrict(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  counties.value = []; subcounties.value = []; parishes.value = []; villages.value = []
  villageQuery.value = ''; villageResults.value = []
  if (!id) {
    const next = clearBelow('region')
    next.region_id = null; next.region = null; next.subregion_id = null; next.subregion = null; next.district_id = null; next.district = null
    emit('update:modelValue', next)
    return
  }
  const unit = districts.value.find((item) => item.id === id)
  if (!unit) return
  emit('update:modelValue', clearBelowSelection(unit, 'district'))
  await Promise.all([
    loadUnits('county', { district_id: id }, counties),
    loadUnits('subcounty', { district_id: id }, subcounties),
  ])
}

async function chooseCounty(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  parishes.value = []; villages.value = []
  villageQuery.value = ''; villageResults.value = []
  const districtId = value('district_id')
  if (!id) {
    const next = clearBelow('district'); next.county_id = null; next.county = null
    emit('update:modelValue', next)
    if (districtId) await loadUnits('subcounty', { district_id: districtId }, subcounties)
    return
  }
  const unit = counties.value.find((item) => item.id === id)
  if (!unit) return
  emit('update:modelValue', clearBelowSelection(unit, 'county'))
  await loadUnits('subcounty', { county_id: id }, subcounties)
}

async function chooseSubcounty(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  parishes.value = []; villages.value = []
  villageQuery.value = ''; villageResults.value = []
  if (!id) { emit('update:modelValue', clearBelow('county')); return }
  const unit = subcounties.value.find((item) => item.id === id)
  if (!unit) return
  emit('update:modelValue', clearBelowSelection(unit, 'subcounty'))
  await loadUnits('parish', { subcounty_id: id }, parishes)
}

async function chooseParish(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  villages.value = []
  villageQuery.value = ''; villageResults.value = []
  if (!id) { emit('update:modelValue', clearBelow('subcounty')); return }
  const unit = parishes.value.find((item) => item.id === id)
  if (!unit) return
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

function searchVillages() {
  window.clearTimeout(searchTimer)
  const sequence = ++searchSequence
  const search = villageQuery.value.trim()
  activeVillageIndex.value = -1
  if (value('village_id')) emit('update:modelValue', clearBelow('parish'))
  if (!search) {
    loading.value.search = false
    villageResults.value = value('parish_id') ? villages.value.slice(0, 25) : []
    return
  }
  if (search.length < 2) { loading.value.search = false; villageResults.value = []; return }
  searchTimer = window.setTimeout(async () => {
    loading.value.search = true; error.value = ''; villageResults.value = []
    try {
      const query = new URLSearchParams({ level: 'village', search, limit: '25' })
      const response = await api<{ data: AdministrativeUnit[] }>(`/geography/units?${query}`, { cacheTtlMs: 60_000 })
      if (sequence !== searchSequence) return
      villageResults.value = response.data
    } catch (problem) {
      if (sequence === searchSequence) error.value = problem instanceof Error ? problem.message : 'Village search failed.'
    } finally {
      if (sequence === searchSequence) loading.value.search = false
    }
  }, 300)
}

async function chooseSearchResult(unit: AdministrativeUnit) {
  window.clearTimeout(searchTimer)
  searchSequence++
  emit('update:modelValue', mergedSelection(unit))
  villageQuery.value = unit.name
  villageResults.value = []
  activeVillageIndex.value = -1
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

function showVillageOptions() {
  if (!villageQuery.value && value('parish_id')) villageResults.value = villages.value.slice(0, 25)
}

function moveVillageResult(direction: number) {
  if (!villageResults.value.length) return
  activeVillageIndex.value = (activeVillageIndex.value + direction + villageResults.value.length) % villageResults.value.length
}

function chooseActiveVillage() {
  const unit = villageResults.value[activeVillageIndex.value]
  if (unit) void chooseSearchResult(unit)
}

function typeLabel(unit: AdministrativeUnit): string {
  return (unit.unit_type || unit.level).replaceAll('-', ' ')
}
</script>

<template>
  <div class="administrative-address">
    <div class="field-grid">
      <label>District / city
        <select :value="value('district_id')" required :disabled="loading.district" @change="chooseDistrict"><option value="">{{ loading.district ? 'Loading…' : 'Select district or city' }}</option><option v-for="unit in districts" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ typeLabel(unit) }})</option></select>
      </label>
      <label>County / municipality
        <select :value="value('county_id')" :disabled="!value('district_id') || loading.county" @change="chooseCounty"><option value="">{{ loading.county ? 'Loading…' : 'Not applicable / select county' }}</option><option v-for="unit in counties" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ typeLabel(unit) }})</option></select>
      </label>
      <label>Sub-county / town / division
        <select :value="value('subcounty_id')" :disabled="!value('district_id') || loading.subcounty" @change="chooseSubcounty"><option value="">{{ loading.subcounty ? 'Loading…' : 'Select sub-county, town, or division' }}</option><option v-for="unit in subcounties" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ typeLabel(unit) }})</option></select>
      </label>
      <label>Parish / ward
        <select :value="value('parish_id')" :disabled="!value('subcounty_id') || loading.parish" @change="chooseParish"><option value="">{{ loading.parish ? 'Loading…' : 'Select parish or ward' }}</option><option v-for="unit in parishes" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ typeLabel(unit) }})</option></select>
      </label>
      <div class="wide village-combobox">
        <label :for="villageControlId">Village / cell
          <input
            :id="villageControlId"
            v-model="villageQuery"
            type="search"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-controls="villageResultsId"
            :aria-expanded="villageResults.length > 0"
            :aria-activedescendant="activeVillageIndex >= 0 ? `${villageResultsId}-option-${activeVillageIndex}` : undefined"
            :placeholder="value('parish_id') ? 'Type or select a village / cell' : 'Type 2+ letters to search all Uganda'"
            @focus="showVillageOptions"
            @input="searchVillages"
            @keydown.down.prevent="moveVillageResult(1)"
            @keydown.up.prevent="moveVillageResult(-1)"
            @keydown.enter.prevent="chooseActiveVillage"
            @keydown.esc="villageResults = []"
          />
          <small>Type two or more letters to search nationally. Selecting a result fills every higher administrative unit.</small>
        </label>
        <div v-if="loading.search" class="address-search-status" role="status">Searching villages…</div>
        <ul v-else-if="villageResults.length" :id="villageResultsId" class="address-search-results" role="listbox" aria-label="Village and cell options">
          <li v-for="(unit, index) in villageResults" :key="unit.id"><button :id="`${villageResultsId}-option-${index}`" type="button" role="option" :aria-selected="activeVillageIndex === index" @mousedown.prevent @click="chooseSearchResult(unit)"><strong>{{ unit.name }}</strong><span>{{ typeLabel(unit) }} · {{ unit.full_address }}</span></button></li>
        </ul>
      </div>
    </div>
    <p v-if="value('full_address')" class="selected-address"><strong>Selected administrative address</strong><span>{{ value('full_address') }}</span><small v-if="value('subregion')">{{ value('subregion') }} subregion · {{ value('region') }} region</small></p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>
