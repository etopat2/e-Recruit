<script setup lang="ts">
defineProps<{ status: string }>()
</script>

<template>
  <span class="status-badge" :data-status="status.toLowerCase().replaceAll(' ', '_')">{{ status.replaceAll('_', ' ') }}</span>
</template>
