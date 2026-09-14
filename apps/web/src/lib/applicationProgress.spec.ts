import { describe, expect, it } from 'vitest'
import type { ApplicationTimelineEvent, CampaignStage } from '../types'
import { progressForApplication } from './applicationProgress'

const stages: CampaignStage[] = ['application', 'hard_copy', 'verification', 'eligibility', 'interview', 'selection', 'medical', 'training']
  .map((stage_code, index) => ({ stage_code, name: stage_code, sequence: index + 1, required: true }))

function event(status: string, day: number): ApplicationTimelineEvent {
  return { status, at: `2026-09-${String(day).padStart(2, '0')}T09:00:00Z`, reason: 'Never show this internally recorded reason.' }
}

describe('application stage progress', () => {
  it('combines configured order with recorded outcomes without exposing event reasons', () => {
    const progress = progressForApplication(stages, [
      event('submitted_online', 1),
      event('hard_copies_received', 2),
      event('documents_verified', 3),
      event('eligible', 4),
      event('interview_scheduled', 5),
      event('interview_scored', 6),
    ])

    expect(progress.map(({ code, state }) => [code, state])).toEqual([
      ['application', 'completed'],
      ['hard_copy', 'completed'],
      ['verification', 'completed'],
      ['eligibility', 'completed'],
      ['interview', 'current'],
      ['selection', 'upcoming'],
      ['medical', 'upcoming'],
      ['training', 'upcoming'],
    ])
    expect(progress.find((stage) => stage.code === 'interview')?.outcome).toBe('Scored')
    expect(JSON.stringify(progress)).not.toContain('Never show')
  })

  it('ends at a negative outcome instead of presenting later stages as skipped', () => {
    const progress = progressForApplication(stages, [event('submitted_online', 1), event('ineligible', 2)])

    expect(progress.at(-1)).toMatchObject({ code: 'eligibility', state: 'terminal', outcome: 'Not eligible' })
    expect(progress.map((stage) => stage.code)).not.toContain('interview')
    expect(progress.some((stage) => (stage.state as string) === 'skipped')).toBe(false)
  })
})
