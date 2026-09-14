<script setup lang="ts">
withDefaults(defineProps<{
  kind?: 'success' | 'error' | 'info'
  message?: string
  page?: boolean
}>(), { kind: 'info', message: '', page: false })
</script>

<template>
  <div
    v-if="message || $slots.default"
    class="alert"
    :class="[kind, { 'page-alert': page }]"
    :role="kind === 'error' ? 'alert' : 'status'"
    :aria-live="kind === 'error' ? 'assertive' : 'polite'"
  >
    <slot>{{ message }}</slot>
  </div>
</template>
