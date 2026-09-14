import { cleanup, fireEvent, render, screen } from '@testing-library/vue'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearToasts, pushToast } from '../lib/toast'
import FieldError from './FieldError.vue'
import FormAlert from './FormAlert.vue'
import ToastViewport from './ToastViewport.vue'

afterEach(() => {
  cleanup()
  clearToasts()
  vi.useRealTimers()
})

describe('form feedback primitives', () => {
  it('renders field errors and form messages with consistent live-region semantics', () => {
    render(FieldError, { props: { id: 'email-error', error: ['Email is required.', 'Use an official address.'] } })
    expect(screen.getByRole('alert')).toHaveAttribute('id', 'email-error')
    expect(screen.getByRole('alert')).toHaveTextContent('Email is required. Use an official address.')

    render(FormAlert, { props: { kind: 'success', message: 'Account updated.' } })
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite')
    expect(screen.getByRole('status')).toHaveTextContent('Account updated.')
  })

  it('announces, dismisses, and expires toast notifications', async () => {
    vi.useFakeTimers()
    render(ToastViewport)
    pushToast('Unable to save.', 'error', 1_000)
    await nextTick()
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to save.')
    await fireEvent.click(screen.getByRole('button', { name: 'Dismiss notification' }))
    expect(screen.queryByText('Unable to save.')).not.toBeInTheDocument()

    pushToast('Saved.', 'success', 1_000)
    await nextTick()
    expect(screen.getByRole('status')).toHaveTextContent('Saved.')
    await vi.advanceTimersByTimeAsync(1_000)
    await nextTick()
    expect(screen.queryByText('Saved.')).not.toBeInTheDocument()
  })
})
