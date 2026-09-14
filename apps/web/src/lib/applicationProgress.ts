import type { ApplicationTimelineEvent, CampaignStage } from '../types'

export type StageProgressState = 'completed' | 'current' | 'upcoming' | 'terminal'

export interface StageProgress {
  code: string
  label: string
  state: StageProgressState
  outcome?: string
  recordedAt?: string
}

interface RecordedStage {
  stageCode: string
  outcome: string
  terminal: boolean
}

const stageLabels: Record<string, string> = {
  application: 'Application Submitted',
  hard_copy: 'Documents Received',
  verification: 'Documents Verified',
  eligibility: 'Eligibility Confirmed',
  interview: 'Interview',
  selection: 'Selection Result',
  medical: 'Medical Examination',
  training: 'Training',
}

const recordedStatuses: Record<string, RecordedStage> = {
  submitted_online: { stageCode: 'application', outcome: 'Submitted', terminal: false },
  awaiting_hard_copies: { stageCode: 'application', outcome: 'Submitted online', terminal: false },
  under_verification: { stageCode: 'application', outcome: 'Submitted', terminal: false },
  hard_copies_received: { stageCode: 'hard_copy', outcome: 'Documents received', terminal: false },
  documents_verified: { stageCode: 'verification', outcome: 'Documents verified', terminal: false },
  verification_completed: { stageCode: 'verification', outcome: 'Documents verified', terminal: false },
  eligible: { stageCode: 'eligibility', outcome: 'Eligible', terminal: false },
  ineligible: { stageCode: 'eligibility', outcome: 'Not eligible', terminal: true },
  interview_scheduled: { stageCode: 'interview', outcome: 'Scheduled', terminal: false },
  interview_attended: { stageCode: 'interview', outcome: 'Attended', terminal: false },
  interview_scored: { stageCode: 'interview', outcome: 'Scored', terminal: false },
  provisionally_selected: { stageCode: 'selection', outcome: 'Provisionally selected', terminal: false },
  selected: { stageCode: 'selection', outcome: 'Provisionally selected', terminal: false },
  reserve: { stageCode: 'selection', outcome: 'Waitlisted', terminal: false },
  waitlisted: { stageCode: 'selection', outcome: 'Waitlisted', terminal: false },
  not_selected: { stageCode: 'selection', outcome: 'Not selected', terminal: true },
  medical_invited: { stageCode: 'medical', outcome: 'Invited', terminal: false },
  fit: { stageCode: 'medical', outcome: 'Fit', terminal: false },
  unfit: { stageCode: 'medical', outcome: 'Unfit', terminal: true },
  final_selected: { stageCode: 'medical', outcome: 'Fit — final selection approved', terminal: false },
  training_invited: { stageCode: 'training', outcome: 'Reporting invitation sent', terminal: false },
  training_reported: { stageCode: 'training', outcome: 'Reported', terminal: false },
  reported: { stageCode: 'training', outcome: 'Reported', terminal: false },
  training_no_show: { stageCode: 'training', outcome: 'No-show', terminal: true },
  no_show: { stageCode: 'training', outcome: 'No-show', terminal: true },
}

export function progressForApplication(stages: readonly CampaignStage[], timeline: readonly ApplicationTimelineEvent[]): StageProgress[] {
  const orderedStages = [...stages].sort((left, right) => left.sequence - right.sequence)
  const configuredCodes = new Set(orderedStages.map((stage) => stage.stage_code))
  const recorded = timeline.flatMap((event) => {
    const match = recordedStageFor(event.status, configuredCodes)
    return match ? [{ event, match }] : []
  })
  const latestByStage = new Map(recorded.map((entry) => [entry.match.stageCode, entry]))
  const latest = recorded.at(-1)
  const terminalSequence = latest?.match.terminal
    ? orderedStages.find((stage) => stage.stage_code === latest.match.stageCode)?.sequence
    : undefined

  return orderedStages
    .filter((stage) => terminalSequence === undefined || stage.sequence <= terminalSequence)
    .map((stage) => {
      const entry = latestByStage.get(stage.stage_code)
      let state: StageProgressState = 'upcoming'
      if (entry) state = latest === entry ? (entry.match.terminal ? 'terminal' : 'current') : 'completed'

      return {
        code: stage.stage_code,
        label: stageLabels[stage.stage_code] ?? stage.name,
        state,
        outcome: entry?.match.outcome,
        recordedAt: entry?.event.at,
      }
    })
}

export function applicantOutcomeLabel(status: string): string {
  return recordedStatuses[normalise(status)]?.outcome ?? humanise(status)
}

function recordedStageFor(status: string, configuredCodes: ReadonlySet<string>): RecordedStage | undefined {
  const normalised = normalise(status)
  if (recordedStatuses[normalised]) return recordedStatuses[normalised]
  if (configuredCodes.has(normalised)) {
    return { stageCode: normalised, outcome: stageLabels[normalised] ?? humanise(normalised), terminal: false }
  }

  return undefined
}

function normalise(value: string): string {
  return value.trim().toLowerCase().replaceAll('-', '_').replaceAll(' ', '_')
}

function humanise(value: string): string {
  const text = normalise(value).replaceAll('_', ' ')
  return text ? `${text.charAt(0).toUpperCase()}${text.slice(1)}` : 'Status updated'
}
