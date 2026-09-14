import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import OperationsWorkflowView from './OperationsWorkflowView.vue'

afterEach(() => cleanup())

describe('OperationsWorkflowView', () => {
  it('opens one contextual operational form at a time in an accessible dialog', async () => {
    render(OperationsWorkflowView)
    expect(document.querySelector('form')).not.toBeInTheDocument()

    const trigger = screen.getByRole('button', { name: /Record hard-copy receipt/i })
    trigger.focus()
    await fireEvent.click(trigger)
    const dialog = await screen.findByRole('dialog', { name: 'Record hard-copy receipt' })
    expect(dialog.querySelectorAll('form')).toHaveLength(1)
    await waitFor(() => expect(screen.getByLabelText('Application ID')).toHaveFocus())

    await fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })
})
