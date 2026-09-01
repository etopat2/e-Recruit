import { render, screen } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import StatusBadge from './StatusBadge.vue'

describe('StatusBadge', () => {
  it('turns machine-readable workflow states into readable text', () => {
    render(StatusBadge, { props: { status: 'awaiting_hard_copies' } })
    expect(screen.getByText('awaiting hard copies')).toHaveAttribute('data-status', 'awaiting_hard_copies')
  })
})
