import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import Dialog from './Dialog.vue'

afterEach(() => {
  cleanup()
  document.body.style.overflow = ''
})

describe('Dialog', () => {
  it('labels the modal, locks scrolling, traps focus, and returns focus on close', async () => {
    const trigger = document.createElement('button')
    trigger.textContent = 'Open'
    document.body.append(trigger)
    trigger.focus()

    const rendered = render(Dialog, {
      props: { open: true, title: 'Record attendance', description: 'Confirm the interview outcome.' },
      slots: { default: '<button data-dialog-initial-focus>Save</button><button>Cancel</button>' },
    })

    const dialog = await screen.findByRole('dialog', { name: 'Record attendance' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(dialog).toHaveAccessibleDescription('Confirm the interview outcome.')
    expect(document.body.style.overflow).toBe('hidden')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus())

    const close = screen.getByRole('button', { name: 'Close dialog' })
    const cancel = screen.getByRole('button', { name: 'Cancel' })
    cancel.focus()
    await fireEvent.keyDown(document, { key: 'Tab' })
    expect(close).toHaveFocus()
    await fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(cancel).toHaveFocus()

    await rendered.rerender({ open: false })
    await waitFor(() => expect(trigger).toHaveFocus())
    expect(document.body.style.overflow).toBe('')
    trigger.remove()
  })

  it('supports Escape and blocks accidental backdrop dismissal while dirty', async () => {
    const rendered = render(Dialog, {
      props: { open: true, title: 'Edit account', dirty: true },
      slots: { default: '<input aria-label="Name" />' },
    })

    await screen.findByRole('dialog')
    await fireEvent.keyDown(document, { key: 'Escape' })
    expect(rendered.emitted()['blocked-close']?.[0]).toEqual(['escape'])
    expect(rendered.emitted().close).toBeUndefined()

    await fireEvent.mouseDown(screen.getByTestId('dialog-backdrop'))
    expect(rendered.emitted()['blocked-close']?.[1]).toEqual(['backdrop'])

    await fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }))
    expect(rendered.emitted().close?.[0]).toEqual(['button'])
  })
})
