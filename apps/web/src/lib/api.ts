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

export function authToken(): string | null {
  return localStorage.getItem('ups_auth_token')
}

export function setAuthToken(token: string | null): void {
  if (token) localStorage.setItem('ups_auth_token', token)
  else localStorage.removeItem('ups_auth_token')
}

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (!(options.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  const token = authToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)
  headers.set('X-Correlation-ID', crypto.randomUUID())
  const response = await fetch(`${apiBase}${path}`, { ...options, headers })
  const contentType = response.headers.get('content-type') || ''
  const payload = contentType.includes('application/json') ? await response.json() : await response.blob()
  if (!response.ok) {
    const body = payload as { message?: string; errors?: Record<string, string[]> }
    throw new ApiError(body.message || `Request failed (${response.status}).`, response.status, body.errors, payload)
  }
  return payload as T
}

export const jsonBody = (value: unknown): Pick<RequestInit, 'body' | 'headers'> => ({
  body: JSON.stringify(value),
  headers: { 'Content-Type': 'application/json' },
})
