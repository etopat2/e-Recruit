import { cleanup, fireEvent, render, screen } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../lib/api'
import { clearEducationCatalogueCache, type EducationLevelGroup } from '../lib/educationQualifications'
import EducationRecordsForm, { type EducationRecordDraft } from './EducationRecordsForm.vue'

const uceResults = [{ value: 'Result 1 (Certificate awarded)', label: 'Result 1 — certificate awarded' }]
const uaceResults = [{ value: '3 Principal passes (3P)', label: '3 Principal-level passes (3P)' }]
const catalogue: EducationLevelGroup[] = [{
  label: 'School education',
  options: [
    { value: 'UCE', label: 'UCE / O-Level', guidance: 'Use the overall result.', results: uceResults, directory_searchable: true },
    { value: 'UACE', label: 'UACE / A-Level', guidance: 'Use the pass level.', results: uaceResults, directory_searchable: true },
    { value: 'Diploma', label: 'Ordinary Diploma', guidance: 'Use the award.', results: [{ value: 'Pass', label: 'Pass' }], directory_searchable: true },
    { value: 'Advanced Craft Certificate (legacy TVET)', label: 'Advanced Craft Certificate', guidance: 'Use the award.', results: [{ value: 'Awarded', label: 'Awarded' }], directory_searchable: false },
    ...Array.from({ length: 28 }, (_, index) => ({ value: `Level ${index + 1}`, label: `Level ${index + 1}`, guidance: 'Use the award.', results: [{ value: 'Pass', label: 'Pass' }], directory_searchable: true })),
  ],
}]

function mockCatalogue(): void {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: catalogue }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })))
}

afterEach(() => {
  cleanup()
  clearApiCache()
  clearEducationCatalogueCache()
  vi.unstubAllGlobals()
})

describe('EducationRecordsForm', () => {
  it('offers only results belonging to the selected level and clears an old result when the level changes', async () => {
    mockCatalogue()
    const records: EducationRecordDraft[] = [{ level: '', institution: '', completion_year: '', result: '' }]
    render(EducationRecordsForm, { props: { modelValue: records } })

    const level = screen.getByLabelText('Level')
    const result = screen.getByLabelText(/Result \/ class/)
    expect(result).toBeDisabled()

    await fireEvent.focus(level)
    expect(await screen.findAllByRole('option')).toHaveLength(32)
    await fireEvent.click(screen.getByRole('option', { name: /^UCE /i }))
    expect(result).toBeEnabled()
    await fireEvent.focus(result)
    expect(screen.getByRole('option', { name: /Result 1.*certificate awarded/i })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /3 Principal-level passes/i })).not.toBeInTheDocument()

    await fireEvent.click(screen.getByRole('option', { name: /Result 1.*certificate awarded/i }))
    expect(records[0].result).toBe('Result 1 (Certificate awarded)')

    await fireEvent.focus(level)
    await fireEvent.update(level, 'UACE')
    await fireEvent.click(screen.getByRole('option', { name: /^UACE /i }))
    expect(records[0].result).toBe('')
    await fireEvent.focus(result)
    expect(screen.getByRole('option', { name: /3 Principal-level passes/i })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Result 1 — certificate awarded/i })).not.toBeInTheDocument()
  })

  it('preserves historical free-text values until the applicant deliberately changes them', () => {
    mockCatalogue()
    const records: EducationRecordDraft[] = [{
      level: 'Historical overseas award',
      institution: 'Example Institution',
      completion_year: '2001',
      result: 'Excellent',
    }]
    render(EducationRecordsForm, { props: { modelValue: records } })

    expect(screen.getByLabelText('Level')).toHaveValue('Historical overseas award')
    expect(screen.getByLabelText(/Result \/ class/)).toHaveValue('Excellent')
  })

  it('preserves a historical result attached to a recognised level', async () => {
    mockCatalogue()
    const records: EducationRecordDraft[] = [{
      level: 'Diploma',
      institution: 'Example Institute',
      completion_year: '2010',
      result: 'Old transcript classification',
    }]
    render(EducationRecordsForm, { props: { modelValue: records } })

    expect(screen.getByLabelText('Level')).toHaveValue('Diploma')
    expect(screen.getByLabelText(/Result \/ class/)).toHaveValue('Old transcript classification')
    await fireEvent.focus(screen.getByLabelText(/Result \/ class/))
    expect(screen.getByRole('option', { name: /Old transcript classification.*Previously saved/i })).toBeInTheDocument()
  })

  it('adds and removes qualification records', async () => {
    mockCatalogue()
    const records: EducationRecordDraft[] = []
    render(EducationRecordsForm, { props: { modelValue: records } })

    await fireEvent.click(screen.getByRole('button', { name: 'Add qualification' }))
    expect(records).toHaveLength(1)
    expect(screen.getByText('Qualification 1')).toBeInTheDocument()

    await fireEvent.click(screen.getByRole('button', { name: 'Remove qualification 1' }))
    expect(records).toHaveLength(0)
    expect(screen.getByText(/starting with your most recent/i)).toBeInTheDocument()
  })
})
