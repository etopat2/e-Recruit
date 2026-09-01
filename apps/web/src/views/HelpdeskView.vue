<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import StatusBadge from '../components/StatusBadge.vue'
import { api, jsonBody } from '../lib/api'

interface Ticket { id: string; subject: string; category: string; status: string; created_at: string }
const tickets = ref<Ticket[]>([]); const form = reactive({ recruitment_campaign_id: '', application_id: '', category: 'application', subject: '', description: '' }); const notice = ref(''); const error = ref('')
onMounted(load)
async function load() { try { const response = await api<{ tickets: { data: Ticket[] } }>('/helpdesk/tickets'); tickets.value = response.tickets.data } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Tickets unavailable.' } }
async function create() { try { await api('/helpdesk/tickets', { method: 'POST', ...jsonBody({ ...form, application_id: form.application_id || null }) }); notice.value = 'Support request opened. Its response deadlines are being tracked.'; form.subject = ''; form.description = ''; await load() } catch (problem) { error.value = problem instanceof Error ? problem.message : 'Request could not be opened.' } }
</script>

<template>
  <section class="page-heading"><p class="eyebrow">Support and accountability</p><h1>Helpdesk</h1><p>Ask for assistance without sharing passwords or authenticator codes. Appeals tied to a decision should be submitted from the application status record.</p></section><div v-if="notice" class="alert success page-alert">{{ notice }}</div><div v-if="error" class="alert error page-alert">{{ error }}</div><section class="configuration-layout"><form class="form-panel" @submit.prevent="create"><h2>Open a support request</h2><label>Campaign ID<input v-model="form.recruitment_campaign_id" required /></label><label>Application ID (if applicable)<input v-model="form.application_id" /></label><label>Category<select v-model="form.category"><option>application</option><option>document</option><option>access</option><option>interview</option><option>appeal</option><option>other</option></select></label><label>Subject<input v-model="form.subject" required /></label><label>Description<textarea v-model="form.description" rows="7" required /></label><button class="button primary full">Submit request</button></form><div><h2>My requests</h2><article v-for="ticket in tickets" :key="ticket.id" class="ticket-card"><div><strong>{{ ticket.subject }}</strong><StatusBadge :status="ticket.status" /></div><p>{{ ticket.category }} · {{ new Date(ticket.created_at).toLocaleString() }}</p><code>{{ ticket.id }}</code></article><div v-if="tickets.length === 0" class="empty-state"><p>No support requests.</p></div></div></section>
</template>
