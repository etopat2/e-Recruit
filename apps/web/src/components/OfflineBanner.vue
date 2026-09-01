<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

const online = ref(navigator.onLine)
const update = () => { online.value = navigator.onLine }
onMounted(() => { window.addEventListener('online', update); window.addEventListener('offline', update) })
onBeforeUnmount(() => { window.removeEventListener('online', update); window.removeEventListener('offline', update) })
</script>

<template>
  <div v-if="!online" class="offline-banner" role="status">
    Offline mode — saved work remains on this device and will require synchronisation.
  </div>
</template>
