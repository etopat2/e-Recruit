<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, shallowRef } from 'vue'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

const installPrompt = shallowRef<BeforeInstallPromptEvent | null>(null)
const installed = shallowRef(false)
const canInstall = computed(() => !installed.value && installPrompt.value !== null)

function isStandalone(): boolean {
  const iosNavigator = navigator as Navigator & { standalone?: boolean }
  return window.matchMedia?.('(display-mode: standalone)').matches === true || iosNavigator.standalone === true
}

function captureInstallPrompt(event: Event): void {
  event.preventDefault()
  installPrompt.value = event as BeforeInstallPromptEvent
}

function markInstalled(): void {
  installed.value = true
  installPrompt.value = null
}

async function install(): Promise<void> {
  const prompt = installPrompt.value
  if (!prompt) return
  installPrompt.value = null
  await prompt.prompt()
  const choice = await prompt.userChoice
  if (choice.outcome === 'accepted') installed.value = true
}

onMounted(() => {
  installed.value = isStandalone()
  window.addEventListener('beforeinstallprompt', captureInstallPrompt)
  window.addEventListener('appinstalled', markInstalled)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeinstallprompt', captureInstallPrompt)
  window.removeEventListener('appinstalled', markInstalled)
})
</script>

<template>
  <button v-if="canInstall" class="nav-action pwa-install-action" type="button" @click="install">Install app</button>
</template>
