import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AdministrativeAddressSelector from './AdministrativeAddressSelector.vue'

const lineage = {
  region: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', code: 'region:central', name: 'CENTRAL', unit_type: 'region' },
  subregion: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAW', code: 'subregion:buganda', name: 'BUGANDA', unit_type: 'subregion' },
  district: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAX', code: 'district:kampala', name: 'KAMPALA', unit_type: 'capital-city' },
  county: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAY', code: 'county:kampala', name: 'KAMPALA', unit_type: 'county' },
  subcounty: { id: '01ARZ3NDEKTSV4RRFFQ69G5FAZ', code: 'subcounty:central', name: 'KAMPALA CENTRAL', unit_type: 'division' },
  parish: { id: '01ARZ3NDEKTSV4RRFFQ69G5FB0', code: 'parish:kisenyi', name: 'KISENYI I', unit_type: 'parish' },
  village: { id: '01ARZ3NDEKTSV4RRFFQ69G5FB1', code: 'village:blue-room', name: 'BLUE ROOM', unit_type: 'village' },
}

afterEach(() => vi.unstubAllGlobals())

describe('AdministrativeAddressSelector', () => {
  it('fills every administrative field when a village search result is selected', async () => {
    const village = {
      ...lineage.village,
      level: 'village',
      parent_id: lineage.parish.id,
      full_address: 'BLUE ROOM, KISENYI I, KAMPALA CENTRAL, KAMPALA, KAMPALA, CENTRAL',
      lineage,
    }
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      const data = url.includes('level=village&search=Blue') ? [village] : []
      return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))

    const rendered = render(AdministrativeAddressSelector, { props: { modelValue: {} } })
    const villageControl = screen.getByRole('combobox', { name: /village \/ cell/i })
    expect(screen.queryByText(/find a village anywhere/i)).not.toBeInTheDocument()
    await fireEvent.update(villageControl, 'Blue')
    const result = await screen.findByRole('option', { name: /BLUE ROOM/i })
    await fireEvent.click(result)

    await waitFor(() => expect(rendered.emitted()['update:modelValue']).toHaveLength(1))
    const updates = rendered.emitted()['update:modelValue'] as Array<[Record<string, string>]>
    const selected = updates.at(-1)?.[0] as Record<string, string>
    expect(selected.village_id).toBe(lineage.village.id)
    expect(selected.parish).toBe('KISENYI I')
    expect(selected.district).toBe('KAMPALA')
    expect(selected.region).toBe('CENTRAL')
    expect(selected.full_address).toContain('KAMPALA CENTRAL')
  })
})
