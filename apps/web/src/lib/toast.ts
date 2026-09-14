import { reactive } from 'vue'

export type ToastKind = 'success' | 'error' | 'info'
export interface ToastMessage {
  id: number
  message: string
  kind: ToastKind
}

export const toasts = reactive<ToastMessage[]>([])
let nextToastId = 1

export function pushToast(message: string, kind: ToastKind = 'info', duration = 5_000): number {
  const id = nextToastId++
  toasts.push({ id, message, kind })
  if (duration > 0) window.setTimeout(() => dismissToast(id), duration)
  return id
}

export function dismissToast(id: number): void {
  const index = toasts.findIndex((toast) => toast.id === id)
  if (index >= 0) toasts.splice(index, 1)
}

export function clearToasts(): void {
  toasts.splice(0)
}
