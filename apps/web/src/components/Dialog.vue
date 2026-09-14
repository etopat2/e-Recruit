<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

export type DialogCloseReason = 'button' | 'escape' | 'backdrop'

const props = withDefaults(defineProps<{
  open: boolean
  title: string
  description?: string
  closeLabel?: string
  closeOnBackdrop?: boolean
  dirty?: boolean
  mobileSheet?: boolean
  initialFocus?: string
}>(), {
  description: '',
  closeLabel: 'Close dialog',
  closeOnBackdrop: true,
  dirty: false,
  mobileSheet: false,
  initialFocus: '',
})

const emit = defineEmits<{
  close: [reason: DialogCloseReason]
  'blocked-close': [reason: 'escape' | 'backdrop']
}>()

const dialog = ref<HTMLElement | null>(null)
const titleId = `dialog-title-${useId()}`
const descriptionId = `dialog-description-${useId()}`
let returnFocus: HTMLElement | null = null
let previousOverflow = ''

function focusableElements(): HTMLElement[] {
  if (!dialog.value) return []
  return Array.from(dialog.value.querySelectorAll<HTMLElement>(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter((element) => !element.hasAttribute('hidden') && element.getAttribute('aria-hidden') !== 'true')
}

async function activate(): Promise<void> {
  returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  document.addEventListener('keydown', handleKeydown)
  await nextTick()
  const requested = props.initialFocus ? dialog.value?.querySelector<HTMLElement>(props.initialFocus) : null
  const target = requested || dialog.value?.querySelector<HTMLElement>('[data-dialog-initial-focus]') || focusableElements()[0] || dialog.value
  target?.focus()
}

function deactivate(): void {
  document.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = previousOverflow
  const target = returnFocus
  returnFocus = null
  void nextTick(() => target?.focus())
}

function requestClose(reason: DialogCloseReason): void {
  if ((reason === 'escape' || reason === 'backdrop') && props.dirty) {
    emit('blocked-close', reason)
    return
  }
  if (reason === 'backdrop' && !props.closeOnBackdrop) return
  emit('close', reason)
}

function handleKeydown(event: KeyboardEvent): void {
  if (!props.open) return
  if (event.key === 'Escape') {
    event.preventDefault()
    requestClose('escape')
    return
  }
  if (event.key !== 'Tab') return
  const focusable = focusableElements()
  if (!focusable.length) {
    event.preventDefault()
    dialog.value?.focus()
    return
  }
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function handleBackdrop(event: MouseEvent): void {
  if (event.target === event.currentTarget) requestClose('backdrop')
}

watch(() => props.open, (open) => {
  if (open) void activate()
  else deactivate()
}, { immediate: true, flush: 'post' })

onBeforeUnmount(() => {
  if (props.open) deactivate()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="dialog-fade">
      <div v-if="open" class="dialog-backdrop" data-testid="dialog-backdrop" @mousedown="handleBackdrop">
        <section
          ref="dialog"
          class="dialog-panel"
          :class="{ 'mobile-sheet': mobileSheet }"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          :aria-describedby="description || $slots.description ? descriptionId : undefined"
          tabindex="-1"
          @mousedown.stop
        >
          <header class="dialog-header">
            <div>
              <p v-if="$slots.eyebrow" class="eyebrow"><slot name="eyebrow" /></p>
              <h2 :id="titleId">{{ title }}</h2>
              <p v-if="description || $slots.description" :id="descriptionId" class="dialog-description">
                <slot name="description">{{ description }}</slot>
              </p>
            </div>
            <button class="dialog-close" type="button" :aria-label="closeLabel" @click="requestClose('button')">×</button>
          </header>
          <div class="dialog-body"><slot /></div>
          <footer v-if="$slots.footer" class="dialog-footer"><slot name="footer" /></footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>
