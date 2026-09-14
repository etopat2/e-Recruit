<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  error?: string | string[] | null
  id?: string
}>(), { error: null, id: '' })

const messages = computed(() => (Array.isArray(props.error) ? props.error : props.error ? [props.error] : []))
</script>

<template>
  <small v-if="messages.length" :id="id || undefined" class="field-error" role="alert">
    {{ messages.join(' ') }}
  </small>
</template>
