<script setup lang="ts">
withDefaults(defineProps<{ lines?: number; label?: string }>(), { lines: 3, label: 'Loading content' })
</script>

<template>
  <div class="skeleton-block" role="status" :aria-label="label">
    <span v-for="line in lines" :key="line" aria-hidden="true" />
  </div>
</template>
