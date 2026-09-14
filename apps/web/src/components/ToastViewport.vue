<script setup lang="ts">
import { dismissToast, toasts } from '../lib/toast'
</script>

<template>
  <ol class="toast-viewport" aria-label="Notifications" aria-live="polite">
    <li v-for="toast in toasts" :key="toast.id" class="toast" :class="toast.kind" :role="toast.kind === 'error' ? 'alert' : 'status'">
      <span>{{ toast.message }}</span>
      <button type="button" aria-label="Dismiss notification" @click="dismissToast(toast.id)">×</button>
    </li>
  </ol>
</template>
