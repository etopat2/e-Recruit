import { afterEach, describe, expect, it } from 'vitest'
import { installLimitedSelectViewport, MAX_VISIBLE_SELECT_OPTIONS } from './limitedSelectViewport'

function createSelect(optionCount: number): HTMLSelectElement {
  const select = document.createElement('select')
  for (let index = 0; index < optionCount; index++) {
    select.add(new Option(`Option ${index + 1}`, String(index + 1)))
  }
  document.body.append(select)
  return select
}

afterEach(() => {
  document.body.replaceChildren()
})

describe('limited select viewport', () => {
  it('opens long selects as seven visible, scrollable rows and collapses after selection', () => {
    const select = createSelect(12)
    const uninstall = installLimitedSelectViewport()

    select.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
    expect(select.size).toBe(MAX_VISIBLE_SELECT_OPTIONS)
    expect(select).toHaveAttribute('data-seven-option-expanded', 'true')

    select.dispatchEvent(new Event('change', { bubbles: true }))
    expect(select).not.toHaveAttribute('size')
    expect(select).not.toHaveAttribute('data-seven-option-expanded')
    uninstall()
  })

  it('leaves selects containing no more than seven options in their native form', () => {
    const select = createSelect(7)
    const uninstall = installLimitedSelectViewport()

    select.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
    expect(select).not.toHaveAttribute('size')
    uninstall()
  })

  it('supports keyboard expansion and escape', () => {
    const select = createSelect(8)
    const uninstall = installLimitedSelectViewport()

    select.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
    expect(select.size).toBe(MAX_VISIBLE_SELECT_OPTIONS)

    select.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(select).not.toHaveAttribute('size')
    uninstall()
  })
})
