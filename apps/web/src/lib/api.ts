export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>
  readonly payload: unknown

  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> = {},
    payload: unknown = null,
  ) {
    super(message)
    this.status = status
    this.errors = errors
    this.payload = payload
  }
}

const apiBase = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')
const responseCache = new Map<string, { expiresAt: number; value: unknown }>()
const inFlightRequests = new Map<string, Promise<unknown>>()
const defaultCacheTtlMs = 5_000
const maximumCacheEntries = 100

export interface ApiOptions extends RequestInit {
  cacheTtlMs?: number
}

export function clearApiCache(): void {
  responseCache.clear()
  inFlightRequests.clear()
}

function cacheResponse(key: string, value: unknown, ttlMs: number): void {
  if (responseCache.size >= maximumCacheEntries) {
    const oldestKey = responseCache.keys().next().value
    if (oldestKey) responseCache.delete(oldestKey)
  }
  responseCache.set(key, { expiresAt: Date.now() + ttlMs, value })
}

export function authToken(): string | null {
  return localStorage.getItem('ups_auth_token')
}

export function setAuthToken(token: string | null): void {
  clearApiCache()
  if (token) localStorage.setItem('ups_auth_token', token)
  else localStorage.removeItem('ups_auth_token')
}

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { cacheTtlMs = defaultCacheTtlMs, ...requestOptions } = options
  const headers = new Headers(requestOptions.headers)
  headers.set('Accept', 'application/json')
  if (!(requestOptions.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  const token = authToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)
  headers.set('X-Correlation-ID', crypto.randomUUID())

  const method = (requestOptions.method || 'GET').toUpperCase()
  const url = `${apiBase}${path}`
  const cacheKey = `${token || 'public'}:${url}`
  if (method === 'GET' && cacheTtlMs > 0) {
    const cached = responseCache.get(cacheKey)
    if (cached && cached.expiresAt > Date.now()) return cached.value as T
    responseCache.delete(cacheKey)

    const inFlight = inFlightRequests.get(cacheKey)
    if (inFlight) return inFlight as Promise<T>
  }

  const request = (async (): Promise<T> => {
    const response = await fetch(url, { ...requestOptions, method, headers })
    const contentType = response.headers.get('content-type') || ''
    const isJson = contentType.includes('application/json')
    const payload = isJson ? await response.json() : await response.blob()
    if (!response.ok) {
      const body = payload as { message?: string; errors?: Record<string, string[]> }
      throw new ApiError(body.message || `Request failed (${response.status}).`, response.status, body.errors, payload)
    }
    if (method === 'GET' && isJson && cacheTtlMs > 0) {
      cacheResponse(cacheKey, payload, cacheTtlMs)
    } else if (method !== 'GET') {
      clearApiCache()
    }
    return payload as T
  })()

  if (method === 'GET' && cacheTtlMs > 0) inFlightRequests.set(cacheKey, request)
  try {
    return await request
  } finally {
    if (inFlightRequests.get(cacheKey) === request) inFlightRequests.delete(cacheKey)
  }
}

export const jsonBody = (value: unknown): Pick<RequestInit, 'body' | 'headers'> => ({
  body: JSON.stringify(value),
  headers: { 'Content-Type': 'application/json' },
})
