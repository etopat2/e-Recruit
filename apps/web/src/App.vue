<script setup lang="ts">
import AppShell from './components/AppShell.vue'
import ToastViewport from './components/ToastViewport.vue'
</script>

<template>
  <AppShell><RouterView /></AppShell>
  <ToastViewport />
</template>
