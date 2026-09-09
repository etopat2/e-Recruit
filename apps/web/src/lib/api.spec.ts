import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, clearApiCache } from './api'

afterEach(() => {
  clearApiCache()
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('api request acceleration', () => {
  it('deduplicates concurrent GETs and serves a fresh response from memory', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ value: 'fast' }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const [first, second] = await Promise.all([
      api<{ value: string }>('/shared-data'),
      api<{ value: string }>('/shared-data'),
    ])
    const third = await api<{ value: string }>('/shared-data')

    expect(first.value).toBe('fast')
    expect(second).toBe(first)
    expect(third).toBe(first)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('invalidates cached GET data after a successful mutation', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ call: fetchMock.mock.calls.length }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await api('/records')
    await api('/records', { method: 'POST', body: '{}' })
    await api('/records')

    expect(fetchMock).toHaveBeenCalledTimes(3)
  })
})
