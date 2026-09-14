<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

export interface ComboboxOption {
  value: string
  label: string
  description?: string
  disabled?: boolean
  data?: unknown
}

const props = withDefaults(defineProps<{
  modelValue?: string
  options?: ComboboxOption[]
  loadOptions?: (query: string, signal: AbortSignal) => Promise<ComboboxOption[]>
  id?: string
  label: string
  placeholder?: string
  hint?: string
  disabled?: boolean
  required?: boolean
  minChars?: number
  debounceMs?: number
  maxVisible?: number
  noResultsText?: string
  loadingText?: string
}>(), {
  modelValue: '',
  options: () => [],
  id: '',
  placeholder: '',
  hint: '',
  disabled: false,
  required: false,
  minChars: 0,
  debounceMs: 200,
  maxVisible: 7,
  noResultsText: 'No matching options found.',
  loadingText: 'Loading options…',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  select: [option: ComboboxOption]
  search: [query: string]
}>()

const generatedId = useId()
const controlId = computed(() => props.id || `floating-combobox-${generatedId}`)
const listboxId = computed(() => `${controlId.value}-listbox`)
const hintId = computed(() => `${controlId.value}-hint`)
const statusId = computed(() => `${controlId.value}-status`)
const anchor = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const query = ref(props.modelValue)
const results = ref<ComboboxOption[]>([])
const expanded = ref(false)
const loading = ref(false)
const searched = ref(false)
const error = ref('')
const activeIndex = ref(-1)
const panelStyle = ref<Record<string, string>>({})
let timer = 0
let requestController: AbortController | null = null

const describedBy = computed(() => [props.hint ? hintId.value : '', expanded.value ? statusId.value : ''].filter(Boolean).join(' ') || undefined)
const clientMatches = computed(() => {
  const normalized = query.value.trim().toLocaleLowerCase()
  const options = normalized
    ? props.options.filter((option) => `${option.label} ${option.description || ''}`.toLocaleLowerCase().includes(normalized))
    : props.options
  return options
})

watch(() => props.modelValue, (value) => {
  if (value !== query.value) query.value = value
})

watch(() => props.options, () => {
  if (!props.loadOptions && expanded.value) setClientResults()
}, { deep: true })

function setClientResults(): void {
  results.value = clientMatches.value
  searched.value = true
  activeIndex.value = results.value.findIndex((option) => !option.disabled)
  expanded.value = true
  void nextTick(updatePosition)
}

function scheduleSearch(): void {
  window.clearTimeout(timer)
  requestController?.abort()
  requestController = null
  error.value = ''
  searched.value = false
  activeIndex.value = -1
  const search = query.value.trim()
  emit('search', search)
  if (search.length < props.minChars) {
    results.value = []
    expanded.value = false
    loading.value = false
    return
  }
  if (!props.loadOptions) {
    setClientResults()
    return
  }
  loading.value = true
  expanded.value = true
  void nextTick(updatePosition)
  timer = window.setTimeout(() => void load(search), props.debounceMs)
}

async function load(search: string): Promise<void> {
  const controller = new AbortController()
  requestController = controller
  try {
    const options = await props.loadOptions?.(search, controller.signal)
    if (controller.signal.aborted || search !== query.value.trim()) return
    results.value = options || []
    searched.value = true
    activeIndex.value = results.value.findIndex((option) => !option.disabled)
  } catch (problem) {
    if (controller.signal.aborted || (problem instanceof DOMException && problem.name === 'AbortError')) return
    results.value = []
    searched.value = true
    error.value = problem instanceof Error ? problem.message : 'Options could not be loaded.'
  } finally {
    if (requestController === controller) {
      requestController = null
      loading.value = false
      expanded.value = true
      await nextTick()
      updatePosition()
    }
  }
}

function handleInput(event: Event): void {
  query.value = (event.target as HTMLInputElement).value
  emit('update:modelValue', query.value)
  scheduleSearch()
}

function open(): void {
  if (props.disabled) return
  if (query.value.trim().length < props.minChars) return
  if (!props.loadOptions) setClientResults()
  else if (results.value.length || searched.value) {
    expanded.value = true
    void nextTick(updatePosition)
  } else scheduleSearch()
}

function close(): void {
  expanded.value = false
  activeIndex.value = -1
}

function moveActive(direction: 1 | -1): void {
  if (!expanded.value) open()
  const enabled = results.value.map((option, index) => ({ option, index })).filter(({ option }) => !option.disabled)
  if (!enabled.length) return
  const current = enabled.findIndex(({ index }) => index === activeIndex.value)
  const next = current < 0 ? (direction === 1 ? 0 : enabled.length - 1) : (current + direction + enabled.length) % enabled.length
  activeIndex.value = enabled[next].index
  void nextTick(() => {
    const activeOption = document.getElementById(`${listboxId.value}-option-${activeIndex.value}`)
    if (typeof activeOption?.scrollIntoView === 'function') activeOption.scrollIntoView({ block: 'nearest' })
  })
}

function choose(option: ComboboxOption): void {
  if (option.disabled) return
  query.value = option.label
  emit('update:modelValue', option.label)
  emit('select', option)
  close()
  void nextTick(() => input.value?.focus())
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    moveActive(1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    moveActive(-1)
  } else if (event.key === 'Enter' && expanded.value && activeIndex.value >= 0) {
    event.preventDefault()
    const option = results.value[activeIndex.value]
    if (option) choose(option)
  } else if (event.key === 'Escape') {
    event.preventDefault()
    close()
  } else if (event.key === 'Tab') {
    close()
  }
}

function updatePosition(): void {
  if (!expanded.value || !anchor.value) return
  const rectangle = anchor.value.getBoundingClientRect()
  const viewportWidth = window.innerWidth
  const viewportHeight = window.innerHeight
  const width = Math.min(Math.max(rectangle.width, 280), viewportWidth - 16)
  const left = Math.max(8, Math.min(rectangle.left, viewportWidth - width - 8))
  const maximumPanelHeight = props.maxVisible * 60 + 8
  const estimatedHeight = Math.min(panel.value?.offsetHeight || maximumPanelHeight, maximumPanelHeight, viewportHeight - 24)
  const below = viewportHeight - rectangle.bottom - 8
  const above = rectangle.top - 8
  const placeAbove = below < Math.min(estimatedHeight, 280) && above > below
  panelStyle.value = {
    left: `${left}px`,
    top: `${placeAbove ? Math.max(8, rectangle.top - estimatedHeight - 6) : rectangle.bottom + 6}px`,
    width: `${width}px`,
    maxHeight: `${Math.max(80, Math.min(placeAbove ? above - 6 : below - 6, estimatedHeight))}px`,
  }
  panel.value?.setAttribute('data-placement', placeAbove ? 'top' : 'bottom')
}

function handleOutsidePointer(event: PointerEvent): void {
  const target = event.target as Node
  if (!anchor.value?.contains(target) && !panel.value?.contains(target)) close()
}

onMounted(() => {
  document.addEventListener('pointerdown', handleOutsidePointer)
  window.addEventListener('resize', updatePosition)
  window.addEventListener('scroll', updatePosition, true)
})

onBeforeUnmount(() => {
  window.clearTimeout(timer)
  requestController?.abort()
  document.removeEventListener('pointerdown', handleOutsidePointer)
  window.removeEventListener('resize', updatePosition)
  window.removeEventListener('scroll', updatePosition, true)
})
</script>

<template>
  <div ref="anchor" class="floating-combobox">
    <label :for="controlId">{{ label }}
      <input
        :id="controlId"
        ref="input"
        :value="query"
        type="search"
        role="combobox"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-controls="listboxId"
        :aria-expanded="expanded"
        :aria-activedescendant="activeIndex >= 0 ? `${listboxId}-option-${activeIndex}` : undefined"
        :aria-describedby="describedBy"
        :aria-busy="loading"
        :disabled="disabled"
        :required="required"
        :placeholder="placeholder"
        @focus="open"
        @input="handleInput"
        @keydown="handleKeydown"
      />
      <small v-if="hint" :id="hintId" class="field-help">{{ hint }}</small>
    </label>
    <Teleport to="body">
      <div
        v-if="expanded"
        :id="listboxId"
        ref="panel"
        class="floating-combobox-panel"
        role="listbox"
        :aria-label="`${label} options`"
        :style="panelStyle"
      >
        <button
          v-for="(option, index) in results"
          :id="`${listboxId}-option-${index}`"
          :key="option.value"
          type="button"
          role="option"
          :aria-label="option.description ? `${option.label} — ${option.description}` : option.label"
          :disabled="option.disabled"
          :aria-selected="activeIndex === index"
          @mouseenter="activeIndex = index"
          @mousedown.prevent
          @click="choose(option)"
        >
          <slot name="option" :option="option">
            <strong>{{ option.label }}</strong>
            <span v-if="option.description">{{ option.description }}</span>
          </slot>
        </button>
        <p :id="statusId" class="floating-combobox-status" :role="error ? 'alert' : 'status'">
          <span v-if="loading"><span class="loading-spinner small" aria-hidden="true" />{{ loadingText }}</span>
          <span v-else-if="error">{{ error }}</span>
          <span v-else-if="searched && !results.length">{{ noResultsText }}</span>
          <span v-else class="visually-hidden">{{ results.length }} options available.</span>
        </p>
      </div>
    </Teleport>
  </div>
</template>
