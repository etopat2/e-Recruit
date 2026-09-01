<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../lib/api'
import { useSessionStore } from '../stores/session'
import type { Campaign } from '../types'

const campaigns = ref<Campaign[]>([])
const loading = ref(true)
const error = ref('')
const session = useSessionStore()
const router = useRouter()

onMounted(async () => {
  try {
    const response = await api<{ data: Campaign[] }>('/campaigns')
    campaigns.value = response.data
  } catch (problem) {
    error.value = problem instanceof Error ? problem.message : 'Campaigns are temporarily unavailable.'
  } finally {
    loading.value = false
  }
})

async function begin(campaignId: string, postId: string) {
  if (!session.authenticated) {
    await router.push({ name: 'access', query: { campaign: campaignId, post: postId } })
    return
  }
  const response = await api<{ data: { id: string } }>('/applications', {
    method: 'POST',
    body: JSON.stringify({ campaign_id: campaignId, post_id: postId }),
  })
  await router.push({ name: 'application', params: { id: response.data.id } })
}
</script>

<template>
  <section class="hero-section">
    <div class="hero-copy">
      <p class="eyebrow">Serve with integrity</p>
      <h1>A fair path to serving Uganda</h1>
      <p class="lede">Apply for Uganda Prisons Service opportunities through one secure portal, keep your work as a draft, and follow every stage with a clear record.</p>
      <div class="hero-actions"><a class="button primary" href="#opportunities">View opportunities</a><RouterLink class="button secondary" to="/access">Track an application</RouterLink></div>
      <ul class="trust-list" aria-label="Portal assurances"><li>One active application per post</li><li>Protected document handling</li><li>Explainable decisions and appeals</li></ul>
    </div>
    <div class="hero-mark" aria-hidden="true"><img :src="'/brand/ups-logo.png'" alt="" /><span>DISCIPLINE · INTEGRITY · SERVICE</span></div>
  </section>

  <section id="opportunities" class="content-section">
    <div class="section-heading"><div><p class="eyebrow">Current recruitment</p><h2>Open opportunities</h2></div><p>All closing times use East Africa Time.</p></div>
    <p v-if="loading" role="status">Loading opportunities…</p>
    <div v-else-if="error" class="alert error" role="alert">{{ error }}</div>
    <div v-else-if="campaigns.length === 0" class="empty-state"><h3>No opportunities are open</h3><p>Please return later. UPS will publish authorised campaigns here.</p></div>
    <div v-else class="campaign-grid">
      <article v-for="campaign in campaigns" :key="campaign.id" class="campaign-card">
        <div class="card-topline"><span>{{ campaign.code }}</span><span>Closes {{ new Date(campaign.closes_at).toLocaleDateString() }}</span></div>
        <h3>{{ campaign.name }}</h3>
        <p>{{ campaign.privacy_notice?.summary }}</p>
        <div v-for="post in campaign.posts" :key="post.id" class="post-row">
          <div><strong>{{ post.name }}</strong><small>{{ post.description }}</small></div>
          <button class="button compact" type="button" @click="begin(campaign.id, post.id)">Apply</button>
        </div>
      </article>
    </div>
  </section>

  <section class="steps-section" aria-labelledby="how-heading">
    <p class="eyebrow">What to expect</p><h2 id="how-heading">Your recruitment journey</h2>
    <ol class="step-grid"><li><span>01</span><h3>Apply online</h3><p>Save progress and upload clear evidence.</p></li><li><span>02</span><h3>Submit originals</h3><p>Follow the campaign’s hard-copy instructions.</p></li><li><span>03</span><h3>Attend assessments</h3><p>Receive schedules and status updates in the portal.</p></li><li><span>04</span><h3>Track the outcome</h3><p>Review decisions, notices, and appeal options.</p></li></ol>
  </section>
</template>
