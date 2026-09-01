<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, jsonBody } from '../lib/api'
import type { Campaign } from '../types'

const campaigns = ref<Campaign[]>([]); const busy = ref(false); const message = ref(''); const error = ref('')
const form = reactive({ code: 'UPS-2026-02', name: 'UPS Recruitment Campaign', year: 2026, opens_at: '', closes_at: '', hard_copy_deadline_at: '', age_cutoff_date: '', appeals_enabled: true, post_code: 'WARDER', post_name: 'Recruit Warder', reference_prefix: 'WRD', hard_copy_required: true, eligibility: '[{"id":"age","version":1,"type":"age_range","field":"date_of_birth","minimum":18,"maximum":30,"cutoff_date":"2026-12-31"}]', selection: '{"total_slots":100,"reserve_size":20,"unfilled_quota_rule":"general_merit"}' })
const validation = computed(() => [
  ['Application dates', Boolean(form.opens_at && form.closes_at && form.closes_at > form.opens_at)],
  ['Hard-copy deadline', !form.hard_copy_required || Boolean(form.hard_copy_deadline_at && form.hard_copy_deadline_at >= form.closes_at)],
  ['Post reference prefix', /^[A-Za-z0-9]+$/.test(form.reference_prefix)],
  ['Eligibility JSON', validJson(form.eligibility)], ['Selection JSON', validJson(form.selection)],
] as Array<[string, boolean]>)
const publishable = computed(() => validation.value.every(([, valid]) => valid))
function validJson(value: string) { try { JSON.parse(value); return true } catch { return false } }
onMounted(load)
async function load() { const response = await api<{ data: Campaign[] }>('/admin/campaigns'); campaigns.value = response.data }
async function createCampaign() {
  if (!publishable.value) return
  busy.value = true; error.value = ''
  try {
    const payload = { code: form.code, name: form.name, year: form.year, timezone: 'Africa/Kampala', opens_at: form.opens_at, closes_at: form.closes_at, hard_copy_deadline_at: form.hard_copy_deadline_at || null, age_cutoff_date: form.age_cutoff_date || null, privacy_notice: { version: `${form.code}-privacy-v1`, summary: 'Recruitment processing and accountability notice.' }, appeals_enabled: form.appeals_enabled, posts: [{ code: form.post_code, name: form.post_name, description: '', reference_prefix: form.reference_prefix, section_configuration: { personal: { required: true }, address: { required: true }, education: { required: true }, declaration: { required: true } }, eligibility_configuration: JSON.parse(form.eligibility), selection_configuration: JSON.parse(form.selection), lc_source_policy: 'origin_or_residence', hard_copy_required: form.hard_copy_required, active: true, document_requirements: [
      { document_type: 'national_id', label: 'National identification', required: true, minimum_files: 1, maximum_files: 1, maximum_size_kb: 5120, allowed_extensions: ['pdf', 'jpg', 'jpeg', 'png'], hard_copy_required: form.hard_copy_required, original_required_at_interview: true, extraction_profile: { fields: ['name', 'nin', 'dob'] } },
      { document_type: 'academic_certificate', label: 'Academic certificate', required: true, minimum_files: 1, maximum_files: 3, maximum_size_kb: 5120, allowed_extensions: ['pdf', 'jpg', 'jpeg', 'png'], hard_copy_required: form.hard_copy_required, original_required_at_interview: true, extraction_profile: { fields: ['name', 'index_number', 'grade'] } },
    ], stages: [
      ['application', 'Application'], ['hard_copy', 'Hard-copy reception'], ['verification', 'Verification'], ['eligibility', 'Eligibility'], ['interview', 'Interview'], ['selection', 'Selection'], ['medical', 'Medical'], ['training', 'Training intake'],
    ].map(([stage_code, name], index) => ({ stage_code, name, sequence: index + 1, required: true, configuration: {} })), assessment_definitions: [
      { code: 'INTERVIEW', name: 'Oral interview', component_type: 'oral_interview', maximum_mark: 100, pass_mark: 50, weight: 100, mandatory: true, assessor_model: 'independent', aggregation_method: 'average', divergence_threshold: 20, blind_scoring: true },
    ] }] }
    const response = await api<{ data: Campaign }>('/campaigns', { method: 'POST', ...jsonBody(payload) }); campaigns.value.unshift(response.data); message.value = 'Draft campaign and immutable version 1 created.'
  } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Campaign was not created.' } finally { busy.value = false }
}
async function publish(campaign: Campaign) { try { await api(`/campaigns/${campaign.id}/publish`, { method: 'POST', ...jsonBody({ change_reason: 'Configuration reviewed against publish guardrails.' }) }); message.value = `${campaign.code} published.`; await load() } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Publish failed.' } }
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Configuration studio</p><h1>Campaigns and posts</h1><p>Criteria, dates, reference formats, stages, and selection policies are versioned configuration—not hard-coded decisions.</p></section><div v-if="message" class="alert success page-alert">{{ message }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div>
  <section class="configuration-layout"><form class="form-panel" @submit.prevent="createCampaign"><h2>New campaign draft</h2><div class="field-grid"><label>Code<input v-model="form.code" required /></label><label>Name<input v-model="form.name" required /></label><label>Year<input v-model="form.year" type="number" /></label><label>Applications open<input v-model="form.opens_at" type="datetime-local" required /></label><label>Applications close<input v-model="form.closes_at" type="datetime-local" required /></label><label>Hard-copy deadline<input v-model="form.hard_copy_deadline_at" type="datetime-local" /></label><label>Age cut-off date<input v-model="form.age_cutoff_date" type="date" /></label><label>Post code<input v-model="form.post_code" /></label><label>Post name<input v-model="form.post_name" /></label><label>Reference prefix<input v-model="form.reference_prefix" /></label><label class="checkbox wide"><input v-model="form.hard_copy_required" type="checkbox" /> Require hard-copy evidence</label><label class="wide">Eligibility rules (JSON)<textarea v-model="form.eligibility" rows="7" spellcheck="false" /></label><label class="wide">Selection policy (JSON)<textarea v-model="form.selection" rows="6" spellcheck="false" /></label></div><div class="guardrail-box"><strong>Publish guardrails</strong><ul><li v-for="([label, valid]) in validation" :key="label" :class="{ valid }">{{ valid ? '✓' : '!' }} {{ label }}</li></ul></div><button class="button primary full" :disabled="busy || !publishable">Create versioned draft</button></form><div><h2>Campaign register</h2><article v-for="campaign in campaigns" :key="campaign.id" class="campaign-card small"><div class="card-topline"><span>{{ campaign.code }}</span><span>{{ campaign.status }}</span></div><h3>{{ campaign.name }}</h3><p>{{ campaign.posts.length }} post(s) · closes {{ new Date(campaign.closes_at).toLocaleString() }}</p><button v-if="campaign.status === 'draft'" class="button compact" @click="publish(campaign)">Publish reviewed version</button></article></div></section>
</template>
