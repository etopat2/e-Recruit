import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearApiCache } from '../lib/api'
import OperationsWorkflowView from './OperationsWorkflowView.vue'

const emptyLookups = {
  posts: [], regions: [], hard_copy: { can_receive: true, receiving_point: 'Uganda Prisons Service Headquarters', transmission_notice: 'Units and regions are transmission channels only; final receipt is recorded at headquarters.' },
  centre_sessions: [], interview_assignments: [], panels: [], medical_schedules: [], selection_outcomes: [],
  medical_results: [], final_selections: [], training_invites: [], selection_runs: [], replacement_recommendations: [],
}

beforeEach(() => {
  clearApiCache()
  emptyLookups.hard_copy.can_receive = true
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const data = String(input).includes('/operations/applications') ? [{
      id: 'private-application-key',
      label: 'Amina Nabirye — UPS/2026/WRD/000321',
      description: 'Status: awaiting hard copies',
      document_requirements: [
        { document_type: 'national_id', label: 'National ID' },
        { document_type: 'application_letter', label: 'Application letter' },
        { document_type: 'lc1_letter', label: 'LC1 letter' },
        { document_type: 'academic_certificate', label: 'S.4 certificate or result slip' },
        { document_type: 'passport_photo', label: 'Passport photo' },
        { document_type: 'skill_certificate', label: 'Skill certificate(s)' },
      ],
    }] : emptyLookups
    return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }))
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('OperationsWorkflowView', () => {
  it('opens a human-labelled operational form without raw ID or JSON inputs', async () => {
    expect(document.querySelector('form')).not.toBeInTheDocument()
    render(OperationsWorkflowView)

    const trigger = await screen.findByRole('button', { name: /Record headquarters hard-copy receipt/i })
    trigger.focus()
    await fireEvent.click(trigger)
    const dialog = await screen.findByRole('dialog', { name: 'Record hard-copy receipt' })
    expect(dialog.querySelectorAll('form')).toHaveLength(1)
    await waitFor(() => expect(screen.getByRole('combobox', { name: /Application/i })).toHaveFocus())
    expect(screen.queryByText(/Application ID|Document checks \(JSON\)/i)).not.toBeInTheDocument()
    expect(dialog.querySelector('textarea')?.closest('label')).toHaveTextContent('Receipt notes')
    await fireEvent.update(screen.getByRole('combobox', { name: /Application/i }), 'Amina')
    await fireEvent.click(await screen.findByRole('option', { name: /Amina Nabirye/i }))
    expect(screen.getByRole('group', { name: 'Required hard-copy documents' }).querySelectorAll('input[type="checkbox"]')).toHaveLength(6)
    expect(screen.getByText(/Skill certificate\(s\) received and matches/i)).toBeInTheDocument()
    expect(screen.getByText(/Uganda Prisons Service Headquarters/)).toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Receiving office' })).not.toBeInTheDocument()

    await fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })

  it('treats units and regions as transmission channels without a receipt action', async () => {
    emptyLookups.hard_copy.can_receive = false
    render(OperationsWorkflowView)

    expect(await screen.findByText(/Units and regions are transmission channels only/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Record headquarters hard-copy receipt/i })).not.toBeInTheDocument()
  })
})
