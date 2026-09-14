<script setup lang="ts">
withDefaults(defineProps<{ label?: string; small?: boolean }>(), { label: 'Loading…', small: false })
</script>

<template>
  <span class="loading-indicator" role="status">
    <span class="loading-spinner" :class="{ small }" aria-hidden="true" />
    <span>{{ label }}</span>
  </span>
</template>
