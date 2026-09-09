<script setup lang="ts">
import { onMounted, ref } from 'vue'
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

const districts = ref<AdministrativeUnit[]>([])
const counties = ref<AdministrativeUnit[]>([])
const subcounties = ref<AdministrativeUnit[]>([])
const parishes = ref<AdministrativeUnit[]>([])
const villages = ref<AdministrativeUnit[]>([])
const villageSearch = ref('')
const villageResults = ref<AdministrativeUnit[]>([])
const loading = ref<Record<string, boolean>>({})
const error = ref('')
let searchTimer = 0

onMounted(async () => {
  await loadUnits('district', {}, districts)
  const districtId = value('district_id')
  if (districtId) {
    await Promise.all([
      loadUnits('county', { district_id: districtId }, counties),
      loadUnits('subcounty', { district_id: districtId }, subcounties),
    ])
  }
  const subcountyId = value('subcounty_id')
  if (subcountyId) await loadUnits('parish', { subcounty_id: subcountyId }, parishes)
  const parishId = value('parish_id')
  if (parishId) await loadUnits('village', { parish_id: parishId }, villages)
})

function value(key: string): string {
  return String(props.modelValue[key] || '')
}

async function loadUnits(level: Level, filters: Record<string, string>, target: typeof districts) {
  loading.value[level] = true
  error.value = ''
  const query = new URLSearchParams({ level, limit: '250', ...filters })
  try {
    const response = await api<{ data: AdministrativeUnit[] }>(`/geography/units?${query}`)
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
  if (!id) { emit('update:modelValue', clearBelow('county')); return }
  const unit = subcounties.value.find((item) => item.id === id)
  if (!unit) return
  emit('update:modelValue', clearBelowSelection(unit, 'subcounty'))
  await loadUnits('parish', { subcounty_id: id }, parishes)
}

async function chooseParish(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  villages.value = []
  if (!id) { emit('update:modelValue', clearBelow('subcounty')); return }
  const unit = parishes.value.find((item) => item.id === id)
  if (!unit) return
  emit('update:modelValue', clearBelowSelection(unit, 'parish'))
  await loadUnits('village', { parish_id: id }, villages)
}

function chooseVillage(event: Event) {
  const id = (event.target as HTMLSelectElement).value
  if (!id) { emit('update:modelValue', clearBelow('parish')); return }
  const unit = villages.value.find((item) => item.id === id)
  if (unit) emit('update:modelValue', mergedSelection(unit))
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
  const search = villageSearch.value.trim()
  if (search.length < 2) { villageResults.value = []; return }
  searchTimer = window.setTimeout(async () => {
    loading.value.search = true; error.value = ''
    try {
      const query = new URLSearchParams({ level: 'village', search, limit: '25' })
      const response = await api<{ data: AdministrativeUnit[] }>(`/geography/units?${query}`)
      villageResults.value = response.data
    } catch (problem) {
      error.value = problem instanceof Error ? problem.message : 'Village search failed.'
    } finally { loading.value.search = false }
  }, 300)
}

async function chooseSearchResult(unit: AdministrativeUnit) {
  emit('update:modelValue', mergedSelection(unit))
  villageSearch.value = unit.name
  villageResults.value = []
  const districtId = unit.lineage.district?.id
  const countyId = unit.lineage.county?.id
  const subcountyId = unit.lineage.subcounty?.id
  const parishId = unit.lineage.parish?.id
  if (districtId) await loadUnits('county', { district_id: districtId }, counties)
  if (districtId) await loadUnits('subcounty', countyId ? { county_id: countyId } : { district_id: districtId }, subcounties)
  if (subcountyId) await loadUnits('parish', { subcounty_id: subcountyId }, parishes)
  if (parishId) await loadUnits('village', { parish_id: parishId }, villages)
}

function typeLabel(unit: AdministrativeUnit): string {
  return (unit.unit_type || unit.level).replaceAll('-', ' ')
}
</script>

<template>
  <div class="administrative-address">
    <label class="wide">Find a village anywhere in Uganda
      <input v-model="villageSearch" type="search" autocomplete="off" placeholder="Type at least 2 letters of the village" @input="searchVillages" />
      <small>Choose a result to fill its region, subregion, district/city, county, sub-county, parish/ward, and village/cell.</small>
    </label>
    <div v-if="loading.search" class="address-search-status" role="status">Searching villages…</div>
    <ul v-else-if="villageResults.length" class="address-search-results" aria-label="Village search results">
      <li v-for="unit in villageResults" :key="unit.id"><button type="button" @click="chooseSearchResult(unit)"><strong>{{ unit.name }}</strong><span>{{ typeLabel(unit) }} · {{ unit.full_address }}</span></button></li>
    </ul>
    <div class="address-divider"><span>or select from district downward</span></div>
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
      <label class="wide">Village / cell
        <select :value="value('village_id')" :disabled="!value('parish_id') || loading.village" @change="chooseVillage"><option value="">{{ loading.village ? 'Loading…' : 'Select village or cell' }}</option><option v-for="unit in villages" :key="unit.id" :value="unit.id">{{ unit.name }} ({{ typeLabel(unit) }})</option></select>
      </label>
    </div>
    <p v-if="value('full_address')" class="selected-address"><strong>Selected administrative address</strong><span>{{ value('full_address') }}</span><small v-if="value('subregion')">{{ value('subregion') }} subregion · {{ value('region') }} region</small></p>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>
