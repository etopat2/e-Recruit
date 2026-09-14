<script setup lang="ts">
withDefaults(defineProps<{
  label: string
  value: string | number
  detail?: string
  tone?: 'gold' | 'success' | 'danger' | 'neutral'
}>(), { detail: '', tone: 'gold' })
</script>

<template>
  <article class="stat-card" :data-tone="tone">
    <span>{{ label }}</span>
    <strong>{{ value }}</strong>
    <small v-if="detail">{{ detail }}</small>
  </article>
</template>
