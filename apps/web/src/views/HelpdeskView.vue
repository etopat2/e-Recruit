<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import Dialog from '../components/Dialog.vue'
import FloatingCombobox, { type ComboboxOption } from '../components/FloatingCombobox.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingIndicator from '../components/LoadingIndicator.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'

interface Ticket { id: string; subject: string; category: string; status: string; created_at: string }
interface CampaignOption { id: string; code: string; name: string }
interface ApplicationOption { id: string; reference: string | null; campaign: { id: string; name: string }; post: { name: string } }
const tickets = ref<Ticket[]>([]); const form = reactive({ recruitment_campaign_id: '', application_id: '', category: 'application', subject: '', description: '' }); const notice = ref(''); const error = ref('')
const campaigns = ref<CampaignOption[]>([]); const applications = ref<ApplicationOption[]>([])
const createDialogOpen = ref(false); const busy = ref(false)
onMounted(load)
async function load() { try { const [ticketResponse, campaignResponse, applicationResponse] = await Promise.all([api<{ tickets: { data: Ticket[] } }>('/helpdesk/tickets'), api<{ data: CampaignOption[] }>('/campaigns'), api<{ data: ApplicationOption[] }>('/applications')]); tickets.value = ticketResponse.tickets.data; campaigns.value = campaignResponse.data; applications.value = applicationResponse.data.filter((application) => Boolean(application.reference)) } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Support reference lists are unavailable.' } }
const campaignOptions = (): ComboboxOption[] => campaigns.value.map((campaign) => ({ value: campaign.id, label: campaign.name, description: campaign.code }))
const applicationOptions = (): ComboboxOption[] => applications.value.map((application) => ({ value: application.id, label: application.reference || 'Draft application', description: `${application.post.name} · ${application.campaign.name}`, data: application }))
const campaignName = (): string => campaigns.value.find((campaign) => campaign.id === form.recruitment_campaign_id)?.name || ''
const applicationName = (): string => applications.value.find((application) => application.id === form.application_id)?.reference || ''
function selectApplication(option: ComboboxOption): void { form.application_id = option.value; const application = option.data as ApplicationOption; form.recruitment_campaign_id = application.campaign.id }
async function create() { busy.value = true; error.value = ''; try { await api('/helpdesk/tickets', { method: 'POST', ...jsonBody({ ...form, application_id: form.application_id || null }) }); notice.value = 'Support request opened. Its response deadlines are being tracked.'; form.subject = ''; form.description = ''; createDialogOpen.value = false; await load() } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Request could not be opened.' } finally { busy.value = false } }
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Support and accountability</p><h1>Helpdesk</h1><p>Ask for assistance without sharing passwords or authenticator codes. Appeals tied to a decision should be submitted from the application status record.</p></section><FormAlert v-if="notice" kind="success" :message="notice" page /><FormAlert v-if="error" kind="error" :message="error" page /><section class="content-section compact-top"><div class="section-heading"><div><p class="eyebrow">My requests</p><h2>{{ tickets.length }} support request(s)</h2></div><button type="button" class="button primary" @click="createDialogOpen = true">Open support request</button></div><article v-for="ticket in tickets" :key="ticket.id" class="ticket-card"><div><strong>{{ ticket.subject }}</strong><StatusBadge :status="ticket.status" /></div><p>{{ ticket.category }} · opened {{ new Date(ticket.created_at).toLocaleString() }}</p></article><div v-if="tickets.length === 0" class="empty-state"><p>No support requests.</p></div></section>
  <Dialog :open="createDialogOpen" title="Open a support request" description="Never include passwords or authenticator codes in a support request." :close-on-backdrop="false" mobile-sheet @close="createDialogOpen = false"><form @submit.prevent="create"><FloatingCombobox label="Campaign" :model-value="campaignName()" :options="campaignOptions()" required placeholder="Search campaign name or code" data-dialog-initial-focus @update:model-value="form.recruitment_campaign_id = ''; form.application_id = ''" @select="form.recruitment_campaign_id = $event.value; form.application_id = ''" /><FloatingCombobox label="Application (if applicable)" :model-value="applicationName()" :options="applicationOptions()" placeholder="Search application reference or post" @update:model-value="form.application_id = ''" @select="selectApplication" /><label>Category<select v-model="form.category"><option>application</option><option>document</option><option>access</option><option>interview</option><option>appeal</option><option>other</option></select></label><label>Subject<input v-model="form.subject" required /></label><label>Description<textarea v-model="form.description" rows="7" required /></label><button class="button primary full" :disabled="busy || !form.recruitment_campaign_id"><LoadingIndicator v-if="busy" small label="Submitting…" /><span v-else>Submit request</span></button></form></Dialog>
</template>
