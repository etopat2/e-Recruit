export const MAX_VISIBLE_SELECT_OPTIONS = 7

const expandedAttribute = 'data-seven-option-expanded'

function selectFromEvent(event: Event): HTMLSelectElement | null {
  return event.target instanceof HTMLSelectElement ? event.target : null
}

function canExpand(select: HTMLSelectElement): boolean {
  return !select.disabled && !select.multiple && select.options.length > MAX_VISIBLE_SELECT_OPTIONS
}

function expand(select: HTMLSelectElement): void {
  if (!canExpand(select) || select.hasAttribute(expandedAttribute)) return

  select.size = MAX_VISIBLE_SELECT_OPTIONS
  select.setAttribute(expandedAttribute, 'true')
  select.focus({ preventScroll: true })
}

function collapse(select: HTMLSelectElement): void {
  if (!select.hasAttribute(expandedAttribute)) return

  select.removeAttribute(expandedAttribute)
  select.removeAttribute('size')
}

/**
 * Native select pop-up heights are controlled by the browser and operating system.
 * This delegated enhancer opens longer single selects as seven-row, scrollable
 * listboxes while preserving native controls and dynamically rendered forms.
 */
export function installLimitedSelectViewport(root: Document = document): () => void {
  const handleMouseDown = (event: MouseEvent): void => {
    const select = selectFromEvent(event)
    if (!select || !canExpand(select) || select.hasAttribute(expandedAttribute)) return

    event.preventDefault()
    expand(select)
  }

  const handleKeyDown = (event: KeyboardEvent): void => {
    const select = selectFromEvent(event)
    if (!select) return

    if (event.key === 'Escape') {
      collapse(select)
      return
    }

    if (event.key === 'Enter' || event.key === ' ' || (event.altKey && event.key === 'ArrowDown')) {
      if (!select.hasAttribute(expandedAttribute) && canExpand(select)) {
        event.preventDefault()
        expand(select)
      }
    }
  }

  const handleChange = (event: Event): void => {
    const select = selectFromEvent(event)
    if (select) collapse(select)
  }

  const handleFocusOut = (event: FocusEvent): void => {
    const select = selectFromEvent(event)
    if (select) collapse(select)
  }

  root.addEventListener('mousedown', handleMouseDown)
  root.addEventListener('keydown', handleKeyDown)
  root.addEventListener('change', handleChange)
  root.addEventListener('focusout', handleFocusOut)

  return () => {
    root.removeEventListener('mousedown', handleMouseDown)
    root.removeEventListener('keydown', handleKeyDown)
    root.removeEventListener('change', handleChange)
    root.removeEventListener('focusout', handleFocusOut)
  }
}
