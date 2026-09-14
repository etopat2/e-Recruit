import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import FloatingCombobox, { type ComboboxOption } from './FloatingCombobox.vue'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const options: ComboboxOption[] = [
  { value: 'central', label: 'Central Region', description: 'Uganda' },
  { value: 'eastern', label: 'Eastern Region', description: 'Uganda' },
  { value: 'northern', label: 'Northern Region', description: 'Uganda' },
]

describe('FloatingCombobox', () => {
  it('uses an ARIA listbox and supports keyboard selection', async () => {
    const rendered = render(FloatingCombobox, {
      props: { label: 'Region', options, modelValue: '' },
    })
    const input = screen.getByRole('combobox', { name: 'Region' })
    await fireEvent.focus(input)

    const listbox = await screen.findByRole('listbox', { name: 'Region options' })
    expect(input).toHaveAttribute('aria-controls', listbox.id)
    expect(input).toHaveAttribute('aria-expanded', 'true')
    await fireEvent.keyDown(input, { key: 'ArrowDown' })
    expect(input.getAttribute('aria-activedescendant')).toContain('option-1')
    await fireEvent.keyDown(input, { key: 'Enter' })

    const selections = rendered.emitted().select as Array<[ComboboxOption]>
    const modelUpdates = rendered.emitted()['update:modelValue'] as Array<[string]>
    expect(selections[0][0]).toMatchObject({ value: 'eastern' })
    expect(modelUpdates.at(-1)).toEqual(['Eastern Region'])
    expect(input).toHaveAttribute('aria-expanded', 'false')
  })

  it('debounces asynchronous searches, cancels the previous request, and reports failures', async () => {
    vi.useFakeTimers()
    const signals: AbortSignal[] = []
    const loadOptions = vi.fn((query: string, signal: AbortSignal): Promise<ComboboxOption[]> => {
      signals.push(signal)
      if (query === 'Ma') {
        return new Promise((resolve, reject) => {
          signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
          void resolve
        })
      }
      if (query === 'fail') return Promise.reject(new Error('Directory unavailable.'))
      return Promise.resolve([{ value: query, label: `${query} result` }])
    })
    render(FloatingCombobox, {
      props: { label: 'Institution', loadOptions, minChars: 2, debounceMs: 100 },
    })
    const input = screen.getByRole('combobox', { name: 'Institution' })

    await fireEvent.update(input, 'Ma')
    await vi.advanceTimersByTimeAsync(100)
    await waitFor(() => expect(loadOptions).toHaveBeenCalledTimes(1))
    await fireEvent.update(input, 'Mak')
    expect(signals[0].aborted).toBe(true)
    await vi.advanceTimersByTimeAsync(100)
    await waitFor(() => expect(screen.getByRole('option', { name: 'Mak result' })).toBeInTheDocument())

    await fireEvent.update(input, 'fail')
    await vi.advanceTimersByTimeAsync(100)
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Directory unavailable.'))
  })
})
