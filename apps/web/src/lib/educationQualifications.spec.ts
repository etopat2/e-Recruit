import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from './api'
import {
  clearEducationCatalogueCache,
  educationLevelFor,
  loadEducationLevelGroups,
  resultOptionsForEducationLevel,
  type EducationLevelGroup,
} from './educationQualifications'

const groups: EducationLevelGroup[] = [
  {
    label: 'School education',
    options: [{
      value: 'PLE',
      label: 'Primary Leaving Examination (PLE) — National Level 1',
      guidance: 'Use the certificate result.',
      directory_searchable: true,
      results: [{ value: 'Division 1', label: 'Division 1' }],
    }],
  },
  {
    label: 'Technical education',
    options: [{
      value: 'Advanced Craft Certificate (legacy TVET)',
      label: 'Advanced Craft Certificate',
      guidance: 'Use the historical award.',
      directory_searchable: false,
      results: [{ value: 'Awarded', label: 'Awarded' }],
    }],
  },
]

afterEach(() => {
  clearApiCache()
  clearEducationCatalogueCache()
  vi.unstubAllGlobals()
})

describe('API-owned Ugandan education qualification catalogue', () => {
  it('loads the canonical catalogue once and retains its directory capability flags', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: groups }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const [first, second] = await Promise.all([loadEducationLevelGroups(), loadEducationLevelGroups()])

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(first).toEqual(second)
    expect(educationLevelFor(first, 'PLE')?.directory_searchable).toBe(true)
    expect(educationLevelFor(first, 'Advanced Craft Certificate (legacy TVET)')?.directory_searchable).toBe(false)
  })

  it('returns only the result choices owned by the selected level', () => {
    expect(resultOptionsForEducationLevel(groups, 'PLE').map((option) => option.value)).toEqual(['Division 1'])
    expect(resultOptionsForEducationLevel(groups, 'Unknown qualification')).toEqual([])
  })
})
