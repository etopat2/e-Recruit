import { cleanup, fireEvent, render, screen } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../lib/api'
import InstitutionDirectoryField from './InstitutionDirectoryField.vue'
import type { EducationRecordDraft } from './EducationRecordsForm.vue'

afterEach(() => {
  cleanup()
  clearApiCache()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function renderField(overrides: Partial<EducationRecordDraft> = {}) {
  const record = reactive<EducationRecordDraft>({
    level: 'UCE',
    institution: '',
    institution_id: null,
    institution_not_listed: false,
    completion_year: '',
    result: '',
    ...overrides,
  })

  render(InstitutionDirectoryField, {
    props: {
      modelValue: record,
      'onUpdate:modelValue': (value: EducationRecordDraft) => Object.assign(record, value),
    },
  })

  return record
}

describe('InstitutionDirectoryField', () => {
  it('reveals manual entry only when the applicant says the institution is not listed', async () => {
    const record = renderField()

    expect(screen.queryByLabelText(/Institution name \(as shown on the certificate\)/i)).not.toBeInTheDocument()
    await fireEvent.click(screen.getByRole('checkbox', { name: 'My institution is not listed' }))

    const manualInput = screen.getByLabelText(/Institution name \(as shown on the certificate\)/i)
    expect(manualInput).toBeVisible()
    expect(screen.queryByRole('combobox', { name: 'Institution' })).not.toBeInTheDocument()

    await fireEvent.update(manualInput, 'Historical Training Centre')
    expect(record.institution).toBe('Historical Training Centre')
    expect(record.institution_not_listed).toBe(true)
    expect(record.institution_id).toBeNull()
  })

  it('shows at most seven official matches and stores the selected provenance', async () => {
    vi.useFakeTimers()
    const matches = Array.from({ length: 9 }, (_, index) => ({
      id: `institution-${index + 1}`,
      name: `Official Institution ${index + 1}`,
      institution_type: 'Secondary School',
      district: 'Kampala',
      registration_number: `REG-${index + 1}`,
      registration_status: 'Registered',
      operational_status: 'Active',
      source: 'moes_emis',
      source_url: 'https://emis.go.ug/emis/public-search',
      last_verified_at: '2026-09-09T00:00:00Z',
    }))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
      JSON.stringify({ data: matches }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    )))
    const record = renderField()

    await fireEvent.update(screen.getByRole('combobox', { name: 'Institution' }), 'Official')
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()

    expect(screen.getAllByRole('option')).toHaveLength(7)
    await fireEvent.mouseDown(screen.getByRole('option', { name: /Official Institution 2/ }))
    expect(record.institution_id).toBe('institution-2')
    expect(record.institution).toBe('Official Institution 2')
    expect(record.institution_source).toBe('moes_emis')
    expect(record.institution_not_listed).toBe(false)
  })

  it('converts a legacy free-text institution into the explicit fallback state', async () => {
    const record = renderField({
      institution: 'Previously Saved College',
      institution_not_listed: undefined,
    })
    await flushPromises()

    expect(record.institution_not_listed).toBe(true)
    expect(screen.getByLabelText(/Institution name \(as shown on the certificate\)/i)).toHaveValue('Previously Saved College')
  })
})
