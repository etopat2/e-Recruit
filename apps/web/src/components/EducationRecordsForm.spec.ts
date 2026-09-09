import { cleanup, fireEvent, render, screen } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import EducationRecordsForm, { type EducationRecordDraft } from './EducationRecordsForm.vue'

afterEach(() => cleanup())

describe('EducationRecordsForm', () => {
  it('offers only results belonging to the selected level and clears an old result when the level changes', async () => {
    const records: EducationRecordDraft[] = [{ level: '', institution: '', completion_year: '', result: '' }]
    render(EducationRecordsForm, { props: { modelValue: records } })

    const level = screen.getByLabelText('Level')
    const result = screen.getByLabelText(/Result \/ class/)
    expect(result).toBeDisabled()

    await fireEvent.update(level, 'UCE')
    expect(result).toBeEnabled()
    expect(screen.getByRole('option', { name: /Result 1 — certificate awarded/i })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /3 Principal-level passes/i })).not.toBeInTheDocument()

    await fireEvent.update(result, 'Result 1 (Certificate awarded)')
    expect(records[0].result).toBe('Result 1 (Certificate awarded)')

    await fireEvent.update(level, 'UACE')
    expect(records[0].result).toBe('')
    expect(screen.getByRole('option', { name: /3 Principal-level passes/i })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Result 1 — certificate awarded/i })).not.toBeInTheDocument()
  })

  it('preserves historical free-text values until the applicant deliberately changes them', () => {
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

  it('preserves a historical result attached to a recognised level', () => {
    const records: EducationRecordDraft[] = [{
      level: 'Diploma',
      institution: 'Example Institute',
      completion_year: '2010',
      result: 'Old transcript classification',
    }]
    render(EducationRecordsForm, { props: { modelValue: records } })

    expect(screen.getByLabelText('Level')).toHaveValue('Diploma')
    expect(screen.getByLabelText(/Result \/ class/)).toHaveValue('Old transcript classification')
    expect(screen.getByRole('option', { name: 'Old transcript classification (previously saved)' })).toBeInTheDocument()
  })

  it('adds and removes qualification records', async () => {
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
