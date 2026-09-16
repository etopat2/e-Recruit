<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch, type ComponentPublicInstance } from 'vue'
import { getDocument, GlobalWorkerOptions, type PDFDocumentLoadingTask, type PDFDocumentProxy, type RenderTask } from 'pdfjs-dist'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { authToken } from '../lib/api'
import LoadingIndicator from './LoadingIndicator.vue'

GlobalWorkerOptions.workerSrc = workerUrl

interface Highlight {
  x?: number
  y?: number
  width?: number
  height?: number
  coordinate_space?: string
}

const props = defineProps<{
  url: string
  filename: string
  mimeType: string
  focusedPage?: number
  highlight?: Highlight | null
}>()

const container = ref<HTMLElement | null>(null)
const pages = ref<number[]>([])
const loading = ref(true)
const firstPageReady = ref(false)
const error = ref('')
const zoom = ref(1)
const canvases = new Map<number, HTMLCanvasElement>()
const pageElements = new Map<number, HTMLElement>()
let loadingTask: PDFDocumentLoadingTask | null = null
let pdf: PDFDocumentProxy | null = null
let activeRender: RenderTask | null = null
let imageObjectUrl = ''
let resizeObserver: ResizeObserver | null = null
let renderGeneration = 0
let resizeTimer: ReturnType<typeof setTimeout> | null = null

const isPdf = () => props.mimeType === 'application/pdf' || props.filename.toLowerCase().endsWith('.pdf')
const isImage = () => props.mimeType.startsWith('image/')

function previewErrorMessage(problem: unknown): string {
  const message = problem instanceof Error ? problem.message : ''
  return /failed to fetch/i.test(message)
    ? 'The protected document service could not be reached. Check the connection and retry the preview.'
    : message || 'The protected preview could not be loaded.'
}

onMounted(() => {
  resizeObserver = new ResizeObserver(() => {
    if (resizeTimer) clearTimeout(resizeTimer)
    resizeTimer = setTimeout(() => void renderPdf(), 120)
  })
  if (container.value) resizeObserver.observe(container.value)
  void load()
})

watch(() => props.url, () => void load())
watch(() => props.focusedPage, (page) => {
  if (page) pageElements.get(page)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})

onBeforeUnmount(cleanUp)

function cleanUp(): void {
  renderGeneration += 1
  activeRender?.cancel()
  loadingTask?.destroy()
  resizeObserver?.disconnect()
  if (resizeTimer) clearTimeout(resizeTimer)
  if (imageObjectUrl) URL.revokeObjectURL(imageObjectUrl)
}

async function load(): Promise<void> {
  renderGeneration += 1
  activeRender?.cancel()
  await loadingTask?.destroy()
  loadingTask = null
  pdf = null
  pages.value = []
  loading.value = true
  firstPageReady.value = false
  error.value = ''
  canvases.clear()
  pageElements.clear()
  if (imageObjectUrl) URL.revokeObjectURL(imageObjectUrl)
  imageObjectUrl = ''

  try {
    if (isPdf()) {
      loadingTask = getDocument({
        url: props.url,
        httpHeaders: { Authorization: `Bearer ${authToken()}` },
        rangeChunkSize: 128 * 1024,
        // Range-only loading prevents a multi-page document from being fully
        // downloaded before PDF.js can paint the first relevant page.
        disableAutoFetch: true,
        disableStream: true,
      })
      pdf = await loadingTask.promise
      pages.value = Array.from({ length: pdf.numPages }, (_, index) => index + 1)
      await nextTick()
      await renderPdf()
    } else if (isImage()) {
      const response = await fetch(props.url, { headers: { Authorization: `Bearer ${authToken()}` } })
      if (!response.ok) throw new Error(`Preview request failed (${response.status}).`)
      imageObjectUrl = URL.createObjectURL(await response.blob())
      firstPageReady.value = true
    } else {
      throw new Error('This file type cannot be previewed in the browser. Download the original to inspect it.')
    }
  } catch (problem) {
    if (problem instanceof Error && problem.name === 'RenderingCancelledException') return
    error.value = previewErrorMessage(problem)
  } finally {
    loading.value = false
  }
}

async function renderPdf(): Promise<void> {
  if (!pdf || !container.value || !pages.value.length) return
  const generation = ++renderGeneration
  activeRender?.cancel()
  const availableWidth = Math.max(280, container.value.clientWidth - 24)

  const focusedPage = Math.max(1, Math.min(props.focusedPage || 1, pages.value.length))
  const renderOrder = [focusedPage, ...pages.value.filter((pageNumber) => pageNumber !== focusedPage)]

  for (const pageNumber of renderOrder) {
    if (generation !== renderGeneration || !pdf) return
    const canvas = canvases.get(pageNumber)
    if (!canvas) continue
    const page = await pdf.getPage(pageNumber)
    const original = page.getViewport({ scale: 1 })
    const scale = (availableWidth / original.width) * zoom.value
    const viewport = page.getViewport({ scale })
    const outputScale = Math.min(window.devicePixelRatio || 1, 2)
    const context = canvas.getContext('2d', { alpha: false })
    if (!context) continue
    canvas.width = Math.floor(viewport.width * outputScale)
    canvas.height = Math.floor(viewport.height * outputScale)
    canvas.style.width = `${Math.floor(viewport.width)}px`
    canvas.style.height = `${Math.floor(viewport.height)}px`
    activeRender = page.render({
      canvasContext: context,
      canvas,
      viewport,
      transform: outputScale === 1 ? undefined : [outputScale, 0, 0, outputScale, 0, 0],
    })
    await activeRender.promise
    page.cleanup()
    firstPageReady.value = true
    loading.value = false
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
  }
}

function setCanvas(element: Element | ComponentPublicInstance | null, page: number): void {
  if (element instanceof HTMLCanvasElement) canvases.set(page, element)
}

function setPageElement(element: Element | ComponentPublicInstance | null, page: number): void {
  if (element instanceof HTMLElement) pageElements.set(page, element)
}

function changeZoom(delta: number): void {
  zoom.value = Math.max(0.5, Math.min(2.5, Number((zoom.value + delta).toFixed(2))))
  void renderPdf()
}

function fitWidth(): void {
  zoom.value = 1
  void renderPdf()
}

function markerStyle(page: number): Record<string, string> | undefined {
  if (page !== (props.focusedPage || 1) || props.highlight?.coordinate_space !== 'normalised') return undefined
  const clamp = (value: number | undefined) => `${Math.max(0, Math.min(1, Number(value || 0))) * 100}%`
  return {
    left: clamp(props.highlight.x),
    top: clamp(props.highlight.y),
    width: clamp(props.highlight.width),
    height: clamp(props.highlight.height),
  }
}

async function openOriginal(): Promise<void> {
  try {
    let blob: Blob
    if (pdf) {
      const bytes = await pdf.getData()
      const copy = new Uint8Array(bytes.byteLength)
      copy.set(bytes)
      blob = new Blob([copy.buffer], { type: 'application/pdf' })
    } else {
      const response = await fetch(props.url, { headers: { Authorization: `Bearer ${authToken()}` } })
      if (!response.ok) throw new Error(`Download request failed (${response.status}).`)
      blob = await response.blob()
    }
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank', 'noopener,noreferrer')
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (problem) {
    error.value = previewErrorMessage(problem)
  }
}
</script>

<template>
  <div ref="container" class="secure-document-preview">
    <div class="preview-toolbar" role="toolbar" aria-label="Document preview controls">
      <button type="button" class="button secondary compact" :disabled="!isPdf() && !isImage()" aria-label="Zoom out" @click="changeZoom(-0.15)">−</button>
      <output aria-live="polite">{{ Math.round(zoom * 100) }}%</output>
      <button type="button" class="button secondary compact" :disabled="!isPdf() && !isImage()" aria-label="Zoom in" @click="changeZoom(0.15)">+</button>
      <button type="button" class="button secondary compact" :disabled="!isPdf() && !isImage()" @click="fitWidth">Fit width</button>
      <button type="button" class="button secondary compact toolbar-end" @click="openOriginal">Open original</button>
    </div>
    <div v-if="loading && !firstPageReady" class="preview-loading"><LoadingIndicator label="Loading first page…" /></div>
    <div v-if="error" class="preview-error" role="alert">
      <span>{{ error }}</span>
      <button type="button" class="button secondary compact" @click="load">Retry preview</button>
    </div>
    <div v-if="isPdf()" class="pdf-pages" :aria-busy="loading">
      <figure v-for="page in pages" :key="page" :ref="(element) => setPageElement(element, page)" class="pdf-page">
        <canvas :ref="(element) => setCanvas(element, page)" />
        <i v-if="markerStyle(page)" class="source-highlight" :style="markerStyle(page)" aria-hidden="true" />
        <figcaption>Page {{ page }} of {{ pages.length }}</figcaption>
      </figure>
    </div>
    <div v-else-if="isImage() && imageObjectUrl" class="image-page" :style="{ width: `${zoom * 100}%` }">
      <img :src="imageObjectUrl" :alt="filename" />
      <i v-if="markerStyle(1)" class="source-highlight" :style="markerStyle(1)" aria-hidden="true" />
    </div>
  </div>
</template>
